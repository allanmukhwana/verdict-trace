<?php
/**
 * =============================================================================
 * VerdictTrace - Dashboard
 * =============================================================================
 * Main dashboard page showing:
 * - KPI stat cards (total complaints, active cases, critical alerts, injury reports)
 * - Complaint trend chart
 * - Severity tier distribution
 * - Recent cases list
 * - Top failure modes
 * =============================================================================
 */

$pageTitle = 'Dashboard';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/es.php';

// ---------------------------------------------------------------------------
// Fetch dashboard data from Elasticsearch
// ---------------------------------------------------------------------------

// Total complaint count
$complaintCount = 0;
$complaintResult = es_request('GET', '/' . ES_INDEX_COMPLAINTS . '/_count');
if (isset($complaintResult['count'])) {
    $complaintCount = (int)$complaintResult['count'];
}

// Active cases count and case data
$activeCases   = 0;
$criticalCases = 0;
$recentCases   = [];
$casesResult = es_search(ES_INDEX_CASES, [
    'size'  => 10,
    'query' => ['bool' => ['must_not' => [['term' => ['status' => 'dismissed']]]]],
    'sort'  => [['updated_at' => 'desc']],
]);
if (isset($casesResult['hits']['hits'])) {
    $activeCases = $casesResult['hits']['total']['value'] ?? 0;
    foreach ($casesResult['hits']['hits'] as $hit) {
        $src = $hit['_source'];
        $src['_id'] = $hit['_id'];
        $recentCases[] = $src;
        if (($src['severity_tier'] ?? 0) >= TIER_CRITICAL) {
            $criticalCases++;
        }
    }
}

// Injury complaint count
$injuryCount = 0;
$injuryResult = es_request('POST', '/' . ES_INDEX_COMPLAINTS . '/_count', [
    'query' => ['term' => ['injury_mentioned' => true]],
]);
if (isset($injuryResult['count'])) {
    $injuryCount = (int)$injuryResult['count'];
}

// Complaint trend (last 14 days)
$trendLabels = [];
$trendData   = [];
$trendResult = es_search(ES_INDEX_COMPLAINTS, [
    'size' => 0,
    'query' => ['range' => ['created_at' => ['gte' => 'now-14d/d']]],
    'aggs' => [
        'daily' => [
            'date_histogram' => [
                'field'             => 'created_at',
                'calendar_interval' => '1d',
                'format'            => 'MMM dd',
            ],
        ],
    ],
]);
if (isset($trendResult['aggregations']['daily']['buckets'])) {
    foreach ($trendResult['aggregations']['daily']['buckets'] as $bucket) {
        $trendLabels[] = $bucket['key_as_string'];
        $trendData[]   = $bucket['doc_count'];
    }
}

// Tier distribution
$tierCounts = [0, 0, 0, 0]; // Monitor, Investigate, Escalate, Critical
$tierResult = es_search(ES_INDEX_CASES, [
    'size' => 0,
    'aggs' => [
        'tiers' => ['terms' => ['field' => 'severity_tier', 'size' => 10]],
    ],
]);
if (isset($tierResult['aggregations']['tiers']['buckets'])) {
    foreach ($tierResult['aggregations']['tiers']['buckets'] as $bucket) {
        $t = (int)$bucket['key'];
        if ($t >= 1 && $t <= 4) {
            $tierCounts[$t - 1] = $bucket['doc_count'];
        }
    }
}

// Top failure modes
$failureModes = [];
$fmResult = es_search(ES_INDEX_COMPLAINTS, [
    'size' => 0,
    'aggs' => [
        'modes' => ['terms' => ['field' => 'failure_mode.keyword', 'size' => 8]],
    ],
]);
if (isset($fmResult['aggregations']['modes']['buckets'])) {
    foreach ($fmResult['aggregations']['modes']['buckets'] as $bucket) {
        $failureModes[] = ['label' => $bucket['key'], 'count' => $bucket['doc_count']];
    }
}

// ---------------------------------------------------------------------------
// Render page
// ---------------------------------------------------------------------------
require_once __DIR__ . '/header.php';
?>

<!-- Page Header -->
<div class="vt-page-header">
    <h1 class="vt-page-title">Dashboard</h1>
    <div class="d-flex gap-2">
        <a href="ingest_upload.php" class="btn btn-vt-primary btn-sm">
            <i class="fas fa-plus me-1"></i> Ingest Data
        </a>
    </div>
</div>

<!-- KPI Stat Cards -->
<div class="row g-3 mb-4">
    <!-- Total Complaints -->
    <div class="col-6 col-lg-3">
        <div class="vt-stat-card">
            <div class="d-flex align-items-start justify-content-between">
                <div>
                    <div class="vt-stat-value"><?= number_format($complaintCount) ?></div>
                    <div class="vt-stat-label">Total Complaints</div>
                </div>
                <div class="vt-stat-icon" style="background: var(--vt-primary);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>
        </div>
    </div>
    <!-- Active Cases -->
    <div class="col-6 col-lg-3">
        <div class="vt-stat-card">
            <div class="d-flex align-items-start justify-content-between">
                <div>
                    <div class="vt-stat-value"><?= number_format($activeCases) ?></div>
                    <div class="vt-stat-label">Active Cases</div>
                </div>
                <div class="vt-stat-icon" style="background: var(--vt-tier-investigate);">
                    <i class="fas fa-folder-open"></i>
                </div>
            </div>
        </div>
    </div>
    <!-- Critical Alerts -->
    <div class="col-6 col-lg-3">
        <div class="vt-stat-card">
            <div class="d-flex align-items-start justify-content-between">
                <div>
                    <div class="vt-stat-value"><?= number_format($criticalCases) ?></div>
                    <div class="vt-stat-label">Critical Alerts</div>
                </div>
                <div class="vt-stat-icon" style="background: var(--vt-tier-critical);">
                    <i class="fas fa-bolt"></i>
                </div>
            </div>
        </div>
    </div>
    <!-- Injury Reports -->
    <div class="col-6 col-lg-3">
        <div class="vt-stat-card">
            <div class="d-flex align-items-start justify-content-between">
                <div>
                    <div class="vt-stat-value"><?= number_format($injuryCount) ?></div>
                    <div class="vt-stat-label">Injury Reports</div>
                </div>
                <div class="vt-stat-icon" style="background: var(--vt-tier-escalate);">
                    <i class="fas fa-user-injured"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
    <!-- Complaint Trend Chart -->
    <div class="col-lg-8">
        <div class="vt-card">
            <div class="vt-card-header">
                <span><i class="fas fa-chart-line me-2 text-primary"></i>Complaint Trend (14 Days)</span>
            </div>
            <div class="vt-card-body" style="height: 280px;">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>
    <!-- Severity Distribution -->
    <div class="col-lg-4">
        <div class="vt-card">
            <div class="vt-card-header">
                <span><i class="fas fa-chart-pie me-2 text-primary"></i>Case Severity</span>
            </div>
            <div class="vt-card-body" style="height: 280px;">
                <canvas id="tierChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Bottom Row: Recent Cases + Top Failure Modes -->
<div class="row g-3">
    <!-- Recent Cases -->
    <div class="col-lg-8">
        <div class="vt-card">
            <div class="vt-card-header">
                <span><i class="fas fa-folder-open me-2 text-primary"></i>Recent Cases</span>
                <a href="case_list.php" class="btn btn-sm btn-vt-outline">View All</a>
            </div>
            <div class="vt-card-body p-0">
                <?php if (empty($recentCases)): ?>
                    <div class="vt-empty">
                        <i class="fas fa-inbox"></i>
                        <p>No cases yet. Ingest data and run the scanner.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="vt-table vt-table-mobile">
                            <thead>
                                <tr>
                                    <th>Case</th>
                                    <th>Product</th>
                                    <th>Tier</th>
                                    <th>Status</th>
                                    <th>Complaints</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($recentCases, 0, 5) as $case): ?>
                                    <tr style="cursor:pointer;" onclick="window.location='case_view.php?id=<?= urlencode($case['_id']) ?>'">
                                        <td data-label="Case">
                                            <strong><?= htmlspecialchars($case['title'] ?? 'Untitled') ?></strong>
                                        </td>
                                        <td data-label="Product">
                                            <code><?= htmlspecialchars($case['product_sku'] ?? '—') ?></code>
                                        </td>
                                        <td data-label="Tier">
                                            <span class="vt-tier vt-tier-<?= (int)($case['severity_tier'] ?? 1) ?>">
                                                <?= tier_label((int)($case['severity_tier'] ?? 1)) ?>
                                            </span>
                                        </td>
                                        <td data-label="Status">
                                            <span class="vt-status vt-status-<?= htmlspecialchars($case['status'] ?? 'open') ?>">
                                                <?= ucfirst(htmlspecialchars($case['status'] ?? 'open')) ?>
                                            </span>
                                        </td>
                                        <td data-label="Complaints">
                                            <?= (int)($case['complaint_count'] ?? 0) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top Failure Modes -->
    <div class="col-lg-4">
        <div class="vt-card">
            <div class="vt-card-header">
                <span><i class="fas fa-bug me-2 text-primary"></i>Top Failure Modes</span>
            </div>
            <div class="vt-card-body" style="height: 300px;">
                <?php if (empty($failureModes)): ?>
                    <div class="vt-empty">
                        <i class="fas fa-inbox"></i>
                        <p>No data yet.</p>
                    </div>
                <?php else: ?>
                    <canvas id="failureChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Chart initialization scripts -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Complaint trend line chart
        createTrendChart(
            'trendChart',
            <?= json_encode($trendLabels) ?>,
            <?= json_encode($trendData) ?>,
            'Complaints'
        );

        // Severity tier doughnut chart
        createDistributionChart(
            'tierChart',
            ['Monitor', 'Investigate', 'Escalate', 'Critical'],
            <?= json_encode($tierCounts) ?>
        );

        // Failure modes bar chart
        <?php if (!empty($failureModes)): ?>
        createBarChart(
            'failureChart',
            <?= json_encode(array_column($failureModes, 'label')) ?>,
            <?= json_encode(array_column($failureModes, 'count')) ?>,
            'Complaints'
        );
        <?php endif; ?>
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
