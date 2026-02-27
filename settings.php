<?php
/**
 * =============================================================================
 * VerdictTrace - Settings
 * =============================================================================
 * Application settings management page. Allows administrators to:
 * - Edit scanner thresholds (confidence, z-score, min cluster docs)
 * - Configure notification preferences
 * - View system status (Elasticsearch, Agent Builder, LLM, Brevo)
 * - Manage user accounts
 * =============================================================================
 */

$pageTitle = 'Settings';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/es.php';

$flashMsg  = '';
$flashType = '';

// ---------------------------------------------------------------------------
// Handle POST: Update settings
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settingsToSave = [
        'confidence_threshold' => $_POST['confidence_threshold'] ?? '0.7',
        'z_score_threshold'    => $_POST['z_score_threshold'] ?? '2.5',
        'cluster_min_docs'     => $_POST['cluster_min_docs'] ?? '5',
        'auto_scan_interval'   => $_POST['auto_scan_interval'] ?? '3600',
    ];

    foreach ($settingsToSave as $key => $value) {
        db_execute(
            "INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = :v2",
            [':k' => $key, ':v' => $value, ':v2' => $value]
        );
    }

    $flashMsg  = 'Settings saved successfully.';
    $flashType = 'success';
}

// ---------------------------------------------------------------------------
// Load current settings
// ---------------------------------------------------------------------------
$settings = [];
try {
    $rows = db_select("SELECT `key`, `value` FROM settings");
    foreach ($rows as $row) {
        $settings[$row['key']] = $row['value'];
    }
} catch (Exception $e) {
    // Table may not exist yet
}

// ---------------------------------------------------------------------------
// System status checks
// ---------------------------------------------------------------------------
$esStatus     = 'unknown';
$esVersion    = '';
$agentStatus  = 'not configured';
$llmStatus    = LLM_API_KEY !== '' ? 'configured' : 'not configured';
$brevoStatus  = BREVO_API_KEY !== '' ? 'configured' : 'not configured';

// Check Elasticsearch connectivity
$esInfo = es_request('GET', '/');
if (isset($esInfo['version']['number'])) {
    $esStatus  = 'connected';
    $esVersion = $esInfo['version']['number'];
} elseif (isset($esInfo['error'])) {
    $esStatus = 'error';
}

// Check Agent Builder
if (KIBANA_URL !== '' && KIBANA_API_KEY !== '') {
    $agentStatus = 'configured';
}

// Load user list
$users = [];
try {
    $users = db_select("SELECT id, name, email, role, notify_email, created_at FROM users ORDER BY id");
} catch (Exception $e) {}

require_once __DIR__ . '/header.php';
?>

<!-- Flash message -->
<?php if ($flashMsg): ?>
    <div class="alert alert-<?= $flashType ?> alert-dismissible fade show"><?= htmlspecialchars($flashMsg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- Page Header -->
<div class="vt-page-header">
    <h1 class="vt-page-title">Settings</h1>
</div>

<div class="row g-4">
    <!-- Scanner Settings -->
    <div class="col-lg-6">
        <div class="vt-card">
            <div class="vt-card-header">
                <span><i class="fas fa-sliders-h me-2"></i>Scanner Configuration</span>
            </div>
            <div class="vt-card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Confidence Threshold</label>
                        <input type="number" step="0.01" min="0" max="1" name="confidence_threshold"
                               class="form-control" value="<?= htmlspecialchars($settings['confidence_threshold'] ?? '0.7') ?>">
                        <small class="text-muted">Minimum confidence score (0–1) for a cluster to generate an Evidence Pack.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Z-Score Threshold</label>
                        <input type="number" step="0.1" min="0" name="z_score_threshold"
                               class="form-control" value="<?= htmlspecialchars($settings['z_score_threshold'] ?? '2.5') ?>">
                        <small class="text-muted">Standard deviations above baseline for anomaly detection flagging.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Minimum Cluster Documents</label>
                        <input type="number" min="1" name="cluster_min_docs"
                               class="form-control" value="<?= htmlspecialchars($settings['cluster_min_docs'] ?? '5') ?>">
                        <small class="text-muted">Minimum complaint count before a product/failure-mode group is considered a cluster.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Auto-Scan Interval (seconds)</label>
                        <input type="number" min="60" name="auto_scan_interval"
                               class="form-control" value="<?= htmlspecialchars($settings['auto_scan_interval'] ?? '3600') ?>">
                        <small class="text-muted">Interval for cron-based automatic scanning. Set to 3600 for hourly.</small>
                    </div>
                    <button type="submit" class="btn btn-vt-primary w-100">
                        <i class="fas fa-save me-1"></i> Save Settings
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- System Status -->
    <div class="col-lg-6">
        <div class="vt-card mb-4">
            <div class="vt-card-header">
                <span><i class="fas fa-heartbeat me-2"></i>System Status</span>
            </div>
            <div class="vt-card-body">
                <table class="w-100" style="font-size:0.875rem;">
                    <!-- Elasticsearch -->
                    <tr>
                        <td class="py-2 pe-3" style="width:160px;"><i class="fas fa-database text-primary me-2"></i>Elasticsearch</td>
                        <td class="py-2">
                            <?php if ($esStatus === 'connected'): ?>
                                <span class="badge bg-success">Connected</span>
                                <small class="text-muted ms-1">v<?= htmlspecialchars($esVersion) ?></small>
                            <?php elseif ($esStatus === 'error'): ?>
                                <span class="badge bg-danger">Error</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Unknown</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Agent Builder -->
                    <tr>
                        <td class="py-2 pe-3"><i class="fas fa-robot text-primary me-2"></i>Agent Builder</td>
                        <td class="py-2">
                            <?php if ($agentStatus === 'configured'): ?>
                                <span class="badge bg-success">Configured</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Not Configured</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- LLM -->
                    <tr>
                        <td class="py-2 pe-3"><i class="fas fa-brain text-primary me-2"></i>LLM API</td>
                        <td class="py-2">
                            <?php if ($llmStatus === 'configured'): ?>
                                <span class="badge bg-success">Configured</span>
                                <small class="text-muted ms-1"><?= htmlspecialchars(LLM_MODEL) ?></small>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Not Configured</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- Brevo -->
                    <tr>
                        <td class="py-2 pe-3"><i class="fas fa-envelope text-primary me-2"></i>Brevo Email</td>
                        <td class="py-2">
                            <?php if ($brevoStatus === 'configured'): ?>
                                <span class="badge bg-success">Configured</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Not Configured</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <!-- MySQL -->
                    <tr>
                        <td class="py-2 pe-3"><i class="fas fa-server text-primary me-2"></i>MySQL</td>
                        <td class="py-2">
                            <?php
                            try { db(); echo '<span class="badge bg-success">Connected</span>'; }
                            catch (Exception $e) { echo '<span class="badge bg-danger">Error</span>'; }
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="vt-card mb-4">
            <div class="vt-card-header">
                <span><i class="fas fa-link me-2"></i>Quick Links</span>
            </div>
            <div class="vt-card-body">
                <div class="d-flex flex-column gap-2">
                    <a href="setup.php" class="btn btn-sm btn-vt-outline text-start">
                        <i class="fas fa-magic me-2"></i> Re-run Setup
                    </a>
                    <a href="scan.php" class="btn btn-sm btn-vt-outline text-start">
                        <i class="fas fa-play me-2"></i> Run Cluster Scanner
                    </a>
                    <a href="<?= htmlspecialchars(KIBANA_URL) ?>" target="_blank" class="btn btn-sm btn-vt-outline text-start <?= KIBANA_URL === '' ? 'disabled' : '' ?>">
                        <i class="fas fa-external-link-alt me-2"></i> Open Kibana
                    </a>
                </div>
            </div>
        </div>

        <!-- Users -->
        <div class="vt-card">
            <div class="vt-card-header">
                <span><i class="fas fa-users me-2"></i>Users</span>
            </div>
            <div class="vt-card-body p-0">
                <?php if (empty($users)): ?>
                    <div class="vt-empty py-3"><p>No users found.</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="vt-table">
                            <thead>
                                <tr><th>Name</th><th>Email</th><th>Role</th><th>Alerts</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($u['name']) ?></td>
                                        <td><small><?= htmlspecialchars($u['email']) ?></small></td>
                                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars(ucfirst($u['role'])) ?></span></td>
                                        <td><?= $u['notify_email'] ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-muted"></i>' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
