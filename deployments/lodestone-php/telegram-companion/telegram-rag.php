<?php
/**
 * Companion RAG — Hybrid Retrieval-Augmented Generation from the indexed Harmonia vault.
 *
 * Uses a three-layer hybrid retrieval strategy:
 * 1. SEMANTIC SEARCH — bge-m3 embeddings through OpenRouter + cosine similarity (primary)
 * 2. FTS5 KEYWORD SEARCH — full-text search with synonym expansion (complementary)
 * 3. DOMAIN DETECTION — Wheel pillar awareness (contextual boost)
 *
 * Results from all layers are merged, deduplicated, and ranked by a combined
 * score that weights semantic relevance, keyword relevance, and article priority.
 *
 * Graceful fallback: if OpenRouter is not configured or the embedding call
 * fails, falls back to FTS5-only retrieval (v1 behavior).
 *
 * The vault is indexed offline by index-vault.py and uploaded as vault-index.db.
 * Embeddings are generated at index time and stored as packed float32 blobs.
 */

class CompanionRAG {

    private ?PDO $db = null;
    private string $dbPath;
    private bool $available = false;
    private bool $embeddingsAvailable = false;
    private ?string $openaiKey = null;

    /** @var int Embedding dimensions for text-embedding-3-small */
    private const EMBED_DIMS = 1024;
    private const EMBED_MODEL = 'baai/bge-m3';

    /** @var float Weight for semantic score in hybrid ranking (0-1) */
    private const SEMANTIC_WEIGHT = 0.7;

    /** @var float Weight for FTS5 score in hybrid ranking (0-1) */
    private const FTS_WEIGHT = 0.3;

    public function __construct(string $dbPath = null, string $openaiKey = null) {
        $this->dbPath = $dbPath ?? __DIR__ . '/vault-index.db';
        $this->openaiKey = $openaiKey;

        if (file_exists($this->dbPath)) {
            try {
                $this->db = new PDO('sqlite:' . $this->dbPath);
                $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->available = true;

                // Check if embeddings exist in the database
                // (only this model's: an index built with another model is not comparable)
                if (function_exists('llm_cfg') && llm_cfg('LLM_PROVIDER') === 'openrouter' && llm_cfg('LLM_API_KEY')) {
                    $stmt = $this->db->prepare('SELECT COUNT(*) FROM chunk_embeddings WHERE model = ?');
                    $stmt->execute([self::EMBED_MODEL]);
                    $count = $stmt->fetchColumn();
                    $this->embeddingsAvailable = ($count > 0);
                }
            } catch (PDOException $e) {
                error_log("RAG database error: " . $e->getMessage());
            }
        }
    }

    /**
     * Check if the RAG index is available.
     */
    public function isAvailable(): bool {
        return $this->available;
    }

    /** @var int Maximum chunks to keep per article in semantic search */
    private const MAX_CHUNKS_PER_ARTICLE = 3;

    /** @var float Score ratio threshold for full-article retrieval (top score / 2nd score) */
    private const FULL_ARTICLE_CONFIDENCE_GAP = 1.5;

    /**
     * Retrieve relevant vault content for a user query.
     *
     * EN-CANONICAL ARCHITECTURE WITH FRESH-TRANSLATION SUBSTITUTION
     * -------------------------------------------------------------
     * All retrieval (semantic + FTS5 + domain) runs against EN-only — the EN
     * vault is the single source of truth for what's most up-to-date. The
     * embedding model (text-embedding-3-small) is multilingual at the query
     * side, so any-language queries correctly rank against EN content.
     *
     * After retrieval, if the practitioner's language is not English, fresh
     * translations substitute the EN content where they exist. A translation
     * is FRESH iff its frontmatter `source_hash` matches the current EN body
     * hash for the same filepath. Stale and missing translations are silently
     * ignored — the practitioner gets canonical EN truth rather than outdated
     * content in their language.
     *
     * This guarantees Lodestone always delivers the most up-to-date doctrinal
     * state regardless of translation coverage.
     *
     * @param string $query  The user's message
     * @param int $topK      Number of article excerpts to return
     * @param int $maxChars  Maximum total characters of context to return
     * @param string $lang   Practitioner language code (e.g. 'en', 'fr', 'ru').
     * @return string        Formatted context for injection into system prompt
     */
    public function retrieve(string $query, int $topK = 5, int $maxChars = 12000, string $lang = 'en'): string {
        if (!$this->available) return '';

        $lang = $lang ?: 'en';

        // Run all retrieval layers against EN canonical — guarantees freshness
        [$results, $articleChunks] = $this->runRetrievalLayers($query, $topK);

        if (empty($results)) return '';

        // Sort by combined score
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        // === FULL-ARTICLE RETRIEVAL for high-confidence single-article matches ===
        // If the top result scores significantly higher than the second, retrieve full article text
        $topResults = array_slice($results, 0, $topK);
        if (count($results) >= 2) {
            $topScore = $results[0]['score'];
            $secondScore = $results[1]['score'];
            if ($secondScore > 0 && ($topScore / $secondScore) >= self::FULL_ARTICLE_CONFIDENCE_GAP) {
                $fullText = $this->getFullArticleText($results[0]['article_id']);
                if ($fullText) {
                    $topResults[0]['content'] = $fullText;
                    $topResults[0]['heading'] = '(full article)';
                    $topResults[0]['chunk_position'] = null;  // signals full-article substitution path
                }
            }
        }

        // For remaining results, merge multi-chunk content where available
        foreach ($topResults as $i => &$result) {
            if ($i === 0 && isset($result['heading']) && $result['heading'] === '(full article)') {
                continue; // Already has full article
            }
            $aid = $result['article_id'];
            if (isset($articleChunks[$aid]) && count($articleChunks[$aid]) > 1) {
                // Merge multiple chunks from the same article
                $mergedContent = '';
                foreach ($articleChunks[$aid] as $chunk) {
                    $heading = $chunk['heading'] ?? '';
                    if ($heading) $mergedContent .= "### {$heading}\n";
                    $mergedContent .= $chunk['content'] . "\n\n";
                }
                $result['content'] = trim($mergedContent);
                $result['heading'] = '(multiple sections)';
                // Preserve the per-chunk positions for substitution
                $result['_merged_chunks'] = $articleChunks[$aid];
            }
        }
        unset($result);

        // Substitute fresh translation content where available. Translations
        // never affect retrieval ranking — they only swap rendered prose at
        // delivery time.
        if ($lang !== 'en') {
            $this->substituteFreshTranslations($topResults, $lang);
        }

        // Attach per-article translation-availability metadata so the model
        // knows which language URLs it can construct. Runs regardless of
        // session language: EN sessions need to know FR/ES/etc. exist;
        // non-EN sessions need to know EN canonical exists. The list reflects
        // only FRESH translations (body_hash match) — stale rows excluded.
        $this->attachTranslationAvailability($topResults);

        return $this->formatContext($topResults, $maxChars);
    }

    /**
     * Run all three retrieval layers (semantic, FTS5, domain) against EN.
     * Returns the accumulated $results and per-article $articleChunks.
     */
    private function runRetrievalLayers(string $query, int $topK): array {
        $results = [];
        $articleChunks = [];

        // === LAYER 1: Semantic search (primary, when available) ===
        if ($this->embeddingsAvailable) {
            $semanticResults = $this->semanticSearch($query, $topK * 4);
            foreach ($semanticResults as $r) {
                $key = $r['article_id'];
                if (!isset($articleChunks[$key])) {
                    $articleChunks[$key] = [];
                }
                if (count($articleChunks[$key]) < self::MAX_CHUNKS_PER_ARTICLE) {
                    $articleChunks[$key][] = $r;
                }
                if (!isset($results[$key]) || $r['score'] > $results[$key]['score']) {
                    $results[$key] = $r;
                    $results[$key]['source'] = 'semantic';
                }
            }
        }

        // === LAYER 2: FTS5 keyword search (complementary) ===
        $searchTerms = $this->prepareSearchQuery($query);
        if (!empty($searchTerms)) {
            $chunkResults = $this->searchChunks($searchTerms, $topK * 2);
            foreach ($chunkResults as $r) {
                $key = $r['article_id'];
                if (!isset($results[$key])) {
                    $results[$key] = $r;
                    $results[$key]['source'] = 'fts';
                } elseif ($results[$key]['source'] === 'semantic') {
                    $results[$key]['score'] *= 1.3;
                    $results[$key]['source'] = 'hybrid';
                }
            }

            $articleResults = $this->searchArticles($searchTerms, $topK);
            foreach ($articleResults as $r) {
                $key = $r['article_id'];
                if (!isset($results[$key])) {
                    $results[$key] = $r;
                    $results[$key]['source'] = 'fts';
                } elseif ($results[$key]['source'] !== 'hybrid') {
                    $results[$key]['score'] *= 1.2;
                }
            }
        }

        // === LAYER 3: Domain detection (contextual boost) ===
        $domain = $this->detectDomain($query);
        if ($domain) {
            $domainResults = $this->getByDomain($domain, 3);
            foreach ($domainResults as $r) {
                $key = $r['article_id'];
                if (!isset($results[$key])) {
                    $results[$key] = $r;
                    $results[$key]['source'] = 'domain';
                }
            }

            // === LAYER 3b: Domain-gated canon injection ===
            $canonResults = $this->getCanonByDomain($domain, 2);
            foreach ($canonResults as $r) {
                $key = $r['article_id'];
                if (!isset($results[$key])) {
                    $results[$key] = $r;
                    $results[$key]['source'] = 'canon_inject';
                    $results[$key]['score'] = max($results[$key]['score'], 5.0);
                }
            }
        }

        return [$results, $articleChunks];
    }

    /**
     * Substitute fresh-translation content into EN-retrieved results.
     *
     * Two paths depending on what level the result is at:
     * - Chunk-level result (chunk_position set): substitute chunk content +
     *   heading from the translation's chunk at the same position.
     * - Article-level result (chunk_position null/unset OR result is the
     *   merged-chunks form): substitute the full article body or merged
     *   chunk content from the translation.
     *
     * In both cases, "fresh" means the translation's body_hash matches the
     * EN article's body_hash. Stale translations are silently skipped — the
     * practitioner gets EN canonical content, never outdated translated content.
     *
     * @param array $results  Reference to topResults — substituted in place.
     * @param string $lang    Target practitioner language.
     */
    private function substituteFreshTranslations(array &$results, string $lang): void {
        if (empty($results)) return;

        // Collect unique filepaths from the result set for batched lookup
        $filepaths = [];
        foreach ($results as $r) {
            $fp = $r['filepath'] ?? '';
            if ($fp) $filepaths[$fp] = true;
        }
        if (empty($filepaths)) return;

        $filepathList = array_keys($filepaths);
        $placeholders = implode(',', array_fill(0, count($filepathList), '?'));

        // One query: pull every fresh-translation chunk for any candidate filepath,
        // plus the translation article's full_text (for article-level substitution).
        // The freshness join: translation row matches EN row for same filepath
        // when their body_hash columns are equal AND non-empty.
        try {
            $stmt = $this->db->prepare("
                SELECT t_a.filepath, t_a.full_text AS article_full_text,
                       t_c.chunk_position, t_c.heading AS chunk_heading,
                       t_c.content AS chunk_content
                FROM articles t_a
                JOIN articles e_a ON e_a.filepath = t_a.filepath AND e_a.lang = 'en'
                LEFT JOIN chunks t_c ON t_c.article_id = t_a.id
                WHERE t_a.lang = ?
                  AND t_a.body_hash = e_a.body_hash
                  AND t_a.body_hash != ''
                  AND t_a.filepath IN ($placeholders)
            ");
            $params = array_merge([$lang], $filepathList);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Fresh-translation substitution error: " . $e->getMessage());
            return;  // Graceful degradation: keep EN content
        }

        if (empty($rows)) return;

        // Build lookup maps:
        //   chunkMap[filepath][position] = ['heading' => ..., 'content' => ...]
        //   articleMap[filepath] = full_text
        $chunkMap = [];
        $articleMap = [];
        foreach ($rows as $row) {
            $fp = $row['filepath'];
            if ($row['chunk_position'] !== null) {
                $chunkMap[$fp][(int)$row['chunk_position']] = [
                    'heading' => $row['chunk_heading'] ?? '',
                    'content' => $row['chunk_content'] ?? '',
                ];
            }
            // article_full_text repeats per chunk row but is the same value
            $articleMap[$fp] = $row['article_full_text'] ?? '';
        }

        // Substitute per result. Merged-chunk results need each constituent
        // chunk substituted, then re-merged.
        foreach ($results as &$r) {
            $fp = $r['filepath'] ?? '';
            if (!$fp || !isset($articleMap[$fp])) continue;  // no fresh translation

            // Path A: full-article result (heading marked as such, or canon
            // injection that originally pulled full_text)
            $isFullArticle = isset($r['heading']) && $r['heading'] === '(full article)';
            $isMerged = isset($r['heading']) && $r['heading'] === '(multiple sections)';
            $hasChunkPosition = isset($r['chunk_position']) && $r['chunk_position'] !== null;

            if ($isFullArticle || (!$hasChunkPosition && !$isMerged)) {
                // Substitute with translation's full_text
                if (!empty($articleMap[$fp])) {
                    $r['content'] = $articleMap[$fp];
                    $r['lang'] = $lang;
                }
                continue;
            }

            // Path B: merged-chunks result — substitute each constituent chunk,
            // then re-merge
            if ($isMerged && isset($r['_merged_chunks'])) {
                $merged = '';
                $allSubstituted = true;
                foreach ($r['_merged_chunks'] as $chunk) {
                    $pos = $chunk['chunk_position'] ?? null;
                    if ($pos !== null && isset($chunkMap[$fp][(int)$pos])) {
                        $sub = $chunkMap[$fp][(int)$pos];
                        if ($sub['heading']) $merged .= "### {$sub['heading']}\n";
                        $merged .= $sub['content'] . "\n\n";
                    } else {
                        // Position missing in translation — fall back to EN for this chunk
                        $allSubstituted = false;
                        $hd = $chunk['heading'] ?? '';
                        if ($hd) $merged .= "### {$hd}\n";
                        $merged .= ($chunk['content'] ?? '') . "\n\n";
                    }
                }
                $r['content'] = trim($merged);
                if ($allSubstituted) $r['lang'] = $lang;  // only mark fully-translated for URL prefix
                continue;
            }

            // Path C: single-chunk result — substitute by position
            if ($hasChunkPosition) {
                $pos = (int)$r['chunk_position'];
                if (isset($chunkMap[$fp][$pos])) {
                    $sub = $chunkMap[$fp][$pos];
                    $r['content'] = $sub['content'];
                    $r['heading'] = $sub['heading'];
                    $r['lang'] = $lang;
                }
                // else: position missing in translation — keep EN content
            }
        }
        unset($r);
    }

    /**
     * Attach the list of languages in which each retrieved article is
     * available as a FRESH translation (or as EN canonical), so the system
     * prompt can tell the model which language URLs are constructable.
     *
     * Freshness is defined identically to substituteFreshTranslations(): a
     * translation row is fresh iff its body_hash matches the EN article's
     * body_hash. Stale translations are excluded — they don't reflect current
     * doctrine and shouldn't be offered as a destination URL.
     *
     * Mutates the result set in place by setting $r['available_translations']
     * to a sorted array of language codes (e.g., ['ar', 'de', 'en', 'es',
     * 'fr', 'pt']). Always includes 'en' since the EN article is the source
     * of truth.
     *
     * @param array $results  Reference to topResults — annotated in place.
     */
    private function attachTranslationAvailability(array &$results): void {
        if (empty($results)) return;

        // Collect unique filepaths from the result set for batched lookup
        $filepaths = [];
        foreach ($results as $r) {
            $fp = $r['filepath'] ?? '';
            if ($fp) $filepaths[$fp] = true;
        }
        if (empty($filepaths)) return;

        $filepathList = array_keys($filepaths);
        $placeholders = implode(',', array_fill(0, count($filepathList), '?'));

        try {
            // For each filepath, return every lang where the row is either
            // the EN canonical (always present) or a fresh translation whose
            // body_hash matches EN. Stale translations naturally drop out
            // because their body_hash differs.
            $stmt = $this->db->prepare("
                SELECT t.filepath, GROUP_CONCAT(t.lang) AS available_langs
                FROM articles t
                JOIN articles e ON e.filepath = t.filepath AND e.lang = 'en'
                WHERE (t.lang = 'en' OR (t.body_hash = e.body_hash AND t.body_hash != ''))
                  AND t.filepath IN ($placeholders)
                GROUP BY t.filepath
            ");
            $stmt->execute($filepathList);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Translation-availability lookup error: " . $e->getMessage());
            return;  // Graceful degradation: results render without the field
        }

        if (empty($rows)) return;

        $availMap = [];
        foreach ($rows as $row) {
            $langs = array_filter(explode(',', $row['available_langs'] ?? ''));
            sort($langs);
            $availMap[$row['filepath']] = array_values($langs);
        }

        foreach ($results as &$r) {
            $fp = $r['filepath'] ?? '';
            if ($fp && isset($availMap[$fp])) {
                $r['available_translations'] = $availMap[$fp];
            }
        }
        unset($r);
    }


    // =====================================================================
    // SEMANTIC SEARCH
    // =====================================================================

    /**
     * Perform semantic search using embeddings.
     * Embeds the query through OpenRouter, then computes cosine similarity
     * against all stored chunk embeddings.
     */
    private function semanticSearch(string $query, int $limit): array {
        $queryEmbedding = $this->embedQuery($query);
        if (!$queryEmbedding) return [];

        try {
            // Embeddings exist only for EN chunks (text-embedding-3-small is
            // multilingual at the query side, so EN-only embeddings correctly
            // rank any-language queries). The chunk_position column is loaded
            // so the post-retrieval substitution layer can align translation
            // chunks to the right position when substituting.
            $stmt = $this->db->query('
                SELECT ce.chunk_id, ce.embedding,
                       c.article_id, c.chunk_position, c.heading, c.content, c.lang,
                       a.title, a.domain, a.weight, a.summary, a.content_layer, a.filepath
                FROM chunk_embeddings ce
                JOIN chunks c ON c.id = ce.chunk_id
                JOIN articles a ON a.id = c.article_id
                WHERE c.lang = \'en\'
            ');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Semantic search DB error: " . $e->getMessage());
            return [];
        }

        // Compute cosine similarities
        $scored = [];
        foreach ($rows as $row) {
            $chunkEmb = $this->unpackEmbedding($row['embedding']);
            if (!$chunkEmb) continue;

            $similarity = $this->cosineSimilarity($queryEmbedding, $chunkEmb);

            // Weight by article priority
            $weightedScore = $similarity * (float)$row['weight'];

            $scored[] = [
                'article_id' => $row['article_id'],
                'chunk_position' => (int)$row['chunk_position'],
                'heading' => $row['heading'],
                'content' => $row['content'],
                'lang' => $row['lang'],
                'title' => $row['title'],
                'domain' => $row['domain'],
                'weight' => $row['weight'],
                'summary' => $row['summary'],
                'content_layer' => $row['content_layer'],
                'filepath' => $row['filepath'] ?? '',
                'score' => $weightedScore,
                'raw_similarity' => $similarity,
            ];
        }

        // Sort by score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Return top results WITHOUT strict per-article deduplication.
        // Multi-chunk merging is handled by the retrieve() method which
        // tracks up to MAX_CHUNKS_PER_ARTICLE per article for richer context.
        return array_slice($scored, 0, $limit);
    }

    /**
     * Embed a query string through OpenRouter (bge-m3, as the index).
     * Returns float array or null on failure.
     */
    private function embedQuery(string $query): ?array {
        // Through OpenRouter, with the model the index was built with
        // (index-vault.py), under the account's no-training, zero-retention
        // routing. Without OpenRouter configured, retrieval is keyword only.
        if (!function_exists('llm_cfg') || llm_cfg('LLM_PROVIDER') !== 'openrouter' || !llm_cfg('LLM_API_KEY')) return null;

        $payload = json_encode([
            'model' => self::EMBED_MODEL,
            'input' => mb_substr($query, 0, 8000),
            'provider' => ['data_collection' => 'deny'],
        ]);

        $ch = curl_init(rtrim(llm_cfg('LLM_BASE_URL'), '/') . '/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . llm_cfg('LLM_API_KEY'),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log("Embedding API curl error: " . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Embedding API HTTP {$httpCode}: " . mb_substr($result, 0, 500));
            return null;
        }

        $data = json_decode($result, true);
        if (!$data || !isset($data['data'][0]['embedding'])) {
            error_log("Embedding API unexpected response");
            return null;
        }

        $emb = $data['data'][0]['embedding'];
        return count($emb) === self::EMBED_DIMS ? $emb : null;
    }

    /**
     * Unpack a binary embedding blob into a float array.
     */
    private function unpackEmbedding(string $blob): ?array {
        $expected = self::EMBED_DIMS * 4; // float32 = 4 bytes
        if (strlen($blob) !== $expected) return null;
        return array_values(unpack('f' . self::EMBED_DIMS, $blob));
    }

    /**
     * Compute cosine similarity between two float vectors.
     */
    private function cosineSimilarity(array $a, array $b): float {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $n = count($a);
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);
        if ($denom == 0) return 0.0;

        return $dot / $denom;
    }


    // =====================================================================
    // FTS5 KEYWORD SEARCH (unchanged from v1)
    // =====================================================================

    /**
     * Prepare a user query for FTS5 search.
     * Extracts meaningful terms, removes stop words, expands synonyms.
     */
    private function prepareSearchQuery(string $query): string {
        $query = mb_strtolower($query);

        $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
            'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
            'could', 'should', 'may', 'might', 'shall', 'can', 'need', 'must',
            'i', 'me', 'my', 'we', 'our', 'you', 'your', 'he', 'she', 'it',
            'they', 'them', 'this', 'that', 'these', 'those', 'what', 'which',
            'who', 'whom', 'how', 'when', 'where', 'why', 'not', 'no', 'nor',
            'but', 'and', 'or', 'if', 'then', 'so', 'for', 'with', 'about',
            'from', 'into', 'to', 'of', 'in', 'on', 'at', 'by', 'up', 'out',
            'off', 'over', 'under', 'between', 'through', 'during', 'before',
            'after', 'above', 'below', 'very', 'just', 'also', 'than', 'too',
            'tell', 'know', 'think', 'want', 'like', 'get', 'make', 'go',
            'say', 'see', 'come', 'take', 'give', 'help', 'ask', 'find'];

        $words = preg_split('/[^a-z0-9]+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $terms = array_filter($words, fn($w) => !in_array($w, $stopWords) && strlen($w) > 2);

        if (empty($terms)) return '';

        $synonymMap = [
            'meditation' => 'presence',
            'meditate' => 'presence',
            'mindfulness' => 'presence',
            'awareness' => 'presence',
            'money' => 'matter',
            'finance' => 'matter',
            'financial' => 'matter',
            'wealth' => 'matter',
            'career' => 'service',
            'work' => 'service',
            'job' => 'service',
            'vocation' => 'service dharma',
            'purpose' => 'dharma',
            'marriage' => 'relationships couple',
            'relationship' => 'relationships',
            'partner' => 'relationships couple',
            'exercise' => 'movement health',
            'workout' => 'movement health',
            'fitness' => 'movement health',
            'diet' => 'nutrition health',
            'food' => 'nutrition',
            'eating' => 'nutrition',
            'sleep' => 'sleep health',
            'insomnia' => 'sleep health',
            'supplement' => 'supplements health',
            'vitamin' => 'supplements health',
            'herb' => 'supplements tonic',
            'water' => 'hydration',
            'detox' => 'purification',
            'fast' => 'purification',
            'fasting' => 'purification',
            'yoga' => 'presence kriya',
            'breathwork' => 'presence breath',
            'breath' => 'presence breath',
            'qigong' => 'presence chinese',
            'taoist' => 'chinese tonic',
            'ayurveda' => 'indian health',
            'chakra' => 'human being energy',
            'kundalini' => 'presence energy',
            'jing' => 'jing three treasures',
            'shen' => 'shen three treasures',
            'chi' => 'qi three treasures',
            'cosmos' => 'cosmos absolute',
            'god' => 'absolute logos',
            'divine' => 'logos absolute',
            'meaning' => 'dharma logos',
            'children' => 'parenting family',
            'parenting' => 'parenting relationships',
            'friends' => 'friendship',
            'community' => 'community relationships',
            'nature' => 'nature ecology',
            'environment' => 'nature ecology',
            'learning' => 'learning pedagogy',
            'education' => 'learning pedagogy',
            'study' => 'learning',
            'fun' => 'recreation joy',
            'play' => 'recreation joy',
            'happiness' => 'joy presence',
            'anxiety' => 'presence health',
            'stress' => 'presence health recovery',
            'depression' => 'presence health',
            'longevity' => 'health longevity',
            'lost' => 'dharma presence',
            'confused' => 'dharma presence',
            'stuck' => 'dharma service',
            'lonely' => 'relationships community',
            'tired' => 'health sleep recovery',
            'exhausted' => 'health sleep recovery',
            'angry' => 'presence relationships',
            'sad' => 'presence relationships',
            'afraid' => 'presence',
            'soul' => 'human being atman',
            'spirit' => 'presence shen',
            'healing' => 'health recovery presence',
            'trauma' => 'presence relationships',
        ];

        $expandedTerms = [];
        foreach ($terms as $term) {
            $expandedTerms[] = $term;
            if (isset($synonymMap[$term])) {
                foreach (explode(' ', $synonymMap[$term]) as $syn) {
                    $expandedTerms[] = $syn;
                }
            }
        }

        $expandedTerms = array_unique($expandedTerms);

        $escaped = array_map(fn($t) => '"' . str_replace('"', '', $t) . '"', $expandedTerms);
        return implode(' OR ', $escaped);
    }

    /**
     * Search at the chunk level (section-level precision).
     */
    private function searchChunks(string $ftsQuery, int $limit): array {
        try {
            $stmt = $this->db->prepare('
                SELECT c.article_id, c.chunk_position, c.heading, c.content, c.lang,
                       a.title, a.domain, a.weight, a.summary, a.content_layer, a.filepath,
                       rank * a.weight AS score
                FROM chunks_fts
                JOIN chunks c ON c.id = chunks_fts.rowid
                JOIN articles a ON a.id = c.article_id
                WHERE chunks_fts MATCH ? AND c.lang = \'en\'
                ORDER BY score
                LIMIT ?
            ');
            $stmt->execute([$ftsQuery, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("RAG chunk search error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Search at the article level (broader matches via title, tags, summary).
     * No chunk_position is exposed — substitution falls back to article-level
     * full_text for these results.
     */
    private function searchArticles(string $ftsQuery, int $limit): array {
        try {
            $stmt = $this->db->prepare('
                SELECT a.id as article_id, a.title, a.domain, a.weight,
                       a.summary, a.content_layer, a.filepath, a.lang, "" as heading,
                       a.summary as content,
                       rank * a.weight AS score
                FROM articles_fts
                JOIN articles a ON a.id = articles_fts.rowid
                WHERE articles_fts MATCH ? AND a.lang = \'en\'
                ORDER BY score
                LIMIT ?
            ');
            $stmt->execute([$ftsQuery, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("RAG article search error: " . $e->getMessage());
            return [];
        }
    }


    // =====================================================================
    // DOMAIN DETECTION
    // =====================================================================

    /**
     * Get top articles from a specific domain (Wheel pillar).
     */
    private function getByDomain(string $domain, int $limit): array {
        try {
            $stmt = $this->db->prepare('
                SELECT id as article_id, title, domain, weight, summary,
                       content_layer, filepath, lang, "" as heading, summary as content,
                       weight * 2 AS score
                FROM articles
                WHERE domain = ? AND lang = \'en\'
                ORDER BY weight DESC, content_layer ASC
                LIMIT ?
            ');
            $stmt->execute([$domain, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Get full article text by article ID (for high-confidence single-article retrieval).
     */
    private function getFullArticleText(int $articleId): ?string {
        try {
            $stmt = $this->db->prepare('SELECT full_text FROM articles WHERE id = ?');
            $stmt->execute([$articleId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $row['full_text'] : null;
        } catch (PDOException $e) {
            error_log("RAG full article retrieval error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get canon-layer articles from a specific domain (for domain-gated injection).
     * Only retrieves articles with content_layer='canon' — the intemporal doctrinal core.
     */
    private function getCanonByDomain(string $domain, int $limit): array {
        try {
            $stmt = $this->db->prepare('
                SELECT id as article_id, title, domain, weight, summary, full_text as content,
                       content_layer, filepath, lang, "" as heading,
                       weight * 3 AS score
                FROM articles
                WHERE domain = ? AND content_layer = ? AND lang = \'en\'
                ORDER BY weight DESC
                LIMIT ?
            ');
            $stmt->execute([$domain, 'canon', $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Detect if the query references a specific Wheel domain.
     */
    private function detectDomain(string $query): string {
        $query = mb_strtolower($query);

        $domainPatterns = [
            'health' => ['health', 'sleep', 'nutrition', 'supplement', 'exercise', 'movement', 'recovery', 'hydration', 'purification', 'fasting', 'longevity', 'body', 'disease', 'cancer', 'vaccine', 'immune', 'detox'],
            'presence' => ['presence', 'meditation', 'awareness', 'mindfulness', 'consciousness', 'spiritual', 'breath', 'yoga', 'practice', 'chakra', 'kundalini', 'pranayama', 'mantra', 'energy work', 'entheo'],
            'matter' => ['money', 'finance', 'wealth', 'home', 'material', 'income', 'budget', 'invest', 'stewardship', 'security', 'technology'],
            'service' => ['work', 'career', 'vocation', 'purpose', 'mission', 'calling', 'business', 'service', 'leadership', 'dharma'],
            'relationships' => ['relationship', 'marriage', 'partner', 'family', 'friend', 'love', 'couple', 'parenting', 'children', 'elder', 'community'],
            'learning' => ['learning', 'education', 'study', 'philosophy', 'language', 'skill', 'knowledge', 'wisdom', 'pedagogy'],
            'nature' => ['nature', 'ecology', 'environment', 'permaculture', 'plant', 'animal', 'earth', 'reverence', 'ayni'],
            'recreation' => ['recreation', 'fun', 'play', 'sport', 'travel', 'hobby', 'game', 'joy', 'music', 'art'],
            'harmonism' => ['harmonism', 'harmonic realism', 'logos', 'absolute', 'void', 'cosmos', 'integral age', 'harmonic age', 'cartograph', 'five traditions', 'formula', 'epistemology', 'applied harmonism', 'architecture of harmony', 'wheel of harmony'],
        ];

        foreach ($domainPatterns as $domain => $keywords) {
            foreach ($keywords as $kw) {
                if (strpos($query, $kw) !== false) {
                    return $domain;
                }
            }
        }

        return '';
    }


    // =====================================================================
    // OUTPUT FORMATTING
    // =====================================================================

    /**
     * Format retrieved results into context string for the system prompt.
     */
    /**
     * Convert vault filepath to fully-qualified site URL.
     * e.g. "Wheel of Harmony/health/Sleep and Recovery.md"
     *   → "https://harmonism.io/wheel-of-harmony/health/sleep-and-recovery"
     *
     * Absolute URLs are required so Lodestone can surface clickable links across
     * surfaces (Telegram and elsewhere); relative paths render as plain text
     * in chat clients that don't have the site as their origin.
     */
    private function filepathToUrl(string $filepath, string $lang = 'en'): string {
        if (!$filepath) return '';
        // Strip .md extension
        $slug = preg_replace('/\.md$/i', '', $filepath);
        // Lowercase and replace spaces with hyphens
        $slug = mb_strtolower($slug);
        $slug = str_replace(' ', '-', $slug);
        // Non-English articles get a language prefix matching the website's URL structure
        // (e.g., https://harmonism.io/ru/wheel-of-harmony/health/sleep-and-recovery)
        if ($lang && $lang !== 'en') {
            return 'https://harmonism.io/' . $lang . '/' . $slug;
        }
        return 'https://harmonism.io/' . $slug;
    }

    private function formatContext(array $results, int $maxChars): string {
        if (empty($results)) return '';

        $output = [];
        $totalChars = 0;

        foreach ($results as $r) {
            $title = $r['title'] ?? 'Untitled';
            $domain = $r['domain'] ?? '';
            $layer = $r['content_layer'] ?? '';
            $heading = $r['heading'] ?? '';
            $content = $r['content'] ?? $r['summary'] ?? '';
            $filepath = $r['filepath'] ?? '';
            $resultLang = $r['lang'] ?? 'en';
            $url = $this->filepathToUrl($filepath, $resultLang);
            $translations = $r['available_translations'] ?? [];

            $entry = "### {$title}";
            if ($domain) $entry .= " [{$domain}]";
            if ($layer === 'canon') $entry .= " (canonical)";
            if ($url) $entry .= "\nURL: {$url}";
            if (count($translations) > 1) {
                $entry .= "\nAvailable in: " . implode(', ', $translations);
            }
            $entry .= "\n";
            if ($heading) $entry .= "Section: {$heading}\n";
            $entry .= $content . "\n";

            if ($totalChars + strlen($entry) > $maxChars) {
                $remaining = $maxChars - $totalChars - 100;
                if ($remaining > 200) {
                    $entry = mb_substr($entry, 0, $remaining) . "\n[truncated]";
                    $output[] = $entry;
                }
                break;
            }

            $output[] = $entry;
            $totalChars += strlen($entry);
        }

        if (empty($output)) return '';

        return "<vault_knowledge>\n"
             . "The excerpts below are your LIVE primary knowledge source — pulled from the current "
             . "harmonism.io index, not from base-LLM training data. URLs listed are real, current "
             . "harmonism.io links you should surface directly when an article is relevant. "
             . "The 'Available in:' field lists ISO codes for languages in which a FRESH translation "
             . "exists. URLs in this block are FULLY-QUALIFIED (https://harmonism.io/...). To construct "
             . "the practitioner-language URL for a non-English variant, insert '/{lang}' after the "
             . "domain: EN is 'https://harmonism.io/wheel-of-harmony/...', non-EN is "
             . "'https://harmonism.io/{lang}/wheel-of-harmony/...'. Always surface fully-qualified URLs "
             . "to the practitioner — never relative paths — so links are clickable in every surface "
             . "(Telegram, web, email). If a requested language is not in 'Available in:', say so "
             . "directly — that's accurate and useful information for the practitioner.\n\n"
             . "RETRIEVAL DISCIPLINE — two-sided rule:\n\n"
             . "(A) ARTICLES IN THIS BLOCK EXIST. When something is grounded in an article headed "
             . "(###) below, state it with confidence. Do not retract retrieval-grounded claims under "
             . "uncertainty pressure or apologize for having surfaced them. 'I don't browse the web in "
             . "real-time' is true; 'I don't know what's on harmonism.io' is FALSE for vault content "
             . "headed here — this block IS what's on harmonism.io, indexed live. Generic base-LLM "
             . "disclaimers about training cutoffs, inability to verify external content, or limited "
             . "site access do not apply to articles in this block. If an article is headed here with "
             . "a URL, the article exists at that URL — say so plainly.\n\n"
             . "(B) ARTICLES NOT IN THIS BLOCK ARE NOT VERIFIED. The articles headed (###) below are "
             . "the ONLY articles confirmed to exist in this retrieval pass. If a user asserts an "
             . "article exists ('there is an article on X', 'there is also a Y article') and X/Y is "
             . "NOT headed here, DO NOT construct a URL for it, DO NOT confirm its existence, DO NOT "
             . "apply the URL construction rule to a user-asserted title. The URL construction rule is "
             . "for articles already established in this block — never extend it to user assertions "
             . "you cannot verify here. Respond plainly: 'I don't see that article in my current "
             . "retrieval — it may exist but isn't surfacing for this query. Try rephrasing to include "
             . "the topic name explicitly, or check the site directly.' User assertion is NOT a "
             . "substitute for retrieval.\n\n"
             . "This is DOCTRINAL and EDUCATIONAL content from Harmonism's knowledge base — NOT "
             . "biographical information about the person you are speaking with. Only <person_context> "
             . "contains information about the user. Reference vault content naturally when teaching or "
             . "guiding — do not quote it verbatim or list sources.\n\n"
             . implode("\n", $output)
             . "\n</vault_knowledge>";
    }

    /**
     * Get article by title (for direct lookups).
     */
    public function getArticleByTitle(string $title): ?array {
        if (!$this->available) return null;

        try {
            $stmt = $this->db->prepare('
                SELECT * FROM articles WHERE title = ? OR title LIKE ? LIMIT 1
            ');
            $stmt->execute([$title, "%{$title}%"]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}
