<?php
/**
 * =============================================================================
 * VerdictTrace - Notifications
 * =============================================================================
 * Displays in-app notifications for escalation alerts, case assignments,
 * comments, and system messages. Supports mark-as-read functionality.
 * =============================================================================
 */

$pageTitle = 'Notifications';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ---------------------------------------------------------------------------
// Handle POST: Mark as read
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read' && isset($_POST['id'])) {
        db_execute("UPDATE notifications SET is_read = 1 WHERE id = :id", [':id' => (int)$_POST['id']]);
    }
    if ($action === 'mark_all_read') {
        db_execute("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
    }

    // Redirect to avoid form resubmission
    header('Location: notification_list.php');
    exit;
}

// ---------------------------------------------------------------------------
// Fetch notifications
// ---------------------------------------------------------------------------
$notifications = [];
try {
    $notifications = db_select(
        "SELECT * FROM notifications ORDER BY created_at DESC LIMIT 50"
    );
} catch (Exception $e) {
    // Table may not exist yet
}

$unreadExists = false;
foreach ($notifications as $n) {
    if (!(int)$n['is_read']) { $unreadExists = true; break; }
}

require_once __DIR__ . '/header.php';
?>

<!-- Page Header -->
<div class="vt-page-header">
    <h1 class="vt-page-title">Notifications</h1>
    <?php if ($unreadExists): ?>
        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn btn-sm btn-vt-outline">
                <i class="fas fa-check-double me-1"></i> Mark All Read
            </button>
        </form>
    <?php endif; ?>
</div>

<!-- Notification List -->
<div class="vt-card">
    <div class="vt-card-body p-0">
        <?php if (empty($notifications)): ?>
            <div class="vt-empty">
                <i class="fas fa-bell-slash"></i>
                <p>No notifications yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
                <?php
                    $isRead    = (int)$n['is_read'];
                    $typeIcons = [
                        'escalation' => 'fas fa-arrow-up text-warning',
                        'assignment' => 'fas fa-user-check text-primary',
                        'comment'    => 'fas fa-comment text-info',
                        'system'     => 'fas fa-cog text-muted',
                    ];
                    $icon = $typeIcons[$n['type']] ?? 'fas fa-bell text-muted';
                ?>
                <div class="d-flex align-items-start gap-3 p-3 border-bottom <?= !$isRead ? 'bg-light' : '' ?>" style="<?= !$isRead ? 'border-left: 3px solid var(--vt-primary);' : '' ?>">
                    <!-- Icon -->
                    <div class="mt-1">
                        <i class="<?= $icon ?>" style="font-size:1.1rem;"></i>
                    </div>

                    <!-- Content -->
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <strong style="font-size:0.9rem;"><?= htmlspecialchars($n['title']) ?></strong>
                            <small class="text-muted ms-2 flex-shrink-0"><?= htmlspecialchars($n['created_at']) ?></small>
                        </div>
                        <?php if ($n['message']): ?>
                            <p class="text-muted mb-1" style="font-size:0.825rem;"><?= htmlspecialchars($n['message']) ?></p>
                        <?php endif; ?>
                        <div class="d-flex gap-2 mt-1">
                            <?php if ($n['case_id']): ?>
                                <a href="case_view.php?id=<?= urlencode($n['case_id']) ?>" class="btn btn-sm btn-vt-outline" style="font-size:0.75rem;padding:2px 10px;">
                                    View Case
                                </a>
                            <?php endif; ?>
                            <?php if (!$isRead): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-light" style="font-size:0.75rem;padding:2px 10px;">
                                        <i class="fas fa-check me-1"></i>Mark Read
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
