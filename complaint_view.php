<?php
/**
 * =============================================================================
 * VerdictTrace - Complaint Detail View
 * =============================================================================
 * Displays a single complaint document from Elasticsearch with all
 * extracted entities, metadata, and the full complaint text.
 * =============================================================================
 */

$pageTitle = 'Complaint Detail';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/es.php';

// ---------------------------------------------------------------------------
// Get complaint by ID
// ---------------------------------------------------------------------------
$docId = trim($_GET['id'] ?? '');
if ($docId === '') {
    header('Location: complaint_list.php');
    exit;
}

$result = es_get_doc(ES_INDEX_COMPLAINTS, $docId);
if (!isset($result['_source'])) {
    require_once __DIR__ . '/header.php';
    echo '<div class="vt-empty"><i class="fas fa-search"></i><p>Complaint not found.</p></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$c = $result['_source'];
$c['_id'] = $docId;

require_once __DIR__ . '/header.php';
?>

<!-- Page Header -->
<div class="vt-page-header">
    <div class="d-flex align-items-center gap-3">
        <a href="complaint_list.php" class="btn btn-light btn-sm rounded-circle"><i class="fas fa-arrow-left"></i></a>
        <div>
            <h1 class="vt-page-title mb-0"><?= htmlspecialchars($c['title'] ?? 'Untitled Complaint') ?></h1>
            <small class="text-muted">ID: <?= htmlspecialchars($docId) ?></small>
        </div>
    </div>
    <?php if (!empty($c['injury_mentioned'])): ?>
        <span class="badge bg-danger"><i class="fas fa-exclamation-circle me-1"></i>Injury Reported</span>
    <?php endif; ?>
</div>

<div class="row g-3">
    <!-- Main Content -->
    <div class="col-lg-8">
        <!-- Full Complaint Text -->
        <div class="vt-card mb-3">
            <div class="vt-card-header">
                <span><i class="fas fa-file-text me-2"></i>Complaint Text</span>
            </div>
            <div class="vt-card-body">
                <p style="line-height:1.8;white-space:pre-wrap;"><?= htmlspecialchars($c['complaint_text'] ?? 'No text available.') ?></p>
            </div>
        </div>

        <!-- Summary -->
        <?php if (!empty($c['summary'])): ?>
        <div class="vt-card mb-3">
            <div class="vt-card-header">
                <span><i class="fas fa-align-left me-2"></i>AI-Extracted Summary</span>
            </div>
            <div class="vt-card-body">
                <p class="mb-0"><?= htmlspecialchars($c['summary']) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Severity Keywords -->
        <?php if (!empty($c['severity_keywords'])): ?>
        <div class="vt-card">
            <div class="vt-card-header">
                <span><i class="fas fa-tags me-2"></i>Severity Keywords</span>
            </div>
            <div class="vt-card-body">
                <?php foreach ((array)$c['severity_keywords'] as $kw): ?>
                    <span class="badge bg-warning text-dark me-1 mb-1"><?= htmlspecialchars($kw) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar: Metadata -->
    <div class="col-lg-4">
        <div class="vt-card">
            <div class="vt-card-header">
                <span><i class="fas fa-info-circle me-2"></i>Details</span>
            </div>
            <div class="vt-card-body">
                <table class="w-100" style="font-size:0.875rem;">
                    <tr>
                        <td class="text-muted py-2" style="width:120px;">Product SKU</td>
                        <td class="py-2 fw-bold"><code><?= htmlspecialchars($c['product_sku'] ?? '—') ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Product Name</td>
                        <td class="py-2 fw-bold"><?= htmlspecialchars($c['product_name'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Failure Mode</td>
                        <td class="py-2 fw-bold"><?= htmlspecialchars($c['failure_mode'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Source</td>
                        <td class="py-2">
                            <span class="badge bg-light text-dark"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($c['source'] ?? '—'))) ?></span>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Injury</td>
                        <td class="py-2">
                            <?= !empty($c['injury_mentioned'])
                                ? '<span class="text-danger fw-bold">Yes</span>'
                                : '<span class="text-muted">No</span>' ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Location</td>
                        <td class="py-2"><?= htmlspecialchars($c['location'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Region</td>
                        <td class="py-2"><?= htmlspecialchars($c['geo_region'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Customer</td>
                        <td class="py-2"><?= htmlspecialchars($c['customer_name'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Created</td>
                        <td class="py-2"><?= htmlspecialchars(substr($c['created_at'] ?? '', 0, 16)) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Ingested</td>
                        <td class="py-2"><?= htmlspecialchars(substr($c['ingested_at'] ?? '', 0, 16)) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
