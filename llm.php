<?php
/**
 * =============================================================================
 * VerdictTrace - LLM API Helper
 * =============================================================================
 * Provides functions for interacting with OpenAI-compatible LLM APIs.
 * Handles entity extraction, embedding generation, and narrative summarization.
 * =============================================================================
 */

require_once __DIR__ . '/config.php';

/**
 * Send a chat completion request to the LLM API.
 *
 * @param string $systemPrompt System-level instruction for the LLM
 * @param string $userMessage  User-level prompt content
 * @param float  $temperature  Sampling temperature (0.0 - 1.0)
 * @return string              The LLM's response text
 */
function llm_chat(string $systemPrompt, string $userMessage, float $temperature = 0.3): string {
    $payload = [
        'model'       => LLM_MODEL,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userMessage],
        ],
        'temperature' => $temperature,
    ];

    $ch = curl_init(LLM_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . LLM_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return 'LLM Error: ' . $error;
    }

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? 'LLM returned no content.';
}

/**
 * Generate a dense vector embedding for the given text.
 *
 * @param string $text Text to embed
 * @return array       Embedding vector (array of floats)
 */
function llm_embed(string $text): array {
    $payload = [
        'model' => LLM_EMBEDDING_MODEL,
        'input' => $text,
    ];

    $ch = curl_init(LLM_EMBEDDING_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . LLM_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['data'][0]['embedding'] ?? [];
}

/**
 * Extract structured entities from a complaint text using the LLM.
 * Returns JSON with: product_sku, failure_mode, severity_keywords,
 * injury_mentioned (bool), location, and summary.
 *
 * @param string $text Raw complaint / support ticket text
 * @return array       Parsed entity data
 */
function llm_extract_entities(string $text): array {
    $systemPrompt = <<<PROMPT
You are a safety complaint entity extractor. Given a support ticket or complaint, extract the following fields as JSON:
{
  "product_sku": "string or null",
  "product_name": "string or null",
  "failure_mode": "string describing the failure (e.g., overheating, battery swelling)",
  "severity_keywords": ["array", "of", "keywords"],
  "injury_mentioned": true/false,
  "location": "city/state/country or null",
  "geo_region": "continent or broad region",
  "summary": "one-sentence summary of the complaint"
}
Return ONLY valid JSON, no additional text.
PROMPT;

    $result = llm_chat($systemPrompt, $text, 0.1);

    // Try to parse JSON from the response
    $decoded = json_decode($result, true);
    if ($decoded === null) {
        // Attempt to extract JSON from markdown code block
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $result, $matches)) {
            $decoded = json_decode($matches[1], true);
        }
    }

    return $decoded ?? [
        'product_sku'      => null,
        'product_name'     => null,
        'failure_mode'     => 'unknown',
        'severity_keywords'=> [],
        'injury_mentioned' => false,
        'location'         => null,
        'geo_region'       => null,
        'summary'          => $text,
    ];
}

/**
 * Generate a plain-language narrative summary for an Evidence Pack.
 *
 * @param array $clusterData Aggregated cluster data (product, failure mode, stats)
 * @param array $exemplars   Representative complaint excerpts
 * @return string            Narrative summary text
 */
function llm_generate_narrative(array $clusterData, array $exemplars): string {
    $systemPrompt = <<<PROMPT
You are a safety investigation analyst. Given aggregated complaint cluster data and representative exemplar cases, write a clear, professional narrative summary suitable for a safety investigation report. Include:
1. What product and failure mode is affected
2. Statistical summary (volume, velocity, geographic spread)
3. Key patterns observed across exemplar cases
4. Recommended investigation focus areas
Keep the tone factual and investigation-grade. Do not speculate beyond the data provided.
PROMPT;

    $userMessage = "Cluster Data:\n" . json_encode($clusterData, JSON_PRETTY_PRINT)
                 . "\n\nExemplar Cases:\n" . json_encode($exemplars, JSON_PRETTY_PRINT);

    return llm_chat($systemPrompt, $userMessage, 0.3);
}
