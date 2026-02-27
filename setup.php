<?php
/**
 * =============================================================================
 * VerdictTrace - Setup Script
 * =============================================================================
 * Run this script ONCE to:
 * 1. Create MySQL tables
 * 2. Create Elasticsearch indices with proper mappings
 * 3. Register Agent Builder tools and agent (optional)
 * 4. Seed demo data (optional)
 *
 * Usage: php setup.php           — interactive CLI setup
 *        Access via browser      — web-based setup wizard
 * =============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/es.php';
require_once __DIR__ . '/agent.php';

// ---------------------------------------------------------------------------
// Detect if running from CLI or browser
// ---------------------------------------------------------------------------
$isCli = (php_sapi_name() === 'cli');

/**
 * Output helper — prints to CLI or HTML
 */
function setup_log(string $msg, string $type = 'info') {
    global $isCli;
    if ($isCli) {
        $prefix = ['info' => '  ℹ', 'ok' => '  ✓', 'err' => '  ✗', 'head' => "\n▸'];
        echo ($prefix[$type] ?? '  ') . " $msg\n";
    } else {
        $colors = ['info' => '#666', 'ok' => '#28a745', 'err' => '#dc3545', 'head' => '#003c8a'];
        $weight = $type === 'head' ? '700' : '400';
        echo "<div style='color:{$colors[$type]};font-weight:{$weight};padding:2px 0;font-family:monospace;'>$msg</div>";
    }
}

// ---------------------------------------------------------------------------
// Web UI wrapper
// ---------------------------------------------------------------------------
if (!$isCli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>VerdictTrace Setup</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">';
    echo '<style>body{font-family:"Outfit",sans-serif;max-width:720px;margin:40px auto;padding:0 20px;background:#fff;color:#001d42;}</style>';
    echo '</head><body>';
    echo '<h1 style="color:#003c8a;">VerdictTrace Setup</h1><hr>';
}

// ===========================================================================
// STEP 1: MySQL Tables
// ===========================================================================
setup_log('Setting up MySQL tables...', 'head');

try {
    $pdo = db();

    // --- Users table (for investigators / admins) ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name`          VARCHAR(255) NOT NULL,
            `email`         VARCHAR(255) NOT NULL UNIQUE,
            `password_hash` VARCHAR(255) NOT NULL,
            `role`          ENUM('admin','investigator','viewer') NOT NULL DEFAULT 'investigator',
            `notify_email`  TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Receive email alerts',
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    setup_log('Table `users` ready', 'ok');

    // --- Ingestion sources (tracked data sources) ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ingest_sources` (
            `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name`        VARCHAR(255) NOT NULL COMMENT 'Source label (e.g., Zendesk, Email)',
            `type`        ENUM('csv','api','manual','email') NOT NULL DEFAULT 'manual',
            `config_json` TEXT COMMENT 'JSON config for API-based sources',
            `last_run_at` DATETIME DEFAULT NULL,
            `doc_count`   INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    setup_log('Table `ingest_sources` ready', 'ok');

    // --- Ingestion log (tracks each batch) ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ingest_log` (
            `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `source_id`   INT UNSIGNED NOT NULL,
            `docs_added`  INT UNSIGNED NOT NULL DEFAULT 0,
            `docs_failed` INT UNSIGNED NOT NULL DEFAULT 0,
            `status`      ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
            `error_msg`   TEXT,
            `started_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `finished_at` DATETIME DEFAULT NULL,
            FOREIGN KEY (`source_id`) REFERENCES `ingest_sources`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    setup_log('Table `ingest_log` ready', 'ok');

    // --- Notification log ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notifications` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id`    INT UNSIGNED DEFAULT NULL,
            `case_id`    VARCHAR(64) COMMENT 'Elasticsearch case doc ID',
            `type`       ENUM('escalation','assignment','comment','system') NOT NULL DEFAULT 'system',
            `title`      VARCHAR(255) NOT NULL,
            `message`    TEXT,
            `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
            `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    setup_log('Table `notifications` ready', 'ok');

    // --- Settings (key-value store for app config) ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `settings` (
            `key`        VARCHAR(128) PRIMARY KEY,
            `value`      TEXT,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    setup_log('Table `settings` ready', 'ok');

    // --- Insert default settings ---
    $defaults = [
        ['confidence_threshold', '0.7'],
        ['z_score_threshold', '2.5'],
        ['cluster_min_docs', '5'],
        ['auto_scan_interval', '3600'],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO `settings` (`key`, `value`) VALUES (?, ?)");
    foreach ($defaults as $d) {
        $stmt->execute($d);
    }
    setup_log('Default settings inserted', 'ok');

    // --- Create default admin user (password: admin123) ---
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->exec("
        INSERT IGNORE INTO `users` (`id`, `name`, `email`, `password_hash`, `role`)
        VALUES (1, 'Admin', 'admin@verdictrace.local', '$hash', 'admin')
    ");
    setup_log('Default admin user created (admin@verdictrace.local / admin123)', 'ok');

    setup_log('MySQL setup complete!', 'ok');

} catch (PDOException $e) {
    setup_log('MySQL Error: ' . $e->getMessage(), 'err');
}

// ===========================================================================
// STEP 2: Elasticsearch Indices
// ===========================================================================
setup_log('Setting up Elasticsearch indices...', 'head');

// --- Complaints index with hybrid search mappings ---
$complaintsMapping = [
    'properties' => [
        'title'            => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'complaint_text'   => ['type' => 'text'],
        'description'      => ['type' => 'text'],
        'source'           => ['type' => 'keyword'],
        'source_id'        => ['type' => 'keyword'],
        'product_sku'      => ['type' => 'keyword'],
        'product_name'     => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'failure_mode'     => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'severity_keywords'=> ['type' => 'keyword'],
        'injury_mentioned' => ['type' => 'boolean'],
        'location'         => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'geo_region'       => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'geo_point'        => ['type' => 'geo_point'],
        'customer_name'    => ['type' => 'keyword'],
        'customer_email'   => ['type' => 'keyword'],
        'summary'          => ['type' => 'text'],
        'embedding'        => ['type' => 'dense_vector', 'dims' => 1536, 'index' => true, 'similarity' => 'cosine'],
        'created_at'       => ['type' => 'date'],
        'ingested_at'      => ['type' => 'date'],
    ],
];

$result = es_create_index(ES_INDEX_COMPLAINTS, $complaintsMapping, ['number_of_replicas' => 0]);
if (isset($result['acknowledged']) && $result['acknowledged']) {
    setup_log('Index `' . ES_INDEX_COMPLAINTS . '` created', 'ok');
} elseif (isset($result['error']['type']) && $result['error']['type'] === 'resource_already_exists_exception') {
    setup_log('Index `' . ES_INDEX_COMPLAINTS . '` already exists (skipped)', 'info');
} else {
    setup_log('Index `' . ES_INDEX_COMPLAINTS . '` error: ' . json_encode($result['error'] ?? $result), 'err');
}

// --- Cases index for investigation workflow ---
$casesMapping = [
    'properties' => [
        'title'           => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'product_sku'     => ['type' => 'keyword'],
        'product_name'    => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'failure_mode'    => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
        'severity_tier'   => ['type' => 'integer'],
        'status'          => ['type' => 'keyword'],  // open, investigating, escalated, dismissed, resolved
        'confidence_score'=> ['type' => 'float'],
        'complaint_count' => ['type' => 'integer'],
        'injury_count'    => ['type' => 'integer'],
        'geo_regions'     => ['type' => 'keyword'],
        'date_range_start'=> ['type' => 'date'],
        'date_range_end'  => ['type' => 'date'],
        'assigned_to'     => ['type' => 'keyword'],
        'narrative'       => ['type' => 'text'],
        'dsl_queries'     => ['type' => 'text'],       // JSON array of DSL queries for traceability
        'exemplar_ids'    => ['type' => 'keyword'],     // Array of complaint doc IDs
        'trend_data'      => ['type' => 'text'],        // JSON for chart rendering
        'audit_log'       => [                          // Immutable audit trail
            'type'       => 'nested',
            'properties' => [
                'action'    => ['type' => 'keyword'],
                'user_id'   => ['type' => 'keyword'],
                'user_name' => ['type' => 'keyword'],
                'reason'    => ['type' => 'text'],
                'timestamp' => ['type' => 'date'],
            ],
        ],
        'created_at' => ['type' => 'date'],
        'updated_at' => ['type' => 'date'],
    ],
];

$result = es_create_index(ES_INDEX_CASES, $casesMapping, ['number_of_replicas' => 0]);
if (isset($result['acknowledged']) && $result['acknowledged']) {
    setup_log('Index `' . ES_INDEX_CASES . '` created', 'ok');
} elseif (isset($result['error']['type']) && $result['error']['type'] === 'resource_already_exists_exception') {
    setup_log('Index `' . ES_INDEX_CASES . '` already exists (skipped)', 'info');
} else {
    setup_log('Index `' . ES_INDEX_CASES . '` error: ' . json_encode($result['error'] ?? $result), 'err');
}

setup_log('Elasticsearch setup complete!', 'ok');

// ===========================================================================
// STEP 3: Agent Builder Setup (Optional)
// ===========================================================================
setup_log('Setting up Elasticsearch Agent Builder...', 'head');

if (KIBANA_URL !== '' && KIBANA_API_KEY !== '') {
    // Register custom tools
    $toolResults = agent_setup_verdictrace_tools();
    foreach ($toolResults as $toolName => $result) {
        if (isset($result['error'])) {
            setup_log("Tool '$toolName': " . json_encode($result['error']), 'err');
        } else {
            setup_log("Tool '$toolName' registered", 'ok');
        }
    }

    // Create the VerdictTrace agent
    $agentResult = agent_setup_verdictrace_agent();
    if (isset($agentResult['error'])) {
        setup_log('Agent creation error: ' . json_encode($agentResult['error']), 'err');
    } else {
        setup_log('VerdictTrace agent created', 'ok');
    }
} else {
    setup_log('Skipped — KIBANA_URL or KIBANA_API_KEY not configured in .env', 'info');
    setup_log('You can run this step later after configuring Agent Builder credentials.', 'info');
}

// ===========================================================================
// STEP 4: Seed Demo Data (Optional)
// ===========================================================================
setup_log('Seeding demo data...', 'head');

$demoComplaints = [
    [
        'title'            => 'Battery overheating during charging',
        'complaint_text'   => 'My PowerCell X200 battery gets extremely hot while charging. The device surface reached a temperature that was uncomfortable to touch. I am concerned this could be a fire hazard.',
        'source'           => 'support_ticket',
        'product_sku'      => 'PC-X200',
        'product_name'     => 'PowerCell X200',
        'failure_mode'     => 'overheating',
        'severity_keywords'=> ['hot', 'fire hazard', 'overheating'],
        'injury_mentioned' => false,
        'location'         => 'Austin, TX, USA',
        'geo_region'       => 'North America',
        'customer_name'    => 'Demo User 1',
        'summary'          => 'Battery overheating during charging on PowerCell X200',
        'created_at'       => date('c', strtotime('-3 days')),
        'ingested_at'      => date('c'),
    ],
    [
        'title'            => 'Battery swelling noticed',
        'complaint_text'   => 'I noticed my PowerCell X200 battery is swelling and the back cover is bulging. The device still works but I am afraid it might explode.',
        'source'           => 'email',
        'product_sku'      => 'PC-X200',
        'product_name'     => 'PowerCell X200',
        'failure_mode'     => 'battery swelling',
        'severity_keywords'=> ['swelling', 'bulging', 'explode'],
        'injury_mentioned' => false,
        'location'         => 'London, UK',
        'geo_region'       => 'Europe',
        'customer_name'    => 'Demo User 2',
        'summary'          => 'Battery swelling on PowerCell X200 with bulging back cover',
        'created_at'       => date('c', strtotime('-2 days')),
        'ingested_at'      => date('c'),
    ],
    [
        'title'            => 'Minor burn from device',
        'complaint_text'   => 'The PowerCell X200 got so hot it gave me a minor burn on my hand. I had to drop the device. This is a serious safety issue that needs immediate attention.',
        'source'           => 'support_ticket',
        'product_sku'      => 'PC-X200',
        'product_name'     => 'PowerCell X200',
        'failure_mode'     => 'overheating',
        'severity_keywords'=> ['burn', 'hot', 'safety issue'],
        'injury_mentioned' => true,
        'location'         => 'Toronto, Canada',
        'geo_region'       => 'North America',
        'customer_name'    => 'Demo User 3',
        'summary'          => 'Customer received minor burn from overheating PowerCell X200',
        'created_at'       => date('c', strtotime('-1 day')),
        'ingested_at'      => date('c'),
    ],
    [
        'title'            => 'Screen flickering issue',
        'complaint_text'   => 'My VisionPro M5 monitor screen flickers randomly. It happens especially when the display is set to high brightness. Very annoying during work.',
        'source'           => 'return_note',
        'product_sku'      => 'VP-M5',
        'product_name'     => 'VisionPro M5',
        'failure_mode'     => 'screen flickering',
        'severity_keywords'=> ['flickering', 'display issue'],
        'injury_mentioned' => false,
        'location'         => 'Berlin, Germany',
        'geo_region'       => 'Europe',
        'customer_name'    => 'Demo User 4',
        'summary'          => 'Screen flickering on VisionPro M5 at high brightness',
        'created_at'       => date('c', strtotime('-5 days')),
        'ingested_at'      => date('c'),
    ],
    [
        'title'            => 'Electrical shock from charging cable',
        'complaint_text'   => 'I received a small electrical shock when plugging in the PowerCell X200 charging cable. The metal connector seemed to have exposed wiring. This is extremely dangerous.',
        'source'           => 'support_ticket',
        'product_sku'      => 'PC-X200',
        'product_name'     => 'PowerCell X200',
        'failure_mode'     => 'electrical shock',
        'severity_keywords'=> ['shock', 'exposed wiring', 'dangerous'],
        'injury_mentioned' => true,
        'location'         => 'Sydney, Australia',
        'geo_region'       => 'Oceania',
        'customer_name'    => 'Demo User 5',
        'summary'          => 'Electrical shock from exposed wiring in PowerCell X200 charging cable',
        'created_at'       => date('c', strtotime('-1 day')),
        'ingested_at'      => date('c'),
    ],
];

$indexed = 0;
foreach ($demoComplaints as $i => $doc) {
    $result = es_index_doc(ES_INDEX_COMPLAINTS, 'demo_' . ($i + 1), $doc);
    if (isset($result['result']) && in_array($result['result'], ['created', 'updated'])) {
        $indexed++;
    }
}
setup_log("Indexed $indexed / " . count($demoComplaints) . " demo complaints", $indexed > 0 ? 'ok' : 'err');

// Seed a demo case
$demoCase = [
    'title'            => 'PowerCell X200 — Overheating & Battery Swelling Cluster',
    'product_sku'      => 'PC-X200',
    'product_name'     => 'PowerCell X200',
    'failure_mode'     => 'overheating / battery swelling',
    'severity_tier'    => TIER_INVESTIGATE,
    'status'           => 'open',
    'confidence_score' => 0.82,
    'complaint_count'  => 4,
    'injury_count'     => 2,
    'geo_regions'      => ['North America', 'Europe', 'Oceania'],
    'date_range_start' => date('c', strtotime('-5 days')),
    'date_range_end'   => date('c'),
    'assigned_to'      => 'admin@verdictrace.local',
    'narrative'        => 'A cluster of 4 complaints related to the PowerCell X200 has been detected. Multiple failure modes reported including overheating, battery swelling, and electrical shock. Two complaints involve injury reports. Geographic spread across 3 regions suggests a systemic issue rather than isolated incidents.',
    'exemplar_ids'     => ['demo_1', 'demo_2', 'demo_3', 'demo_5'],
    'audit_log'        => [
        [
            'action'    => 'created',
            'user_id'   => 'system',
            'user_name' => 'VerdictTrace Agent',
            'reason'    => 'Auto-generated from cluster detection. Confidence score 0.82 exceeded threshold 0.7.',
            'timestamp' => date('c'),
        ],
    ],
    'created_at' => date('c'),
    'updated_at' => date('c'),
];

$result = es_index_doc(ES_INDEX_CASES, 'demo_case_1', $demoCase);
if (isset($result['result']) && in_array($result['result'], ['created', 'updated'])) {
    setup_log('Demo case created', 'ok');
} else {
    setup_log('Demo case error: ' . json_encode($result), 'err');
}

// ===========================================================================
// Done
// ===========================================================================
setup_log('', 'info');
setup_log('Setup complete! You can now access VerdictTrace at: ' . APP_URL, 'head');

if (!$isCli) {
    echo '<br><a href="index.php" style="display:inline-block;padding:10px 24px;background:#003c8a;color:#fff;text-decoration:none;border-radius:6px;font-family:Outfit,sans-serif;font-weight:600;">Go to Dashboard →</a>';
    echo '</body></html>';
}
