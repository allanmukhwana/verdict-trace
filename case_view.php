<?php
/**
 * =============================================================================
 * VerdictTrace - Case Detail View
 * =============================================================================
 * Displays a single investigation case with:
 * - Evidence Pack (narrative, exemplar cases, trend chart, DSL queries)
 * - Tier escalation controls
 * - Audit log timeline
 * - Action buttons (approve, escalate, dismiss)
 * =============================================================================
 */

$pageTitle = 'Case Detail';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/es.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';

// ---------------------------------------------------------------------------
// Get case ID from query string
// ---------------------------------------------------------------------------
$caseId = trim($_GET['id'] ?? '');
if ($caseId === '') {
    header('Location: case_list.php');
    exit;
}

// ---------------------------------------------------------------------------
// Handle POST actions (escalate, dismiss, comment)
// ---------------------------------------------------------------------------
$flashMsg  = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    // Fetch current case to build audit entry
    $current = es_get_doc(ES_INDEX_CASES, $caseId);
    $currentSource = $current['_source'] ?? [];
    $auditLog = $currentSource['audit_log'] ?? [];

    // New audit entry
    $auditEntry = [
        'action'    => $action,
        'user_id'   => '1',                       // TODO: replace with session user
        'user_name' => 'Admin',
        'reason'    => $reason,
        'timestamp' => date('c'),
    ];
    $auditLog[] = $auditEntry;

    $updateFields = ['audit_log' => $auditLog, 'updated_at' => date('c')];

    switch ($action) {
        case 'escalate':
            $currentTier = (int)($currentSource['severity_tier'] ?? 1);
            $newTier = min($currentTier + 1, TIER_CRITICAL);
            $updateFields['severity_tier'] = $newTier;
            $updateFields['status'] = $newTier >= TIER_ESCALATE ? 'escalated' : 'investigating';

            // Send email notification to investigators
            $users = db_select("SELECT name, email FROM users WHERE notify_email = 1");
            foreach ($users as $user) {
                email_escalation_alert(
                    $user['email'],
                    $user['name'],
                    $caseId,
                    $newTier,
                    $currentSource['product_sku'] ?? '',
                    $currentSource['failure_mode'] ?? '',
                    substr($currentSource['narrative'] ?? '', 0, 200)
                );
            }

            // Create notification record
            db_insert("INSERT INTO notifications (user_id, case_id, type, title, message) VALUES (1, :case_id, 'escalation', :title, :msg)", [
                ':case_id' => $caseId,
                ':title'   => 'Case escalated to ' . tier_label($newTier),
                ':msg'     => 'Case ' . $caseId . ' was escalated to ' . tier_label($newTier) . '. Reason: ' . $reason,
            ]);

            $flashMsg  = 'Case escalated to ' . tier_label($newTier);
            $flashType = 'success';
            break;

        case 'dismiss':
            $updateFields['status'] = 'dismissed';
            $flashMsg  = 'Case dismissed.';
            $flashType = 'warning';
            break;

        case 'resolve':
            $updateFields['status'] = 'resolved';
            $flashMsg  = 'Case marked as resolved.';
            $flashType = 'success';
            break;

        case 'comment':
            // Comment-only — audit entry already added above
            $flashMsg  = 'Comment added.';
            $flashType = 'info';
            break;
    }

    // Update the case document in Elasticsearch
    es_update_doc(ES_INDEX_CASES, $caseId, $updateFields);
}

// ---------------------------------------------------------------------------
// Fetch case data
// ---------------------------------------------------------------------------
$caseResult = es_get_doc(ES_INDEX_CASES, $caseId);
if (!isset($caseResult['_source'])) {
    $pageTitle = 'Case Not Found';
    require_once __DIR__ . '/header.php';
    echo '<div class="vt-empty"><i class="fas fa-search"></i><p>Case not found.</p></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$case = $caseResult['_source'];
$case['_id'] = $caseId;

// Fetch exemplar complaints
$exemplars = [];
$exemplarIds = $case['exemplar_ids'] ?? [];
if (!empty($exemplarIds)) {
    $mget = es_request('POST', '/' . ES_INDEX_COMPLAINTS . '/_mget', [
        'ids' => $exemplarIds,
    ]);
    if (isset($mget['docs'])) {
        foreach ($mget['docs'] as $doc) {
            if (isset($doc['_source'])) {
                $src = $doc['_source'];
                $src['_id'] = $doc['_id'];
                $exemplars[] = $src;
            }
        }
    }
}

// Parse trend data (if stored as JSON)
$trendData   = [];
$trendLabels = [];
if (!empty($case['trend_data'])) {
    $parsed = json_decode($case['trend_data'], true);
    if ($parsed) {
        $trendLabels = array_keys($parsed);
        $trendData   = array_values($parsed);
    }
}

// Parse audit log
$auditLog = $case['audit_log'] ?? [];

require_once __DIR__ . '/header.php';
?>

<!-- Flash message -->
<?php if ($flashMsg): ?>
    <div class="alert alert-<?= $flashType ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flashMsg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Page Header with Back Button -->
<div class="vt-page-header">
    <div class="d-flex align-items-center gap-3">
        <a href="case_list.php" class="btn btn-light btn-sm rounded-circle">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h1 class="vt-page-title mb-0"><?= htmlspecialchars($case['title'] ?? 'Untitled Case') ?></h1>
            <small class="text-muted">Case ID: <?= htmlspecialchars($caseId) ?></small>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="vt-tier vt-tier-<?= (int)($case['severity_tier'] ?? 1) ?>">
            <?= tier_label((int)($case['severity_tier'] ?? 1)) ?>
        </span>
        <span class="vt-status vt-status-<?= htmlspecialchars($case['status'] ?? 'open') ?>">
            <?= ucfirst(htmlspecialchars($case['status'] ?? 'open')) ?>
        </span>
    </div>
</div>

<!-- Case Overview Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="vt-stat-card">
            <div class="vt-stat-value"><?= (int)($case['complaint_count'] ?? 0) ?></div>
            <div class="vt-stat-label">Complaints</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="vt-stat-card">
            <div class="vt-stat-value <?= ((int)($case['injury_count'] ?? 0)) > 0 ? 'text-danger' : '' ?>">
                <?= (int)($case['injury_count'] ?? 0) ?>
            </div>
            <div class="vt-stat-label">Injury Reports</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="vt-stat-card">
            <div class="vt-stat-value"><?= count($case['geo_regions'] ?? []) ?></div>
            <div class="vt-stat-label">Regions Affected</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="vt-stat-card">
            <div class="vt-stat-value"><?= number_format((float)($case['confidence_score'] ?? 0), 2) ?></div>
            <div class="vt-stat-label">Confidence Score</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Left Column: Narrative + Exemplars + Trend -->
    <div class="col-lg-8">

        <!-- Narrative -->
        <div class="vt-card mb-3">
            <div class="vt-card-header">
                <span><i class="fas fa-file-alt me-2"></i>Investigation Narrative</span>
            </div>
            <div class="vt-card-body">
                <p style="line-height:1.7;"><?= nl2br(htmlspecialchars($case['narrative'] ?? 'No narrative generated yet.')) ?></p>

                <!-- Product & Failure Mode Info -->
                <div class="row mt-3 g-2">
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Product</small>
                        <strong><?= htmlspecialchars($case['product_name'] ?? $case['product_sku'] ?? '—') ?></strong>
                        <code class="ms-1"><?= htmlspecialchars($case['product_sku'] ?? '') ?></code>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Failure Mode</small>
                        <strong><?= htmlspecialchars($case['failure_mode'] ?? '—') ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Date Range</small>
                        <strong><?= htmlspecialchars(substr($case['date_range_start'] ?? '', 0, 10)) ?> — <?= htmlspecialchars(substr($case['date_range_end'] ?? '', 0, 10)) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Regions</small>
                        <strong><?= htmlspecialchars(implode(', ', $case['geo_regions'] ?? [])) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Exemplar Complaints -->
        <div class="vt-card mb-3">
            <div class="vt-card-header">
                <span><i class="fas fa-list-ul me-2"></i>Exemplar Complaints (<?= count($exemplars) ?>)</span>
            </div>
            <div class="vt-card-body p-0">
                <?php if (empty($exemplars)): ?>
                    <div class="vt-empty py-4">
                        <i class="fas fa-inbox"></i>
                        <p>No exemplar complaints linked.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($exemplars as $ex): ?>
                        <div class="p-3 border-bottom">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <strong><?= htmlspecialchars($ex['title'] ?? 'Untitled') ?></strong>
                                <?php if (!empty($ex['injury_mentioned'])): ?>
                                    <span class="badge bg-danger">Injury</span>
                                <?php endif; ?>
                            </div>
                            <p class="mb-1 text-muted" style="font-size:0.875rem;">
                                <?= htmlspecialchars(mb_substr($ex['complaint_text'] ?? '', 0, 200)) ?>
                                <?= mb_strlen($ex['complaint_text'] ?? '') > 200 ? '...' : '' ?>
                            </p>
                            <div class="d-flex gap-3" style="font-size:0.78rem;">
                                <span class="text-muted"><i class="fas fa-box me-1"></i><?= htmlspecialchars($ex['product_sku'] ?? '—') ?></span>
                                <span class="text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($ex['location'] ?? '—') ?></span>
                                <span class="text-muted"><i class="fas fa-tag me-1"></i><?= htmlspecialchars($ex['source'] ?? '—') ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Trend Chart (if data available) -->
        <?php if (!empty($trendLabels)): ?>
        <div class="vt-card mb-3">
            <div class="vt-card-header">
                <span><i class="fas fa-chart-area me-2"></i>Complaint Trend</span>
            </div>
            <div class="vt-card-body" style="height:220px;">
                <canvas id="caseTrendChart"></canvas>
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                createTrendChart('caseTrendChart', <?= json_encode($trendLabels) ?>, <?= json_encode($trendData) ?>, 'Complaints');
            });
        </script>
        <?php endif; ?>
    </div>

    <!-- Right Column: Actions + Audit Log -->
    <div class="col-lg-4">

        <!-- Actions -->
        <?php if (!in_array($case['status'] ?? '', ['dismissed', 'resolved'])): ?>
        <div class="vt-card mb-3">
            <div class="vt-card-header">
                <span><i class="fas fa-gavel me-2"></i>Actions</span>
            </div>
            <div class="vt-card-body">
                <!-- Escalate Form -->
                <form method="post" class="mb-3">
                    <input type="hidden" name="action" value="escalate">
                    <div class="mb-2">
                        <label class="form-label">Escalation Reason</label>
                        <textarea name="reason" class="form-control" rows="2" placeholder="Provide reasoning for escalation..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-warning btn-sm w-100">
                        <i class="fas fa-arrow-up me-1"></i> Escalate to <?= tier_label(min((int)($case['severity_tier'] ?? 1) + 1, TIER_CRITICAL)) ?>
                    </button>
                </form>

                <!-- Resolve -->
                <form method="post" class="mb-2">
                    <input type="hidden" name="action" value="resolve">
                    <input type="hidden" name="reason" value="Case resolved by investigator.">
                    <button type="submit" class="btn btn-success btn-sm w-100">
                        <i class="fas fa-check me-1"></i> Mark Resolved
                    </button>
                </form>

                <!-- Dismiss -->
                <form method="post">
                    <input type="hidden" name="action" value="dismiss">
                    <div class="mb-2">
                        <textarea name="reason" class="form-control" rows="2" placeholder="Reason for dismissal..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="fas fa-times me-1"></i> Dismiss
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Add Comment -->
        <div class="vt-card mb-3">
            <div class="vt-card-header">
                <span><i class="fas fa-comment me-2"></i>Add Comment</span>
            </div>
            <div class="vt-card-body">
                <form method="post">
                    <input type="hidden" name="action" value="comment">
                    <div class="mb-2">
                        <textarea name="reason" class="form-control" rows="3" placeholder="Add investigation notes..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-vt-primary btn-sm w-100">
                        <i class="fas fa-paper-plane me-1"></i> Post Comment
                    </button>
                </form>
            </div>
        </div>

        <!-- Audit Log -->
        <div class="vt-card">
            <div class="vt-card-header">
                <span><i class="fas fa-history me-2"></i>Audit Log</span>
            </div>
            <div class="vt-card-body">
                <?php if (empty($auditLog)): ?>
                    <p class="text-muted mb-0" style="font-size:0.875rem;">No audit entries yet.</p>
                <?php else: ?>
                    <div class="vt-timeline">
                        <?php foreach (array_reverse($auditLog) as $entry): ?>
                            <div class="vt-timeline-item">
                                <div class="vt-timeline-time"><?= htmlspecialchars($entry['timestamp'] ?? '') ?></div>
                                <div class="vt-timeline-action">
                                    <?= htmlspecialchars(ucfirst($entry['action'] ?? 'action')) ?>
                                    <span class="text-muted fw-normal">by <?= htmlspecialchars($entry['user_name'] ?? 'Unknown') ?></span>
                                </div>
                                <?php if (!empty($entry['reason'])): ?>
                                    <div class="vt-timeline-reason"><?= htmlspecialchars($entry['reason']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
