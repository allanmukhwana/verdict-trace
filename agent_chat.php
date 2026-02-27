<?php
/**
 * =============================================================================
 * VerdictTrace - AI Agent Chat (Full Page)
 * =============================================================================
 * Full-page conversational interface for the VerdictTrace investigation agent.
 * Uses Elasticsearch Agent Builder for natural language data exploration.
 * Falls back to direct Elasticsearch queries if Agent Builder is not configured.
 * =============================================================================
 */

$pageTitle = 'AI Agent';
require_once __DIR__ . '/header.php';
?>

<!-- Page Header -->
<div class="vt-page-header">
    <h1 class="vt-page-title">
        <i class="fas fa-robot me-2"></i>VerdictTrace Agent
    </h1>
    <div>
        <?php if (KIBANA_URL !== '' && KIBANA_API_KEY !== ''): ?>
            <span class="badge bg-success"><i class="fas fa-check me-1"></i>Agent Builder Connected</span>
        <?php else: ?>
            <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>Agent Builder Not Configured</span>
        <?php endif; ?>
    </div>
</div>

<!-- Chat Interface (full-page version) -->
<div class="vt-card" style="height: calc(100vh - 200px); min-height: 400px; display: flex; flex-direction: column;">
    <!-- Chat Messages -->
    <div id="fullChatMessages" class="flex-grow-1 p-3" style="overflow-y: auto;">
        <!-- Welcome message -->
        <div class="vt-agent-msg vt-agent-msg-bot mb-3" style="max-width:70%;">
            <p class="mb-2"><strong>Welcome to VerdictTrace Agent</strong></p>
            <p class="mb-2">I can help you investigate safety signals in your complaint data. Try asking me:</p>
            <ul style="font-size:0.85rem; padding-left:18px; margin:0;">
                <li>"Show me recent complaints about overheating"</li>
                <li>"What are the top failure modes this week?"</li>
                <li>"Are there any injury reports for PowerCell X200?"</li>
                <li>"Summarize active investigation cases"</li>
                <li>"How many complaints per region for PC-X200?"</li>
            </ul>
        </div>
    </div>

    <!-- Suggested Prompts (shown initially) -->
    <div id="suggestedPrompts" class="px-3 pb-2">
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-sm btn-vt-outline rounded-pill" onclick="useSuggestion('Show recent overheating complaints')">
                <i class="fas fa-fire me-1"></i>Overheating complaints
            </button>
            <button class="btn btn-sm btn-vt-outline rounded-pill" onclick="useSuggestion('What are the top failure modes?')">
                <i class="fas fa-chart-bar me-1"></i>Top failure modes
            </button>
            <button class="btn btn-sm btn-vt-outline rounded-pill" onclick="useSuggestion('Show all injury reports')">
                <i class="fas fa-user-injured me-1"></i>Injury reports
            </button>
            <button class="btn btn-sm btn-vt-outline rounded-pill" onclick="useSuggestion('Summarize active cases')">
                <i class="fas fa-folder-open me-1"></i>Active cases
            </button>
        </div>
    </div>

    <!-- Input Area -->
    <div class="p-3 border-top">
        <form id="fullChatForm" onsubmit="sendFullChatMessage(event)">
            <div class="input-group">
                <input type="text" id="fullChatInput" class="form-control" placeholder="Ask the VerdictTrace agent..." autocomplete="off" style="border-radius:24px 0 0 24px;">
                <button class="btn btn-vt-primary" type="submit" style="border-radius:0 24px 24px 0; padding:8px 20px;">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    /**
     * Send a message in the full-page chat interface.
     */
    function sendFullChatMessage(e) {
        e.preventDefault();

        var input   = $('#fullChatInput');
        var message = input.val().trim();
        if (!message) return;

        var messages = $('#fullChatMessages');

        // Hide suggested prompts after first message
        $('#suggestedPrompts').fadeOut(200);

        // Display user message
        messages.append(
            '<div class="vt-agent-msg vt-agent-msg-user mb-3" style="max-width:70%;margin-left:auto;">' +
            '<p>' + escapeHtml(message) + '</p></div>'
        );
        input.val('');

        // Loading indicator
        var loadingId = 'fl-' + Date.now();
        messages.append(
            '<div id="' + loadingId + '" class="vt-agent-msg vt-agent-msg-bot mb-3" style="max-width:70%;">' +
            '<p><span class="vt-spinner"></span> Analyzing...</p></div>'
        );
        messages.scrollTop(messages[0].scrollHeight);

        // Send to backend
        $.ajax({
            url: 'agent_api.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ message: message }),
            dataType: 'json',
            success: function (data) {
                $('#' + loadingId).remove();
                var reply = data.reply || 'Sorry, I could not process that request.';
                messages.append(
                    '<div class="vt-agent-msg vt-agent-msg-bot mb-3" style="max-width:70%;">' +
                    '<p>' + formatAgentReply(reply) + '</p></div>'
                );
                messages.scrollTop(messages[0].scrollHeight);
            },
            error: function () {
                $('#' + loadingId).remove();
                messages.append(
                    '<div class="vt-agent-msg vt-agent-msg-bot mb-3" style="max-width:70%;">' +
                    '<p class="text-danger">Connection error. Please try again.</p></div>'
                );
                messages.scrollTop(messages[0].scrollHeight);
            }
        });
    }

    /**
     * Use a suggested prompt.
     */
    function useSuggestion(text) {
        $('#fullChatInput').val(text);
        $('#fullChatForm').trigger('submit');
    }

    // Focus input on page load
    $(document).ready(function () {
        $('#fullChatInput').focus();
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
