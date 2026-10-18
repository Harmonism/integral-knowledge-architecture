<?php
/**
 * The Wisdom Canon — shared reading-track engine.
 *
 * Surface-agnostic. Both Lodestone surfaces consume this file:
 *   - telegram-companion/telegram-bot.php  (subject_kind 'telegram', subject_id = chat id)
 *   - companion-api.php / reading-api.php  (subject_kind 'web', subject_id = client uuid)
 *
 * It owns four things and nothing else: the covenant, the station, the taper,
 * and the margin. It composes no prose and calls no model — the surfaces do
 * that, using readingSystemPromptBlock() as the instruction.
 *
 * DOCTRINAL CONSTRAINTS ENCODED HERE (do not relax without a Decision):
 *
 *   #633  Harmonism declines textual authority categorically. This engine never
 *         stores or ships a per-chapter Harmonist reading. What it holds is the
 *         table of contents (reference data), the arc, and the practitioner's
 *         own notes. Frames are generated per practitioner at message time from
 *         the instruction in readingSystemPromptBlock(), never authored here.
 *
 *   #1109 A commentary series was declined. A library of stored frames is that
 *         series wearing a feature's clothes. Frames are therefore ephemeral by
 *         construction: nothing in the schema can hold one.
 *
 *   #1102 Morocco hold. No Qur'an track ships, in any language. The Abrahamic
 *         cartography routes through the contemplative literature the corpus
 *         already engages. Enforced at CANON below and re-asserted in
 *         readingResolveWork().
 *
 *   Self-liquidation. Contact tapers on a curve fixed at covenant time and is
 *         never adjustable upward inside an arc. readingBuildTaper() is the
 *         only writer of reading_checkins, and it runs once, at creation.
 *
 * PHP 8.0 target (Hostinger). No enums, no readonly, no never-return.
 * Traps observed: no `??` inside string interpolation (#193); no `*​/` inside
 * docblocks (#380); constants via define() at top of file, not bottom (#656).
 */

if (!defined('READING_ENGINE_VERSION')) {
    define('READING_ENGINE_VERSION', '1.0.0');
}

// Seam stamps. Every margin note carries exactly one.
if (!defined('READING_SEAMS')) {
    define('READING_SEAMS', ['text', 'harmonism', 'mine']);
}

// Check-in kinds. Four, and no fifth.
if (!defined('READING_CHECKIN_KINDS')) {
    define('READING_CHECKIN_KINDS', ['arrival', 'friction', 'return', 'close']);
}


// ==========================================================================
// THE CANON
// ==========================================================================
//
// Reference data, not commentary. `units` is the canonical division and is
// confirmable by the practitioner at covenant time, because editions differ
// and a wrong unit count silently corrupts every station in the arc.
//
// `titles` ships ONLY where the division is standard and verified. Where it is
// not, the key is absent and stations are numbered. An invented table of
// contents is the cheapest possible way to make this feature dishonest, so the
// rule is: no titles unless certain. The title pass ran 2026-09-02 against two
// independent sources per work; each entry's comment names what was verified and
// which works stay numbered because their headings are translator-supplied.
//
// `anchors` are wikilink targets for adjacent Harmonist ground. They point;
// they never paraphrase. Empty is acceptable and preferable to a stretch.

if (!function_exists('readingCanon')) {
function readingCanon(): array {
    static $canon = null;
    if ($canon !== null) return $canon;

    $canon = [

        // ---- The Threshold: the sequenced first path, in its stated order ----

        'miracle-of-mindfulness' => [
            'title' => 'The Miracle of Mindfulness',
            'author' => 'Thich Nhat Hanh',
            'track' => 'threshold', 'position' => 1,
            'units' => 7, 'unit_label' => 'chapters',
            // Verified 2026-09-02 against the Beacon 1987 revised and 2016 gift editions (tr. Mobi Ho).
            // Seven numbered chapters; the Exercises, the Forest afterword and the sutra selection are
            // appendices, not stations.
            'titles' => [
                ['The Essential Discipline', '', ''],
                ['The Miracle Is to Walk on Earth', '', ''],
                ['A Day of Mindfulness', '', ''],
                ['The Pebble', '', ''],
                ['One Is All, All Is One: The Five Aggregates', '', ''],
                ['The Almond Tree in Your Front Yard', '', ''],
                ['Three Wondrous Answers', '', ''],
            ],
            'anchors' => ['Wheel of Harmony/presence/Wheel of Presence|Wheel of Presence'],
            'note' => 'Breathing, walking, washing a dish with full attention.',
        ],
        'siddhartha' => [
            'title' => 'Siddhartha', 'author' => 'Hermann Hesse',
            'track' => 'threshold', 'position' => 2,
            'units' => 12, 'unit_label' => 'chapters',
            // Verified 2026-09-02. German titles are authorial and fixed (Gutenberg #2499); English
            // renderings follow Hilda Rosner (New Directions, 1951) throughout — do not mix translators.
            'titles' => [
                ['The Brahmin\'s Son', 'Der Sohn des Brahmanen', 'Part One'],
                ['With the Samanas', 'Bei den Samanas', 'Part One'],
                ['Gotama', 'Gotama', 'Part One'],
                ['Awakening', 'Erwachen', 'Part One'],
                ['Kamala', 'Kamala', 'Part Two'],
                ['Amongst the People', 'Bei den Kindermenschen', 'Part Two'],
                ['Samsara', 'Sansara', 'Part Two'],
                ['By the River', 'Am Flusse', 'Part Two'],
                ['The Ferryman', 'Der Fährmann', 'Part Two'],
                ['The Son', 'Der Sohn', 'Part Two'],
                ['Om', 'Om', 'Part Two'],
                ['Govinda', 'Govinda', 'Part Two'],
            ],
            'translations' => ['Rosner (New Directions)'],
            'anchors' => ['Philosophy/Horizons/The Way of Harmony|The Way of Harmony'],
            'note' => 'The seeker\'s journey from borrowed doctrine to lived truth.',
        ],
        'the-prophet' => [
            'title' => 'The Prophet', 'author' => 'Khalil Gibran',
            'track' => 'threshold', 'position' => 3,
            // Verified 2026-09-02 against the 1923 Knopf contents page: 26 sermons framed by the
            // ship's arrival and the farewell. 28 stations so the arc opens on an arrival and closes
            // on a departure — exactly four weeks at one a day. The sermon-only count is 26.
            'units' => 28, 'unit_label' => 'addresses',
            'titles' => [
                ['The Coming of the Ship', '', 'frame'],
                ['On Love', '', 'sermon 1'],
                ['On Marriage', '', 'sermon 2'],
                ['On Children', '', 'sermon 3'],
                ['On Giving', '', 'sermon 4'],
                ['On Eating and Drinking', '', 'sermon 5'],
                ['On Work', '', 'sermon 6'],
                ['On Joy and Sorrow', '', 'sermon 7'],
                ['On Houses', '', 'sermon 8'],
                ['On Clothes', '', 'sermon 9'],
                ['On Buying and Selling', '', 'sermon 10'],
                ['On Crime and Punishment', '', 'sermon 11'],
                ['On Laws', '', 'sermon 12'],
                ['On Freedom', '', 'sermon 13'],
                ['On Reason and Passion', '', 'sermon 14'],
                ['On Pain', '', 'sermon 15'],
                ['On Self-Knowledge', '', 'sermon 16'],
                ['On Teaching', '', 'sermon 17'],
                ['On Friendship', '', 'sermon 18'],
                ['On Talking', '', 'sermon 19'],
                ['On Time', '', 'sermon 20'],
                ['On Good and Evil', '', 'sermon 21'],
                ['On Prayer', '', 'sermon 22'],
                ['On Pleasure', '', 'sermon 23'],
                ['On Beauty', '', 'sermon 24'],
                ['On Religion', '', 'sermon 25'],
                ['On Death', '', 'sermon 26'],
                ['The Farewell', '', 'frame'],
            ],
            'anchors' => ['Wheel of Harmony/Wheel of Harmony|Wheel of Harmony'],
            'note' => 'A poetic passage through every room of a life.',
        ],
        'tao-te-ching' => [
            'title' => 'Tao Te Ching', 'author' => 'Lao Tzu',
            'track' => 'threshold', 'position' => 4,
            'units' => 81, 'unit_label' => 'chapters',
            // The 81 chapters carry no traditional titles. Numbered is correct.
            'anchors' => ['Philosophy/Doctrine/Logos|Logos', 'Harmonic Realism'],
            'translations' => ['Mitchell (recommended)', 'Feng & English', 'Addiss & Lombardo'],
            'note' => 'Flow, restraint, and alignment with the Tao.',
        ],
        'meditations' => [
            'title' => 'Meditations', 'author' => 'Marcus Aurelius',
            'track' => 'threshold', 'position' => 5,
            'units' => 12, 'unit_label' => 'books',
            // Verified 2026-09-02: the twelve books carry no transmitted titles (Hays's "Debts and
            // Lessons" is his own heading for Book 1). Numbered is correct.
            'anchors' => ['Philosophy/Horizons/Freedom and Dharma|Freedom and Dharma'],
            'translations' => ['Hays', 'Farquharson', 'Hard (Oxford)'],
            'note' => 'An emperor\'s private notes on meeting each day with steadiness.',
        ],
        'dhammapada' => [
            'title' => 'Dhammapada', 'author' => 'attributed to the Buddha',
            'track' => 'threshold', 'position' => 6,
            'units' => 26, 'unit_label' => 'chapters',
            'titles' => [
                ['The Pairs', 'Yamaka', '1–20'],
                ['Heedfulness', 'Appamāda', '21–32'],
                ['The Mind', 'Citta', '33–43'],
                ['Flowers', 'Puppha', '44–59'],
                ['The Fool', 'Bāla', '60–75'],
                ['The Wise', 'Paṇḍita', '76–89'],
                ['The Arahant', 'Arahanta', '90–99'],
                ['Thousands', 'Sahassa', '100–115'],
                ['Evil', 'Pāpa', '116–128'],
                ['The Rod', 'Daṇḍa', '129–145'],
                ['Aging', 'Jarā', '146–156'],
                ['The Self', 'Atta', '157–166'],
                ['The World', 'Loka', '167–178'],
                ['The Buddha', 'Buddha', '179–196'],
                ['Happiness', 'Sukha', '197–208'],
                ['The Dear', 'Piya', '209–220'],
                ['Anger', 'Kodha', '221–234'],
                ['Impurities', 'Mala', '235–255'],
                ['The Just', 'Dhammaṭṭha', '256–272'],
                ['The Path', 'Magga', '273–289'],
                ['Miscellaneous', 'Pakiṇṇaka', '290–305'],
                ['Hell', 'Niraya', '306–319'],
                ['The Elephant', 'Nāga', '320–333'],
                ['Craving', 'Taṇhā', '334–359'],
                ['The Bhikkhu', 'Bhikkhu', '360–382'],
                ['The Brahmin', 'Brāhmaṇa', '383–423'],
            ],
            'anchors' => [
                'Philosophy/Doctrine/Multidimensional Causality|Multidimensional Causality',
                'Wheel of Harmony/presence/Wheel of Presence|Wheel of Presence',
            ],
            'translations' => [
                'Ānandajoti Bhikkhu (scholarly, with Pāli)',
                'Gil Fronsdal (accessible, precise)',
                'Eknath Easwaran (contemplative)',
            ],
            'note' => '423 verses on the mind, opened at random or walked in order.',
        ],
        'essential-rumi' => [
            'title' => 'The Essential Rumi', 'author' => 'Rumi, tr. Coleman Barks',
            'track' => 'threshold', 'position' => 7,
            // Verified 2026-09-02 against the 1995 HarperSanFrancisco edition (Barks: "the twenty-seven
            // divisions here"). Section names are Barks's own; the second field carries his subtitle.
            // "book" in this volume means a book of the Mathnawi, hence 'sections'. The 2004 expanded
            // edition may add a 28th section and is not verified — do not name it.
            'units' => 27, 'unit_label' => 'sections',
            'titles' => [
                ['The Tavern', 'Whoever Brought Me Here Will Have to Take Me Home', ''],
                ['Bewilderment', 'I Have Five Things to Say', ''],
                ['Emptiness and Silence', 'The Night Air', ''],
                ['Spring Giddiness', 'Stand in the Wake of This Chattering and Grow Airy', ''],
                ['Feeling Separation', 'Don\'t Come Near Me', ''],
                ['Controlling the Desire-Body', 'How Did You Kill Your Rooster, Husam?', ''],
                ['Sohbet', 'Meetings on the Riverbank', ''],
                ['Being a Lover', 'The Sunrise Ruby', ''],
                ['The Pickaxe', 'Getting to the Treasure Beneath the Foundation', ''],
                ['Art as Flirtation with Surrender', 'Wanting New Silk Harp Strings', ''],
                ['Union', 'Gnats Inside the Wind', ''],
                ['The Sheikh', 'I Have Such a Teacher', ''],
                ['Recognizing Elegance', 'Your Reasonable Father', ''],
                ['The Howling Necessity', 'Cry Out in Your Weakness', ''],
                ['Teaching Stories', 'How the Unseen World Works', ''],
                ['Rough Metaphors', 'More Teaching Stories', ''],
                ['Solomon Poems', 'The Far Mosque', ''],
                ['The Three Fish', 'Gamble Everything for Love', ''],
                ['Jesus Poems', 'The Population of the World', ''],
                ['In Baghdad, Dreaming of Cairo', 'More Teaching Stories', ''],
                ['Beginning and End', 'The Stories That Frame the Mathnawi', ''],
                ['Green Ears Everywhere', 'Children Running Through', ''],
                ['Being Woven', 'Communal Practice', ''],
                ['Wished-For Song', 'Secret Practices', ''],
                ['Majesty', 'This We Have Now', ''],
                ['Evolutionary Intelligence', 'Say I Am You', ''],
                ['The Turn', 'Dance in Your Blood', ''],
            ],
            'translations' => ['Barks, The Essential Rumi (HarperSanFrancisco, 1995)'],
            'anchors' => ['Philosophy/Convergences/Harmonism and the Traditions|Harmonism and the Traditions'],
            'note' => 'The poetry of the open heart. Not understood so much as breathed.',
        ],
        'enchiridion' => [
            'title' => 'Enchiridion', 'author' => 'Epictetus',
            'track' => 'threshold', 'position' => 8,
            'units' => 53, 'unit_label' => 'chapters',
            // Verified 2026-09-02: 53 is the Schweighäuser/Oldfather/Long division, untitled. The MIT
            // Classics (Carter/Higginson) text ends at 52; a practitioner on that text corrects the count.
            'anchors' => ['Philosophy/Horizons/Freedom and Dharma|Freedom and Dharma'],
            'note' => 'What is in one\'s power, and what is not.',
        ],
        'bhagavad-gita' => [
            'title' => 'Bhagavad Gita', 'author' => 'the Mahābhārata',
            'track' => 'threshold', 'position' => 9,
            'units' => 18, 'unit_label' => 'chapters',
            'titles' => [
                ['The Despondency of Arjuna', 'Arjuna Viṣāda Yoga', ''],
                ['The Yoga of Knowledge', 'Sāṅkhya Yoga', ''],
                ['The Yoga of Action', 'Karma Yoga', ''],
                ['Knowledge, Action and Renunciation', 'Jñāna Karma Sannyāsa Yoga', ''],
                ['The Yoga of Renunciation', 'Karma Sannyāsa Yoga', ''],
                ['The Yoga of Meditation', 'Dhyāna Yoga', ''],
                ['Knowledge and Realization', 'Jñāna Vijñāna Yoga', ''],
                ['The Imperishable Absolute', 'Akṣara Brahma Yoga', ''],
                ['The Royal Knowledge', 'Rāja Vidyā Rāja Guhya Yoga', ''],
                ['Divine Manifestations', 'Vibhūti Yoga', ''],
                ['The Vision of the Universal Form', 'Viśvarūpa Darśana Yoga', ''],
                ['The Yoga of Devotion', 'Bhakti Yoga', ''],
                ['The Field and the Knower', 'Kṣetra Kṣetrajña Vibhāga Yoga', ''],
                ['The Three Guṇas', 'Guṇatraya Vibhāga Yoga', ''],
                ['The Supreme Person', 'Puruṣottama Yoga', ''],
                ['The Divine and the Demonic', 'Daivāsura Sampad Vibhāga Yoga', ''],
                ['The Threefold Faith', 'Śraddhātraya Vibhāga Yoga', ''],
                ['Liberation through Renunciation', 'Mokṣa Sannyāsa Yoga', ''],
            ],
            'anchors' => [
                'Philosophy/Doctrine/Dharma|Dharma',
                'Wheel of Harmony/service/Wheel of Service|Wheel of Service',
            ],
            'translations' => [
                'Eknath Easwaran (clear first reading)',
                'Winthrop Sargeant (Sanskrit fidelity)',
                'Sri Aurobindo, Essays on the Gita (the demanding second pass)',
            ],
            'note' => 'Action, duty, and the three yogas in a single dialogue.',
        ],

        // ---- The Five Cartographies: one foundational text per soul-map ----
        // Gita, Tao Te Ching and Meditations above serve double duty.

        'shaman-healer-sage' => [
            'title' => 'Shaman, Healer, Sage', 'author' => 'Alberto Villoldo',
            'track' => 'cartography', 'cartography' => 'shamanic', 'position' => 3,
            // Verified 2026-09-02 against the Harmony/Crown 2000 contents page (Bantam 2001 identical):
            // nine chapters in three parts. The earlier count of 11 was wrong.
            'units' => 9, 'unit_label' => 'chapters',
            'titles' => [
                ['Healing and Infinity', '', 'Part I — Teachings of the Shamans'],
                ['The Luminous Healers', '', 'Part I'],
                ['The Luminous Energy Field', '', 'Part I'],
                ['The Chakras', '', 'Part II — The Luminous World'],
                ['The Shaman\'s Way of Seeing', '', 'Part II'],
                ['Sacred Space', '', 'Part II'],
                ['The Illumination Process', '', 'Part III — The Shaman\'s Work'],
                ['The Extraction Process', '', 'Part III'],
                ['Death, Dying, and Beyond', '', 'Part III'],
            ],
            'anchors' => [
                'The Human Being',
                'Philosophy/Convergences/The Five Cartographies of the Soul|The Five Cartographies of the Soul',
            ],
            'note' => 'The Luminous Energy Field and the eight-chakra system, Q\'ero Laika stream.',
        ],
        'way-of-a-pilgrim' => [
            'title' => 'The Way of a Pilgrim', 'author' => 'anonymous, 19th c. Russia',
            'track' => 'cartography', 'cartography' => 'abrahamic', 'position' => 5,
            'units' => 7, 'unit_label' => 'narratives',
            // Verified 2026-09-02: the R. M. French translation heads the narratives with bare numerals
            // 1–7 across both parts (The Way of a Pilgrim, four; The Pilgrim Continues His Way, three).
            // Savin titles the last three "Meetings"; SPCK's stand-alone prints only four. Numbered is
            // correct; the edition is named in the note.
            'anchors' => ['The Hesychast Cartography of the Heart'],
            'translations' => ['R. M. French (Harper / SPCK — both parts, seven narratives)', 'Olga Savin (Shambhala)'],
            'note' => 'The Prayer of the Heart, descending from mind to heart until it prays itself. Seven narratives when both parts are printed; editions with only the first part have four.',
        ],

        // ---- Perennial Core and companions, available as standalone arcs ----

        'ashtavakra-gita' => [
            'title' => 'Ashtavakra Gita', 'author' => 'attributed to Aṣṭāvakra',
            'track' => 'standalone', 'units' => 20, 'unit_label' => 'chapters',
            // Verified 2026-09-02: the Sanskrit marks chapters by ordinal colophon only; topical
            // headings are translator-supplied and differ across editions. Numbered, with the
            // verse count carried in the range field (298 in the Marshall recension).
            'titles' => [
                ['', '', '20 verses'],
                ['', '', '25 verses'],
                ['', '', '14 verses'],
                ['', '', '6 verses'],
                ['', '', '4 verses'],
                ['', '', '4 verses'],
                ['', '', '5 verses'],
                ['', '', '4 verses'],
                ['', '', '8 verses'],
                ['', '', '8 verses'],
                ['', '', '8 verses'],
                ['', '', '8 verses'],
                ['', '', '7 verses'],
                ['', '', '4 verses'],
                ['', '', '20 verses'],
                ['', '', '11 verses'],
                ['', '', '20 verses'],
                ['', '', '100 verses'],
                ['', '', '8 verses'],
                ['', '', '14 verses'],
            ],
            'anchors' => ['The Absolute', 'Harmonic Realism'],
            'note' => 'The most direct pointing to the already-liberated nature of consciousness.',
        ],
        'zhuangzi' => [
            'title' => 'Zhuangzi', 'author' => 'Zhuang Zhou',
            'track' => 'standalone', 'units' => 33, 'unit_label' => 'chapters',
            // Verified 2026-09-02: the Chinese titles are Guo Xiang's traditional set (ctext.org,
            // identical everywhere); English renderings are Burton Watson's, with pinyin for the
            // six name-chapters. Inner 1–7, Outer 8–22, Miscellaneous 23–33.
            'titles' => [
                ['Free and Easy Wandering', '逍遙遊 Xiāoyáo yóu', 'Inner'],
                ['Discussion on Making All Things Equal', '齊物論 Qí wù lùn', 'Inner'],
                ['The Secret of Caring for Life', '養生主 Yǎngshēng zhǔ', 'Inner'],
                ['In the World of Men', '人間世 Rénjiān shì', 'Inner'],
                ['The Sign of Virtue Complete', '德充符 Dé chōng fú', 'Inner'],
                ['The Great and Venerable Teacher', '大宗師 Dà zōngshī', 'Inner'],
                ['Fit for Emperors and Kings', '應帝王 Yìng dì wáng', 'Inner'],
                ['Webbed Toes', '駢拇 Piánmǔ', 'Outer'],
                ['Horses\' Hoofs', '馬蹄 Mǎtí', 'Outer'],
                ['Rifling Trunks', '胠篋 Qū qiè', 'Outer'],
                ['Let It Be, Leave It Alone', '在宥 Zài yòu', 'Outer'],
                ['Heaven and Earth', '天地 Tiāndì', 'Outer'],
                ['The Way of Heaven', '天道 Tiān dào', 'Outer'],
                ['The Turning of Heaven', '天運 Tiān yùn', 'Outer'],
                ['Constrained in Will', '刻意 Kè yì', 'Outer'],
                ['Mending the Inborn Nature', '繕性 Shàn xìng', 'Outer'],
                ['Autumn Floods', '秋水 Qiū shuǐ', 'Outer'],
                ['Perfect Happiness', '至樂 Zhì lè', 'Outer'],
                ['Mastering Life', '達生 Dá shēng', 'Outer'],
                ['The Mountain Tree', '山木 Shān mù', 'Outer'],
                ['Tian Zifang', '田子方 Tián Zǐfāng', 'Outer'],
                ['Knowledge Wandered North', '知北遊 Zhī běi yóu', 'Outer'],
                ['Gengsang Chu', '庚桑楚 Gēngsāng Chǔ', 'Miscellaneous'],
                ['Xu Wugui', '徐無鬼 Xú Wúguǐ', 'Miscellaneous'],
                ['Zeyang', '則陽 Zé Yáng', 'Miscellaneous'],
                ['External Things', '外物 Wài wù', 'Miscellaneous'],
                ['Imputed Words', '寓言 Yùyán', 'Miscellaneous'],
                ['Giving Away a Throne', '讓王 Ràng wáng', 'Miscellaneous'],
                ['Robber Zhi', '盜跖 Dào Zhí', 'Miscellaneous'],
                ['Discoursing on Swords', '說劍 Shuō jiàn', 'Miscellaneous'],
                ['The Old Fisherman', '漁父 Yú fù', 'Miscellaneous'],
                ['Lie Yukou', '列禦寇 Liè Yùkòu', 'Miscellaneous'],
                ['The World', '天下 Tiānxià', 'Miscellaneous'],
            ],
            'anchors' => ['Philosophy/Doctrine/Logos|Logos'],
            'translations' => ['Burton Watson (recommended)', 'Ziporyn'],
            'note' => 'Where the Tao Te Ching states the principle, the Zhuangzi enacts it.',
        ],
        'yoga-sutras' => [
            'title' => 'Yoga Sutras of Patanjali', 'author' => 'Patañjali',
            'track' => 'standalone', 'units' => 4, 'unit_label' => 'padas',
            'titles' => [
                ['On Absorption', 'Samādhi Pāda', ''],
                ['On Practice', 'Sādhana Pāda', ''],
                ['On Attainments', 'Vibhūti Pāda', ''],
                ['On Liberation', 'Kaivalya Pāda', ''],
            ],
            'anchors' => ['Wheel of Harmony/presence/Wheel of Presence|Wheel of Presence'],
            'translations' => ['Satchidananda (practice-oriented)', 'I.K. Taimni (philosophical depth)'],
            'note' => 'Four padas. Short enough to read in a week and long enough to work for a life.',
        ],
        'the-republic' => [
            'title' => 'The Republic', 'author' => 'Plato',
            'track' => 'standalone', 'units' => 10, 'unit_label' => 'books',
            // Verified 2026-09-02: no traditional book titles; Stephanus ranges carried in the range
            // field so a station can name where it starts and ends in any edition.
            'titles' => [
                ['', '', '327a–354c'],
                ['', '', '357a–383c'],
                ['', '', '386a–417b'],
                ['', '', '419a–445e'],
                ['', '', '449a–480a'],
                ['', '', '484a–511e'],
                ['', '', '514a–541b'],
                ['', '', '543a–569c'],
                ['', '', '571a–592b'],
                ['', '', '595a–621d'],
            ],
            'anchors' => [
                'World/Blueprint/Architecture of Harmony|Architecture of Harmony',
                'The Human Being',
            ],
            'note' => 'Justice in the soul and justice in the city, held as one question.',
        ],
        'interior-castle' => [
            'title' => 'The Interior Castle', 'author' => 'Teresa of Ávila',
            'track' => 'standalone', 'units' => 7, 'unit_label' => 'mansions',
            // Verified 2026-09-02 (Spanish text and the Peers translation): the seven moradas are
            // numbered, not named; chapter counts 2/1/2/3/4/11/4 carried in the range field. Peers's
            // per-chapter headings are his summaries and are not shipped.
            'titles' => [
                ['First Mansions', 'Moradas primeras', 'chs. 1–2'],
                ['Second Mansions', 'Moradas segundas', 'ch. 1'],
                ['Third Mansions', 'Moradas terceras', 'chs. 1–2'],
                ['Fourth Mansions', 'Moradas cuartas', 'chs. 1–3'],
                ['Fifth Mansions', 'Moradas quintas', 'chs. 1–4'],
                ['Sixth Mansions', 'Moradas sextas', 'chs. 1–11'],
                ['Seventh Mansions', 'Moradas séptimas', 'chs. 1–4'],
            ],
            'anchors' => ['Philosophy/Convergences/The Five Cartographies of the Soul|The Five Cartographies of the Soul'],
            'note' => 'Seven successive mansions, the journey inward toward union at the centre.',
        ],
    ];

    return $canon;
}
}


/**
 * Resolve a canon key to its record, or null.
 *
 * The #1102 / #1109 hold is re-asserted here rather than trusted to the table
 * above, because a future edit adds a work to CANON far more easily than it
 * re-reads this file's header.
 */
if (!function_exists('readingResolveWork')) {
function readingResolveWork(string $key): ?array {
    $blocked = ['quran', 'qur-an', 'koran', 'al-quran', 'the-quran'];
    if (in_array(strtolower($key), $blocked, true)) return null;

    $canon = readingCanon();
    if (!isset($canon[$key])) return null;

    $w = $canon[$key];
    $w['key'] = $key;
    return $w;
}
}

/** Works belonging to a track, ordered by position. */
if (!function_exists('readingTrack')) {
function readingTrack(string $track): array {
    $out = [];
    foreach (readingCanon() as $key => $w) {
        if (($w['track'] ?? '') !== $track) continue;
        $w['key'] = $key;
        $out[] = $w;
    }
    usort($out, function ($a, $b) {
        return ($a['position'] ?? 99) <=> ($b['position'] ?? 99);
    });
    return $out;
}
}


// ==========================================================================
// SCHEMA
// ==========================================================================
//
// Four tables, created idempotently. Called from both surfaces' memory
// bootstrap, so a deploy migrates on the next request with no migration step.
// Nothing here can hold a generated frame — see the header.

if (!function_exists('readingEnsureSchema')) {
function readingEnsureSchema(PDO $db): void {
    static $done = [];
    $id = spl_object_id($db);
    if (isset($done[$id])) return;
    $done[$id] = true;

    $db->exec('
        CREATE TABLE IF NOT EXISTS reading_arcs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_kind TEXT NOT NULL,
            subject_id TEXT NOT NULL,
            work_key TEXT NOT NULL,
            title TEXT NOT NULL,
            units INTEGER NOT NULL,
            unit_label TEXT NOT NULL DEFAULT "chapters",
            pace_num INTEGER NOT NULL DEFAULT 1,
            pace_days INTEGER NOT NULL DEFAULT 1,
            sitting_hour INTEGER NOT NULL DEFAULT -1,
            timezone TEXT NOT NULL DEFAULT "UTC",
            cadence TEXT NOT NULL DEFAULT "each_sitting",
            surface TEXT NOT NULL DEFAULT "telegram",
            discuss TEXT NOT NULL DEFAULT "yes",
            started_on TEXT NOT NULL,
            projected_end TEXT NOT NULL,
            state TEXT NOT NULL DEFAULT "active",
            closed_reason TEXT DEFAULT "",
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )
    ');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_reading_arcs_subject
               ON reading_arcs(subject_kind, subject_id, state)');

    $db->exec('
        CREATE TABLE IF NOT EXISTS reading_progress (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            arc_id INTEGER NOT NULL,
            unit_index INTEGER NOT NULL,
            opened_at INTEGER DEFAULT 0,
            opened_count INTEGER DEFAULT 0,
            completed_at INTEGER DEFAULT 0,
            UNIQUE(arc_id, unit_index)
        )
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS reading_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            arc_id INTEGER NOT NULL,
            unit_index INTEGER NOT NULL DEFAULT 0,
            seam TEXT NOT NULL,
            body TEXT NOT NULL,
            cite TEXT DEFAULT "",
            created_at INTEGER NOT NULL
        )
    ');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_reading_notes_arc
               ON reading_notes(arc_id, created_at DESC)');

    // The taper, materialised. Written once by readingBuildTaper() at covenant
    // time and never re-generated for a live arc — that immutability IS the
    // self-liquidation guarantee, so it must not become a convenience to
    // recompute. Rows may be cancelled or pulled earlier; never added.
    $db->exec('
        CREATE TABLE IF NOT EXISTS reading_checkins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            arc_id INTEGER NOT NULL,
            kind TEXT NOT NULL,
            week_index INTEGER NOT NULL DEFAULT 0,
            due_at INTEGER NOT NULL,
            state TEXT NOT NULL DEFAULT "pending",
            outreach_id INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL
        )
    ');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_reading_checkins_due
               ON reading_checkins(state, due_at)');
}
}


// ==========================================================================
// THE COVENANT
// ==========================================================================

/** Pace presets. num units per days days. */
if (!function_exists('readingPacePresets')) {
function readingPacePresets(): array {
    return [
        'daily'      => ['num' => 1, 'days' => 1, 'label' => 'one a day'],
        'double'     => ['num' => 2, 'days' => 1, 'label' => 'two a day'],
        'alternate'  => ['num' => 1, 'days' => 2, 'label' => 'one every other day'],
        'twice-week' => ['num' => 2, 'days' => 7, 'label' => 'two a week'],
        'weekly'     => ['num' => 1, 'days' => 7, 'label' => 'one a week'],
    ];
}
}

/** Total days an arc runs, given units and pace. */
if (!function_exists('readingArcDays')) {
function readingArcDays(int $units, int $paceNum, int $paceDays): int {
    if ($paceNum < 1) $paceNum = 1;
    if ($paceDays < 1) $paceDays = 1;
    return (int)max(1, (int)ceil($units * $paceDays / $paceNum));
}
}

/**
 * Create an arc and its taper. Returns the arc id.
 *
 * $opts keys: work_key, title, units, unit_label, pace_num, pace_days,
 *             sitting_hour, timezone, cadence, surface, discuss, start_date.
 */
if (!function_exists('readingCreateArc')) {
function readingCreateArc(PDO $db, string $subjectKind, string $subjectId, array $opts): int {
    readingEnsureSchema($db);

    $workKey = (string)($opts['work_key'] ?? 'custom');
    $work    = $workKey === 'custom' ? null : readingResolveWork($workKey);

    $title = trim((string)($opts['title'] ?? ($work['title'] ?? '')));
    if ($title === '') $title = 'Untitled reading';

    $units = (int)($opts['units'] ?? ($work['units'] ?? 0));
    if ($units < 1) $units = 1;
    if ($units > 400) $units = 400;

    $unitLabel = (string)($opts['unit_label'] ?? ($work['unit_label'] ?? 'chapters'));

    $paceNum  = (int)($opts['pace_num'] ?? 1);
    $paceDays = (int)($opts['pace_days'] ?? 1);
    if ($paceNum < 1)  $paceNum = 1;
    if ($paceDays < 1) $paceDays = 1;

    $tz = (string)($opts['timezone'] ?? 'UTC');
    if (!readingTzValid($tz)) $tz = 'UTC';

    $sittingHour = (int)($opts['sitting_hour'] ?? -1);
    if ($sittingHour < -1 || $sittingHour > 23) $sittingHour = -1;

    $cadence = (string)($opts['cadence'] ?? 'each_sitting');
    if (!in_array($cadence, ['each_sitting', 'twice_weekly', 'weekly', 'silent'], true)) {
        $cadence = 'each_sitting';
    }

    $discuss = (string)($opts['discuss'] ?? 'yes');
    if (!in_array($discuss, ['yes', 'on_demand', 'no'], true)) $discuss = 'yes';

    // discuss=no is honoured completely: the plan runs, contact does not.
    if ($discuss === 'no') $cadence = 'silent';

    $surface = (string)($opts['surface'] ?? 'telegram');
    if (!in_array($surface, ['telegram', 'web', 'email'], true)) $surface = 'telegram';

    $startDate = (string)($opts['start_date'] ?? readingToday($tz));
    $days      = readingArcDays($units, $paceNum, $paceDays);
    $endDate   = readingAddDays($startDate, $days - 1);

    $now = time();

    // One active arc per subject. Starting a second closes the first as
    // 'released' rather than silently running two tapers against one person.
    $db->prepare('UPDATE reading_arcs SET state = "closed", closed_reason = "released",
                  updated_at = ? WHERE subject_kind = ? AND subject_id = ? AND state = "active"')
       ->execute([$now, $subjectKind, $subjectId]);

    $stmt = $db->prepare('
        INSERT INTO reading_arcs
            (subject_kind, subject_id, work_key, title, units, unit_label,
             pace_num, pace_days, sitting_hour, timezone, cadence, surface,
             discuss, started_on, projected_end, state, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"active",?,?)
    ');
    $stmt->execute([
        $subjectKind, $subjectId, $workKey, $title, $units, $unitLabel,
        $paceNum, $paceDays, $sittingHour, $tz, $cadence, $surface,
        $discuss, $startDate, $endDate, $now, $now,
    ]);

    $arcId = (int)$db->lastInsertId();
    readingBuildTaper($db, $arcId);
    return $arcId;
}
}

/** The subject's active arc, or null. */
if (!function_exists('readingActiveArc')) {
function readingActiveArc(PDO $db, string $subjectKind, string $subjectId): ?array {
    readingEnsureSchema($db);
    $stmt = $db->prepare('SELECT * FROM reading_arcs
                          WHERE subject_kind = ? AND subject_id = ? AND state = "active"
                          ORDER BY id DESC LIMIT 1');
    $stmt->execute([$subjectKind, $subjectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
}

if (!function_exists('readingGetArc')) {
function readingGetArc(PDO $db, int $arcId): ?array {
    readingEnsureSchema($db);
    $stmt = $db->prepare('SELECT * FROM reading_arcs WHERE id = ?');
    $stmt->execute([$arcId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
}

/**
 * Close an arc. `completed` is the handoff; `released` is the practitioner
 * stopping; `reset` is a restart. Pending check-ins are cancelled either way.
 */
if (!function_exists('readingCloseArc')) {
function readingCloseArc(PDO $db, int $arcId, string $reason = 'completed'): void {
    readingEnsureSchema($db);
    $db->prepare('UPDATE reading_arcs SET state = "closed", closed_reason = ?, updated_at = ?
                  WHERE id = ?')->execute([$reason, time(), $arcId]);
    $db->prepare('UPDATE reading_checkins SET state = "cancelled"
                  WHERE arc_id = ? AND state = "pending"')->execute([$arcId]);
}
}


// ==========================================================================
// THE TAPER
// ==========================================================================
//
// Contact decays across the arc and the final week is silent. An engagement
// product raises cadence as retention decays; this lowers it on a curve fixed
// before the first page is turned.
//
// Written once, at covenant time. There is deliberately no function that adds
// a check-in to a live arc.

/** Messages per week for each week of the arc. Last week is always zero. */
if (!function_exists('readingTaperSeries')) {
function readingTaperSeries(int $weeks, string $cadence): array {
    if ($cadence === 'silent' || $weeks < 1) return array_fill(0, max($weeks, 0), 0);

    $start = 1;
    if ($cadence === 'each_sitting') $start = 7;
    elseif ($cadence === 'twice_weekly') $start = 2;

    if ($weeks === 1) return [$start];

    $series = [];
    for ($i = 0; $i < $weeks; $i++) {
        if ($i === $weeks - 1) { $series[] = 0; continue; }
        $frac = $i / ($weeks - 1);
        $v = (int)round($start * (1 - pow($frac, 0.85)));
        if ($i === 0) {
            $v = $start;
        } elseif ($v < 1 && $frac < 0.8) {
            // At low cadences the curve rounds to zero long before the arc
            // ends, which reads as abandonment rather than as a taper. Hold a
            // floor of one until the last fifth, then drop to silence. Still
            // monotonic: the floor only ever lifts a value the curve had put
            // below one, and everything after 0.8 is zero.
            $v = 1;
        }
        $series[] = max($v, 0);
    }
    return $series;
}
}

/**
 * Materialise the arc's check-ins. Idempotent per arc: refuses to run twice.
 */
if (!function_exists('readingBuildTaper')) {
function readingBuildTaper(PDO $db, int $arcId): int {
    readingEnsureSchema($db);

    $chk = $db->prepare('SELECT COUNT(*) FROM reading_checkins WHERE arc_id = ?');
    $chk->execute([$arcId]);
    if ((int)$chk->fetchColumn() > 0) return 0;

    $arc = readingGetArc($db, $arcId);
    if (!$arc) return 0;

    $days  = readingArcDays((int)$arc['units'], (int)$arc['pace_num'], (int)$arc['pace_days']);
    $weeks = (int)max(1, (int)ceil($days / 7));
    $series = readingTaperSeries($weeks, (string)$arc['cadence']);

    $tz   = (string)$arc['timezone'];
    $hour = (int)$arc['sitting_hour'];
    // No fixed sitting hour: the check-in lands in the evening, after a day in
    // which the reading could have happened — never in the morning, where it
    // reads as a prod rather than a question.
    $deliverHour = $hour >= 0 ? min(23, $hour + 1) : 20;

    $ins = $db->prepare('INSERT INTO reading_checkins
        (arc_id, kind, week_index, due_at, state, created_at)
        VALUES (?,?,?,?,"pending",?)');

    $now = time();
    $count = 0;

    for ($w = 0; $w < $weeks; $w++) {
        $n = (int)$series[$w];
        if ($n < 1) continue;
        // Spread n slots across the week's seven days.
        $step = 7 / $n;
        for ($k = 0; $k < $n; $k++) {
            $dayOffset = (int)floor($w * 7 + $k * $step);
            if ($dayOffset >= $days) break;
            $date = readingAddDays((string)$arc['started_on'], $dayOffset);
            $due  = readingTimestampFor($date, $deliverHour, $tz);
            // Kind is provisional: the cron re-decides arrival vs friction vs
            // return from live state. What the taper fixes is the SLOT.
            $ins->execute([$arcId, 'arrival', $w, $due, $now]);
            $count++;
        }
    }

    // The Close fires once, the day after the arc's last day, whatever the
    // cadence — including 'silent'. It is the handoff, not a check-in, and it
    // is the one message a reader who asked for no contact still receives.
    $closeDate = readingAddDays((string)$arc['started_on'], $days);
    $ins->execute([$arcId, 'close', $weeks, readingTimestampFor($closeDate, $deliverHour, $tz), $now]);
    $count++;

    return $count;
}
}

/** Check-ins due now across all active arcs. Consumed by the daily cron. */
if (!function_exists('readingDueCheckins')) {
function readingDueCheckins(PDO $db, ?int $now = null, int $limit = 200): array {
    readingEnsureSchema($db);
    $now = $now ?? time();
    $stmt = $db->prepare('
        SELECT c.*, a.subject_kind, a.subject_id, a.title, a.units, a.unit_label,
               a.pace_num, a.pace_days, a.timezone, a.started_on, a.discuss,
               a.surface, a.work_key, a.cadence
        FROM reading_checkins c
        JOIN reading_arcs a ON a.id = c.arc_id
        WHERE c.state = "pending" AND c.due_at <= ? AND a.state = "active"
        ORDER BY c.due_at ASC LIMIT ' . (int)$limit);
    $stmt->execute([$now]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
}

if (!function_exists('readingMarkCheckin')) {
function readingMarkCheckin(PDO $db, int $checkinId, string $state, int $outreachId = 0, string $kind = ''): void {
    readingEnsureSchema($db);
    if ($kind !== '' && in_array($kind, READING_CHECKIN_KINDS, true)) {
        $db->prepare('UPDATE reading_checkins SET state = ?, outreach_id = ?, kind = ? WHERE id = ?')
           ->execute([$state, $outreachId, $kind, $checkinId]);
        return;
    }
    $db->prepare('UPDATE reading_checkins SET state = ?, outreach_id = ? WHERE id = ?')
       ->execute([$state, $outreachId, $checkinId]);
}
}

/**
 * Check-ins due for one subject.
 *
 * The website has no outbound channel of its own, so a web arc's taper slots
 * do not become push messages — they become the question Lodestone raises at the
 * top of the practitioner's next conversation, and are consumed there. Same
 * taper, same ceiling, no new delivery infrastructure, and nothing arrives
 * that the practitioner did not open the door for.
 */
if (!function_exists('readingDueForSubject')) {
function readingDueForSubject(PDO $db, string $subjectKind, string $subjectId, ?int $now = null): ?array {
    readingEnsureSchema($db);
    $now = $now ?? time();
    $stmt = $db->prepare('
        SELECT c.* FROM reading_checkins c
        JOIN reading_arcs a ON a.id = c.arc_id
        WHERE a.subject_kind = ? AND a.subject_id = ? AND a.state = "active"
          AND c.state = "pending" AND c.due_at <= ?
        ORDER BY c.due_at ASC LIMIT 1');
    $stmt->execute([$subjectKind, $subjectId, $now]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
}

/** Remaining pending contact for an arc — used by /arc and the dashboard. */
if (!function_exists('readingTaperRemaining')) {
function readingTaperRemaining(PDO $db, int $arcId): array {
    readingEnsureSchema($db);
    $stmt = $db->prepare('SELECT COUNT(*) AS pending,
                                 SUM(CASE WHEN state = "queued" THEN 1 ELSE 0 END) AS sent
                          FROM reading_checkins WHERE arc_id = ?');
    $stmt->execute([$arcId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $p = $db->prepare('SELECT COUNT(*) FROM reading_checkins WHERE arc_id = ? AND state = "pending"');
    $p->execute([$arcId]);
    $q = $db->prepare('SELECT COUNT(*) FROM reading_checkins WHERE arc_id = ? AND state = "queued"');
    $q->execute([$arcId]);
    return ['pending' => (int)$p->fetchColumn(), 'sent' => (int)$q->fetchColumn()];
}
}


// ==========================================================================
// THE STATION
// ==========================================================================

/**
 * Where the arc stands. Returns day index (1-based), the unit that is due,
 * how many units are complete, and whether the reader is behind or ahead.
 */
if (!function_exists('readingProgress')) {
function readingProgress(PDO $db, array $arc, ?int $now = null): array {
    readingEnsureSchema($db);
    $now  = $now ?? time();
    $tz   = (string)$arc['timezone'];
    $days = readingArcDays((int)$arc['units'], (int)$arc['pace_num'], (int)$arc['pace_days']);

    $elapsed = readingDaysBetween((string)$arc['started_on'], readingToday($tz, $now));
    $dayIndex = max(1, $elapsed + 1);

    $dueUnit = (int)floor($elapsed * (int)$arc['pace_num'] / (int)$arc['pace_days']) + 1;
    $dueUnit = max(1, min((int)$arc['units'], $dueUnit));

    $stmt = $db->prepare('SELECT COUNT(*) FROM reading_progress WHERE arc_id = ? AND completed_at > 0');
    $stmt->execute([(int)$arc['id']]);
    $done = (int)$stmt->fetchColumn();

    $stmt = $db->prepare('SELECT MAX(unit_index) FROM reading_progress WHERE arc_id = ? AND completed_at > 0');
    $stmt->execute([(int)$arc['id']]);
    $lastDone = (int)$stmt->fetchColumn();

    // The station the reader is actually on: the first unit not yet complete.
    $current = min((int)$arc['units'], max(1, $lastDone + 1));

    return [
        'day_index'  => min($dayIndex, $days + 1),
        'total_days' => $days,
        'due_unit'   => $dueUnit,
        'current'    => $current,
        'completed'  => $done,
        'units'      => (int)$arc['units'],
        'behind'     => max(0, $dueUnit - $current),
        'finished'   => $done >= (int)$arc['units'],
    ];
}
}

/**
 * The station for a unit: its number, its title if the work has a verified
 * table of contents, and the adjacent Harmonist ground.
 *
 * This function returns REFERENCE DATA ONLY. It does not and must not return
 * a reading of the unit — see the header, #633 and #1109.
 */
if (!function_exists('readingStation')) {
function readingStation(array $arc, int $unitIndex): array {
    $units = (int)$arc['units'];
    $unitIndex = max(1, min($units, $unitIndex));

    $work = ((string)$arc['work_key'] === 'custom') ? null : readingResolveWork((string)$arc['work_key']);

    $name = '';
    $native = '';
    $range = '';
    if ($work && !empty($work['titles']) && isset($work['titles'][$unitIndex - 1])) {
        $t = $work['titles'][$unitIndex - 1];
        $name   = (string)($t[0] ?? '');
        $native = (string)($t[1] ?? '');
        $range  = (string)($t[2] ?? '');
    }

    $label = rtrim((string)$arc['unit_label'], 's');
    $heading = ucfirst($label) . ' ' . $unitIndex;
    if ($name !== '') $heading .= ' — ' . $name;

    return [
        'unit_index'   => $unitIndex,
        'units'        => $units,
        'heading'      => $heading,
        'name'         => $name,
        'native'       => $native,
        'range'        => $range,
        'unit_label'   => $label,
        'anchors'      => $work['anchors'] ?? [],
        'translations' => $work['translations'] ?? [],
        'has_toc'      => $work !== null && !empty($work['titles']),
    ];
}
}

if (!function_exists('readingOpenStation')) {
function readingOpenStation(PDO $db, int $arcId, int $unitIndex): void {
    readingEnsureSchema($db);
    $db->prepare('INSERT INTO reading_progress (arc_id, unit_index, opened_at, opened_count)
                  VALUES (?,?,?,1)
                  ON CONFLICT(arc_id, unit_index) DO UPDATE SET
                    opened_at = excluded.opened_at,
                    opened_count = reading_progress.opened_count + 1')
       ->execute([$arcId, $unitIndex, time()]);
}
}

/** Mark a unit read. Returns the new progress array. */
if (!function_exists('readingMarkRead')) {
function readingMarkRead(PDO $db, array $arc, int $unitIndex): array {
    readingEnsureSchema($db);
    $unitIndex = max(1, min((int)$arc['units'], $unitIndex));
    $db->prepare('INSERT INTO reading_progress (arc_id, unit_index, opened_at, opened_count, completed_at)
                  VALUES (?,?,?,1,?)
                  ON CONFLICT(arc_id, unit_index) DO UPDATE SET completed_at = excluded.completed_at')
       ->execute([(int)$arc['id'], $unitIndex, time(), time()]);
    return readingProgress($db, $arc);
}
}

/**
 * A unit is stalled when it has been opened three or more times over two or
 * more days and is still not complete. That is friction with the text or with
 * the plan — distinct from a gap, which is just silence and is not chased.
 */
if (!function_exists('readingStalledUnit')) {
function readingStalledUnit(PDO $db, int $arcId): ?array {
    readingEnsureSchema($db);
    $stmt = $db->prepare('SELECT unit_index, opened_at, opened_count
                          FROM reading_progress
                          WHERE arc_id = ? AND completed_at = 0 AND opened_count >= 3
                          ORDER BY unit_index ASC LIMIT 1');
    $stmt->execute([$arcId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if (time() - (int)$row['opened_at'] < 86400) return null;
    return $row;
}
}


// ==========================================================================
// THE MARGIN
// ==========================================================================

if (!function_exists('readingAddNote')) {
function readingAddNote(PDO $db, int $arcId, int $unitIndex, string $seam, string $body, string $cite = ''): int {
    readingEnsureSchema($db);
    $seam = strtolower(trim($seam));
    if (!in_array($seam, READING_SEAMS, true)) $seam = 'mine';
    $body = trim($body);
    if ($body === '') return 0;
    if (function_exists('mb_substr')) $body = mb_substr($body, 0, 4000);
    $stmt = $db->prepare('INSERT INTO reading_notes (arc_id, unit_index, seam, body, cite, created_at)
                          VALUES (?,?,?,?,?,?)');
    $stmt->execute([$arcId, max(0, $unitIndex), $seam, $body, trim($cite), time()]);
    return (int)$db->lastInsertId();
}
}

if (!function_exists('readingNotes')) {
function readingNotes(PDO $db, int $arcId, int $limit = 50, int $unitIndex = -1): array {
    readingEnsureSchema($db);
    if ($unitIndex >= 0) {
        $stmt = $db->prepare('SELECT * FROM reading_notes WHERE arc_id = ? AND unit_index = ?
                              ORDER BY created_at DESC LIMIT ' . (int)$limit);
        $stmt->execute([$arcId, $unitIndex]);
    } else {
        $stmt = $db->prepare('SELECT * FROM reading_notes WHERE arc_id = ?
                              ORDER BY created_at DESC LIMIT ' . (int)$limit);
        $stmt->execute([$arcId]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
}

if (!function_exists('readingNoteCounts')) {
function readingNoteCounts(PDO $db, int $arcId): array {
    readingEnsureSchema($db);
    $out = ['text' => 0, 'harmonism' => 0, 'mine' => 0, 'total' => 0];
    $stmt = $db->prepare('SELECT seam, COUNT(*) AS n FROM reading_notes WHERE arc_id = ? GROUP BY seam');
    $stmt->execute([$arcId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $s = (string)$r['seam'];
        if (isset($out[$s])) $out[$s] = (int)$r['n'];
        $out['total'] += (int)$r['n'];
    }
    return $out;
}
}

/**
 * A note from an earlier unit that plausibly bears on the current one, for the
 * Return check-in. Deliberately dumb: the practitioner's own words, most
 * recent 'mine' note from a prior unit. Selection sophistication belongs in
 * the model call, not in a hand-written relevance heuristic.
 */
if (!function_exists('readingReturnCandidate')) {
function readingReturnCandidate(PDO $db, int $arcId, int $currentUnit): ?array {
    readingEnsureSchema($db);
    $stmt = $db->prepare('SELECT * FROM reading_notes
                          WHERE arc_id = ? AND seam = "mine" AND unit_index > 0
                            AND unit_index < ?
                          ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$arcId, $currentUnit]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
}


// ==========================================================================
// THE SYSTEM-PROMPT BLOCK
// ==========================================================================

/**
 * The XML block both surfaces prepend when the practitioner has an active arc.
 *
 * This is where the #633 discipline is actually enforced at runtime. The
 * engine cannot stop a model from expounding; this instruction can, and it is
 * the single highest-leverage string in the feature.
 */
if (!function_exists('readingSystemPromptBlock')) {
function readingSystemPromptBlock(PDO $db, array $arc, ?array $dueCheckin = null): string {
    $prog    = readingProgress($db, $arc);
    $station = readingStation($arc, (int)$prog['current']);
    $counts  = readingNoteCounts($db, (int)$arc['id']);
    $notes   = readingNotes($db, (int)$arc['id'], 6);

    $lines = [];
    $lines[] = '<reading_arc>';
    $lines[] = 'The practitioner is walking a reading arc with you.';
    $lines[] = 'Text: ' . $arc['title'];
    $lines[] = 'Position: day ' . $prog['day_index'] . ' of ' . $prog['total_days']
             . ', ' . $station['heading'] . ', ' . $prog['completed'] . ' of ' . $prog['units'] . ' complete.';
    if ((int)$prog['behind'] > 0) {
        $lines[] = 'They are ' . $prog['behind'] . ' behind the pace they set. Do not chase this. It matters only if they raise it.';
    }
    $lines[] = 'Margin: ' . $counts['total'] . ' notes (' . $counts['text'] . ' text, '
             . $counts['harmonism'] . ' harmonism, ' . $counts['mine'] . ' their own).';

    if (!empty($station['anchors'])) {
        $lines[] = 'Adjacent Harmonist ground for this unit: ' . implode('; ', $station['anchors'])
                 . '. Offer it AFTER the unit is read, never before, and name it as adjacency rather than as the text\'s own claim.';
    }
    if (!empty($station['translations'])) {
        $lines[] = 'Translations you may name: ' . implode('; ', $station['translations']) . '.';
    }

    if ($notes) {
        $lines[] = 'Their most recent margin notes, newest first:';
        foreach ($notes as $n) {
            $seam = strtoupper((string)$n['seam']);
            $body = (string)$n['body'];
            if (function_exists('mb_substr')) $body = mb_substr($body, 0, 300);
            $lines[] = '  [' . $seam . ' · unit ' . (int)$n['unit_index'] . '] ' . $body;
        }
    }

    $lines[] = '';
    $lines[] = 'HOW YOU ACCOMPANY A READING — this governs every reply while an arc is active:';
    $lines[] = '1. You supply sequence, question, and frame. You do not supply the reading.';
    $lines[] = '2. Harmonism declines textual authority categorically. Never say what a passage'
             . ' means, what it teaches, or how Harmonism reads it. When asked directly for a'
             . ' meaning, return the question to their own encounter with the passage, and offer'
             . ' the tradition\'s own best readers by name rather than standing in for them.';
    $lines[] = '3. Structural facts about the text are permitted and welcome: where a unit sits in'
             . ' the work\'s architecture, what a term is in the original, where translations'
             . ' diverge. That is scholarship. Reading the passage for them is not.';
    $lines[] = '4. Your questions point at their life, not at the text. Not "what does this chapter'
             . ' argue" — the chapter answers that.';
    $lines[] = '5. Ask one thing. Then stop. A reader who spends longer talking to you than reading'
             . ' has been badly served, and the ratio is your responsibility, not theirs.';
    $lines[] = '6. When they write something worth keeping, tell them to put it in the margin in'
             . ' their own words before it settles into yours.';
    $lines[] = '7. Never praise consistency, never mark a streak, never treat a missed day as a'
             . ' lapse. The arc is theirs.';

    if ((string)$arc['discuss'] === 'no') {
        $lines[] = '8. They asked for the plan and no questions. Honour it completely: answer what'
                 . ' they raise, volunteer nothing about the reading, ask nothing about it.';
    } elseif ((string)$arc['discuss'] === 'on_demand') {
        $lines[] = '8. They asked you to speak about the reading only when they open it. Do not'
                 . ' raise the arc yourself.';
    }

    if ($dueCheckin !== null) {
        $kindPrompt = readingCheckinPrompt(
            (string)$dueCheckin['kind'],
            $station,
            readingReturnCandidate($db, (int)$arc['id'], (int)$prog['current'])
        );
        $lines[] = '';
        $lines[] = 'A check-in from their taper is due and has not been delivered. Fold it into'
                 . ' your reply to whatever they have just said — do not open with it, do not'
                 . ' stack it on top of an answer they are waiting for, and drop it entirely if'
                 . ' what they wrote is heavier than the reading. The instruction: ' . $kindPrompt;
    }

    $lines[] = '</reading_arc>';

    return implode("\n", $lines);
}
}

/**
 * The instruction for composing one check-in message. Consumed by the cron,
 * which pairs it with the arc block above.
 */
if (!function_exists('readingCheckinPrompt')) {
function readingCheckinPrompt(string $kind, array $station, ?array $returnNote = null): string {
    switch ($kind) {
        case 'friction':
            return 'Compose a FRICTION check-in. They have opened ' . $station['heading']
                 . ' several times over more than a day without finishing it. Ask, in one or two'
                 . ' sentences, whether the text is hard or the pace is wrong — and make plain that'
                 . ' changing the pace is the ordinary thing to do, not a concession. Do not'
                 . ' encourage, do not reassure, do not mention how many days it has been.';

        case 'return':
            $q = $returnNote ? (string)$returnNote['body'] : '';
            return 'Compose a RETURN check-in. Quote back their own earlier margin note — "'
                 . $q . '" — and ask whether what they saw there shows up in ' . $station['heading']
                 . '. Their words, not yours. One question, then stop.';

        case 'close':
            return 'Compose the CLOSE. The arc is finished. Ask them, without preamble, what they'
                 . ' now think the book is about, in one sentence, without looking. Then hand them'
                 . ' back to the text: name the second reading as the real one, suggest a different'
                 . ' translation if the work has one worth naming, and say plainly that you are not'
                 . ' scheduling that reading and will not be alongside it unless they ask. Do not'
                 . ' congratulate. Do not summarise the arc. Do not offer a next book.';

        case 'arrival':
        default:
            return 'Compose an ARRIVAL check-in for ' . $station['heading']
                 . '. One question, pointed at their own life rather than at the text, arising from'
                 . ' what this unit is structurally doing in the work. Two sentences at most.'
                 . ' Do not summarise the unit. Do not tell them what it means.';
    }
}
}


// ==========================================================================
// DATES
// ==========================================================================
//
// All arc arithmetic is in the practitioner's local calendar. A reader in
// Casablanca who reads at 22:00 must not have their day roll over mid-sitting.

if (!function_exists('readingTzValid')) {
function readingTzValid(string $tz): bool {
    if ($tz === '') return false;
    try { new DateTimeZone($tz); return true; }
    catch (Exception $e) { return false; }
}
}

if (!function_exists('readingToday')) {
function readingToday(string $tz, ?int $now = null): string {
    try { $z = new DateTimeZone($tz); }
    catch (Exception $e) { $z = new DateTimeZone('UTC'); }
    $d = new DateTime('@' . ($now ?? time()));
    $d->setTimezone($z);
    return $d->format('Y-m-d');
}
}

if (!function_exists('readingAddDays')) {
function readingAddDays(string $date, int $days): string {
    $d = DateTime::createFromFormat('Y-m-d', $date, new DateTimeZone('UTC'));
    if (!$d) return $date;
    $d->setTime(12, 0, 0);
    if ($days >= 0) $d->modify('+' . $days . ' days');
    else $d->modify($days . ' days');
    return $d->format('Y-m-d');
}
}

if (!function_exists('readingDaysBetween')) {
function readingDaysBetween(string $from, string $to): int {
    $a = DateTime::createFromFormat('Y-m-d', $from, new DateTimeZone('UTC'));
    $b = DateTime::createFromFormat('Y-m-d', $to, new DateTimeZone('UTC'));
    if (!$a || !$b) return 0;
    $a->setTime(12, 0, 0);
    $b->setTime(12, 0, 0);
    return (int)$a->diff($b)->format('%r%a');
}
}

/** Unix timestamp for a local date at a local hour. */
if (!function_exists('readingTimestampFor')) {
function readingTimestampFor(string $date, int $hour, string $tz): int {
    try { $z = new DateTimeZone($tz); }
    catch (Exception $e) { $z = new DateTimeZone('UTC'); }
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . sprintf('%02d', max(0, min(23, $hour))) . ':00:00', $z);
    if (!$d) return time();
    return $d->getTimestamp();
}
}

/** Human date for display, in the arc's timezone. */
if (!function_exists('readingFormatDate')) {
function readingFormatDate(string $date, string $tz = 'UTC'): string {
    $d = DateTime::createFromFormat('Y-m-d', $date, new DateTimeZone('UTC'));
    if (!$d) return $date;
    return $d->format('D j M');
}
}
