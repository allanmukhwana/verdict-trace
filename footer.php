<?php
/**
 * =============================================================================
 * VerdictTrace - Shared Footer
 * =============================================================================
 * Included at the bottom of every page. Closes the main content wrapper
 * and provides: mobile bottom navigation, agent chat panel, and JS imports.
 * =============================================================================
 */
?>
    </div><!-- /.container-fluid -->
</main><!-- /.vt-main -->

<!-- ======================================================================= -->
<!-- MOBILE BOTTOM NAVIGATION (app-like tab bar)                             -->
<!-- ======================================================================= -->
<nav class="vt-bottomnav d-lg-none">
    <a href="index.php" class="vt-bottomnav-item <?= $currentPage === 'index' ? 'active' : '' ?>">
        <i class="fas fa-chart-line"></i>
        <span>Dashboard</span>
    </a>
    <a href="case_list.php" class="vt-bottomnav-item <?= str_starts_with($currentPage, 'case_') ? 'active' : '' ?>">
        <i class="fas fa-folder-open"></i>
        <span>Cases</span>
    </a>
    <a href="complaint_list.php" class="vt-bottomnav-item <?= str_starts_with($currentPage, 'complaint_') ? 'active' : '' ?>">
        <i class="fas fa-exclamation-triangle"></i>
        <span>Complaints</span>
    </a>
    <a href="ingest_upload.php" class="vt-bottomnav-item <?= str_starts_with($currentPage, 'ingest_') ? 'active' : '' ?>">
        <i class="fas fa-cloud-upload-alt"></i>
        <span>Ingest</span>
    </a>
    <a href="agent_chat.php" class="vt-bottomnav-item <?= str_starts_with($currentPage, 'agent_') ? 'active' : '' ?>">
        <i class="fas fa-robot"></i>
        <span>Agent</span>
    </a>
</nav>

<!-- ======================================================================= -->
<!-- AGENT CHAT FLOATING PANEL                                               -->
<!-- ======================================================================= -->
<div id="agentChatPanel" class="vt-agent-panel" style="display:none;">
    <div class="vt-agent-header">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-robot"></i>
            <strong>VerdictTrace Agent</strong>
        </div>
        <button class="btn btn-sm btn-link text-white p-0" onclick="toggleAgentChat()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div id="agentChatMessages" class="vt-agent-messages">
        <div class="vt-agent-msg vt-agent-msg-bot">
            <p>Hello! I'm the VerdictTrace investigation agent. I can help you search complaints, analyze clusters, and review case data. What would you like to investigate?</p>
        </div>
    </div>
    <div class="vt-agent-input">
        <form id="agentChatForm" onsubmit="sendAgentMessage(event)">
            <div class="input-group">
                <input type="text" id="agentChatInput" class="form-control" placeholder="Ask the agent..." autocomplete="off">
                <button class="btn btn-primary" type="submit">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================= -->
<!-- JavaScript: jQuery, Bootstrap, Custom Scripts                           -->
<!-- ======================================================================= -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets.js"></script>

</body>
</html>
