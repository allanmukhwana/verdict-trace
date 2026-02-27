<?php
/**
 * =============================================================================
 * VerdictTrace - Data Ingestion: Upload
 * =============================================================================
 * Upload CSV files or manually enter complaint data for ingestion into
 * Elasticsearch. Supports:
 * - CSV file upload (drag & drop or file picker)
 * - Manual single-complaint entry form
 * - Ingestion history log
 * =============================================================================
 */

$pageTitle = 'Ingest Data';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/es.php';
require_once __DIR__ . '/llm.php';

$flashMsg  = '';
$flashType = '';

// ---------------------------------------------------------------------------
// Handle POST: Manual complaint entry
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_submit'])) {
    $title         = trim($_POST['title'] ?? '');
    $complaintText = trim($_POST['complaint_text'] ?? '');
    $productSku    = trim($_POST['product_sku'] ?? '');
    $productName   = trim($_POST['product_name'] ?? '');
    $source        = trim($_POST['source'] ?? 'manual');
    $customerName  = trim($_POST['customer_name'] ?? '');
    $location      = trim($_POST['location'] ?? '');

    if ($complaintText === '') {
        $flashMsg  = 'Complaint text is required.';
        $flashType = 'danger';
    } else {
        // Use LLM to extract entities from the complaint text
        $entities = [];
        if (LLM_API_KEY !== '') {
            $entities = llm_extract_entities($complaintText);
        }

        // Build document for Elasticsearch
        $doc = [
            'title'             => $title ?: ($entities['summary'] ?? 'Untitled'),
            'complaint_text'    => $complaintText,
            'description'       => $complaintText,
            'source'            => $source,
            'product_sku'       => $productSku ?: ($entities['product_sku'] ?? ''),
            'product_name'      => $productName ?: ($entities['product_name'] ?? ''),
            'failure_mode'      => $entities['failure_mode'] ?? 'unknown',
            'severity_keywords' => $entities['severity_keywords'] ?? [],
            'injury_mentioned'  => $entities['injury_mentioned'] ?? false,
            'location'          => $location ?: ($entities['location'] ?? ''),
            'geo_region'        => $entities['geo_region'] ?? '',
            'customer_name'     => $customerName,
            'summary'           => $entities['summary'] ?? $title,
            'created_at'        => date('c'),
            'ingested_at'       => date('c'),
        ];

        // Generate embedding if LLM is configured
        if (LLM_API_KEY !== '') {
            $embedding = llm_embed($complaintText);
            if (!empty($embedding)) {
                $doc['embedding'] = $embedding;
            }
        }

        // Index into Elasticsearch
        $result = es_index_doc(ES_INDEX_COMPLAINTS, null, $doc);
        if (isset($result['result']) && in_array($result['result'], ['created', 'updated'])) {
            $flashMsg  = 'Complaint ingested successfully.';
            $flashType = 'success';
        } else {
            $flashMsg  = 'Ingestion failed: ' . json_encode($result['error'] ?? 'Unknown error');
            $flashType = 'danger';
        }
    }
}

// ---------------------------------------------------------------------------
// Handle POST: CSV file upload
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $flashMsg  = 'File upload error.';
        $flashType = 'danger';
    } elseif (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
        $flashMsg  = 'Only CSV files are accepted.';
        $flashType = 'danger';
    } else {
        // Create ingestion log entry
        $sourceId = db_insert(
            "INSERT INTO ingest_sources (name, type) VALUES (:name, 'csv')",
            [':name' => 'CSV: ' . $file['name']]
        );
        $logId = db_insert(
            "INSERT INTO ingest_log (source_id) VALUES (:sid)",
            [':sid' => $sourceId]
        );

        // Parse CSV
        $handle  = fopen($file['tmp_name'], 'r');
        $headers = fgetcsv($handle);
        $headers = array_map('strtolower', array_map('trim', $headers));

        $docsAdded  = 0;
        $docsFailed = 0;
        $bulkDocs   = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            if (!$data) { $docsFailed++; continue; }

            $text = $data['complaint_text'] ?? $data['description'] ?? $data['text'] ?? '';
            if ($text === '') { $docsFailed++; continue; }

            // Build document
            $doc = [
                'title'           => $data['title'] ?? '',
                'complaint_text'  => $text,
                'description'     => $text,
                'source'          => $data['source'] ?? 'csv',
                'product_sku'     => $data['product_sku'] ?? $data['sku'] ?? '',
                'product_name'    => $data['product_name'] ?? $data['product'] ?? '',
                'failure_mode'    => $data['failure_mode'] ?? 'unknown',
                'injury_mentioned'=> in_array(strtolower($data['injury_mentioned'] ?? $data['injury'] ?? ''), ['true', '1', 'yes']),
                'location'        => $data['location'] ?? '',
                'geo_region'      => $data['geo_region'] ?? $data['region'] ?? '',
                'customer_name'   => $data['customer_name'] ?? $data['customer'] ?? '',
                'summary'         => $data['summary'] ?? '',
                'created_at'      => $data['created_at'] ?? $data['date'] ?? date('c'),
                'ingested_at'     => date('c'),
            ];

            $bulkDocs[] = $doc;

            // Bulk index in batches of 50
            if (count($bulkDocs) >= 50) {
                $bulkResult = es_bulk_index(ES_INDEX_COMPLAINTS, $bulkDocs);
                $docsAdded += count($bulkDocs);
                $bulkDocs = [];
            }
        }

        // Index remaining docs
        if (!empty($bulkDocs)) {
            es_bulk_index(ES_INDEX_COMPLAINTS, $bulkDocs);
            $docsAdded += count($bulkDocs);
        }

        fclose($handle);

        // Update log
        db_execute("UPDATE ingest_log SET docs_added = :added, docs_failed = :failed, status = 'completed', finished_at = NOW() WHERE id = :id", [
            ':added'  => $docsAdded,
            ':failed' => $docsFailed,
            ':id'     => $logId,
        ]);
        db_execute("UPDATE ingest_sources SET last_run_at = NOW(), doc_count = doc_count + :cnt WHERE id = :id", [
            ':cnt' => $docsAdded,
            ':id'  => $sourceId,
        ]);

        $flashMsg  = "CSV imported: $docsAdded documents added, $docsFailed failed.";
        $flashType = $docsFailed > 0 ? 'warning' : 'success';
    }
}

// ---------------------------------------------------------------------------
// Fetch recent ingestion history
// ---------------------------------------------------------------------------
$ingestHistory = [];
try {
    $ingestHistory = db_select(
        "SELECT il.*, iss.name AS source_name FROM ingest_log il JOIN ingest_sources iss ON il.source_id = iss.id ORDER BY il.started_at DESC LIMIT 10"
    );
} catch (Exception $e) {
    // Table may not exist yet
}

require_once __DIR__ . '/header.php';
?>

<!-- Flash message -->
<?php if ($flashMsg): ?>
    <div class="alert alert-<?= $flashType ?> alert-dismissible fade show"><?= htmlspecialchars($flashMsg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- Page Header -->
<div class="vt-page-header">
    <h1 class="vt-page-title">Ingest Data</h1>
</div>

<div class="row g-4">
    <!-- CSV Upload -->
    <div class="col-lg-6">
        <div class="vt-card">
            <div class="vt-card-header">
                <span><i class="fas fa-file-csv me-2"></i>Upload CSV File</span>
            </div>
            <div class="vt-card-body">
                <form method="post" enctype="multipart/form-data" id="csvForm">
                    <div class="vt-dropzone" id="csvDropzone">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p class="mb-1"><strong>Drag & drop CSV file here</strong></p>
                        <p>or click to browse</p>
                        <input type="file" name="csv_file" id="csvFileInput" accept=".csv" style="display:none;">
                    </div>
                    <div id="csvFileName" class="mt-2 text-muted small" style="display:none;"></div>
                    <button type="submit" class="btn btn-vt-primary w-100 mt-3" id="csvSubmitBtn" disabled>
                        <i class="fas fa-upload me-1"></i> Upload & Ingest
                    </button>
                </form>

                <div class="mt-3 p-3 rounded" style="background:var(--vt-bg-light);font-size:0.8rem;">
                    <strong>Expected CSV columns:</strong><br>
                    <code>title, complaint_text, source, product_sku, product_name, failure_mode, injury_mentioned, location, geo_region, customer_name, created_at</code>
                </div>
            </div>
        </div>
    </div>

    <!-- Manual Entry -->
    <div class="col-lg-6">
        <div class="vt-card">
            <div class="vt-card-header">
                <span><i class="fas fa-pen me-2"></i>Manual Entry</span>
            </div>
            <div class="vt-card-body">
                <form method="post">
                    <input type="hidden" name="manual_submit" value="1">
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control" placeholder="Brief complaint title">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Complaint Text <span class="text-danger">*</span></label>
                        <textarea name="complaint_text" class="form-control" rows="4" placeholder="Full complaint or support ticket text..." required></textarea>
                    </div>
                    <div class="row g-2">
                        <div class="col-6 mb-3">
                            <label class="form-label">Product SKU</label>
                            <input type="text" name="product_sku" class="form-control" placeholder="e.g., PC-X200">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Product Name</label>
                            <input type="text" name="product_name" class="form-control" placeholder="e.g., PowerCell X200">
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-6 mb-3">
                            <label class="form-label">Source</label>
                            <select name="source" class="form-select">
                                <option value="manual">Manual Entry</option>
                                <option value="support_ticket">Support Ticket</option>
                                <option value="email">Email</option>
                                <option value="return_note">Return Note</option>
                                <option value="repair_log">Repair Log</option>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" name="location" class="form-control" placeholder="City, Country">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Customer Name</label>
                        <input type="text" name="customer_name" class="form-control" placeholder="Optional">
                    </div>
                    <button type="submit" class="btn btn-vt-primary w-100">
                        <i class="fas fa-plus me-1"></i> Ingest Complaint
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Ingestion History -->
<div class="vt-card mt-4">
    <div class="vt-card-header">
        <span><i class="fas fa-history me-2"></i>Ingestion History</span>
    </div>
    <div class="vt-card-body p-0">
        <?php if (empty($ingestHistory)): ?>
            <div class="vt-empty py-4">
                <i class="fas fa-inbox"></i>
                <p>No ingestion history yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="vt-table vt-table-mobile">
                    <thead>
                        <tr>
                            <th>Source</th>
                            <th>Added</th>
                            <th>Failed</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ingestHistory as $log): ?>
                            <tr>
                                <td data-label="Source"><?= htmlspecialchars($log['source_name']) ?></td>
                                <td data-label="Added"><span class="text-success fw-bold"><?= (int)$log['docs_added'] ?></span></td>
                                <td data-label="Failed"><span class="<?= $log['docs_failed'] > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= (int)$log['docs_failed'] ?></span></td>
                                <td data-label="Status">
                                    <span class="badge <?= $log['status'] === 'completed' ? 'bg-success' : ($log['status'] === 'failed' ? 'bg-danger' : 'bg-warning') ?>">
                                        <?= ucfirst($log['status']) ?>
                                    </span>
                                </td>
                                <td data-label="Date"><small class="text-muted"><?= htmlspecialchars($log['started_at']) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    $(document).ready(function () {
        // Initialize CSV dropzone
        initDropzone('csvDropzone', 'csvFileInput');

        // Show file name and enable submit on file selection
        $('#csvFileInput').on('change', function () {
            var name = this.files[0] ? this.files[0].name : '';
            if (name) {
                $('#csvFileName').text('Selected: ' + name).show();
                $('#csvSubmitBtn').prop('disabled', false);
            }
        });
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
