<?php
/**
 * =============================================================================
 * VerdictTrace - Elasticsearch Agent Builder Integration
 * =============================================================================
 * Manages interaction with Elasticsearch Agent Builder via the Kibana API.
 * Registers custom ES|QL tools, creates agents, and handles conversational
 * queries through the Agent Builder chat interface.
 *
 * Reference: https://www.elastic.co/elasticsearch/agent-builder
 * Docs:      https://www.elastic.co/docs/explore-analyze/ai-features/agent-builder/get-started
 * =============================================================================
 */

require_once __DIR__ . '/config.php';

/**
 * Send a request to the Kibana Agent Builder API.
 *
 * @param string     $method   HTTP method (GET, POST, PUT, DELETE)
 * @param string     $endpoint API endpoint path (e.g., /api/agent_builder/tools)
 * @param array|null $body     Request body (JSON-encoded)
 * @return array               Decoded JSON response
 */
function agent_request(string $method, string $endpoint, ?array $body = null): array {
    $url = rtrim(KIBANA_URL, '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init();

    $headers = [
        'Content-Type: application/json',
        'kbn-xsrf: true', // Required for Kibana API calls
    ];

    // Authenticate with API key
    if (KIBANA_API_KEY !== '') {
        $headers[] = 'Authorization: ApiKey ' . KIBANA_API_KEY;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_SSL_VERIFYPEER => (APP_ENV !== 'development'),
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['error' => 'cURL error: ' . $error, '_http_code' => 0];
    }

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        return ['error' => 'Invalid JSON response', '_raw' => $response, '_http_code' => $httpCode];
    }

    $decoded['_http_code'] = $httpCode;
    return $decoded;
}

// ===========================================================================
// Tool Registration
// ===========================================================================

/**
 * Register a custom ES|QL tool with the Agent Builder.
 * Tools allow the agent to execute structured Elasticsearch queries.
 *
 * @param string $toolId      Unique tool identifier
 * @param string $description Human-readable description of what the tool does
 * @param string $esqlQuery   ES|QL query template
 * @param array  $params      Parameter definitions for the tool
 * @return array              API response
 */
function agent_register_tool(string $toolId, string $description, string $esqlQuery, array $params = []): array {
    $body = [
        'id'            => $toolId,
        'type'          => 'esql',
        'description'   => $description,
        'configuration' => [
            'query' => $esqlQuery,
        ],
    ];

    if (!empty($params)) {
        $body['params'] = $params;
    }

    return agent_request('POST', '/api/agent_builder/tools', $body);
}

/**
 * List all registered custom tools.
 *
 * @return array API response with tool list
 */
function agent_list_tools(): array {
    return agent_request('GET', '/api/agent_builder/tools');
}

/**
 * Delete a registered tool by ID.
 *
 * @param string $toolId Tool identifier
 * @return array         API response
 */
function agent_delete_tool(string $toolId): array {
    return agent_request('DELETE', '/api/agent_builder/tools/' . urlencode($toolId));
}

// ===========================================================================
// Agent Management
// ===========================================================================

/**
 * Create a custom agent with specified tools and instructions.
 *
 * @param string $agentId     Unique agent identifier
 * @param string $name        Display name for the agent
 * @param string $description Agent description
 * @param string $instructions System prompt / instructions for the agent
 * @param array  $toolIds     Array of tool IDs to assign to the agent
 * @return array              API response
 */
function agent_create(string $agentId, string $name, string $description, string $instructions, array $toolIds = []): array {
    $body = [
        'id'           => $agentId,
        'name'         => $name,
        'description'  => $description,
        'instructions' => $instructions,
        'tools'        => $toolIds,
    ];

    return agent_request('POST', '/api/agent_builder/agents', $body);
}

/**
 * List all registered agents.
 *
 * @return array API response with agent list
 */
function agent_list(): array {
    return agent_request('GET', '/api/agent_builder/agents');
}

/**
 * Delete an agent by ID.
 *
 * @param string $agentId Agent identifier
 * @return array          API response
 */
function agent_delete(string $agentId): array {
    return agent_request('DELETE', '/api/agent_builder/agents/' . urlencode($agentId));
}

// ===========================================================================
// Chat / Conversation
// ===========================================================================

/**
 * Send a chat message to an agent and get a response.
 *
 * @param string $agentId Agent identifier (or 'default' for built-in agent)
 * @param string $message User message text
 * @param string $convId  Conversation ID for continuity (optional)
 * @return array          API response with agent reply
 */
function agent_chat(string $agentId, string $message, string $convId = ''): array {
    $body = [
        'agent_id' => $agentId,
        'message'  => $message,
    ];

    if ($convId !== '') {
        $body['conversation_id'] = $convId;
    }

    return agent_request('POST', '/api/agent_builder/chat', $body);
}

// ===========================================================================
// VerdictTrace-Specific Tool Definitions
// ===========================================================================

/**
 * Register all VerdictTrace-specific tools with Agent Builder.
 * Call this once during setup to configure the agent environment.
 *
 * @return array Results for each tool registration
 */
function agent_setup_verdictrace_tools(): array {
    $results = [];

    // Tool 1: Search complaints by keyword
    $results['search_complaints'] = agent_register_tool(
        'verdictrace_search_complaints',
        'Search VerdictTrace complaint records by keyword, product, or failure mode. Returns matching complaints with their severity and status.',
        'FROM verdictrace_complaints | WHERE complaint_text LIKE ?keyword OR failure_mode LIKE ?keyword | SORT created_at DESC | LIMIT 50',
        ['keyword' => ['type' => 'keyword', 'description' => 'Search term for complaints']]
    );

    // Tool 2: Get complaint cluster summary
    $results['cluster_summary'] = agent_register_tool(
        'verdictrace_cluster_summary',
        'Get a summary of complaint clusters grouped by product SKU and failure mode, including counts and date ranges.',
        'FROM verdictrace_complaints | STATS count = COUNT(*), earliest = MIN(created_at), latest = MAX(created_at) BY product_sku, failure_mode | SORT count DESC | LIMIT 20'
    );

    // Tool 3: Get active investigation cases
    $results['active_cases'] = agent_register_tool(
        'verdictrace_active_cases',
        'List active VerdictTrace investigation cases with their severity tier, product, and status.',
        'FROM verdictrace_cases | WHERE status != "dismissed" | SORT severity_tier DESC, updated_at DESC | LIMIT 30'
    );

    // Tool 4: Injury mention analysis
    $results['injury_analysis'] = agent_register_tool(
        'verdictrace_injury_analysis',
        'Analyze complaints that mention injuries, burns, shocks, or other harm. Returns product and failure mode breakdown.',
        'FROM verdictrace_complaints | WHERE injury_mentioned == true | STATS count = COUNT(*) BY product_sku, failure_mode | SORT count DESC | LIMIT 20'
    );

    // Tool 5: Geographic distribution
    $results['geo_distribution'] = agent_register_tool(
        'verdictrace_geo_distribution',
        'Get geographic distribution of complaints for a specific product SKU.',
        'FROM verdictrace_complaints | WHERE product_sku == ?sku | STATS count = COUNT(*) BY geo_region | SORT count DESC',
        ['sku' => ['type' => 'keyword', 'description' => 'Product SKU to analyze']]
    );

    return $results;
}

/**
 * Create the VerdictTrace investigation agent with all registered tools.
 *
 * @return array API response
 */
function agent_setup_verdictrace_agent(): array {
    $instructions = <<<INSTRUCTIONS
You are VerdictTrace, a safety investigation assistant. Your role is to help safety and quality teams investigate product hazard signals.

Guidelines:
- Always be factual and cite data from Elasticsearch queries
- Flag potential safety concerns clearly with severity assessments
- Never recommend auto-triggering recalls — always recommend human review
- When presenting complaint clusters, include count, date range, geographic spread, and injury mention rate
- Prioritize clusters with injury mentions or rapid velocity increases
- Use professional, investigation-grade language suitable for regulatory documentation
INSTRUCTIONS;

    return agent_create(
        'verdictrace_agent',
        'VerdictTrace Safety Investigator',
        'AI-powered safety investigation agent that analyzes complaint clusters, identifies hazard signals, and assists human investigators with evidence-based analysis.',
        $instructions,
        [
            'verdictrace_search_complaints',
            'verdictrace_cluster_summary',
            'verdictrace_active_cases',
            'verdictrace_injury_analysis',
            'verdictrace_geo_distribution',
        ]
    );
}
