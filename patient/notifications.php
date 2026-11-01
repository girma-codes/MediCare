
<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['patient']);
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$userId = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle notification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid request. Please refresh the page.';
    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'mark_read') {

            $id = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);

            if ($id) {
                $stmt = $pdo->prepare("
                    UPDATE notifications
                    SET is_read = 1
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$id, $userId]);
                $message = 'Notification marked as read.';
            }

        } elseif ($action === 'mark_all') {

            $stmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE user_id = ? AND is_read = 0
            ");
            $stmt->execute([$userId]);
            $message = 'All notifications marked as read.';

        } elseif ($action === 'delete') {

            $id = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);

            if ($id) {
                $stmt = $pdo->prepare("
                    DELETE FROM notifications
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$id, $userId]);
                $message = 'Notification deleted.';
            }

        } else {
            $error = 'Invalid action.';
        }
    }
}

// Get notifications belonging to logged-in patient
$stmt = $pdo->prepare("
    SELECT id, message, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC, id DESC
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$total = count($notifications);
$unread = 0;

foreach ($notifications as $notification) {
    if ((int)$notification['is_read'] === 0) {
        $unread++;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Notifications | MediCare HMS</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: #f4f7fb;
    color: #263449;
}

.sidebar {
    width: 245px;
    height: 100vh;
    position: fixed;
    background: #102b46;
    color: white;
    padding: 30px 18px;
}

.logo {
    font-size: 23px;
    font-weight: bold;
    margin-bottom: 40px;
    color: #40d6bd;
}

.sidebar a {
    display: block;
    color: #dce7f1;
    text-decoration: none;
    padding: 14px;
    border-radius: 9px;
    margin-bottom: 7px;
}

.sidebar a:hover,
.sidebar a.active {
    background: #1b4666;
    color: white;
}

.main {
    margin-left: 245px;
    padding: 35px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

.header h1 {
    font-size: 28px;
}

.subtitle {
    color: #8290a2;
    margin-top: 8px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    padding: 22px;
    border-radius: 13px;
    box-shadow: 0 5px 20px #00000008;
}

.stat-card h2 {
    font-size: 27px;
    margin-bottom: 7px;
}

.stat-card p {
    color: #8290a2;
}

.card {
    background: white;
    border-radius: 14px;
    padding: 25px;
    box-shadow: 0 5px 20px #00000008;
}

.notification {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    padding: 20px 10px;
    border-bottom: 1px solid #edf0f4;
}

.notification.unread {
    background: #f0faf8;
    border-left: 4px solid #20b99f;
    padding-left: 15px;
}

.notification-content {
    flex: 1;
}

.notification-content p {
    line-height: 1.6;
    margin-bottom: 8px;
}

.notification-content small {
    color: #8290a2;
}

.badge {
    display: inline-block;
    font-size: 11px;
    font-weight: bold;
    padding: 6px 10px;
    border-radius: 20px;
    margin-bottom: 8px;
}

.read {
    background: #e8edf3;
    color: #64748b;
}

.unread-badge {
    background: #dff7ec;
    color: #168253;
}

.actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

button {
    border: none;
    padding: 9px 12px;
    border-radius: 7px;
    cursor: pointer;
    font-weight: bold;
}

.read-btn {
    background: #e5f4ff;
    color: #2563a5;
}

.delete-btn {
    background: #ffe8e8;
    color: #bd3434;
}

.all-btn {
    background: #159f8b;
    color: white;
    padding: 12px 16px;
}

.alert {
    padding: 14px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.success {
    background: #e1f7eb;
    color: #187b4c;
}

.danger {
    background: #ffe5e5;
    color: #b52d2d;
}

.empty {
    text-align: center;
    padding: 55px 15px;
    color: #8290a2;
}

@media(max-width: 800px) {
    .sidebar {
        width: 100%;
        height: auto;
        position: relative;
    }

    .main {
        margin-left: 0;
        padding: 20px;
    }

    .stats {
        grid-template-columns: 1fr;
    }

    .notification {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
</head>

<body>

<aside class="sidebar">

    <div class="logo">✚ MediCare</div>

    <a href="dashboard.php">Dashboard</a>
    <a href="appointments.php">Book Appointment</a>
    <a href="my-appointments.php">My Appointments</a>
    <a href="medical-history.php">Medical History</a>
    <a href="profile.php">My Profile</a>
    <a href="notifications.php" class="active">Notifications</a>
    <a href="../logout.php">Logout</a>

</aside>

<main class="main">

    <div class="header">
        <div>
            <h1>Notifications</h1>
            <p class="subtitle">Stay updated with your hospital activities.</p>
        </div>

        <?php if ($unread > 0): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="mark_all">
            <button class="all-btn" type="submit">
                Mark All as Read
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert danger"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="stats">

        <div class="stat-card">
            <h2><?= $total ?></h2>
            <p>Total Notifications</p>
        </div>

        <div class="stat-card">
            <h2><?= $unread ?></h2>
            <p>Unread Notifications</p>
        </div>

    </div>

    <section class="card">

        <?php if ($total > 0): ?>

            <?php foreach ($notifications as $notification): ?>

                <div class="notification <?= (int)$notification['is_read'] === 0 ? 'unread' : '' ?>">

                    <div class="notification-content">

                        <?php if ((int)$notification['is_read'] === 0): ?>
                            <span class="badge unread-badge">UNREAD</span>
                        <?php else: ?>
                            <span class="badge read">READ</span>
                        <?php endif; ?>

                        <p><?= nl2br(e($notification['message'])) ?></p>

                        <small>
                            <?= e(date('M d, Y - h:i A', strtotime($notification['created_at']))) ?>
                        </small>

                    </div>

                    <div class="actions">

                        <?php if ((int)$notification['is_read'] === 0): ?>

                            <form method="POST">
                                <input type="hidden" name="csrf_token"
                                       value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id"
                                       value="<?= e($notification['id']) ?>">

                                <button type="submit" class="read-btn">
                                    Mark Read
                                </button>
                            </form>

                        <?php endif; ?>

                        <form method="POST"
                              onsubmit="return confirm('Delete this notification?');">

                            <input type="hidden" name="csrf_token"
                                   value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="notification_id"
                                   value="<?= e($notification['id']) ?>">

                            <button type="submit" class="delete-btn">
                                Delete
                            </button>

                        </form>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <div class="empty">
                <h2>No Notifications Yet</h2>
                <p>You don't have any notifications at the moment.</p>
            </div>

        <?php endif; ?>

    </section>

</main>

</body>
</html>
