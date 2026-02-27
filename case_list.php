<?php
/**
 * =============================================================================
 * VerdictTrace - Case List
 * =============================================================================
 * Displays all investigation cases with filtering by tier, status, and search.
 * Cases are fetched from the Elasticsearch verdictrace_cases index.
 * =============================================================================
 */

$pageTitle = 'Cases';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/es.php';

// ---------------------------------------------------------------------------
// Filters from query string
// ---------------------------------------------------------------------------
$filterTier   = isset($_GET['tier'])   ? (int)$_GET['tier']           : 0;
$filterStatus = isset($_GET['status']) ? trim($_GET['status'])        : '';
$searchQuery  = isset($_GET['q'])      ? trim($_GET['q'])             : '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$from         = ($page - 1) * $perPage;

// ---------------------------------------------------------------------------
// Build Elasticsearch query
// ---------------------------------------------------------------------------
$must   = [];
$filter = [];

// Text search
if ($searchQuery !== '') {
    $must[] = [
        'multi_match' => [
            'query'  => $searchQuery,
            'fields' => ['title', 'product_sku', 'product_name', 'failure_mode', 'narrative'],
        ],
    ];
}

// Tier filter
if ($filterTier > 0) {
    $filter[] = ['term' => ['severity_tier' => $filterTier]];
}

// Status filter
if ($filterStatus !== '') {
    $filter[] = ['term' => ['status' => $filterStatus]];
}

$esQuery = ['size' => $perPage, 'from' => $from, 'sort' => [['updated_at' => 'desc']]];
if (!empty($must) || !empty($filter)) {
    $bool = [];
    if (!empty($must))   $bool['must']   = $must;
    if (!empty($filter)) $bool['filter'] = $filter;
    $esQuery['query'] = ['bool' => $bool];
} else {
    $esQuery['query'] = ['match_all' => (object)[]];
}

$result   = es_search(ES_INDEX_CASES, $esQuery);
$cases    = [];
$totalHits = 0;

if (isset($result['hits'])) {
    $totalHits = $result['hits']['total']['value'] ?? 0;
    foreach ($result['hits']['hits'] as $hit) {
        $src = $hit['_source'];
        $src['_id'] = $hit['_id'];
        $cases[] = $src;
    }
}

$totalPages = max(1, ceil($totalHits / $perPage));

require_once __DIR__ . '/header.php';
?>

<!-- Page Header -->
<div class="vt-page-header">
    <h1 class="vt-page-title">Investigation Cases</h1>
    <span class="text-muted"><?= number_format($totalHits) ?> case<?= $totalHits !== 1 ? 's' : '' ?></span>
</div>

<!-- Filters -->
<div class="row g-2 mb-4">
    <div class="col-12 col-md-4">
        <div class="vt-search">
            <i class="fas fa-search vt-search-icon"></i>
            <form method="get">
                <!-- Preserve existing filters -->
                <?php if ($filterTier): ?><input type="hidden" name="tier" value="<?= $filterTier ?>"><?php endif; ?>
                <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>"><?php endif; ?>
                <input type="text" name="q" class="form-control" placeholder="Search cases..." value="<?= htmlspecialchars($searchQuery) ?>">
            </form>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <select class="form-select" onchange="applyFilter('tier', this.value)">
            <option value="">All Tiers</option>
            <option value="1" <?= $filterTier === 1 ? 'selected' : '' ?>>Monitor</option>
            <option value="2" <?= $filterTier === 2 ? 'selected' : '' ?>>Investigate</option>
            <option value="3" <?= $filterTier === 3 ? 'selected' : '' ?>>Escalate</option>
            <option value="4" <?= $filterTier === 4 ? 'selected' : '' ?>>Critical</option>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <select class="form-select" onchange="applyFilter('status', this.value)">
            <option value="">All Statuses</option>
            <option value="open" <?= $filterStatus === 'open' ? 'selected' : '' ?>>Open</option>
            <option value="investigating" <?= $filterStatus === 'investigating' ? 'selected' : '' ?>>Investigating</option>
            <option value="escalated" <?= $filterStatus === 'escalated' ? 'selected' : '' ?>>Escalated</option>
            <option value="resolved" <?= $filterStatus === 'resolved' ? 'selected' : '' ?>>Resolved</option>
            <option value="dismissed" <?= $filterStatus === 'dismissed' ? 'selected' : '' ?>>Dismissed</option>
        </select>
    </div>
</div>

<!-- Case List -->
<div class="vt-card">
    <div class="vt-card-body p-0">
        <?php if (empty($cases)): ?>
            <div class="vt-empty">
                <i class="fas fa-folder-open"></i>
                <p>No cases found matching your filters.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="vt-table vt-table-mobile">
                    <thead>
                        <tr>
                            <th>Case</th>
                            <th>Product</th>
                            <th>Failure Mode</th>
                            <th>Tier</th>
                            <th>Status</th>
                            <th>Complaints</th>
                            <th>Injuries</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $case): ?>
                            <tr style="cursor:pointer;" onclick="window.location='case_view.php?id=<?= urlencode($case['_id']) ?>'">
                                <td data-label="Case">
                                    <strong><?= htmlspecialchars($case['title'] ?? 'Untitled') ?></strong>
                                </td>
                                <td data-label="Product">
                                    <code><?= htmlspecialchars($case['product_sku'] ?? '—') ?></code>
                                </td>
                                <td data-label="Failure Mode">
                                    <?= htmlspecialchars($case['failure_mode'] ?? '—') ?>
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
                                <td data-label="Injuries">
                                    <?php $ic = (int)($case['injury_count'] ?? 0); ?>
                                    <?= $ic > 0 ? '<span class="text-danger fw-bold">' . $ic . '</span>' : '0' ?>
                                </td>
                                <td data-label="Updated">
                                    <small class="text-muted"><?= htmlspecialchars(substr($case['updated_at'] ?? '', 0, 10)) ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-center py-3">
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $p ?>&tier=<?= $filterTier ?>&status=<?= urlencode($filterStatus) ?>&q=<?= urlencode($searchQuery) ?>">
                                        <?= $p ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    /* Apply a filter by updating the URL query parameter */
    function applyFilter(key, value) {
        var params = new URLSearchParams(window.location.search);
        if (value) {
            params.set(key, value);
        } else {
            params.delete(key);
        }
        params.delete('page'); // Reset to page 1
        window.location.search = params.toString();
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
