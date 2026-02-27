<?php
/**
 * =============================================================================
 * VerdictTrace - Complaint List
 * =============================================================================
 * Browse and search all ingested complaints from Elasticsearch.
 * Supports keyword search, source filtering, and pagination.
 * =============================================================================
 */

$pageTitle = 'Complaints';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/es.php';

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$searchQuery  = trim($_GET['q'] ?? '');
$filterSource = trim($_GET['source'] ?? '');
$filterInjury = isset($_GET['injury']) ? $_GET['injury'] : '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$from         = ($page - 1) * $perPage;

// ---------------------------------------------------------------------------
// Build Elasticsearch query
// ---------------------------------------------------------------------------
$must   = [];
$filter = [];

if ($searchQuery !== '') {
    $must[] = [
        'multi_match' => [
            'query'  => $searchQuery,
            'fields' => ['title^2', 'complaint_text', 'product_sku', 'product_name', 'failure_mode', 'summary'],
        ],
    ];
}
if ($filterSource !== '') {
    $filter[] = ['term' => ['source' => $filterSource]];
}
if ($filterInjury === '1') {
    $filter[] = ['term' => ['injury_mentioned' => true]];
}

$esQuery = ['size' => $perPage, 'from' => $from, 'sort' => [['created_at' => 'desc']]];
if (!empty($must) || !empty($filter)) {
    $bool = [];
    if (!empty($must))   $bool['must']   = $must;
    if (!empty($filter)) $bool['filter'] = $filter;
    $esQuery['query'] = ['bool' => $bool];
} else {
    $esQuery['query'] = ['match_all' => (object)[]];
}

$result     = es_search(ES_INDEX_COMPLAINTS, $esQuery);
$complaints = [];
$totalHits  = 0;

if (isset($result['hits'])) {
    $totalHits = $result['hits']['total']['value'] ?? 0;
    foreach ($result['hits']['hits'] as $hit) {
        $src = $hit['_source'];
        $src['_id'] = $hit['_id'];
        $complaints[] = $src;
    }
}

$totalPages = max(1, ceil($totalHits / $perPage));

require_once __DIR__ . '/header.php';
?>

<!-- Page Header -->
<div class="vt-page-header">
    <h1 class="vt-page-title">Complaints</h1>
    <div class="d-flex align-items-center gap-2">
        <span class="text-muted"><?= number_format($totalHits) ?> total</span>
        <a href="ingest_upload.php" class="btn btn-vt-primary btn-sm"><i class="fas fa-plus me-1"></i>Ingest</a>
    </div>
</div>

<!-- Filters -->
<div class="row g-2 mb-4">
    <div class="col-12 col-md-5">
        <div class="vt-search">
            <i class="fas fa-search vt-search-icon"></i>
            <form method="get">
                <?php if ($filterSource): ?><input type="hidden" name="source" value="<?= htmlspecialchars($filterSource) ?>"><?php endif; ?>
                <?php if ($filterInjury !== ''): ?><input type="hidden" name="injury" value="<?= htmlspecialchars($filterInjury) ?>"><?php endif; ?>
                <input type="text" name="q" class="form-control" placeholder="Search complaints..." value="<?= htmlspecialchars($searchQuery) ?>">
            </form>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <select class="form-select" onchange="applyFilter('source', this.value)">
            <option value="">All Sources</option>
            <option value="support_ticket" <?= $filterSource === 'support_ticket' ? 'selected' : '' ?>>Support Ticket</option>
            <option value="email" <?= $filterSource === 'email' ? 'selected' : '' ?>>Email</option>
            <option value="return_note" <?= $filterSource === 'return_note' ? 'selected' : '' ?>>Return Note</option>
            <option value="repair_log" <?= $filterSource === 'repair_log' ? 'selected' : '' ?>>Repair Log</option>
            <option value="csv" <?= $filterSource === 'csv' ? 'selected' : '' ?>>CSV Import</option>
            <option value="manual" <?= $filterSource === 'manual' ? 'selected' : '' ?>>Manual</option>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <select class="form-select" onchange="applyFilter('injury', this.value)">
            <option value="">All Reports</option>
            <option value="1" <?= $filterInjury === '1' ? 'selected' : '' ?>>Injury Only</option>
        </select>
    </div>
</div>

<!-- Complaint List -->
<div class="vt-card">
    <div class="vt-card-body p-0">
        <?php if (empty($complaints)): ?>
            <div class="vt-empty">
                <i class="fas fa-exclamation-triangle"></i>
                <p>No complaints found.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="vt-table vt-table-mobile">
                    <thead>
                        <tr>
                            <th>Complaint</th>
                            <th>Product</th>
                            <th>Failure Mode</th>
                            <th>Source</th>
                            <th>Injury</th>
                            <th>Location</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaints as $c): ?>
                            <tr style="cursor:pointer;" onclick="window.location='complaint_view.php?id=<?= urlencode($c['_id']) ?>'">
                                <td data-label="Complaint">
                                    <strong><?= htmlspecialchars(mb_substr($c['title'] ?? $c['summary'] ?? 'Untitled', 0, 50)) ?></strong>
                                    <?php if (mb_strlen($c['title'] ?? $c['summary'] ?? '') > 50) echo '...'; ?>
                                </td>
                                <td data-label="Product">
                                    <code><?= htmlspecialchars($c['product_sku'] ?? '—') ?></code>
                                </td>
                                <td data-label="Failure Mode">
                                    <?= htmlspecialchars($c['failure_mode'] ?? '—') ?>
                                </td>
                                <td data-label="Source">
                                    <span class="badge bg-light text-dark"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($c['source'] ?? '—'))) ?></span>
                                </td>
                                <td data-label="Injury">
                                    <?php if (!empty($c['injury_mentioned'])): ?>
                                        <span class="badge bg-danger"><i class="fas fa-exclamation-circle me-1"></i>Yes</span>
                                    <?php else: ?>
                                        <span class="text-muted">No</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Location">
                                    <small><?= htmlspecialchars($c['location'] ?? '—') ?></small>
                                </td>
                                <td data-label="Date">
                                    <small class="text-muted"><?= htmlspecialchars(substr($c['created_at'] ?? '', 0, 10)) ?></small>
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
                            <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $p ?>&q=<?= urlencode($searchQuery) ?>&source=<?= urlencode($filterSource) ?>&injury=<?= urlencode($filterInjury) ?>"><?= $p ?></a>
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
    function applyFilter(key, value) {
        var params = new URLSearchParams(window.location.search);
        if (value) { params.set(key, value); } else { params.delete(key); }
        params.delete('page');
        window.location.search = params.toString();
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
