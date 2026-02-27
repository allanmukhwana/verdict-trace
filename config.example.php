<?php
/**
 * =============================================================================
 * VerdictTrace - Configuration Loader
 * =============================================================================
 * Loads environment variables from .env file and provides global configuration
 * constants and helper functions used across the entire application.
 * =============================================================================
 */

// ---------------------------------------------------------------------------
// Load .env file
// ---------------------------------------------------------------------------
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    die('<h2>Missing .env file</h2><p>Copy <code>.env.example</code> to <code>.env</code> and fill in your credentials.</p>');
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    // Skip comments
    if (strpos(trim($line), '#') === 0) {
        continue;
    }
    // Parse KEY=VALUE
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // Remove surrounding quotes if present
        $value = trim($value, '"\'');
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

// ---------------------------------------------------------------------------
// Helper: get environment variable with optional default
// ---------------------------------------------------------------------------
function env(string $key, $default = '') {
    $val = getenv($key);
    return ($val !== false && $val !== '') ? $val : $default;
}

// ---------------------------------------------------------------------------
// Application Constants
// ---------------------------------------------------------------------------
define('APP_NAME',     env('APP_NAME', 'VerdictTrace'));
define('APP_URL',      env('APP_URL', 'http://localhost:8080'));
define('APP_ENV',      env('APP_ENV', 'development'));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'UTC'));

// ---------------------------------------------------------------------------
// MySQL Constants
// ---------------------------------------------------------------------------
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'verdict_trace'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// ---------------------------------------------------------------------------
// Elasticsearch Constants
// ---------------------------------------------------------------------------
define('ES_HOST',             env('ES_HOST', 'http://localhost:9200'));
define('ES_PORT',             env('ES_PORT', '9200'));
define('ES_API_KEY',          env('ES_API_KEY', ''));
define('ES_INDEX_COMPLAINTS', env('ES_INDEX_COMPLAINTS', 'verdictrace_complaints'));
define('ES_INDEX_CASES',      env('ES_INDEX_CASES', 'verdictrace_cases'));

// ---------------------------------------------------------------------------
// Elasticsearch Agent Builder (Kibana API) Constants
// ---------------------------------------------------------------------------
define('KIBANA_URL',     env('KIBANA_URL', ''));
define('KIBANA_API_KEY', env('KIBANA_API_KEY', ''));

// ---------------------------------------------------------------------------
// LLM API Constants
// ---------------------------------------------------------------------------
define('LLM_API_URL',         env('LLM_API_URL', 'https://api.openai.com/v1/chat/completions'));
define('LLM_API_KEY',         env('LLM_API_KEY', ''));
define('LLM_MODEL',           env('LLM_MODEL', 'gpt-4o'));
define('LLM_EMBEDDING_URL',   env('LLM_EMBEDDING_URL', 'https://api.openai.com/v1/embeddings'));
define('LLM_EMBEDDING_MODEL', env('LLM_EMBEDDING_MODEL', 'text-embedding-3-small'));

// ---------------------------------------------------------------------------
// Brevo Email API Constants
// ---------------------------------------------------------------------------
define('BREVO_API_KEY',       env('BREVO_API_KEY', ''));
define('BREVO_SENDER_NAME',   env('BREVO_SENDER_NAME', 'VerdictTrace'));
define('BREVO_SENDER_EMAIL',  env('BREVO_SENDER_EMAIL', 'alerts@yourdomain.com'));

// ---------------------------------------------------------------------------
// Timezone
// ---------------------------------------------------------------------------
date_default_timezone_set(APP_TIMEZONE);

// ---------------------------------------------------------------------------
// Error Reporting (based on environment)
// ---------------------------------------------------------------------------
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// ---------------------------------------------------------------------------
// Severity Tiers (used throughout the application)
// ---------------------------------------------------------------------------
define('TIER_MONITOR',    1);
define('TIER_INVESTIGATE', 2);
define('TIER_ESCALATE',   3);
define('TIER_CRITICAL',   4);

/**
 * Map tier number to label
 */
function tier_label(int $tier): string {
    $labels = [
        TIER_MONITOR     => 'Monitor',
        TIER_INVESTIGATE => 'Investigate',
        TIER_ESCALATE    => 'Escalate',
        TIER_CRITICAL    => 'Critical',
    ];
    return $labels[$tier] ?? 'Unknown';
}

/**
 * Map tier number to Bootstrap badge class
 */
function tier_badge(int $tier): string {
    $badges = [
        TIER_MONITOR     => 'bg-info',
        TIER_INVESTIGATE => 'bg-warning text-dark',
        TIER_ESCALATE    => 'bg-orange',
        TIER_CRITICAL    => 'bg-danger',
    ];
    return $badges[$tier] ?? 'bg-secondary';
}
