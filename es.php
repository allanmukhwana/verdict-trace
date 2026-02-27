<?php
/**
 * =============================================================================
 * VerdictTrace - Elasticsearch Helper
 * =============================================================================
 * Provides functions for communicating with Elasticsearch via cURL.
 * Handles all REST API calls: indexing, searching, aggregations, and
 * ML anomaly detection job management. Zero external SDK dependencies.
 * =============================================================================
 */

require_once __DIR__ . '/config.php';

/**
 * Send a request to Elasticsearch via cURL.
 *
 * @param string      $method   HTTP method (GET, POST, PUT, DELETE)
 * @param string      $endpoint Elasticsearch endpoint path (e.g., /my_index/_search)
 * @param array|null  $body     Request body as associative array (will be JSON-encoded)
 * @return array                Decoded JSON response
 */
function es_request(string $method, string $endpoint, ?array $body = null): array {
    // Build full URL — strip leading slash to avoid double-slash
    $url = rtrim(ES_HOST, '/') . '/' . ltrim($endpoint, '/');

    // Initialize cURL
    $ch = curl_init();

    // Common headers
    $headers = [
        'Content-Type: application/json',
    ];

    // Authenticate with API key if provided
    if (ES_API_KEY !== '') {
        $headers[] = 'Authorization: ApiKey ' . ES_API_KEY;
    }

    // Set cURL options
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_SSL_VERIFYPEER => (APP_ENV !== 'development'), // Verify SSL in production
    ]);

    // Attach body for POST/PUT
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    // Handle cURL errors
    if ($response === false) {
        return ['error' => 'cURL error: ' . $error, '_http_code' => 0];
    }

    // Decode JSON response
    $decoded = json_decode($response, true);
    if ($decoded === null) {
        return ['error' => 'Invalid JSON response', '_raw' => $response, '_http_code' => $httpCode];
    }

    // Attach HTTP status code for inspection
    $decoded['_http_code'] = $httpCode;
    return $decoded;
}

// ===========================================================================
// Index Operations
// ===========================================================================

/**
 * Create an Elasticsearch index with specified mappings and settings.
 *
 * @param string $index    Index name
 * @param array  $mappings Mappings configuration
 * @param array  $settings Settings configuration (optional)
 * @return array           ES response
 */
function es_create_index(string $index, array $mappings, array $settings = []): array {
    $body = ['mappings' => $mappings];
    if (!empty($settings)) {
        $body['settings'] = $settings;
    }
    return es_request('PUT', "/$index", $body);
}

/**
 * Check if an Elasticsearch index exists.
 *
 * @param string $index Index name
 * @return bool
 */
function es_index_exists(string $index): bool {
    $result = es_request('HEAD', "/$index");
    return isset($result['_http_code']) && $result['_http_code'] === 200;
}

// ===========================================================================
// Document Operations
// ===========================================================================

/**
 * Index (upsert) a document into Elasticsearch.
 *
 * @param string      $index Index name
 * @param string|null $id    Document ID (null for auto-generated)
 * @param array       $doc   Document body
 * @return array              ES response
 */
function es_index_doc(string $index, ?string $id, array $doc): array {
    $endpoint = $id ? "/$index/_doc/$id" : "/$index/_doc";
    $method   = $id ? 'PUT' : 'POST';
    return es_request($method, $endpoint, $doc);
}

/**
 * Get a document by ID from Elasticsearch.
 *
 * @param string $index Index name
 * @param string $id    Document ID
 * @return array        ES response (document in '_source')
 */
function es_get_doc(string $index, string $id): array {
    return es_request('GET', "/$index/_doc/$id");
}

/**
 * Update a document partially.
 *
 * @param string $index Index name
 * @param string $id    Document ID
 * @param array  $doc   Partial document fields to update
 * @return array        ES response
 */
function es_update_doc(string $index, string $id, array $doc): array {
    return es_request('POST', "/$index/_update/$id", ['doc' => $doc]);
}

/**
 * Delete a document by ID.
 *
 * @param string $index Index name
 * @param string $id    Document ID
 * @return array        ES response
 */
function es_delete_doc(string $index, string $id): array {
    return es_request('DELETE', "/$index/_doc/$id");
}

// ===========================================================================
// Search Operations
// ===========================================================================

/**
 * Execute a search query against an Elasticsearch index.
 *
 * @param string $index Index name
 * @param array  $query Full search body (query, aggs, knn, size, sort, etc.)
 * @return array        ES response
 */
function es_search(string $index, array $query): array {
    return es_request('POST', "/$index/_search", $query);
}

/**
 * Perform a hybrid search combining BM25 keyword + knn dense vector.
 * This is the core retrieval strategy for hazard signal detection.
 *
 * @param string $index        Index name
 * @param string $keyword      Keyword query string for BM25
 * @param array  $vector       Dense vector for knn search
 * @param int    $size         Number of results to return
 * @param array  $filters      Additional filter clauses (optional)
 * @return array               ES response
 */
function es_hybrid_search(string $index, string $keyword, array $vector, int $size = 20, array $filters = []): array {
    // Build the BM25 query part
    $boolQuery = [
        'must' => [
            ['multi_match' => [
                'query'  => $keyword,
                'fields' => ['title^2', 'description', 'complaint_text', 'failure_mode'],
            ]],
        ],
    ];

    // Add filters if provided
    if (!empty($filters)) {
        $boolQuery['filter'] = $filters;
    }

    // Build the full hybrid search body
    $body = [
        'size'  => $size,
        'query' => ['bool' => $boolQuery],
        'knn'   => [
            'field'          => 'embedding',
            'query_vector'   => $vector,
            'k'              => $size,
            'num_candidates' => $size * 5,
        ],
    ];

    return es_search($index, $body);
}

// ===========================================================================
// Aggregation Operations
// ===========================================================================

/**
 * Run an aggregation query for clustering complaints by multiple dimensions.
 * Groups by product_sku × failure_mode × time_window × geo_region.
 *
 * @param string $index       Index name
 * @param string $timeWindow  Date histogram interval (e.g., '1w', '1d')
 * @param array  $filters     Optional filter clauses
 * @return array              ES response with aggregations
 */
function es_cluster_aggregation(string $index, string $timeWindow = '1w', array $filters = []): array {
    $body = [
        'size' => 0, // We only want aggregation results, no documents
        'aggs' => [
            'by_product' => [
                'terms' => ['field' => 'product_sku', 'size' => 50],
                'aggs'  => [
                    'by_failure_mode' => [
                        'terms' => ['field' => 'failure_mode.keyword', 'size' => 20],
                        'aggs'  => [
                            'over_time' => [
                                'date_histogram' => [
                                    'field'             => 'created_at',
                                    'calendar_interval' => $timeWindow,
                                ],
                            ],
                            'by_region' => [
                                'terms' => ['field' => 'geo_region.keyword', 'size' => 20],
                            ],
                            'injury_mentions' => [
                                'filter' => [
                                    'match' => ['complaint_text' => 'injury burn shock harm hurt'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    // Apply top-level filters if provided
    if (!empty($filters)) {
        $body['query'] = ['bool' => ['filter' => $filters]];
    }

    return es_search($index, $body);
}

/**
 * Get complaint count trends over time for a specific product/failure mode.
 *
 * @param string $index       Index name
 * @param string $productSku  Product SKU to filter
 * @param string $failureMode Failure mode to filter
 * @param string $interval    Date histogram interval
 * @return array              ES response
 */
function es_trend_aggregation(string $index, string $productSku, string $failureMode, string $interval = '1d'): array {
    $body = [
        'size'  => 0,
        'query' => [
            'bool' => [
                'filter' => [
                    ['term' => ['product_sku' => $productSku]],
                    ['term' => ['failure_mode.keyword' => $failureMode]],
                ],
            ],
        ],
        'aggs' => [
            'trend' => [
                'date_histogram' => [
                    'field'             => 'created_at',
                    'calendar_interval' => $interval,
                ],
            ],
        ],
    ];

    return es_search($index, $body);
}

// ===========================================================================
// Bulk Operations
// ===========================================================================

/**
 * Bulk index multiple documents.
 *
 * @param string $index Index name
 * @param array  $docs  Array of documents, each with optional '_id' key
 * @return array        ES bulk response
 */
function es_bulk_index(string $index, array $docs): array {
    $ndjson = '';
    foreach ($docs as $doc) {
        $id = $doc['_id'] ?? null;
        unset($doc['_id']);

        // Action line
        $action = ['index' => ['_index' => $index]];
        if ($id) {
            $action['index']['_id'] = $id;
        }
        $ndjson .= json_encode($action) . "\n";
        // Document line
        $ndjson .= json_encode($doc) . "\n";
    }

    // Bulk endpoint requires raw NDJSON, not a JSON body
    $url = rtrim(ES_HOST, '/') . '/_bulk';
    $ch  = curl_init();

    $headers = [
        'Content-Type: application/x-ndjson',
    ];
    if (ES_API_KEY !== '') {
        $headers[] = 'Authorization: ApiKey ' . ES_API_KEY;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $ndjson,
        CURLOPT_SSL_VERIFYPEER => (APP_ENV !== 'development'),
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true) ?? ['error' => 'Bulk request failed'];
}
