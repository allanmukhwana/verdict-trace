<?php
/**
 * =============================================================================
 * VerdictTrace - Cluster Detection Scanner
 * =============================================================================
 * The core intelligence engine. Scans Elasticsearch complaint data to:
 * 1. Run multi-dimensional aggregation clustering
 * 2. Apply confidence gate scoring
 * 3. Generate Evidence Packs for clusters that cross the threshold
 * 4. Create or update investigation cases
 *
 * Can be run via browser (manual trigger) or CLI (cron job).
 * =============================================================================
 */

$pageTitle = 'Cluster Scanner';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/es.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/email.php';

// ---------------------------------------------------------------------------
// Load configurable thresholds from settings
// ---------------------------------------------------------------------------
$confidenceThreshold = 0.7;
$clusterMinDocs      = 5;
try {
    $row = db_select_one("SELECT value FROM settings WHERE `key` = 'confidence_threshold'");
    if ($row) $confidenceThreshold = (float)$row['value'];
    $row = db_select_one("SELECT value FROM settings WHERE `key` = 'cluster_min_docs'");
    if ($row) $clusterMinDocs = (int)$row['value'];
} catch (Exception $e) {
    // Use defaults
}

// ---------------------------------------------------------------------------
// Detect CLI vs browser
// ---------------------------------------------------------------------------
$isCli  = (php_sapi_name() === 'cli');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

// Only run scan on POST (browser) or CLI
$runScan = $isCli || $isPost;

$scanResults = [];
$scanLog     = '';

/**
 * Log a scan message.
 */
function scan_log(string $msg, string $type = 'info') {
    global $scanLog;
    $prefix = ['info' => 'ℹ', 'ok' => '✓', 'warn' => '⚠', 'err' => '✗'];
    $scanLog .= ($prefix[$type] ?? '•') . " $msg\n";
}

// ===========================================================================
// Run the scan
// ===========================================================================
if ($runScan) {
    scan_log('Starting cluster detection scan...');
    scan_log('Confidence threshold: ' . $confidenceThreshold);
    scan_log('Minimum cluster docs: ' . $clusterMinDocs);

    // Step 1: Run multi-dimensional aggregation
    scan_log('Running aggregation clustering (product × failure_mode × region)...');

    $aggResult = es_cluster_aggregation(ES_INDEX_COMPLAINTS, '1w');
    $productBuckets = $aggResult['aggregations']['by_product']['buckets'] ?? [];

    scan_log('Found ' . count($productBuckets) . ' product buckets.');

    $clustersDetected = 0;
    $casesCreated     = 0;

    foreach ($productBuckets as $productBucket) {
        $productSku    = $productBucket['key'];
        $productTotal  = $productBucket['doc_count'];
        $failureBuckets = $productBucket['by_failure_mode']['buckets'] ?? [];

        foreach ($failureBuckets as $fmBucket) {
            $failureMode  = $fmBucket['key'];
            $fmCount      = $fmBucket['doc_count'];
            $injuryCount  = $fmBucket['injury_mentions']['doc_count'] ?? 0;
            $regionBuckets = $fmBucket['by_region']['buckets'] ?? [];
            $timeBuckets   = $fmBucket['over_time']['buckets'] ?? [];

            // Skip clusters below minimum document threshold
            if ($fmCount < $clusterMinDocs) {
                continue;
            }

            $clustersDetected++;

            // Step 2: Compute confidence gate score
            $geoSpread       = count($regionBuckets);
            $injuryRate      = $fmCount > 0 ? ($injuryCount / $fmCount) : 0;
            $velocity        = computeVelocity($timeBuckets);
            $confidenceScore = computeConfidenceScore($fmCount, $injuryRate, $geoSpread, $velocity);

            scan_log("Cluster: $productSku / $failureMode — $fmCount complaints, $injuryCount injuries, $geoSpread regions, confidence=$confidenceScore");

            // Skip if below confidence threshold
            if ($confidenceScore < $confidenceThreshold) {
                scan_log("  Below threshold ($confidenceScore < $confidenceThreshold) — skipping.", 'info');
                continue;
            }

            scan_log("  Above threshold — generating Evidence Pack.", 'ok');

            // Step 3: Check if a case already exists for this cluster
            $existingCase = es_search(ES_INDEX_CASES, [
                'size' => 1,
                'query' => [
                    'bool' => [
                        'filter' => [
                            ['term' => ['product_sku' => $productSku]],
                            ['term' => ['failure_mode.keyword' => $failureMode]],
                            ['bool' => ['must_not' => [
                                ['terms' => ['status' => ['dismissed', 'resolved']]],
                            ]]],
                        ],
                    ],
                ],
            ]);

            $existingCaseId = null;
            if (!empty($existingCase['hits']['hits'])) {
                $existingCaseId = $existingCase['hits']['hits'][0]['_id'];
                scan_log("  Existing case found: $existingCaseId — updating.", 'info');
            }

            // Step 4: Fetch exemplar documents
            $exemplarResult = es_search(ES_INDEX_COMPLAINTS, [
                'size' => 5,
                'query' => [
                    'bool' => [
                        'filter' => [
                            ['term' => ['product_sku' => $productSku]],
                            ['term' => ['failure_mode.keyword' => $failureMode]],
                        ],
                    ],
                ],
                'sort' => [['created_at' => 'desc']],
            ]);

            $exemplarIds  = [];
            $exemplarData = [];
            foreach (($exemplarResult['hits']['hits'] ?? []) as $hit) {
                $exemplarIds[] = $hit['_id'];
                $exemplarData[] = [
                    'title'       => $hit['_source']['title'] ?? '',
                    'summary'     => $hit['_source']['summary'] ?? '',
                    'location'    => $hit['_source']['location'] ?? '',
                    'injury'      => $hit['_source']['injury_mentioned'] ?? false,
                ];
            }

            // Step 5: Determine severity tier
            $tier = determineTier($fmCount, $injuryCount, $geoSpread, $velocity);

            // Step 6: Generate narrative via LLM (if configured)
            $narrative = '';
            if (LLM_API_KEY !== '') {
                $clusterInfo = [
                    'product_sku'  => $productSku,
                    'failure_mode' => $failureMode,
                    'complaint_count' => $fmCount,
                    'injury_count'    => $injuryCount,
                    'injury_rate'     => round($injuryRate * 100, 1) . '%',
                    'geo_regions'     => array_column($regionBuckets, 'key'),
                    'geo_spread'      => $geoSpread,
                    'velocity'        => $velocity,
                    'confidence_score' => $confidenceScore,
                    'tier'            => tier_label($tier),
                ];
                $narrative = llm_generate_narrative($clusterInfo, $exemplarData);
            } else {
                $regions = implode(', ', array_column($regionBuckets, 'key'));
                $narrative = "Cluster detected for product $productSku with failure mode '$failureMode'. "
                    . "$fmCount complaints found across $geoSpread regions ($regions). "
                    . "$injuryCount injury reports. Confidence score: $confidenceScore.";
            }

            // Step 7: Build the DSL query record for traceability
            $dslQueries = json_encode([
                'cluster_aggregation' => [
                    'index' => ES_INDEX_COMPLAINTS,
                    'filter' => ['product_sku' => $productSku, 'failure_mode' => $failureMode],
                    'aggs' => 'by_product > by_failure_mode > over_time + by_region + injury_mentions',
                ],
                'exemplar_query' => [
                    'index' => ES_INDEX_COMPLAINTS,
                    'filter' => ['product_sku' => $productSku, 'failure_mode' => $failureMode],
                    'sort' => 'created_at desc',
                    'size' => 5,
                ],
            ]);

            // Step 8: Build trend data
            $trendData = [];
            foreach ($timeBuckets as $tb) {
                $trendData[$tb['key_as_string'] ?? date('Y-m-d', $tb['key'] / 1000)] = $tb['doc_count'];
            }

            // Step 9: Create or update the case
            $caseDoc = [
                'title'            => "$productSku — " . ucfirst($failureMode) . " Cluster",
                'product_sku'      => $productSku,
                'failure_mode'     => $failureMode,
                'severity_tier'    => $tier,
                'status'           => $tier >= TIER_ESCALATE ? 'escalated' : 'open',
                'confidence_score' => $confidenceScore,
                'complaint_count'  => $fmCount,
                'injury_count'     => $injuryCount,
                'geo_regions'      => array_column($regionBuckets, 'key'),
                'narrative'        => $narrative,
                'dsl_queries'      => $dslQueries,
                'exemplar_ids'     => $exemplarIds,
                'trend_data'       => json_encode($trendData),
                'updated_at'       => date('c'),
            ];

            if ($existingCaseId) {
                // Update existing case
                $auditEntry = [
                    'action'    => 'rescan_update',
                    'user_id'   => 'system',
                    'user_name' => 'VerdictTrace Scanner',
                    'reason'    => "Rescan detected $fmCount complaints (was previously flagged). Confidence: $confidenceScore.",
                    'timestamp' => date('c'),
                ];
                $caseDoc['audit_log'] = $auditEntry; // Will be appended via script
                es_update_doc(ES_INDEX_CASES, $existingCaseId, $caseDoc);
                scan_log("  Case $existingCaseId updated.", 'ok');
            } else {
                // Create new case
                $caseDoc['date_range_start'] = date('c', strtotime('-7 days'));
                $caseDoc['date_range_end']   = date('c');
                $caseDoc['created_at']       = date('c');
                $caseDoc['audit_log'] = [[
                    'action'    => 'created',
                    'user_id'   => 'system',
                    'user_name' => 'VerdictTrace Scanner',
                    'reason'    => "Auto-generated from cluster detection. Confidence $confidenceScore exceeded threshold $confidenceThreshold.",
                    'timestamp' => date('c'),
                ]];

                $createResult = es_index_doc(ES_INDEX_CASES, null, $caseDoc);
                $newCaseId = $createResult['_id'] ?? 'unknown';
                scan_log("  New case created: $newCaseId", 'ok');
                $casesCreated++;

                // Send notification emails for new cases
                try {
                    $users = db_select("SELECT name, email FROM users WHERE notify_email = 1");
                    foreach ($users as $user) {
                        email_escalation_alert(
                            $user['email'], $user['name'], $newCaseId, $tier,
                            $productSku, $failureMode, substr($narrative, 0, 200)
                        );
                    }
                } catch (Exception $e) {
                    scan_log("  Email notification error: " . $e->getMessage(), 'warn');
                }

                // Create in-app notification
                try {
                    db_insert("INSERT INTO notifications (case_id, type, title, message) VALUES (:cid, 'escalation', :title, :msg)", [
                        ':cid'   => $newCaseId,
                        ':title' => "New case: $productSku — $failureMode",
                        ':msg'   => "Cluster detected with $fmCount complaints, $injuryCount injuries. Tier: " . tier_label($tier),
                    ]);
                } catch (Exception $e) {
                    // Silently ignore
                }
            }

            $scanResults[] = [
                'product'    => $productSku,
                'failure'    => $failureMode,
                'count'      => $fmCount,
                'injuries'   => $injuryCount,
                'confidence' => $confidenceScore,
                'tier'       => tier_label($tier),
                'action'     => $existingCaseId ? 'updated' : 'created',
            ];
        }
    }

    scan_log("Scan complete. $clustersDetected clusters detected, $casesCreated new cases created.", 'ok');
}

// ===========================================================================
// Scoring Functions
// ===========================================================================

/**
 * Compute complaint velocity (rate of increase) from time buckets.
 *
 * @param array $timeBuckets Date histogram buckets
 * @return float             Velocity score (0.0 - 1.0)
 */
function computeVelocity(array $timeBuckets): float {
    if (count($timeBuckets) < 2) return 0.0;

    $counts = array_column($timeBuckets, 'doc_count');
    $recent = array_slice($counts, -2);
    $older  = array_slice($counts, 0, -2);

    $recentAvg = count($recent) > 0 ? array_sum($recent) / count($recent) : 0;
    $olderAvg  = count($older) > 0 ? array_sum($older) / count($older) : 0;

    if ($olderAvg == 0) return $recentAvg > 0 ? 1.0 : 0.0;
    $ratio = ($recentAvg - $olderAvg) / $olderAvg;
    return max(0.0, min(1.0, $ratio));
}

/**
 * Compute the confidence gate score.
 * Weighted combination of: volume, injury rate, geographic spread, velocity.
 *
 * @param int   $count      Number of complaints
 * @param float $injuryRate Proportion of complaints mentioning injury
 * @param int   $geoSpread  Number of distinct geographic regions
 * @param float $velocity   Rate of increase
 * @return float            Confidence score (0.0 - 1.0)
 */
function computeConfidenceScore(int $count, float $injuryRate, int $geoSpread, float $velocity): float {
    // Normalize volume (log scale, cap at ~50 complaints)
    $volumeScore = min(1.0, log($count + 1) / log(50));

    // Injury rate is already 0-1
    $injuryScore = $injuryRate;

    // Geographic spread normalized (cap at 5 regions)
    $geoScore = min(1.0, $geoSpread / 5);

    // Velocity is already 0-1

    // Weighted combination
    $score = ($volumeScore * 0.3) + ($injuryScore * 0.35) + ($geoScore * 0.2) + ($velocity * 0.15);
    return round(min(1.0, $score), 3);
}

/**
 * Determine severity tier based on cluster metrics.
 *
 * @param int   $count      Complaint count
 * @param int   $injuries   Injury count
 * @param int   $geoSpread  Geographic spread
 * @param float $velocity   Complaint velocity
 * @return int              Tier constant (1-4)
 */
function determineTier(int $count, int $injuries, int $geoSpread, float $velocity): int {
    // Critical: injuries + wide spread + high volume
    if ($injuries >= 3 && $geoSpread >= 3 && $count >= 20) return TIER_CRITICAL;

    // Escalate: any injuries + moderate spread
    if ($injuries >= 1 && ($geoSpread >= 2 || $count >= 10)) return TIER_ESCALATE;

    // Investigate: moderate volume or any injury
    if ($injuries >= 1 || $count >= 10 || $velocity > 0.5) return TIER_INVESTIGATE;

    // Default: Monitor
    return TIER_MONITOR;
}

// ===========================================================================
// Render Page (browser only)
// ===========================================================================
if (!$isCli) {
    require_once __DIR__ . '/header.php';
?>

<!-- Page Header -->
<div class="vt-page-header">
    <h1 class="vt-page-title"><i class="fas fa-radar me-2"></i>Cluster Scanner</h1>
</div>

<!-- Scanner Controls -->
<div class="vt-card mb-4">
    <div class="vt-card-body">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h6 class="fw-bold mb-1">Run Cluster Detection</h6>
                <p class="text-muted mb-0" style="font-size:0.875rem;">
                    Scans all complaints, detects statistically significant clusters, applies confidence gate scoring, and generates Evidence Packs for clusters that cross the threshold.
                </p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <form method="post">
                    <button type="submit" class="btn btn-vt-primary">
                        <i class="fas fa-play me-1"></i> Run Scan Now
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Scan Results -->
<?php if ($runScan): ?>
    <!-- Log Output -->
    <div class="vt-card mb-4">
        <div class="vt-card-header">
            <span><i class="fas fa-terminal me-2"></i>Scan Log</span>
        </div>
        <div class="vt-card-body">
            <pre style="font-size:0.825rem;line-height:1.7;margin:0;white-space:pre-wrap;font-family:'Outfit',monospace;"><?= htmlspecialchars($scanLog) ?></pre>
        </div>
    </div>

    <!-- Results Table -->
    <?php if (!empty($scanResults)): ?>
    <div class="vt-card">
        <div class="vt-card-header">
            <span><i class="fas fa-table me-2"></i>Detected Clusters (<?= count($scanResults) ?>)</span>
        </div>
        <div class="vt-card-body p-0">
            <div class="table-responsive">
                <table class="vt-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Failure Mode</th>
                            <th>Complaints</th>
                            <th>Injuries</th>
                            <th>Confidence</th>
                            <th>Tier</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scanResults as $sr): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($sr['product']) ?></code></td>
                                <td><?= htmlspecialchars($sr['failure']) ?></td>
                                <td><?= $sr['count'] ?></td>
                                <td>
                                    <?= $sr['injuries'] > 0 ? '<span class="text-danger fw-bold">' . $sr['injuries'] . '</span>' : '0' ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress" style="width:60px;height:5px;">
                                            <div class="progress-bar" style="width:<?= $sr['confidence'] * 100 ?>%;background:var(--vt-primary);"></div>
                                        </div>
                                        <small><?= $sr['confidence'] ?></small>
                                    </div>
                                </td>
                                <td><span class="vt-tier vt-tier-<?= array_search($sr['tier'], ['','Monitor','Investigate','Escalate','Critical']) ?>"><?= $sr['tier'] ?></span></td>
                                <td><span class="badge <?= $sr['action'] === 'created' ? 'bg-success' : 'bg-info' ?>"><?= ucfirst($sr['action']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Configuration -->
<div class="vt-card mt-4">
    <div class="vt-card-header">
        <span><i class="fas fa-sliders-h me-2"></i>Scanner Configuration</span>
    </div>
    <div class="vt-card-body">
        <div class="row g-3">
            <div class="col-sm-6 col-md-3">
                <small class="text-muted d-block">Confidence Threshold</small>
                <strong><?= $confidenceThreshold ?></strong>
            </div>
            <div class="col-sm-6 col-md-3">
                <small class="text-muted d-block">Min. Cluster Docs</small>
                <strong><?= $clusterMinDocs ?></strong>
            </div>
            <div class="col-sm-6 col-md-3">
                <small class="text-muted d-block">Scoring Weights</small>
                <strong>Vol:30% Injury:35% Geo:20% Vel:15%</strong>
            </div>
            <div class="col-12 mt-2">
                <a href="settings.php" class="btn btn-sm btn-vt-outline">
                    <i class="fas fa-cog me-1"></i> Edit in Settings
                </a>
            </div>
        </div>
    </div>
</div>

<?php
    require_once __DIR__ . '/footer.php';
} else {
    // CLI output
    echo $scanLog;
}
?>
