/**
 * =============================================================================
 * VerdictTrace - Custom JavaScript
 * =============================================================================
 * jQuery-based client-side logic for:
 * - Agent chat panel
 * - AJAX helpers
 * - File upload dropzone
 * - Chart rendering helpers
 * - Mobile UX enhancements
 * =============================================================================
 */

$(document).ready(function () {
    // -----------------------------------------------------------------------
    // Initialize Bootstrap tooltips
    // -----------------------------------------------------------------------
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (el) {
        return new bootstrap.Tooltip(el);
    });

    // -----------------------------------------------------------------------
    // Auto-dismiss alerts after 5 seconds
    // -----------------------------------------------------------------------
    setTimeout(function () {
        $('.alert-dismissible').each(function () {
            var bsAlert = bootstrap.Alert.getOrCreateInstance(this);
            if (bsAlert) bsAlert.close();
        });
    }, 5000);

    // -----------------------------------------------------------------------
    // Mobile: close offcanvas sidebar on link click
    // -----------------------------------------------------------------------
    $('.vt-sidebar .vt-nav-item').on('click', function () {
        var offcanvasEl = document.getElementById('vtSidebar');
        var offcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl);
        if (offcanvas) offcanvas.hide();
    });
});

// ===========================================================================
// Agent Chat Panel
// ===========================================================================

/**
 * Toggle the floating agent chat panel visibility.
 */
function toggleAgentChat() {
    var panel = $('#agentChatPanel');
    panel.toggle();
    if (panel.is(':visible')) {
        $('#agentChatInput').focus();
    }
}

/**
 * Send a message to the agent chat.
 * Posts to agent_api.php and displays the response.
 *
 * @param {Event} e Form submit event
 */
function sendAgentMessage(e) {
    e.preventDefault();

    var input = $('#agentChatInput');
    var message = input.val().trim();
    if (!message) return;

    var messagesDiv = $('#agentChatMessages');

    // Display user message
    messagesDiv.append(
        '<div class="vt-agent-msg vt-agent-msg-user"><p>' +
        escapeHtml(message) +
        '</p></div>'
    );

    // Clear input
    input.val('');

    // Show loading indicator
    var loadingId = 'loading-' + Date.now();
    messagesDiv.append(
        '<div id="' + loadingId + '" class="vt-agent-msg vt-agent-msg-bot">' +
        '<p><span class="vt-spinner"></span> Thinking...</p></div>'
    );

    // Scroll to bottom
    messagesDiv.scrollTop(messagesDiv[0].scrollHeight);

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
            messagesDiv.append(
                '<div class="vt-agent-msg vt-agent-msg-bot"><p>' +
                formatAgentReply(reply) +
                '</p></div>'
            );
            messagesDiv.scrollTop(messagesDiv[0].scrollHeight);
        },
        error: function () {
            $('#' + loadingId).remove();
            messagesDiv.append(
                '<div class="vt-agent-msg vt-agent-msg-bot"><p>Connection error. Please try again.</p></div>'
            );
            messagesDiv.scrollTop(messagesDiv[0].scrollHeight);
        }
    });
}

/**
 * Format agent reply text — basic markdown-like formatting.
 */
function formatAgentReply(text) {
    // Escape HTML first
    text = escapeHtml(text);
    // Bold
    text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    // Line breaks
    text = text.replace(/\n/g, '<br>');
    return text;
}

// ===========================================================================
// File Upload Dropzone
// ===========================================================================

/**
 * Initialize a drag-and-drop file upload zone.
 *
 * @param {string} dropzoneId  ID of the dropzone element
 * @param {string} fileInputId ID of the hidden file input
 */
function initDropzone(dropzoneId, fileInputId) {
    var dropzone = $('#' + dropzoneId);
    var fileInput = $('#' + fileInputId);

    // Click to select file
    dropzone.on('click', function () {
        fileInput.trigger('click');
    });

    // Drag events
    dropzone.on('dragover', function (e) {
        e.preventDefault();
        dropzone.addClass('dragover');
    });
    dropzone.on('dragleave drop', function (e) {
        e.preventDefault();
        dropzone.removeClass('dragover');
    });
    dropzone.on('drop', function (e) {
        e.preventDefault();
        var files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            fileInput[0].files = files;
            fileInput.trigger('change');
        }
    });
}

// ===========================================================================
// Chart Helpers
// ===========================================================================

/**
 * Create a line chart for complaint trends.
 *
 * @param {string} canvasId Canvas element ID
 * @param {Array}  labels   X-axis labels (dates)
 * @param {Array}  data     Y-axis values (counts)
 * @param {string} label    Dataset label
 */
function createTrendChart(canvasId, labels, data, label) {
    var ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: label || 'Complaints',
                data: data,
                borderColor: '#003c8a',
                backgroundColor: 'rgba(0, 60, 138, 0.08)',
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointBackgroundColor: '#003c8a',
                borderWidth: 2,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { family: 'Outfit', size: 11 } },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: '#f0f0f0' },
                    ticks: {
                        font: { family: 'Outfit', size: 11 },
                        stepSize: 1,
                    },
                },
            }
        }
    });
}

/**
 * Create a doughnut chart for distribution data.
 *
 * @param {string} canvasId Canvas element ID
 * @param {Array}  labels   Segment labels
 * @param {Array}  data     Segment values
 */
function createDistributionChart(canvasId, labels, data) {
    var ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    var colors = ['#003c8a', '#0dcaf0', '#ffc107', '#fd7e14', '#dc3545', '#198754', '#6f42c1', '#d63384'];

    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors.slice(0, labels.length),
                borderWidth: 0,
                hoverOffset: 4,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: { family: 'Outfit', size: 12 },
                        padding: 12,
                        usePointStyle: true,
                        pointStyleWidth: 8,
                    }
                }
            }
        }
    });
}

/**
 * Create a horizontal bar chart.
 *
 * @param {string} canvasId Canvas element ID
 * @param {Array}  labels   Category labels
 * @param {Array}  data     Values
 * @param {string} label    Dataset label
 */
function createBarChart(canvasId, labels, data, label) {
    var ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: label || 'Count',
                data: data,
                backgroundColor: 'rgba(0, 60, 138, 0.75)',
                borderRadius: 4,
                barThickness: 24,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: '#f0f0f0' },
                    ticks: { font: { family: 'Outfit', size: 11 }, stepSize: 1 },
                },
                y: {
                    grid: { display: false },
                    ticks: { font: { family: 'Outfit', size: 12 } },
                },
            }
        }
    });
}

// ===========================================================================
// Utility Functions
// ===========================================================================

/**
 * Escape HTML special characters to prevent XSS.
 *
 * @param {string} str Raw string
 * @return {string}    Escaped string
 */
function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

/**
 * Format a date string for display.
 *
 * @param {string} dateStr ISO date string
 * @return {string}        Formatted date (e.g., "Jan 15, 2026")
 */
function formatDate(dateStr) {
    if (!dateStr) return '—';
    var d = new Date(dateStr);
    return d.toLocaleDateString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric'
    });
}

/**
 * Format a date string for display with time.
 *
 * @param {string} dateStr ISO date string
 * @return {string}        Formatted datetime
 */
function formatDateTime(dateStr) {
    if (!dateStr) return '—';
    var d = new Date(dateStr);
    return d.toLocaleDateString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

/**
 * Show a temporary toast notification.
 *
 * @param {string} message Toast message
 * @param {string} type    Bootstrap alert type (success, danger, warning, info)
 */
function showToast(message, type) {
    type = type || 'info';
    var id = 'toast-' + Date.now();
    var html = '<div id="' + id + '" class="alert alert-' + type + ' alert-dismissible fade show position-fixed" ' +
               'style="top:70px;right:16px;z-index:9999;min-width:280px;box-shadow:0 4px 12px rgba(0,0,0,0.15);">' +
               message +
               '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    $('body').append(html);
    setTimeout(function () { $('#' + id).fadeOut(300, function () { $(this).remove(); }); }, 4000);
}

/**
 * Confirm action with a styled modal.
 *
 * @param {string}   title    Modal title
 * @param {string}   message  Modal body text
 * @param {function} onConfirm Callback if confirmed
 */
function confirmAction(title, message, onConfirm) {
    // Remove any existing modal
    $('#vtConfirmModal').remove();

    var modalHtml =
        '<div class="modal fade" id="vtConfirmModal" tabindex="-1">' +
        '  <div class="modal-dialog modal-dialog-centered">' +
        '    <div class="modal-content" style="border-radius:12px;border:none;">' +
        '      <div class="modal-header border-0 pb-0">' +
        '        <h5 class="modal-title fw-bold">' + title + '</h5>' +
        '        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
        '      </div>' +
        '      <div class="modal-body">' + message + '</div>' +
        '      <div class="modal-footer border-0 pt-0">' +
        '        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>' +
        '        <button type="button" class="btn btn-vt-primary" id="vtConfirmBtn">Confirm</button>' +
        '      </div>' +
        '    </div>' +
        '  </div>' +
        '</div>';

    $('body').append(modalHtml);
    var modal = new bootstrap.Modal(document.getElementById('vtConfirmModal'));
    modal.show();

    $('#vtConfirmBtn').on('click', function () {
        modal.hide();
        if (typeof onConfirm === 'function') onConfirm();
    });
}
