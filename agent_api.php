<?php
/**
 * =============================================================================
 * VerdictTrace - Agent API Endpoint
 * =============================================================================
 * JSON API endpoint for the agent chat interface. Receives user messages,
 * routes them to Elasticsearch Agent Builder (if configured) or falls back
 * to direct Elasticsearch queries + LLM interpretation.
 *
 * Request:  POST { "message": "user question" }
 * Response: JSON { "reply": "agent response text" }
 * =============================================================================
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/es.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/agent.php';

// ---------------------------------------------------------------------------
// Parse incoming JSON request
// ---------------------------------------------------------------------------
$input   = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');

if ($message === '') {
    echo json_encode(['reply' => 'Please enter a message.']);
    exit;
}

// ---------------------------------------------------------------------------
// Strategy 1: Use Elasticsearch Agent Builder if configured
// ---------------------------------------------------------------------------
if (KIBANA_URL !== '' && KIBANA_API_KEY !== '') {
    $agentResponse = agent_chat('verdictrace_agent', $message);

    if (isset($agentResponse['response']) || isset($agentResponse['message'])) {
        $reply = $agentResponse['response'] ?? $agentResponse['message'] ?? '';
        echo json_encode(['reply' => $reply, 'source' => 'agent_builder']);
        exit;
    }
    // If Agent Builder fails, fall through to local strategy
}

// ---------------------------------------------------------------------------
// Strategy 2: Local Elasticsearch query + LLM interpretation
// ---------------------------------------------------------------------------

// Determine query intent using keyword matching
$lowerMsg = strtolower($message);
$esData   = [];

// --- Intent: Search complaints ---
if (preg_match('/(complaint|report|ticket|issue|problem|overheating|burn|shock|swelling|flicker)/i', $message)) {
    // Extract possible search keywords
    $searchTerms = preg_replace('/^(show|find|search|get|list|display)\s+(me\s+)?/i', '', $message);
    $searchTerms = preg_replace('/(recent|latest|all|complaints?|reports?|tickets?|about|for|with|the)\s*/i', '', $searchTerms);
    $searchTerms = trim($searchTerms);

    if ($searchTerms === '') {
        $searchTerms = $message; // Fallback to full message
    }

    $result = es_search(ES_INDEX_COMPLAINTS, [
        'size'  => 10,
        'query' => [
            'multi_match' => [
                'query'  => $searchTerms,
                'fields' => ['title^2', 'complaint_text', 'product_sku', 'product_name', 'failure_mode', 'summary'],
            ],
        ],
        'sort' => [['created_at' => 'desc']],
    ]);

    if (isset($result['hits']['hits'])) {
        $esData['type'] = 'complaint_search';
        $esData['total'] = $result['hits']['total']['value'] ?? 0;
        $esData['results'] = [];
        foreach ($result['hits']['hits'] as $hit) {
            $s = $hit['_source'];
            $esData['results'][] = [
                'title'       => $s['title'] ?? '',
                'product_sku' => $s['product_sku'] ?? '',
                'failure_mode'=> $s['failure_mode'] ?? '',
                'injury'      => $s['injury_mentioned'] ?? false,
                'location'    => $s['location'] ?? '',
                'summary'     => $s['summary'] ?? mb_substr($s['complaint_text'] ?? '', 0, 100),
                'date'        => substr($s['created_at'] ?? '', 0, 10),
            ];
        }
    }
}

// --- Intent: Failure mode analysis ---
elseif (preg_match('/(failure mode|top|breakdown|distribution|categor)/i', $message)) {
    $result = es_search(ES_INDEX_COMPLAINTS, [
        'size' => 0,
        'aggs' => [
            'modes' => ['terms' => ['field' => 'failure_mode.keyword', 'size' => 15]],
        ],
    ]);

    if (isset($result['aggregations']['modes']['buckets'])) {
        $esData['type'] = 'failure_mode_analysis';
        $esData['modes'] = [];
        foreach ($result['aggregations']['modes']['buckets'] as $b) {
            $esData['modes'][] = ['mode' => $b['key'], 'count' => $b['doc_count']];
        }
    }
}

// --- Intent: Injury reports ---
elseif (preg_match('/(injury|injuries|burn|harm|hurt|shock)/i', $message)) {
    $filter = [['term' => ['injury_mentioned' => true]]];

    // Check if a specific product is mentioned
    if (preg_match('/([A-Z]{2,}-[A-Z0-9]+)/i', $message, $m)) {
        $filter[] = ['term' => ['product_sku' => strtoupper($m[1])]];
    }

    $result = es_search(ES_INDEX_COMPLAINTS, [
        'size'  => 10,
        'query' => ['bool' => ['filter' => $filter]],
        'sort'  => [['created_at' => 'desc']],
    ]);

    if (isset($result['hits']['hits'])) {
        $esData['type'] = 'injury_reports';
        $esData['total'] = $result['hits']['total']['value'] ?? 0;
        $esData['results'] = [];
        foreach ($result['hits']['hits'] as $hit) {
            $s = $hit['_source'];
            $esData['results'][] = [
                'title'       => $s['title'] ?? '',
                'product_sku' => $s['product_sku'] ?? '',
                'failure_mode'=> $s['failure_mode'] ?? '',
                'location'    => $s['location'] ?? '',
                'summary'     => $s['summary'] ?? mb_substr($s['complaint_text'] ?? '', 0, 100),
                'date'        => substr($s['created_at'] ?? '', 0, 10),
            ];
        }
    }
}

// --- Intent: Active cases ---
elseif (preg_match('/(case|cases|investigation|active|escalat|critical)/i', $message)) {
    $result = es_search(ES_INDEX_CASES, [
        'size'  => 10,
        'query' => ['bool' => ['must_not' => [['term' => ['status' => 'dismissed']]]]],
        'sort'  => [['severity_tier' => 'desc'], ['updated_at' => 'desc']],
    ]);

    if (isset($result['hits']['hits'])) {
        $esData['type'] = 'active_cases';
        $esData['total'] = $result['hits']['total']['value'] ?? 0;
        $esData['cases'] = [];
        foreach ($result['hits']['hits'] as $hit) {
            $s = $hit['_source'];
            $esData['cases'][] = [
                'title'       => $s['title'] ?? '',
                'product_sku' => $s['product_sku'] ?? '',
                'tier'        => tier_label((int)($s['severity_tier'] ?? 1)),
                'status'      => $s['status'] ?? 'open',
                'complaints'  => (int)($s['complaint_count'] ?? 0),
                'injuries'    => (int)($s['injury_count'] ?? 0),
            ];
        }
    }
}

// --- Intent: Region / geographic analysis ---
elseif (preg_match('/(region|geo|geographic|country|location|where)/i', $message)) {
    $queryBody = ['size' => 0, 'aggs' => ['regions' => ['terms' => ['field' => 'geo_region.keyword', 'size' => 20]]]];

    // Check for product SKU in message
    if (preg_match('/([A-Z]{2,}-[A-Z0-9]+)/i', $message, $m)) {
        $queryBody['query'] = ['term' => ['product_sku' => strtoupper($m[1])]];
    }

    $result = es_search(ES_INDEX_COMPLAINTS, $queryBody);

    if (isset($result['aggregations']['regions']['buckets'])) {
        $esData['type'] = 'geo_distribution';
        $esData['regions'] = [];
        foreach ($result['aggregations']['regions']['buckets'] as $b) {
            $esData['regions'][] = ['region' => $b['key'], 'count' => $b['doc_count']];
        }
    }
}

// --- Fallback: general search ---
else {
    $result = es_search(ES_INDEX_COMPLAINTS, [
        'size'  => 5,
        'query' => [
            'multi_match' => [
                'query'  => $message,
                'fields' => ['title^2', 'complaint_text', 'product_sku', 'failure_mode', 'summary'],
            ],
        ],
    ]);

    if (isset($result['hits']['hits'])) {
        $esData['type'] = 'general_search';
        $esData['total'] = $result['hits']['total']['value'] ?? 0;
        $esData['results'] = [];
        foreach ($result['hits']['hits'] as $hit) {
            $s = $hit['_source'];
            $esData['results'][] = [
                'title'       => $s['title'] ?? '',
                'product_sku' => $s['product_sku'] ?? '',
                'summary'     => $s['summary'] ?? mb_substr($s['complaint_text'] ?? '', 0, 100),
            ];
        }
    }
}

// ---------------------------------------------------------------------------
// Generate natural language reply using LLM (if configured)
// ---------------------------------------------------------------------------
if (LLM_API_KEY !== '' && !empty($esData)) {
    $systemPrompt = <<<PROMPT
You are VerdictTrace, a safety investigation assistant. You help safety teams analyze complaint data.
Given the user's question and the Elasticsearch query results below, provide a clear, concise, professional answer.
- Cite specific numbers, products, and failure modes from the data
- If injuries are mentioned, highlight them prominently
- Use bullet points for lists
- Keep responses under 300 words
- Do not fabricate data not present in the results
PROMPT;

    $userPrompt = "User question: $message\n\nElasticsearch results:\n" . json_encode($esData, JSON_PRETTY_PRINT);
    $reply = llm_chat($systemPrompt, $userPrompt, 0.3);
} elseif (!empty($esData)) {
    // Format a basic reply without LLM
    $reply = formatBasicReply($esData);
} else {
    $reply = "I couldn't find relevant data for your question. Try searching for specific products, failure modes, or asking about active investigation cases.";
}

echo json_encode(['reply' => $reply, 'source' => 'local', 'data' => $esData]);

// ===========================================================================
// Helper: Format a basic reply without LLM
// ===========================================================================
function formatBasicReply(array $data): string {
    $type = $data['type'] ?? '';

    switch ($type) {
        case 'complaint_search':
        case 'injury_reports':
        case 'general_search':
            $total = $data['total'] ?? 0;
            $lines = ["Found **$total** matching records:\n"];
            foreach (($data['results'] ?? []) as $r) {
                $line = "• **{$r['title']}**";
                if (!empty($r['product_sku'])) $line .= " ({$r['product_sku']})";
                if (!empty($r['failure_mode'])) $line .= " — {$r['failure_mode']}";
                if (!empty($r['injury'])) $line .= " ⚠️ INJURY";
                if (!empty($r['date'])) $line .= " [{$r['date']}]";
                $lines[] = $line;
            }
            return implode("\n", $lines);

        case 'failure_mode_analysis':
            $lines = ["**Top Failure Modes:**\n"];
            foreach (($data['modes'] ?? []) as $m) {
                $lines[] = "• **{$m['mode']}**: {$m['count']} complaints";
            }
            return implode("\n", $lines);

        case 'active_cases':
            $total = $data['total'] ?? 0;
            $lines = ["**$total Active Cases:**\n"];
            foreach (($data['cases'] ?? []) as $c) {
                $line = "• **{$c['title']}** ({$c['product_sku']}) — Tier: {$c['tier']}, Status: {$c['status']}";
                if ($c['injuries'] > 0) $line .= " ⚠️ {$c['injuries']} injuries";
                $lines[] = $line;
            }
            return implode("\n", $lines);

        case 'geo_distribution':
            $lines = ["**Geographic Distribution:**\n"];
            foreach (($data['regions'] ?? []) as $r) {
                $lines[] = "• **{$r['region']}**: {$r['count']} complaints";
            }
            return implode("\n", $lines);

        default:
            return "Query executed but no formatted results available.";
    }
}
