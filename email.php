<?php
/**
 * =============================================================================
 * VerdictTrace - Brevo Email Helper (API-based, not SMTP)
 * =============================================================================
 * Sends transactional emails using the Brevo (formerly Sendinblue) REST API.
 * Used for alert notifications when cases are escalated.
 * =============================================================================
 */

require_once __DIR__ . '/config.php';

/**
 * Send a transactional email via the Brevo API.
 *
 * @param string $toEmail   Recipient email address
 * @param string $toName    Recipient display name
 * @param string $subject   Email subject
 * @param string $htmlBody  HTML content of the email
 * @return array            API response ['success' => bool, 'message' => string]
 */
function email_send(string $toEmail, string $toName, string $subject, string $htmlBody): array {
    // Brevo transactional email API endpoint
    $url = 'https://api.brevo.com/v3/smtp/email';

    // Build the request payload per Brevo API specification
    $payload = [
        'sender'  => [
            'name'  => BREVO_SENDER_NAME,
            'email' => BREVO_SENDER_EMAIL,
        ],
        'to' => [
            ['email' => $toEmail, 'name' => $toName],
        ],
        'subject'     => $subject,
        'htmlContent' => $htmlBody,
    ];

    // Execute the API request
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'api-key: ' . BREVO_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    // Handle errors
    if ($response === false) {
        return ['success' => false, 'message' => 'cURL error: ' . $error];
    }

    $data = json_decode($response, true);

    // Brevo returns 201 on success
    if ($httpCode === 201) {
        return ['success' => true, 'message' => 'Email sent successfully', 'messageId' => $data['messageId'] ?? ''];
    }

    return ['success' => false, 'message' => $data['message'] ?? 'Unknown Brevo API error (HTTP ' . $httpCode . ')'];
}

/**
 * Send a case escalation alert email.
 *
 * @param string $toEmail     Recipient email
 * @param string $toName      Recipient name
 * @param string $caseId      Case identifier
 * @param int    $newTier     New severity tier
 * @param string $productSku  Affected product
 * @param string $failureMode Failure mode description
 * @param string $narrative   Brief narrative summary
 * @return array              Send result
 */
function email_escalation_alert(
    string $toEmail,
    string $toName,
    string $caseId,
    int $newTier,
    string $productSku,
    string $failureMode,
    string $narrative
): array {
    $tierLabel = tier_label($newTier);
    $tierColor = [
        TIER_MONITOR     => '#0dcaf0',
        TIER_INVESTIGATE => '#ffc107',
        TIER_ESCALATE    => '#fd7e14',
        TIER_CRITICAL    => '#dc3545',
    ][$newTier] ?? '#6c757d';

    $subject = "[VerdictTrace] Case $caseId escalated to $tierLabel";

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: 'Outfit', Arial, sans-serif; background: #f8f9fa; padding: 20px;">
  <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
    <div style="background: #003c8a; padding: 20px 24px;">
      <h1 style="color: #ffffff; margin: 0; font-size: 20px;">VerdictTrace Alert</h1>
    </div>
    <div style="padding: 24px;">
      <div style="display: inline-block; padding: 4px 12px; border-radius: 4px; background: {$tierColor}; color: #fff; font-weight: 600; font-size: 14px; margin-bottom: 16px;">
        {$tierLabel}
      </div>
      <h2 style="color: #001d42; margin-top: 8px;">Case {$caseId} Escalated</h2>
      <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr><td style="padding: 8px 0; color: #666; width: 140px;">Product SKU</td><td style="padding: 8px 0; font-weight: 600;">{$productSku}</td></tr>
        <tr><td style="padding: 8px 0; color: #666;">Failure Mode</td><td style="padding: 8px 0; font-weight: 600;">{$failureMode}</td></tr>
        <tr><td style="padding: 8px 0; color: #666;">New Tier</td><td style="padding: 8px 0; font-weight: 600;">{$tierLabel}</td></tr>
      </table>
      <p style="color: #333; line-height: 1.6;">{$narrative}</p>
      <a href="{APP_URL}/case_view.php?id={$caseId}" style="display: inline-block; margin-top: 16px; padding: 10px 24px; background: #003c8a; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600;">View Case</a>
    </div>
    <div style="padding: 16px 24px; background: #f1f3f5; text-align: center; font-size: 12px; color: #888;">
      VerdictTrace &mdash; The agent builds the case. The verdict belongs to humans.
    </div>
  </div>
</body>
</html>
HTML;

    return email_send($toEmail, $toName, $subject, $htmlBody);
}
