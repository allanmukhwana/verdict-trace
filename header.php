<?php
/**
 * =============================================================================
 * VerdictTrace - Shared Header
 * =============================================================================
 * Included at the top of every page. Provides:
 * - HTML head with Bootstrap, Font Awesome, Google Fonts, Chart.js
 * - Top navigation bar (mobile-first, app-like)
 * - Sidebar navigation for desktop
 * =============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Determine current page for active nav highlighting
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Fetch unread notification count for badge
$unreadCount = 0;
try {
    $row = db_select_one("SELECT COUNT(*) as cnt FROM notifications WHERE is_read = 0");
    $unreadCount = (int)($row['cnt'] ?? 0);
} catch (Exception $e) {
    // Silently ignore if table doesn't exist yet
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#003c8a">
    <title><?= htmlspecialchars($pageTitle ?? 'VerdictTrace') ?> — VerdictTrace</title>

    <!-- Google Font: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <!-- VerdictTrace Custom CSS -->
    <link href="assets.css" rel="stylesheet">
</head>
<body>

<!-- ======================================================================= -->
<!-- TOP NAVBAR (visible on all screens)                                     -->
<!-- ======================================================================= -->
<nav class="navbar navbar-dark vt-navbar fixed-top">
    <div class="container-fluid">
        <!-- Hamburger for mobile sidebar toggle -->
        <button class="btn btn-link text-white d-lg-none p-0 me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#vtSidebar" aria-label="Toggle navigation">
            <i class="fas fa-bars fa-lg"></i>
        </button>

        <!-- Brand / Logo -->
        <a class="navbar-brand d-flex align-items-center" href="index.php">
            <img src="https://hackathons.thinktank.support/verdict-trace/logo.png" alt="VerdictTrace" height="32" class="me-2">
            <span class="d-none d-sm-inline fw-semibold">VerdictTrace</span>
        </a>

        <!-- Right side: notifications + user -->
        <div class="d-flex align-items-center gap-3">
            <!-- Notification bell -->
            <a href="notification_list.php" class="text-white position-relative" title="Notifications">
                <i class="fas fa-bell fa-lg"></i>
                <?php if ($unreadCount > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.65rem;">
                        <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
                    </span>
                <?php endif; ?>
            </a>

            <!-- Agent chat toggle -->
            <button class="btn btn-sm btn-outline-light rounded-pill d-none d-md-inline-flex align-items-center gap-1" onclick="toggleAgentChat()">
                <i class="fas fa-robot"></i> <span class="d-none d-lg-inline">Agent</span>
            </button>

            <!-- User dropdown -->
            <div class="dropdown">
                <button class="btn btn-link text-white p-0 dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle fa-lg"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="auth_logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- ======================================================================= -->
<!-- SIDEBAR NAVIGATION (offcanvas on mobile, fixed on desktop)             -->
<!-- ======================================================================= -->
<div class="offcanvas-lg offcanvas-start vt-sidebar" tabindex="-1" id="vtSidebar">
    <div class="offcanvas-header d-lg-none">
        <h5 class="offcanvas-title">
            <img src="https://hackathons.thinktank.support/verdict-trace/logo.png" alt="VerdictTrace" height="28" class="me-2">
            Menu
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#vtSidebar"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column p-0">
        <nav class="vt-nav flex-grow-1">
            <a href="index.php" class="vt-nav-item <?= $currentPage === 'index' ? 'active' : '' ?>">
                <i class="fas fa-chart-line"></i> <span>Dashboard</span>
            </a>
            <a href="case_list.php" class="vt-nav-item <?= str_starts_with($currentPage, 'case_') ? 'active' : '' ?>">
                <i class="fas fa-folder-open"></i> <span>Cases</span>
            </a>
            <a href="complaint_list.php" class="vt-nav-item <?= str_starts_with($currentPage, 'complaint_') ? 'active' : '' ?>">
                <i class="fas fa-exclamation-triangle"></i> <span>Complaints</span>
            </a>
            <a href="ingest_upload.php" class="vt-nav-item <?= str_starts_with($currentPage, 'ingest_') ? 'active' : '' ?>">
                <i class="fas fa-cloud-upload-alt"></i> <span>Ingest Data</span>
            </a>
            <a href="evidence_list.php" class="vt-nav-item <?= str_starts_with($currentPage, 'evidence_') ? 'active' : '' ?>">
                <i class="fas fa-file-alt"></i> <span>Evidence Packs</span>
            </a>
            <a href="agent_chat.php" class="vt-nav-item <?= str_starts_with($currentPage, 'agent_') ? 'active' : '' ?>">
                <i class="fas fa-robot"></i> <span>AI Agent</span>
            </a>

            <div class="vt-nav-divider"></div>

            <a href="notification_list.php" class="vt-nav-item <?= str_starts_with($currentPage, 'notification_') ? 'active' : '' ?>">
                <i class="fas fa-bell"></i> <span>Notifications</span>
                <?php if ($unreadCount > 0): ?>
                    <span class="badge bg-danger ms-auto"><?= $unreadCount ?></span>
                <?php endif; ?>
            </a>
            <a href="settings.php" class="vt-nav-item <?= $currentPage === 'settings' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i> <span>Settings</span>
            </a>
        </nav>

        <!-- Sidebar footer -->
        <div class="vt-nav-footer">
            <small class="text-muted">VerdictTrace v1.0</small><br>
            <small class="text-muted">Open Source · MIT License</small>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MAIN CONTENT WRAPPER                                                    -->
<!-- ======================================================================= -->
<main class="vt-main">
    <div class="container-fluid">
