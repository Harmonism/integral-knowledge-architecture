#!/usr/bin/env python3
"""
Vault Indexer — Processes the Harmonia Obsidian vault into a SQLite database
with full-text search for the Telegram Companion's RAG pipeline.

Run locally before deployment:
    python3 index-vault.py --vault ../../Harmonia --output vault-index.db

The resulting vault-index.db gets uploaded to the server alongside the PHP files.

EN-CANONICAL ARCHITECTURE WITH FRESH-TRANSLATION SUBSTITUTION
-------------------------------------------------------------
The index treats English as the canonical source of truth. Every retrieval
query runs against the EN corpus, which guarantees the practitioner always
receives the most up-to-date doctrinal state — regardless of translation
coverage. Translations are stored alongside as a presentation layer: at
retrieval time, if a translation is FRESH (its frontmatter `source_hash`
matches the current EN body hash) and a corresponding chunk exists at the
same position, the translated content substitutes the EN content for delivery.
If the translation is stale or missing, the EN content stays — the practitioner
gets canonical truth in English rather than outdated content in their language.

Schema:
- articles: one row per (filepath, lang) pair with composite UNIQUE constraint.
  Each article carries a `body_hash`. For EN this is the hash of the current
  body. For translations this is the `source_hash` from frontmatter — the hash
  of the EN body the translation was generated from. A translation is FRESH
  iff its body_hash equals the corresponding EN article's body_hash.
- chunks: one row per heading-segmented section. Carries `chunk_position`
  (0-indexed within the article) so translation chunks can be aligned to EN
  chunks by position. Translations preserve EN heading structure under the
  translation pipeline's discipline, so position alignment is reliable.
- chunk_embeddings: ONLY generated for EN chunks. text-embedding-3-small is
  multilingual at the query side, so an English-only embedding corpus correctly
  ranks any-language queries against EN content. Cuts embedding cost ~10x
  compared to per-language embedding storage.

Hash algorithm: SHA-256 of the body bytes (frontmatter excluded), first 16 hex
chars. Matches `i18n/scripts/translate-sync.py:hash_body()` exactly so the
freshness comparison is meaningful.

When migrating from any pre-`body_hash` schema, the indexer auto-detects the
legacy table shape and rebuilds vault-index.db from scratch — SQLite cannot
drop or alter UNIQUE / column constraints in place.
"""

import argparse
import hashlib
import json
import os
import re
import sqlite3
import struct
import sys
import time
import urllib.request
import urllib.error
import yaml


# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

# Folders to skip when walking the EN vault (private content, infrastructure,
# non-text assets). Individual files within public folders are filtered by
# audience:developer in frontmatter.
SKIP_DIRS = {
    'node_modules', '.obsidian', '.git', '.trash', 'templates',
    'Internal',       # all developer/operational content: pipelines, client profiles, collaborations, drafts, digital infrastructure
    'Collaborations', # safety net — private partnership docs (now inside Internal, kept in case of stray root-level files)
    'Media',          # images, PDFs, non-text assets
    'i18n',           # translation source files — handled by translation walk, not EN walk
}

# Languages to index from i18n/translations/{lang}/. Keep in sync with the
# website's supported languages (astro.config.mjs translationDirs).
TRANSLATION_LANGUAGES = ['fr', 'es', 'ar', 'pt', 'de', 'zh', 'ja', 'hi', 'ru']

# Frontmatter audiences to exclude — developer-facing content (pipelines, deploy
# docs, session archives, task dashboards, etc.) is excluded automatically via
# `audience: developer` in frontmatter.
SKIP_AUDIENCES = {'developer'}

# Content layer priority for retrieval ranking
LAYER_WEIGHT = {'canon': 3, 'bridge': 2, 'applied': 1, 'navigation': 0}
STATUS_WEIGHT = {'stable': 3, 'crystallizing': 2, 'provisional': 1}
MATURITY_WEIGHT = {'complete': 3, 'developing': 2, 'stub': 1}


# ---------------------------------------------------------------------------
# Frontmatter Parsing
# ---------------------------------------------------------------------------

# Regex for separating frontmatter from body (matches translate-sync.py)
_FRONTMATTER_RE = re.compile(r'^---\n(.*?)\n---\n?', re.DOTALL)


def parse_frontmatter(content: str) -> tuple[dict, str]:
    """Extract YAML frontmatter and body from a markdown file."""
    if content.startswith('---'):
        parts = content.split('---', 2)
        if len(parts) >= 3:
            try:
                meta = yaml.safe_load(parts[1]) or {}
                body = parts[2].strip()
                return meta, body
            except yaml.YAMLError:
                pass
    return {}, content.strip()


def hash_body(content: str) -> str:
    """
    Hash the file body (frontmatter excluded). Returns 16-char hex string.

    MUST match i18n/scripts/translate-sync.py:hash_body() exactly. Translations
    store this hash in their frontmatter as `source_hash`, recording which
    version of the EN source they were translated from. At index time we
    recompute the hash on the current EN body; equality means the translation
    is fresh, inequality means it is stale.
    """
    match = _FRONTMATTER_RE.match(content)
    body = content[match.end():] if match else content
    return hashlib.sha256(body.strip().encode('utf-8')).hexdigest()[:16]


# ---------------------------------------------------------------------------
# Chunking
# ---------------------------------------------------------------------------

def chunk_by_heading(body: str, max_chunk_size: int = 3000) -> list[dict]:
    """
    Split markdown body into chunks by heading boundaries.
    Each chunk gets the heading hierarchy as context.
    """
    lines = body.split('\n')
    chunks = []
    current_heading = ''
    current_text = []

    for line in lines:
        heading_match = re.match(r'^(#{1,4})\s+(.+)', line)
        if heading_match:
            # Save previous chunk
            text = '\n'.join(current_text).strip()
            if text and len(text) > 50:  # Skip trivial chunks
                chunks.append({
                    'heading': current_heading,
                    'text': text[:max_chunk_size],
                })
            current_heading = heading_match.group(2).strip()
            current_text = []
        else:
            current_text.append(line)

    # Save final chunk
    text = '\n'.join(current_text).strip()
    if text and len(text) > 50:
        chunks.append({
            'heading': current_heading,
            'text': text[:max_chunk_size],
        })

    # If no headings found, treat entire body as one chunk
    if not chunks and body.strip():
        chunks.append({
            'heading': '',
            'text': body.strip()[:max_chunk_size * 2],
        })

    return chunks


def clean_markdown(text: str) -> str:
    """Strip wikilinks, callouts, and other Obsidian syntax for indexing."""
    # Convert wikilinks [[Target|Display]] -> Display, [[Target]] -> Target
    text = re.sub(r'\[\[([^|\]]+)\|([^\]]+)\]\]', r'\2', text)
    text = re.sub(r'\[\[([^\]]+)\]\]', r'\1', text)
    # Strip callout syntax
    text = re.sub(r'>\s*\[![\w]+\].*', '', text)
    # Strip HTML tags
    text = re.sub(r'<[^>]+>', '', text)
    # Strip image embeds
    text = re.sub(r'!\[\[.*?\]\]', '', text)
    text = re.sub(r'!\[.*?\]\(.*?\)', '', text)
    # Collapse whitespace
    text = re.sub(r'\n{3,}', '\n\n', text)
    return text.strip()


# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------

def _detect_legacy_schema(db_path: str) -> bool:
    """
    Detect any pre-`body_hash` schema. Both the original single-language schema
    AND the intermediate (filepath, lang) schema are legacy now — neither has
    the `body_hash` column required for freshness comparison. SQLite cannot
    add columns to existing UNIQUE-constrained tables in place, so the cleanest
    migration is to rebuild from scratch (cheap: ~$0.10 in embeddings).
    """
    if not os.path.isfile(db_path):
        return False
    try:
        conn = sqlite3.connect(db_path)
        c = conn.cursor()
        c.execute("PRAGMA table_info(articles)")
        cols = [row[1] for row in c.fetchall()]
        conn.close()
        if not cols:
            return False  # No articles table — fresh DB, no migration needed
        return 'body_hash' not in cols
    except sqlite3.Error:
        return False


def create_database(db_path: str) -> sqlite3.Connection:
    """Create SQLite database with FTS5 virtual table for full-text search."""

    # Auto-migrate: legacy schema → rebuild. The alternative (in-place ALTER) is
    # impossible because SQLite cannot drop UNIQUE constraints, and the old
    # schema's UNIQUE(filepath) prevents the multi-lang composite uniqueness
    # the new schema requires.
    if _detect_legacy_schema(db_path):
        print(f"  Legacy schema detected at {db_path} — rebuilding from scratch.")
        os.remove(db_path)

    conn = sqlite3.connect(db_path)
    c = conn.cursor()

    # Articles table — one row per (vault file, language) pair.
    # body_hash is the freshness signal: for EN it's the current body hash;
    # for translations it's the source_hash from frontmatter (the hash of
    # the EN body the translation was generated from). Equality across the
    # same filepath means the translation is fresh.
    c.execute('''
        CREATE TABLE IF NOT EXISTS articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filepath TEXT NOT NULL,
            lang TEXT NOT NULL DEFAULT 'en',
            title TEXT NOT NULL,
            domain TEXT DEFAULT '',
            tags TEXT DEFAULT '',
            doctrinal_status TEXT DEFAULT 'stable',
            content_layer TEXT DEFAULT 'applied',
            maturity TEXT DEFAULT 'complete',
            full_text TEXT NOT NULL,
            summary TEXT DEFAULT '',
            weight REAL DEFAULT 1.0,
            content_hash TEXT NOT NULL,
            body_hash TEXT NOT NULL DEFAULT '',
            UNIQUE(filepath, lang)
        )
    ''')

    # Chunks table — multiple chunks per article. chunk_position is the
    # 0-indexed offset within the article's chunk sequence. Translation chunks
    # at the same (filepath, position) substitute the EN chunk content at
    # retrieval time when the translation is fresh.
    c.execute('''
        CREATE TABLE IF NOT EXISTS chunks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            article_id INTEGER NOT NULL,
            lang TEXT NOT NULL DEFAULT 'en',
            chunk_position INTEGER NOT NULL DEFAULT 0,
            heading TEXT DEFAULT '',
            content TEXT NOT NULL,
            FOREIGN KEY (article_id) REFERENCES articles(id)
        )
    ''')

    c.execute('CREATE INDEX IF NOT EXISTS idx_chunks_lang ON chunks(lang)')
    c.execute('CREATE INDEX IF NOT EXISTS idx_articles_lang ON articles(lang)')
    c.execute('CREATE INDEX IF NOT EXISTS idx_articles_filepath ON articles(filepath)')

    # FTS5 index over chunks for fast full-text search.
    # Lang filtering happens via the JOIN with chunks.lang in retrieval queries.
    c.execute('''
        CREATE VIRTUAL TABLE IF NOT EXISTS chunks_fts USING fts5(
            content, heading, title, domain, tags,
            content='chunks',
            content_rowid='id',
            tokenize='porter unicode61'
        )
    ''')

    # Triggers to keep FTS in sync
    c.execute('''
        CREATE TRIGGER IF NOT EXISTS chunks_ai AFTER INSERT ON chunks BEGIN
            INSERT INTO chunks_fts(rowid, content, heading, title, domain, tags)
            SELECT new.id, new.content, new.heading, a.title, a.domain, a.tags
            FROM articles a WHERE a.id = new.article_id;
        END
    ''')

    c.execute('''
        CREATE TRIGGER IF NOT EXISTS chunks_ad AFTER DELETE ON chunks BEGIN
            INSERT INTO chunks_fts(chunks_fts, rowid, content, heading, title, domain, tags)
            VALUES('delete', old.id, old.content, old.heading,
                   (SELECT title FROM articles WHERE id = old.article_id),
                   (SELECT domain FROM articles WHERE id = old.article_id),
                   (SELECT tags FROM articles WHERE id = old.article_id));
        END
    ''')

    # Article-level FTS for broader searches
    c.execute('''
        CREATE VIRTUAL TABLE IF NOT EXISTS articles_fts USING fts5(
            title, domain, tags, summary, full_text,
            content='articles',
            content_rowid='id',
            tokenize='porter unicode61'
        )
    ''')

    c.execute('''
        CREATE TRIGGER IF NOT EXISTS articles_ai AFTER INSERT ON articles BEGIN
            INSERT INTO articles_fts(rowid, title, domain, tags, summary, full_text)
            VALUES(new.id, new.title, new.domain, new.tags, new.summary, new.full_text);
        END
    ''')

    # Embeddings table — keyed by chunk_id. Generated only for EN chunks
    # (text-embedding-3-small is multilingual at query time).
    c.execute('''
        CREATE TABLE IF NOT EXISTS chunk_embeddings (
            chunk_id INTEGER PRIMARY KEY,
            embedding BLOB NOT NULL,
            model TEXT DEFAULT 'baai/bge-m3',
            FOREIGN KEY (chunk_id) REFERENCES chunks(id)
        )
    ''')

    conn.commit()
    return conn


def compute_weight(meta: dict) -> float:
    """Compute retrieval priority weight from article metadata."""
    layer = meta.get('content_layer', 'applied')
    status = meta.get('doctrinal_status', 'stable')
    maturity = meta.get('maturity', 'complete')

    w = (LAYER_WEIGHT.get(layer, 1) * 2 +
         STATUS_WEIGHT.get(status, 1) * 1.5 +
         MATURITY_WEIGHT.get(maturity, 1))
    return round(w, 2)


def extract_summary(body: str, max_len: int = 500) -> str:
    """Extract the first substantive paragraph as a summary."""
    lines = body.split('\n')
    paragraphs = []
    current = []

    for line in lines:
        stripped = line.strip()
        if not stripped:
            if current:
                paragraphs.append(' '.join(current))
                current = []
        elif not stripped.startswith('#') and not stripped.startswith('*Sub-pillar') and not stripped.startswith('*Pillar'):
            # Skip navigation/subtitle lines
            if not (stripped.startswith('See also:') or stripped.startswith('*See also:')):
                current.append(stripped)

    if current:
        paragraphs.append(' '.join(current))

    # Return first paragraph that's substantive (>80 chars)
    for p in paragraphs:
        if len(p) > 80:
            return p[:max_len]

    return paragraphs[0][:max_len] if paragraphs else ''


# ---------------------------------------------------------------------------
# Embeddings (EN-only)
# ---------------------------------------------------------------------------

# Open-weight, multilingual, served through OpenRouter under the account's
# no-training, zero-retention routing (the same rule as Lodestone's generation).
# Query side: companion-rag.php and telegram-rag.php must use the same model.
EMBEDDING_MODEL = 'baai/bge-m3'
EMBEDDING_DIMS = 1024
EMBEDDING_BATCH_SIZE = 32
EMBEDDING_URL = 'https://openrouter.ai/api/v1/embeddings'


def get_embeddings(texts: list[str], api_key: str) -> list[list[float]]:
    """Embed a batch of texts through OpenRouter."""
    url = EMBEDDING_URL
    payload = json.dumps({
        'model': EMBEDDING_MODEL,
        'input': texts,
        'provider': {'data_collection': 'deny'},
    }).encode('utf-8')

    req = urllib.request.Request(url, data=payload, headers={
        'Content-Type': 'application/json',
        'Authorization': f'Bearer {api_key}',
    })

    # A rate limit (429) or a provider hiccup (5xx) is waited out, not skipped.
    for attempt in range(6):
        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                data = json.loads(resp.read().decode('utf-8'))
                sorted_data = sorted(data['data'], key=lambda x: x['index'])
                out = [d['embedding'] for d in sorted_data]
                if any(len(v) != EMBEDDING_DIMS for v in out):
                    print(f"  ERROR: {EMBEDDING_MODEL} returned vectors that are not {EMBEDDING_DIMS}-dimensional")
                    return []
                return out
        except urllib.error.HTTPError as e:
            if (e.code == 429 or e.code >= 500) and attempt < 5:
                wait = int(e.headers.get('Retry-After') or 0) or 2 ** (attempt + 1)
                print(f"  HTTP {e.code}; waiting {wait}s and trying again")
                time.sleep(wait)
                continue
            print(f"  ERROR: embedding call failed: {e}")
            return []
        except Exception as e:
            print(f"  ERROR: embedding call failed: {e}")
            return []
    return []


def pack_embedding(embedding: list[float]) -> bytes:
    """Pack a float list into a binary blob (float32)."""
    return struct.pack(f'{len(embedding)}f', *embedding)


def generate_embeddings(conn: sqlite3.Connection, api_key: str):
    """
    Generate embeddings for EN chunks that don't have them yet.
    Translation chunks are intentionally skipped — text-embedding-3-small is
    multilingual at the query side, so EN-only embeddings correctly rank
    any-language queries.
    """
    cursor = conn.cursor()

    # Vectors from another model cannot be compared with this one's: drop them.
    cursor.execute('DELETE FROM chunk_embeddings WHERE model IS NULL OR model != ?', (EMBEDDING_MODEL,))
    if cursor.rowcount:
        print(f"Removed {cursor.rowcount} embeddings made with another model.")
    conn.commit()

    cursor.execute('''
        SELECT c.id, c.heading, c.content, a.title, a.domain
        FROM chunks c
        JOIN articles a ON a.id = c.article_id
        LEFT JOIN chunk_embeddings ce ON ce.chunk_id = c.id
        WHERE ce.chunk_id IS NULL AND c.lang = 'en'
    ''')
    rows = cursor.fetchall()

    if not rows:
        print("All EN chunks already have embeddings.")
        return

    print(f"\nGenerating embeddings for {len(rows)} EN chunks...")

    for i in range(0, len(rows), EMBEDDING_BATCH_SIZE):
        batch = rows[i:i + EMBEDDING_BATCH_SIZE]

        texts = []
        for chunk_id, heading, content, title, domain in batch:
            parts = [f"[{title}]"]
            if domain:
                parts.append(f"[{domain}]")
            if heading:
                parts.append(f"## {heading}")
            parts.append(content)
            texts.append(' '.join(parts)[:8000])

        embeddings = get_embeddings(texts, api_key)

        if len(embeddings) != len(batch):
            print(f"  WARNING: Expected {len(batch)} embeddings, got {len(embeddings)}. Skipping batch.")
            continue

        for (chunk_id, _, _, _, _), emb in zip(batch, embeddings):
            cursor.execute(
                'INSERT OR REPLACE INTO chunk_embeddings (chunk_id, embedding, model) VALUES (?, ?, ?)',
                (chunk_id, pack_embedding(emb), EMBEDDING_MODEL)
            )

        conn.commit()
        batch_end = min(i + EMBEDDING_BATCH_SIZE, len(rows))
        print(f"  Embedded chunks {i+1}–{batch_end} of {len(rows)}")

        if batch_end < len(rows):
            time.sleep(0.5)

    cursor.execute('SELECT COUNT(*) FROM chunk_embeddings')
    total = cursor.fetchone()[0]
    print(f"Total embeddings in database: {total}")


# ---------------------------------------------------------------------------
# Indexing
# ---------------------------------------------------------------------------

def index_one_root(conn: sqlite3.Connection, source_root: str, lang: str,
                    skip_dirs: set, label: str) -> tuple[int, int]:
    """
    Walk a single directory tree and index its markdown files at the given lang.

    For EN articles, body_hash is computed from the current body content.
    For translation articles, body_hash is read from the frontmatter `source_hash`
    field (the hash of the EN body at translation time). Freshness comparison
    happens at retrieval time: a translation is fresh iff its body_hash equals
    the EN article's body_hash for the same filepath.

    Returns (indexed_count, skipped_count).
    """
    cursor = conn.cursor()
    indexed = 0
    skipped = 0

    for root, dirs, files in os.walk(source_root):
        if skip_dirs:
            dirs[:] = [d for d in dirs if d not in skip_dirs]

        for fname in files:
            if not fname.endswith('.md'):
                continue

            filepath = os.path.join(root, fname)
            rel_path = os.path.relpath(filepath, source_root)

            try:
                with open(filepath, 'r', encoding='utf-8') as f:
                    raw_content = f.read()
            except (IOError, UnicodeDecodeError):
                skipped += 1
                continue

            if len(raw_content.strip()) < 100:
                skipped += 1
                continue

            meta_check, _ = parse_frontmatter(raw_content)
            audience = meta_check.get('audience', '')
            if audience in SKIP_AUDIENCES:
                skipped += 1
                continue

            content_hash = hashlib.md5(raw_content.encode()).hexdigest()

            # Compute body_hash according to source-of-truth role:
            # - EN: hash the current body
            # - Translation: read source_hash from frontmatter (it's the hash
            #   of the EN body the translation was generated from)
            if lang == 'en':
                body_hash = hash_body(raw_content)
            else:
                body_hash = str(meta_check.get('source_hash', '')).strip().strip('"')

            # Skip translations missing source_hash — without it we cannot
            # determine freshness, so the translation cannot safely substitute
            # for any EN content. Worth a warning so the operator can run
            # `translate-sync.py fix-hashes LANG`.
            if lang != 'en' and not body_hash:
                print(f"  [{label}] WARNING: missing source_hash, skipping: {rel_path}")
                skipped += 1
                continue

            # Skip if already indexed with same content_hash for this (filepath, lang)
            cursor.execute(
                'SELECT id FROM articles WHERE filepath = ? AND lang = ? AND content_hash = ?',
                (rel_path, lang, content_hash)
            )
            if cursor.fetchone():
                continue

            # Remove old version if exists for this (filepath, lang)
            cursor.execute(
                'SELECT id FROM articles WHERE filepath = ? AND lang = ?',
                (rel_path, lang)
            )
            old = cursor.fetchone()
            if old:
                cursor.execute('DELETE FROM chunks WHERE article_id = ?', (old[0],))
                cursor.execute('DELETE FROM articles WHERE id = ?', (old[0],))

            meta, body = parse_frontmatter(raw_content)
            cleaned = clean_markdown(body)

            title = fname.replace('.md', '')
            domain = meta.get('domain', '')
            tags_raw = meta.get('tags', [])
            tags = ', '.join(tags_raw) if isinstance(tags_raw, list) else str(tags_raw)
            doctrinal_status = meta.get('doctrinal_status', 'stable')
            content_layer = meta.get('content_layer', 'applied')
            maturity = meta.get('maturity', 'complete')
            weight = compute_weight(meta)
            summary = extract_summary(cleaned)

            cursor.execute('''
                INSERT INTO articles
                (filepath, lang, title, domain, tags, doctrinal_status, content_layer,
                 maturity, full_text, summary, weight, content_hash, body_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ''', (rel_path, lang, title, domain, tags, doctrinal_status, content_layer,
                  maturity, cleaned, summary, weight, content_hash, body_hash))

            article_id = cursor.lastrowid

            chunks = chunk_by_heading(cleaned)
            for position, chunk in enumerate(chunks):
                cursor.execute(
                    'INSERT INTO chunks (article_id, lang, chunk_position, heading, content) VALUES (?, ?, ?, ?, ?)',
                    (article_id, lang, position, chunk['heading'], chunk['text'])
                )

            indexed += 1

    conn.commit()
    print(f"  [{label}] indexed: {indexed}, skipped: {skipped}")
    return indexed, skipped


def report_translation_freshness(conn: sqlite3.Connection):
    """
    Print per-language freshness stats. For each translation language, count:
    - fresh: translation exists AND body_hash matches the EN article
    - stale: translation exists BUT body_hash mismatches the EN article
    - missing: EN article exists but no translation row for this lang
    """
    cursor = conn.cursor()

    cursor.execute("SELECT COUNT(*) FROM articles WHERE lang = 'en'")
    en_total = cursor.fetchone()[0]

    print(f"\n=== Translation Freshness ===")
    print(f"  EN canonical articles: {en_total}")

    for lang in TRANSLATION_LANGUAGES:
        cursor.execute('''
            SELECT
                SUM(CASE WHEN t.body_hash = e.body_hash THEN 1 ELSE 0 END) as fresh,
                SUM(CASE WHEN t.body_hash != e.body_hash THEN 1 ELSE 0 END) as stale,
                COUNT(t.id) as translated
            FROM articles e
            LEFT JOIN articles t ON t.filepath = e.filepath AND t.lang = ?
            WHERE e.lang = 'en'
        ''', (lang,))
        row = cursor.fetchone()
        fresh = row[0] or 0
        stale = row[1] or 0
        translated = row[2] or 0
        missing = en_total - translated
        coverage_pct = round((translated / en_total * 100) if en_total else 0, 1)
        fresh_pct = round((fresh / en_total * 100) if en_total else 0, 1)

        print(f"  [{lang}] fresh: {fresh} ({fresh_pct}%) | stale: {stale} | missing: {missing} | total coverage: {translated}/{en_total} ({coverage_pct}%)")


def index_vault(vault_path: str, db_path: str, embed_key: str = None,
                 translations_path: str = None):
    """Walk the EN vault + translation roots and index all markdown files."""
    conn = create_database(db_path)

    print(f"\n=== Indexing English vault ===")
    print(f"  source: {vault_path}")
    en_indexed, en_skipped = index_one_root(
        conn, vault_path, 'en', SKIP_DIRS, label='en'
    )

    total_translation_indexed = 0
    total_translation_skipped = 0
    if translations_path and os.path.isdir(translations_path):
        print(f"\n=== Indexing translations ===")
        print(f"  source: {translations_path}")
        for lang in TRANSLATION_LANGUAGES:
            lang_root = os.path.join(translations_path, lang)
            if not os.path.isdir(lang_root):
                print(f"  [{lang}] directory not found, skipping")
                continue
            indexed, skipped = index_one_root(
                conn, lang_root, lang, set(), label=lang
            )
            total_translation_indexed += indexed
            total_translation_skipped += skipped
    else:
        if translations_path:
            print(f"\n  WARNING: translations path not found: {translations_path}")
        else:
            print(f"\n  No translations path provided — indexing English only.")

    cursor = conn.cursor()

    cursor.execute('SELECT COUNT(*) FROM articles')
    total_articles = cursor.fetchone()[0]
    cursor.execute('SELECT COUNT(*) FROM chunks')
    total_chunks = cursor.fetchone()[0]

    print(f"\n=== Vault Indexing Complete ===")
    print(f"English:      indexed {en_indexed} (skipped {en_skipped})")
    print(f"Translations: indexed {total_translation_indexed} (skipped {total_translation_skipped})")
    print(f"Total in database: {total_articles} articles, {total_chunks} chunks")

    cursor.execute('''
        SELECT lang, COUNT(*) as cnt FROM articles
        GROUP BY lang ORDER BY cnt DESC
    ''')
    print(f"\nBy language:")
    for row in cursor.fetchall():
        print(f"  {row[0]}: {row[1]}")

    cursor.execute('''
        SELECT domain, COUNT(*) as cnt FROM articles
        WHERE domain != '' AND lang = 'en' GROUP BY domain ORDER BY cnt DESC
    ''')
    print(f"\nEnglish by domain:")
    for row in cursor.fetchall():
        print(f"  {row[0]}: {row[1]}")

    cursor.execute('''
        SELECT content_layer, COUNT(*) as cnt FROM articles
        WHERE lang = 'en' GROUP BY content_layer ORDER BY cnt DESC
    ''')
    print(f"\nEnglish by content layer:")
    for row in cursor.fetchall():
        print(f"  {row[0]}: {row[1]}")

    # Surface translation freshness so the operator can see at a glance how
    # much of each language coverage will substitute vs fall back to EN.
    if total_translation_indexed > 0:
        report_translation_freshness(conn)

    if embed_key:
        generate_embeddings(conn, embed_key)
    else:
        print("\nNo OpenRouter API key provided — skipping embedding generation.")
        print("To generate embeddings, re-run with --openrouter-key YOUR_KEY or set OPENROUTER_API_KEY env var.")

    conn.close()
    print(f"\nDatabase saved to: {db_path}")


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Index the Harmonia vault for RAG retrieval')
    parser.add_argument('--vault', required=True, help='Path to the Harmonia vault root')
    parser.add_argument('--output', default='vault-index.db', help='Output SQLite database path')
    parser.add_argument('--translations', default=None,
                        help='Path to i18n/translations/ directory. If omitted, only English is indexed. '
                             'Default candidate: ../i18n/translations relative to this script.')
    parser.add_argument('--openrouter-key', default=None, help='OpenRouter API key for embedding generation (or OPENROUTER_API_KEY)')
    parser.add_argument('--embeddings-only', action='store_true',
                        help='Skip vault indexing, only generate embeddings for existing chunks')
    args = parser.parse_args()

    embed_key = args.openrouter_key or os.environ.get('OPENROUTER_API_KEY')

    if args.embeddings_only:
        if not embed_key:
            print("Error: --embeddings-only requires an OpenRouter API key (--openrouter-key or OPENROUTER_API_KEY env var)")
            sys.exit(1)
        if not os.path.isfile(args.output):
            print(f"Error: database not found at {args.output}. Run full indexing first.")
            sys.exit(1)
        conn = sqlite3.connect(args.output)
        conn.execute('''
            CREATE TABLE IF NOT EXISTS chunk_embeddings (
                chunk_id INTEGER PRIMARY KEY,
                embedding BLOB NOT NULL,
                model TEXT DEFAULT 'baai/bge-m3',
                FOREIGN KEY (chunk_id) REFERENCES chunks(id)
            )
        ''')
        generate_embeddings(conn, embed_key)
        conn.close()
        print(f"\nDatabase updated: {args.output}")
    else:
        if not os.path.isdir(args.vault):
            print(f"Error: vault path not found: {args.vault}")
            sys.exit(1)

        translations_path = args.translations
        if not translations_path:
            script_dir = os.path.dirname(os.path.abspath(__file__))
            candidate = os.path.normpath(os.path.join(script_dir, '..', 'i18n', 'translations'))
            if os.path.isdir(candidate):
                translations_path = candidate
                print(f"Using default translations path: {translations_path}")

        index_vault(args.vault, args.output, embed_key, translations_path)
