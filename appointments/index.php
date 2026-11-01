
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin']);

// CSRF protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

$successMessages = [
    'confirmed' => 'Appointment successfully confirmed.',
    'cancelled' => 'Appointment successfully cancelled.',
    'completed' => 'Appointment successfully marked as completed.'
];

$errorMessages = [
    'invalid' => 'Invalid appointment request.',
    'notfound' => 'Appointment was not found.',
    'status' => 'This appointment cannot be updated from its current status.',
    'failed' => 'Unable to update appointment. Please try again.'
];

if (isset($successMessages[$_GET['success'] ?? ''])) {
    $success = $successMessages[$_GET['success']];
}

if (isset($errorMessages[$_GET['error'] ?? ''])) {
    $error = $errorMessages[$_GET['error']];
}

// Handle appointment status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page.');
    }

    $appointmentId = filter_input(
        INPUT_POST,
        'appointment_id',
        FILTER_VALIDATE_INT
    );

    $newStatus = $_POST['status'] ?? '';

    $allowedStatuses = [
        'confirmed',
        'cancelled',
        'completed'
    ];

    if (!$appointmentId || !in_array($newStatus, $allowedStatuses, true)) {
        header('Location: index.php?error=invalid');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.patient_id,
                a.status,
                p.user_id
            FROM appointments a
            INNER JOIN patients p ON p.id = a.patient_id
            WHERE a.id = :id
            FOR UPDATE
        ");

        $stmt->execute(['id' => $appointmentId]);
        $appointment = $stmt->fetch();

        if (!$appointment) {
            $pdo->rollBack();
            header('Location: index.php?error=notfound');
            exit;
        }

        $currentStatus = $appointment['status'];

        $validTransition = false;

        if ($currentStatus === 'pending') {
            $validTransition = in_array(
                $newStatus,
                ['confirmed', 'cancelled'],
                true
            );
        } elseif ($currentStatus === 'confirmed') {
            $validTransition = in_array(
                $newStatus,
                ['completed', 'cancelled'],
                true
            );
        }

        if (!$validTransition) {
            $pdo->rollBack();
            header('Location: index.php?error=status');
            exit;
        }

        $update = $pdo->prepare("
            UPDATE appointments
            SET status = :status
            WHERE id = :id
        ");

        $update->execute([
            'status' => $newStatus,
            'id' => $appointmentId
        ]);

        // Notify the patient using patients.user_id
        if (!empty($appointment['user_id'])) {

            $messages = [
                'confirmed' => 'Your appointment has been confirmed by the hospital.',
                'cancelled' => 'Your appointment has been cancelled by the hospital.',
                'completed' => 'Your appointment has been marked as completed.'
            ];

            $notification = $pdo->prepare("
                INSERT INTO notifications (user_id, message, is_read, created_at)
                VALUES (:user_id, :message, 0, NOW())
            ");

            $notification->execute([
                'user_id' => $appointment['user_id'],
                'message' => $messages[$newStatus]
            ]);
        }

        $pdo->commit();

        header('Location: index.php?success=' . $newStatus);
        exit;

    } catch (PDOException $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log($e->getMessage());

        header('Location: index.php?error=failed');
        exit;
    }
}

// Search and filters
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$dateFilter = $_GET['date'] ?? '';

$validFilters = [
    '',
    'pending',
    'confirmed',
    'completed',
    'cancelled'
];

if (!in_array($statusFilter, $validFilters, true)) {
    $statusFilter = '';
}

if (
    $dateFilter !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)
) {
    $dateFilter = '';
}

$appointments = [];
$totalAppointments = 0;
$pendingAppointments = 0;
$confirmedAppointments = 0;
$completedAppointments = 0;
$cancelledAppointments = 0;

try {

    // Statistics
    $stats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(status = 'pending') AS pending,
            SUM(status = 'confirmed') AS confirmed,
            SUM(status = 'completed') AS completed,
            SUM(status = 'cancelled') AS cancelled
        FROM appointments
    ")->fetch();

    $totalAppointments = (int) ($stats['total'] ?? 0);
    $pendingAppointments = (int) ($stats['pending'] ?? 0);
    $confirmedAppointments = (int) ($stats['confirmed'] ?? 0);
    $completedAppointments = (int) ($stats['completed'] ?? 0);
    $cancelledAppointments = (int) ($stats['cancelled'] ?? 0);

    // Appointment records
    $sql = "
        SELECT
            a.id,
            a.appointment_date,
            a.appointment_time,
            a.reason,
            a.status,
            a.created_at,

            p.patient_number,

            patient_user.full_name AS patient_name,
            patient_user.email AS patient_email,

            doctor_user.full_name AS doctor_name,

            d.name AS department_name

        FROM appointments a

        INNER JOIN patients p
            ON p.id = a.patient_id

        INNER JOIN users patient_user
            ON patient_user.id = p.user_id

        INNER JOIN doctors doc
            ON doc.id = a.doctor_id

        INNER JOIN users doctor_user
            ON doctor_user.id = doc.user_id

        LEFT JOIN departments d
            ON d.id = doc.department_id

        WHERE 1 = 1
    ";

    $params = [];

    if ($search !== '') {
        $sql .= "
            AND (
                patient_user.full_name LIKE :patient_search
                OR doctor_user.full_name LIKE :doctor_search
                OR p.patient_number LIKE :number_search
            )
        ";

        $keyword = "%$search%";

        $params['patient_search'] = $keyword;
        $params['doctor_search'] = $keyword;
        $params['number_search'] = $keyword;
    }

    if ($statusFilter !== '') {
        $sql .= " AND a.status = :status ";
        $params['status'] = $statusFilter;
    }

    if ($dateFilter !== '') {
        $sql .= " AND a.appointment_date = :appointment_date ";
        $params['appointment_date'] = $dateFilter;
    }

    $sql .= "
        ORDER BY
            a.appointment_date DESC,
            a.appointment_time DESC,
            a.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $appointments = $stmt->fetchAll();

} catch (PDOException $e) {

    error_log($e->getMessage());

    $error = 'Unable to load appointment records. Please check the database.';
}

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function statusClass($status): string {
    return match ($status) {
        'pending' => 'pending',
        'confirmed' => 'confirmed',
        'completed' => 'completed',
        'cancelled' => 'cancelled',
        default => 'pending'
    };
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Appointment Management | MediCare HMS</title>

<style>

@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

:root {
    --primary: #1769aa;
    --dark: #102d4a;
    --bg: #f4f7fb;
    --border: #e8edf4;
    --muted: #8491a5;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Inter, Arial, sans-serif;
    background: var(--bg);
    color: #1e293b;
}

.page {
    max-width: 1550px;
    margin: auto;
    padding: 35px;
}

.back {
    display: inline-block;
    margin-bottom: 22px;
    text-decoration: none;
    color: var(--primary);
    font-size: 12px;
    font-weight: 600;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 28px;
}

.header h1 {
    font-size: 25px;
    margin-bottom: 8px;
}

.header p {
    color: var(--muted);
    font-size: 13px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 15px;
    margin-bottom: 25px;
}

.card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 5px 20px rgba(20,50,80,.04);
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
}

.stat-number {
    font-size: 29px;
    font-weight: 800;
    margin-top: 12px;
}

.stat-total {
    border-top: 3px solid #1769aa;
}

.stat-pending {
    border-top: 3px solid #d97706;
}

.stat-confirmed {
    border-top: 3px solid #2563eb;
}

.stat-completed {
    border-top: 3px solid #16a34a;
}

.stat-cancelled {
    border-top: 3px solid #dc2626;
}

.alert {
    padding: 14px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 13px;
}

.success {
    background: #dcfce7;
    color: #166534;
}

.error {
    background: #fee2e2;
    color: #991b1b;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 20px;
}

.section-header h2 {
    font-size: 17px;
    margin-bottom: 6px;
}

.section-header p {
    color: var(--muted);
    font-size: 12px;
}

.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 9px;
    align-items: center;
}

.filters input,
.filters select {
    padding: 11px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-family: inherit;
    font-size: 12px;
    outline: none;
    background: white;
}

.filters input:focus,
.filters select:focus {
    border-color: var(--primary);
}

.btn {
    display: inline-block;
    padding: 10px 14px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    font-family: inherit;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: #104e83;
}

.btn-light {
    background: white;
    color: var(--primary);
    border: 1px solid var(--border);
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 1150px;
    border-collapse: collapse;
}

th, td {
    text-align: left;
    padding: 15px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 12px;
    vertical-align: middle;
}

th {
    color: #8290a5;
    background: #f9fbfd;
    font-weight: 700;
}

td {
    color: #475569;
}

tbody tr:hover {
    background: #f8fbff;
}

.patient-name,
.doctor-name {
    color: #1e293b;
    font-weight: 700;
}

.small-text {
    display: block;
    color: var(--muted);
    font-size: 10px;
    margin-top: 5px;
}

.status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    text-transform: capitalize;
}

.status.pending {
    background: #fef3c7;
    color: #92400e;
}

.status.confirmed {
    background: #dbeafe;
    color: #1d4ed8;
}

.status.completed {
    background: #dcfce7;
    color: #166534;
}

.status.cancelled {
    background: #fee2e2;
    color: #991b1b;
}

.action-form {
    display: flex;
    align-items: center;
    gap: 6px;
}

.action-form select {
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 11px;
}

.empty {
    text-align: center;
    padding: 45px;
    color: var(--muted);
}

@media(max-width: 1000px) {
    .stats {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media(max-width: 650px) {
    .page {
        padding: 20px 14px;
    }

    .stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .header h1 {
        font-size: 21px;
    }

    .card {
        padding: 16px;
    }

    .filters {
        width: 100%;
    }

    .filters input,
    .filters select {
        flex: 1;
        min-width: 120px;
    }
}

</style>

</head>

<body>

<main class="page">

    <a class="back" href="../admin/dashboard.php">
        ← Back to Admin Dashboard
    </a>

    <header class="header">

        <div>
            <h1>Appointment Management</h1>
            <p>Manage, review and monitor hospital appointments.</p>
        </div>

    </header>

    <?php if ($success): ?>
        <div class="alert success">
            ✓ <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error">
            ⚠ <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="stats">

        <div class="card stat-total">
            <div class="stat-label">Total Appointments</div>
            <div class="stat-number"><?= $totalAppointments ?></div>
        </div>

        <div class="card stat-pending">
            <div class="stat-label">Pending</div>
            <div class="stat-number"><?= $pendingAppointments ?></div>
        </div>

        <div class="card stat-confirmed">
            <div class="stat-label">Confirmed</div>
            <div class="stat-number"><?= $confirmedAppointments ?></div>
        </div>

        <div class="card stat-completed">
            <div class="stat-label">Completed</div>
            <div class="stat-number"><?= $completedAppointments ?></div>
        </div>

        <div class="card stat-cancelled">
            <div class="stat-label">Cancelled</div>
            <div class="stat-number"><?= $cancelledAppointments ?></div>
        </div>

    </section>

    <section class="card">

        <div class="section-header">

            <div>
                <h2>Appointment Records</h2>
                <p>View and manage all patient appointments.</p>
            </div>

            <form method="GET" class="filters">

                <input
                    type="search"
                    name="search"
                    placeholder="Search patient or doctor..."
                    value="<?= e($search) ?>"
                >

                <select name="status">

                    <option value="">All Statuses</option>

                    <option value="pending"
                        <?= $statusFilter === 'pending' ? 'selected' : '' ?>>
                        Pending
                    </option>

                    <option value="confirmed"
                        <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>
                        Confirmed
                    </option>

                    <option value="completed"
                        <?= $statusFilter === 'completed' ? 'selected' : '' ?>>
                        Completed
                    </option>

                    <option value="cancelled"
                        <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>
                        Cancelled
                    </option>

                </select>

                <input
                    type="date"
                    name="date"
                    value="<?= e($dateFilter) ?>"
                >

                <button type="submit" class="btn btn-primary">
                    Filter
                </button>

                <a href="index.php" class="btn btn-light">
                    Reset
                </a>

            </form>

        </div>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Department</th>
                        <th>Date & Time</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($appointments)): ?>

                    <tr>
                        <td colspan="8" class="empty">
                            No appointments found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($appointments as $appointment): ?>

                        <tr>

                            <td>
                                #<?= (int)$appointment['id'] ?>
                            </td>

                            <td>
                                <span class="patient-name">
                                    <?= e($appointment['patient_name']) ?>
                                </span>

                                <span class="small-text">
                                    <?= e($appointment['patient_number']) ?>
                                </span>
                            </td>

                            <td class="doctor-name">
                                <?= e($appointment['doctor_name']) ?>
                            </td>

                            <td>
                                <?= e($appointment['department_name'] ?? 'N/A') ?>
                            </td>

                            <td>
    <strong>
        <?= e(date('M d, Y', strtotime($appointment['appointment_date']))) ?>
    </strong>

    <span class="small-text">
        <i class="fa-regular fa-clock"></i>
        <?= e(date('h:i A', strtotime($appointment['appointment_time']))) ?>
    </span>
</td>

                            <td>
                                <?= e($appointment['reason'] ?: 'Not specified') ?>
                            </td>

                            <td>
                                <span class="status <?= e(statusClass($appointment['status'])) ?>">
                                    <?= e($appointment['status']) ?>
                                </span>
                            </td>

                            <td>

                                <?php if (
                                    $appointment['status'] === 'pending' ||
                                    $appointment['status'] === 'confirmed'
                                ): ?>

                                    <form method="POST" class="action-form">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($_SESSION['csrf_token']) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="appointment_id"
                                            value="<?= (int)$appointment['id'] ?>"
                                        >

                                        <select name="status" required>

                                            <?php if ($appointment['status'] === 'pending'): ?>

                                                <option value="confirmed">
                                                    Confirm
                                                </option>

                                                <option value="cancelled">
                                                    Cancel
                                                </option>

                                            <?php else: ?>

                                                <option value="completed">
                                                    Complete
                                                </option>

                                                <option value="cancelled">
                                                    Cancel
                                                </option>

                                            <?php endif; ?>

                                        </select>

                                        <button
                                            type="submit"
                                            class="btn btn-primary"
                                            onclick="return confirm('Are you sure you want to update this appointment?');"
                                        >
                                            Update
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span class="small-text">
                                        No actions available
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

</body>
</html>
