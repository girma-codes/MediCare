
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['receptionist']);

// CSRF protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Register a new appointment and notify the patient
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_appointment'])) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh the page.";
    } else {
        $patient_id = (int)($_POST['patient_id'] ?? 0);
        $doctor_id = (int)($_POST['doctor_id'] ?? 0);
        $appointment_date = $_POST['appointment_date'] ?? '';
        $appointment_time = $_POST['appointment_time'] ?? '';
        $reason = trim($_POST['reason'] ?? '');

        if (!$patient_id || !$doctor_id || !$appointment_date || !$appointment_time) {
            $error = "Please fill in all required fields.";
        } else {
            try {
                // Verify patient and doctor
                $check = $pdo->prepare("
                    SELECT p.user_id,
                           (SELECT COUNT(*) FROM doctors WHERE id = ?) AS doctor_exists
                    FROM patients p
                    WHERE p.id = ?
                    LIMIT 1
                ");
                $check->execute([$doctor_id, $patient_id]);
                $valid = $check->fetch();

                if (!$valid || !$valid['doctor_exists']) {
                    $error = "Selected patient or doctor does not exist.";
                } else {
                    $pdo->beginTransaction();

                    $insert = $pdo->prepare("
                        INSERT INTO appointments
                        (patient_id, doctor_id, appointment_date,
                         appointment_time, reason, status)
                        VALUES (?, ?, ?, ?, ?, 'pending')
                    ");

                    $insert->execute([
                        $patient_id,
                        $doctor_id,
                        $appointment_date,
                        $appointment_time,
                        $reason
                    ]);

                    // Notify patient account, not patients.id
                    $notification = $pdo->prepare("
                        INSERT INTO notifications (user_id, message, is_read)
                        VALUES (?, ?, 0)
                    ");

                    $notification->execute([
                        $valid['user_id'],
                        "Your appointment request has been registered successfully. Your appointment is currently pending approval."
                    ]);

                    $pdo->commit();

                    $message = "Appointment registered successfully! Patient has been notified.";
                }

            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($ex->getMessage());
                $error = "Could not register appointment. Please try again.";
            }
        }
    }
}

// Update appointment status and notify the patient
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh the page.";
    } else {
        $appointment_id = (int)($_POST['appointment_id'] ?? 0);
        $new_status = $_POST['status'] ?? '';

        $allowed_statuses = ['pending', 'confirmed', 'completed', 'cancelled'];

        if ($appointment_id > 0 && in_array($new_status, $allowed_statuses, true)) {

            try {
                $pdo->beginTransaction();

                // Lock appointment and find patient user account
                $check = $pdo->prepare("
                    SELECT a.status, p.user_id
                    FROM appointments a
                    JOIN patients p ON a.patient_id = p.id
                    WHERE a.id = ?
                    FOR UPDATE
                ");
                $check->execute([$appointment_id]);
                $appointment = $check->fetch();

                if (!$appointment) {
                    throw new Exception("Appointment not found.");
                }

                $old_status = $appointment['status'];

                if ($old_status !== $new_status) {

                    $update = $pdo->prepare("
                        UPDATE appointments
                        SET status = ?
                        WHERE id = ?
                    ");
                    $update->execute([$new_status, $appointment_id]);

                    $status_messages = [
                        'pending' => 'Your appointment is now pending approval.',
                        'confirmed' => 'Good news! Your appointment has been confirmed.',
                        'completed' => 'Your appointment has been marked as completed.',
                        'cancelled' => 'Your appointment has been cancelled.'
                    ];

                    $notification = $pdo->prepare("
                        INSERT INTO notifications (user_id, message, is_read)
                        VALUES (?, ?, 0)
                    ");

                    $notification->execute([
                        $appointment['user_id'],
                        $status_messages[$new_status]
                    ]);

                    $message = "Appointment status updated successfully! Patient has been notified.";

                } else {
                    $message = "Appointment status has not changed. No new notification was sent.";
                }

                $pdo->commit();

            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($ex->getMessage());
                $error = "Could not update appointment status. Please try again.";
            }

        } else {
            $error = "Invalid appointment information.";
        }
    }
}

// Get patients
$patients = $pdo->query("
    SELECT p.id, p.patient_number, u.full_name
    FROM patients p
    JOIN users u ON p.user_id = u.id
    ORDER BY u.full_name ASC
")->fetchAll();

// Get doctors
$doctors = $pdo->query("
    SELECT d.id, u.full_name, d.specialization, dep.name AS department
    FROM doctors d
    JOIN users u ON d.user_id = u.id
    LEFT JOIN departments dep ON d.department_id = dep.id
    ORDER BY u.full_name ASC
")->fetchAll();

// Search and filter
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';

$sql = "
    SELECT
        a.id,
        a.appointment_date,
        a.appointment_time,
        a.reason,
        a.status,
        p.patient_number,
        pu.full_name AS patient_name,
        du.full_name AS doctor_name,
        d.specialization
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    JOIN users pu ON p.user_id = pu.id
    JOIN doctors d ON a.doctor_id = d.id
    JOIN users du ON d.user_id = du.id
    WHERE 1=1
";

$params = [];

if ($search !== '') {
    $sql .= " AND (pu.full_name LIKE ? OR p.patient_number LIKE ? OR du.full_name LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if (in_array($status_filter, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
    $sql .= " AND a.status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$appointments = $stmt->fetchAll();

// Dashboard statistics
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'pending') AS pending,
        SUM(status = 'confirmed') AS confirmed,
        SUM(status = 'completed') AS completed,
        SUM(status = 'cancelled') AS cancelled
    FROM appointments
")->fetch();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment Management | MediCare HMS</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #263445;
        }

        .header {
            background: #102d46;
            color: white;
            padding: 22px 35px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header h2 { font-size: 23px; }

        .header a {
            color: white;
            text-decoration: none;
            margin-left: 15px;
        }

        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        .stat-card h3 {
            font-size: 28px;
            margin-bottom: 8px;
            color: #087e8b;
        }

        .stat-card p { color: #718096; }

        .panel {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        .panel h2 {
            margin-bottom: 20px;
            font-size: 21px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-weight: bold;
            font-size: 14px;
        }

        input, select, textarea {
            padding: 12px;
            border: 1px solid #d9e1eb;
            border-radius: 7px;
            font-size: 14px;
            width: 100%;
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        .full-width { grid-column: 1 / -1; }

        button {
            background: #087e8b;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 7px;
            cursor: pointer;
            font-weight: bold;
        }

        button:hover { background: #06636d; }

        .message {
            padding: 14px;
            border-radius: 7px;
            margin-bottom: 18px;
            background: #d9f7e8;
            color: #12643a;
        }

        .error {
            background: #ffe0e0;
            color: #a12626;
        }

        .filter-form {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-form input {
            flex: 1;
            min-width: 200px;
        }

        .table-wrapper { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        th, td {
            padding: 14px;
            border-bottom: 1px solid #edf0f5;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #f5f8fb;
            color: #536477;
        }

        .status {
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .pending { background: #fff0c2; color: #8a6200; }
        .confirmed { background: #d8edff; color: #075b9b; }
        .completed { background: #d8f5e5; color: #187044; }
        .cancelled { background: #ffe0e0; color: #a12626; }

        .status-form {
            display: flex;
            gap: 7px;
        }

        .status-form select {
            min-width: 115px;
            padding: 7px;
        }

        .status-form button { padding: 8px 10px; }

        .empty {
            text-align: center;
            padding: 30px;
            color: #718096;
        }

        @media(max-width: 650px) {
            .form-grid { grid-template-columns: 1fr; }
            .header { padding: 20px; }
            .panel { padding: 17px; }
        }
    </style>
</head>

<body>

<header class="header">
    <div>
        <h2>MediCare HMS</h2>
        <p>Receptionist Portal</p>
    </div>

    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<main class="container">

    <h1 style="margin-bottom:8px;">Appointment Management</h1>
    <p style="color:#718096;margin-bottom:25px;">
        Register, search and manage patient appointments.
    </p>

    <?php if ($message): ?>
        <div class="message"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Statistics -->
    <section class="stats">
        <div class="stat-card">
            <h3><?= (int)$stats['total'] ?></h3>
            <p>Total Appointments</p>
        </div>

        <div class="stat-card">
            <h3><?= (int)$stats['pending'] ?></h3>
            <p>Pending</p>
        </div>

        <div class="stat-card">
            <h3><?= (int)$stats['confirmed'] ?></h3>
            <p>Confirmed</p>
        </div>

        <div class="stat-card">
            <h3><?= (int)$stats['completed'] ?></h3>
            <p>Completed</p>
        </div>

        <div class="stat-card">
            <h3><?= (int)$stats['cancelled'] ?></h3>
            <p>Cancelled</p>
        </div>
    </section>

    <!-- Create Appointment -->
    <section class="panel">
        <h2>+ Register New Appointment</h2>

        <?php if (count($patients) === 0 || count($doctors) === 0): ?>
            <p class="message error">
                You need at least one registered patient and one registered doctor
                before creating an appointment.
            </p>
        <?php else: ?>

        <form method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="form-grid">

                <div class="form-group">
                    <label>Select Patient *</label>
                    <select name="patient_id" required>
                        <option value="">Choose Patient</option>
                        <?php foreach ($patients as $patient): ?>
                            <option value="<?= (int)$patient['id'] ?>">
                                <?= e($patient['full_name']) ?>
                                (<?= e($patient['patient_number']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Select Doctor *</label>
                    <select name="doctor_id" required>
                        <option value="">Choose Doctor</option>
                        <?php foreach ($doctors as $doctor): ?>
                            <option value="<?= (int)$doctor['id'] ?>">
                                Dr. <?= e($doctor['full_name']) ?>
                                - <?= e($doctor['specialization']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Appointment Date *</label>
                    <input type="date" name="appointment_date"
                           min="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label>Appointment Time *</label>
                    <input type="time" name="appointment_time" required>
                </div>

                <div class="form-group full-width">
                    <label>Reason for Appointment</label>
                    <textarea name="reason"
                              placeholder="Enter reason for appointment..."></textarea>
                </div>

                <div class="full-width">
                    <button type="submit" name="create_appointment">
                        Register Appointment
                    </button>
                </div>

            </div>
        </form>

        <?php endif; ?>
    </section>

    <!-- Appointment List -->
    <section class="panel">
        <h2>All Appointments</h2>

        <form method="GET" class="filter-form">
            <input type="text" name="search"
                   placeholder="Search patient, ID or doctor..."
                   value="<?= e($search) ?>">

            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach (['pending','confirmed','completed','cancelled'] as $s): ?>
                    <option value="<?= e($s) ?>"
                        <?= $status_filter === $s ? 'selected' : '' ?>>
                        <?= ucfirst($s) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Search</button>
            <a href="appointments.php"
               style="padding:12px;text-decoration:none;color:#087e8b;">
                Reset
            </a>
        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Update Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (count($appointments) > 0): ?>

                    <?php foreach ($appointments as $appointment): ?>
                        <tr>
                            <td>#<?= (int)$appointment['id'] ?></td>

                            <td>
                                <?= e($appointment['patient_name']) ?><br>
                                <small><?= e($appointment['patient_number']) ?></small>
                            </td>

                            <td>
                                Dr. <?= e($appointment['doctor_name']) ?><br>
                                <small><?= e($appointment['specialization']) ?></small>
                            </td>

                            <td><?= e($appointment['appointment_date']) ?></td>

                            <td><?= e(date('h:i A', strtotime($appointment['appointment_time']))) ?></td>

                            <td><?= e($appointment['reason'] ?: 'Not specified') ?></td>

                            <td>
                                <span class="status <?= e($appointment['status']) ?>">
                                    <?= e($appointment['status']) ?>
                                </span>
                            </td>

                            <td>
                                <form method="POST" class="status-form">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= e($_SESSION['csrf_token']) ?>">

                                    <input type="hidden" name="appointment_id"
                                           value="<?= (int)$appointment['id'] ?>">

                                    <select name="status">
                                        <?php foreach (['pending','confirmed','completed','cancelled'] as $s): ?>
                                            <option value="<?= e($s) ?>"
                                                <?= $appointment['status'] === $s ? 'selected' : '' ?>>
                                                <?= ucfirst($s) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button type="submit" name="update_status">
                                        Save
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="8" class="empty">
                            No appointments found.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>
