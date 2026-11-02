
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['receptionist']);

date_default_timezone_set('Africa/Addis_Ababa');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function validAppointmentDateTime($date, $time) {
    $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
    $timeObj = DateTime::createFromFormat('!H:i', $time);

    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        return false;
    }

    if (!$timeObj || $timeObj->format('H:i') !== $time) {
        return false;
    }

    // Appointment slots must be every 30 minutes.
    $minutes = (int)$timeObj->format('i');

    if (!in_array($minutes, [0, 30], true)) {
        return false;
    }

    $selected = new DateTime("$date $time");
    $now = new DateTime();

    return $selected >= $now;
}

// Create appointment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_appointment'])) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh the page.";
    } else {
        $patient_id = filter_var($_POST['patient_id'] ?? '', FILTER_VALIDATE_INT);
        $doctor_id = filter_var($_POST['doctor_id'] ?? '', FILTER_VALIDATE_INT);
        $appointment_date = trim($_POST['appointment_date'] ?? '');
        $appointment_time = trim($_POST['appointment_time'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (!$patient_id || !$doctor_id || !$appointment_date || !$appointment_time) {
            $error = "Please fill in all required fields.";
        } elseif (!validAppointmentDateTime($appointment_date, $appointment_time)) {
            $error = "Invalid date or time. Select a future date and a valid 30-minute time slot.";
        } elseif (strlen($reason) > 1000) {
            $error = "Appointment reason is too long.";
        } else {
            try {
                $pdo->beginTransaction();

                // Lock patient and doctor rows to reduce concurrent booking conflicts.
                $patientCheck = $pdo->prepare("
                    SELECT user_id
                    FROM patients
                    WHERE id = ?
                    FOR UPDATE
                ");
                $patientCheck->execute([$patient_id]);
                $patient = $patientCheck->fetch(PDO::FETCH_ASSOC);

                $doctorCheck = $pdo->prepare("
                    SELECT id
                    FROM doctors
                    WHERE id = ?
                    FOR UPDATE
                ");
                $doctorCheck->execute([$doctor_id]);
                $doctor = $doctorCheck->fetch(PDO::FETCH_ASSOC);

                if (!$patient || !$doctor) {
                    throw new Exception("Selected patient or doctor does not exist.");
                }

                // Check whether the doctor already has an active appointment.
                $doctorConflict = $pdo->prepare("
                    SELECT id
                    FROM appointments
                    WHERE doctor_id = ?
                      AND appointment_date = ?
                      AND appointment_time = ?
                      AND status IN ('pending', 'confirmed')
                    LIMIT 1
                ");
                $doctorConflict->execute([
                    $doctor_id,
                    $appointment_date,
                    $appointment_time
                ]);

                if ($doctorConflict->fetch()) {
                    throw new Exception("This doctor already has an appointment at the selected time.");
                }

                // Check whether the patient already has an active appointment.
                $patientConflict = $pdo->prepare("
                    SELECT id
                    FROM appointments
                    WHERE patient_id = ?
                      AND appointment_date = ?
                      AND appointment_time = ?
                      AND status IN ('pending', 'confirmed')
                    LIMIT 1
                ");
                $patientConflict->execute([
                    $patient_id,
                    $appointment_date,
                    $appointment_time
                ]);

                if ($patientConflict->fetch()) {
                    throw new Exception("This patient already has an appointment at the selected time.");
                }

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
                    $appointment_time . ':00',
                    $reason
                ]);

                $notification = $pdo->prepare("
                    INSERT INTO notifications (user_id, message, is_read)
                    VALUES (?, ?, 0)
                ");

                $notification->execute([
                    $patient['user_id'],
                    "Your appointment request has been registered successfully. Your appointment is currently pending approval."
                ]);

                $pdo->commit();

                $message = "Appointment registered successfully! Patient has been notified.";

            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($ex->getMessage());

                if (
                    str_contains($ex->getMessage(), 'already has an appointment') ||
                    str_contains($ex->getMessage(), 'does not exist')
                ) {
                    $error = $ex->getMessage();
                } else {
                    $error = "Could not register appointment. Please try again.";
                }
            }
        }
    }
}

// Update appointment status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh the page.";
    } else {
        $appointment_id = filter_var($_POST['appointment_id'] ?? '', FILTER_VALIDATE_INT);
        $new_status = $_POST['status'] ?? '';

        $allowed_statuses = ['pending', 'confirmed', 'completed', 'cancelled'];

        if ($appointment_id && in_array($new_status, $allowed_statuses, true)) {
            try {
                $pdo->beginTransaction();

                $check = $pdo->prepare("
                    SELECT a.status, p.user_id
                    FROM appointments a
                    JOIN patients p ON a.patient_id = p.id
                    WHERE a.id = ?
                    FOR UPDATE
                ");
                $check->execute([$appointment_id]);
                $appointment = $check->fetch(PDO::FETCH_ASSOC);

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

                    $statusMessages = [
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
                        $statusMessages[$new_status]
                    ]);

                    $message = "Appointment status updated successfully! Patient has been notified.";
                } else {
                    $message = "Appointment status has not changed.";
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

// Patients
$patients = $pdo->query("
    SELECT p.id, p.patient_number, u.full_name
    FROM patients p
    JOIN users u ON p.user_id = u.id
    ORDER BY u.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Doctors
$doctors = $pdo->query("
    SELECT d.id, u.full_name, d.specialization, dep.name AS department
    FROM doctors d
    JOIN users u ON d.user_id = u.id
    LEFT JOIN departments dep ON d.department_id = dep.id
    ORDER BY u.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

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
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'pending'), 0) AS pending,
        COALESCE(SUM(status = 'confirmed'), 0) AS confirmed,
        COALESCE(SUM(status = 'completed'), 0) AS completed,
        COALESCE(SUM(status = 'cancelled'), 0) AS cancelled
    FROM appointments
")->fetch(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Appointment Management | MediCare HMS</title>

<style>
* { margin:0; padding:0; box-sizing:border-box; }

body {
    font-family:Arial,sans-serif;
    background:#f3f6fb;
    color:#263445;
}

.header {
    background:#102a43;
    color:white;
    padding:22px 35px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap;
    gap:15px;
}

.header h2 { font-size:23px; margin-bottom:5px; }

.header a {
    color:white;
    text-decoration:none;
    margin-left:18px;
}

.container {
    max-width:1450px;
    margin:30px auto;
    padding:0 20px;
}

.page-heading { margin-bottom:25px; }

.page-heading h1 {
    font-size:27px;
    margin-bottom:8px;
}

.muted { color:#718096; }

.stats {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(170px,1fr));
    gap:18px;
    margin-bottom:25px;
}

.stat-card,.panel {
    background:white;
    border-radius:13px;
    box-shadow:0 3px 14px rgba(0,0,0,.04);
}

.stat-card { padding:22px; }

.stat-card h3 {
    font-size:28px;
    color:#2563eb;
    margin-bottom:8px;
}

.stat-card p { color:#718096; }

.panel {
    padding:25px;
    margin-bottom:25px;
}

.panel h2 {
    font-size:20px;
    margin-bottom:20px;
}

.form-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}

.form-group {
    display:flex;
    flex-direction:column;
    gap:8px;
}

.form-group label {
    font-weight:bold;
    font-size:14px;
}

input,select,textarea {
    width:100%;
    padding:12px;
    border:1px solid #d9e1eb;
    border-radius:8px;
    font-size:14px;
    background:white;
}

input:focus,select:focus,textarea:focus {
    outline:none;
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.10);
}

textarea { resize:vertical; min-height:85px; }

.full-width { grid-column:1/-1; }

button {
    background:#2563eb;
    color:white;
    border:0;
    padding:12px 20px;
    border-radius:8px;
    cursor:pointer;
    font-weight:bold;
}

button:hover { background:#1d4ed8; }

.message {
    padding:14px;
    border-radius:8px;
    margin-bottom:18px;
    background:#dcfce7;
    color:#166534;
}

.error {
    background:#fee2e2;
    color:#991b1b;
}

.slot-note {
    margin-top:8px;
    color:#64748b;
    font-size:12px;
}

.filter-form {
    display:flex;
    gap:12px;
    margin-bottom:20px;
    flex-wrap:wrap;
}

.filter-form input { flex:1; min-width:200px; }
.filter-form select { width:auto; min-width:150px; }

.table-wrapper { overflow-x:auto; }

table {
    width:100%;
    border-collapse:collapse;
    min-width:950px;
}

th,td {
    padding:14px 12px;
    border-bottom:1px solid #edf0f5;
    text-align:left;
    font-size:13px;
}

th {
    background:#f5f8fb;
    color:#536477;
}

.status {
    display:inline-block;
    padding:6px 10px;
    border-radius:20px;
    font-size:11px;
    font-weight:bold;
    text-transform:capitalize;
}

.pending { background:#fff0c2; color:#8a6200; }
.confirmed { background:#d8edff; color:#075b9b; }
.completed { background:#d8f5e5; color:#187044; }
.cancelled { background:#ffe0e0; color:#a12626; }

.status-form {
    display:flex;
    gap:7px;
}

.status-form select {
    min-width:110px;
    padding:8px;
}

.status-form button { padding:8px 12px; }

.empty {
    text-align:center;
    padding:30px;
    color:#718096;
}

@media(max-width:650px) {
    .form-grid { grid-template-columns:1fr; }
    .header { padding:20px; }
    .panel { padding:17px; }
    .container { padding:0 12px; }
    .filter-form input,.filter-form select { width:100%; }
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
        <a href="../patients/index.php">Patients</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<main class="container">

    <div class="page-heading">
        <h1>Appointment Management</h1>
        <p class="muted">Register, search and manage patient appointments.</p>
    </div>

    <?php if ($message): ?>
        <div class="message"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>

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

    <section class="panel">
        <h2>+ Register New Appointment</h2>

        <?php if (!$patients || !$doctors): ?>
            <div class="message error">
                You need at least one registered patient and one registered doctor.
            </div>
        <?php else: ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="form-grid">

                <div class="form-group">
                    <label>Select Patient *</label>
                    <select name="patient_id" required>
                        <option value="">Choose Patient</option>
                        <?php foreach ($patients as $patient): ?>
                            <option value="<?= (int)$patient['id'] ?>">
                                <?= e($patient['full_name']) ?> (<?= e($patient['patient_number']) ?>)
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
                                Dr. <?= e($doctor['full_name']) ?> - <?= e($doctor['specialization']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Appointment Date *</label>
                    <input
                        type="date"
                        name="appointment_date"
                        min="<?= date('Y-m-d') ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Appointment Time *</label>
                    <select name="appointment_time" required>
                        <option value="">Choose a time slot</option>
                        <?php
                        for ($hour = 8; $hour <= 16; $hour++) {
                            foreach ([0, 30] as $minute) {
                                if ($hour === 16 && $minute === 30) {
                                    continue;
                                }
                                $time = sprintf('%02d:%02d', $hour, $minute);
                                $display = date('h:i A', strtotime($time));
                                echo '<option value="' . e($time) . '">' . e($display) . '</option>';
                            }
                        }
                        ?>
                    </select>
                    <p class="slot-note">
                        Appointment slots are available every 30 minutes, from 08:00 AM to 04:00 PM.
                    </p>
                </div>

                <div class="form-group full-width">
                    <label>Reason for Appointment</label>
                    <textarea name="reason" maxlength="1000"
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

    <section class="panel">
        <h2>All Appointments</h2>

        <form method="GET" class="filter-form">
            <input
                type="text"
                name="search"
                placeholder="Search patient, ID or doctor..."
                value="<?= e($search) ?>"
            >

            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach (['pending','confirmed','completed','cancelled'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status_filter === $s ? 'selected' : '' ?>>
                        <?= ucfirst($s) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Search</button>
            <a href="appointments.php" style="padding:12px;text-decoration:none;color:#2563eb;">
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
                <?php if ($appointments): ?>
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
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="appointment_id" value="<?= (int)$appointment['id'] ?>">

                                    <select name="status">
                                        <?php foreach (['pending','confirmed','completed','cancelled'] as $s): ?>
                                            <option value="<?= e($s) ?>" <?= $appointment['status'] === $s ? 'selected' : '' ?>>
                                                <?= ucfirst($s) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button type="submit" name="update_status">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="empty">No appointments found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>
