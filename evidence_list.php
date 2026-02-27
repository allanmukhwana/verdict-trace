<?php
/**
 * =============================================================================
 * VerdictTrace - Evidence Pack List
 * =============================================================================
 * Displays all generated Evidence Packs. Each pack is linked to a case
 * and contains the traceable DSL queries, exemplar docs, trend data,
 * and narrative that constitute the investigation record.
 * =============================================================================
 */

$pageTitle = 'Evidence Packs';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/es.php';

// ---------------------------------------------------------------------------
// Fetch cases that have evidence packs (cases with exemplar_ids + narrative)
// ---------------------------------------------------------------------------
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$from    = ($page - 1) * $perPage;

$esQuery = [
    'size' => $perPage,
    'from' => $from,
    'sort' => [['updated_at' => 'desc']],
    'query' => [
        'bool' => [
            'must' => [
                ['exists' => ['field' => 'narrative']],
                ['exists' => ['field' => 'exemplar_ids']],
            ],
        ],
    ],
];

$result    = es_search(ES_INDEX_CASES, $esQuery);
$packs     = [];
$totalHits = 0;

if (isset($result['hits'])) {
    $totalHits = $result['hits']['total']['value'] ?? 0;
    foreach ($result['hits']['hits'] as $hit) {
        $src = $hit['_source'];
        $src['_id'] = $hit['_id'];
        $packs[] = $src;
    }
}

$totalPages = max(1, ceil($totalHits / $perPage));

require_once __DIR__ . '/header.php';
?>

<!-- Page Header -->
<div class="vt-page-header">
    <h1 class="vt-page-title">Evidence Packs</h1>
    <span class="text-muted"><?= number_format($totalHits) ?> pack<?= $totalHits !== 1 ? 's' : '' ?></span>
</div>

<?php if (empty($packs)): ?>
    <div class="vt-card">
        <div class="vt-empty">
            <i class="fas fa-file-alt"></i>
            <p>No evidence packs generated yet.<br>Run the <a href="scan.php">cluster scanner</a> to detect signals and generate evidence.</p>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($packs as $pack): ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="vt-card h-100" style="cursor:pointer;" onclick="window.location='case_view.php?id=<?= urlencode($pack['_id']) ?>'">
                    <div class="vt-card-body">
                        <!-- Tier & Status badges -->
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="vt-tier vt-tier-<?= (int)($pack['severity_tier'] ?? 1) ?>">
                                <?= tier_label((int)($pack['severity_tier'] ?? 1)) ?>
                            </span>
                            <span class="vt-status vt-status-<?= htmlspecialchars($pack['status'] ?? 'open') ?>">
                                <?= ucfirst(htmlspecialchars($pack['status'] ?? 'open')) ?>
                            </span>
                        </div>

                        <!-- Title -->
                        <h6 class="fw-bold mb-2"><?= htmlspecialchars($pack['title'] ?? 'Untitled') ?></h6>

                        <!-- Product info -->
                        <div class="mb-2">
                            <code class="me-2"><?= htmlspecialchars($pack['product_sku'] ?? '') ?></code>
                            <small class="text-muted"><?= htmlspecialchars($pack['failure_mode'] ?? '') ?></small>
                        </div>

                        <!-- Stats row -->
                        <div class="d-flex gap-3 mb-3" style="font-size:0.8rem;">
                            <span><i class="fas fa-file-alt text-primary me-1"></i><?= (int)($pack['complaint_count'] ?? 0) ?> complaints</span>
                            <?php if (((int)($pack['injury_count'] ?? 0)) > 0): ?>
                                <span class="text-danger"><i class="fas fa-user-injured me-1"></i><?= (int)$pack['injury_count'] ?> injuries</span>
                            <?php endif; ?>
                            <span><i class="fas fa-globe text-muted me-1"></i><?= count($pack['geo_regions'] ?? []) ?> regions</span>
                        </div>

                        <!-- Narrative excerpt -->
                        <p class="text-muted mb-0" style="font-size:0.825rem;line-height:1.5;">
                            <?= htmlspecialchars(mb_substr($pack['narrative'] ?? '', 0, 150)) ?>
                            <?= mb_strlen($pack['narrative'] ?? '') > 150 ? '...' : '' ?>
                        </p>

                        <!-- Confidence score -->
                        <div class="mt-3 pt-2 border-top d-flex justify-content-between align-items-center">
                            <small class="text-muted">Confidence</small>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress" style="width:80px;height:6px;">
                                    <div class="progress-bar" style="width:<?= ((float)($pack['confidence_score'] ?? 0)) * 100 ?>%;background:var(--vt-primary);"></div>
                                </div>
                                <small class="fw-bold"><?= number_format((float)($pack['confidence_score'] ?? 0), 2) ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="d-flex justify-content-center mt-4">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
