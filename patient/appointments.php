
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['patient']);

$userId = $_SESSION['user_id'];

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Get logged-in patient
$stmt = $pdo->prepare("SELECT id, patient_number FROM patients WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$patient = $stmt->fetch();

if (!$patient) {
    exit("Patient profile not found.");
}

$patientId = $patient['id'];

// Get departments and doctors
$stmt = $pdo->query("
    SELECT
        d.id AS doctor_id,
        d.specialization,
        dep.id AS department_id,
        dep.name AS department_name,
        u.full_name AS doctor_name
    FROM doctors d
    INNER JOIN users u ON d.user_id = u.id
    LEFT JOIN departments dep ON d.department_id = dep.id
    WHERE u.status = 'active'
    ORDER BY u.full_name
");
$doctors = $stmt->fetchAll();

$success = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh the page.";
    } else {

        $doctorId = filter_input(INPUT_POST, 'doctor_id', FILTER_VALIDATE_INT);
        $date = $_POST['appointment_date'] ?? '';
        $time = $_POST['appointment_time'] ?? '';
        $reason = trim($_POST['reason'] ?? '');

        $dateValid = DateTime::createFromFormat('Y-m-d', $date);
        $timeValid = DateTime::createFromFormat('H:i', $time);

        if (!$doctorId || !$dateValid || $dateValid->format('Y-m-d') !== $date ||
            !$timeValid || $timeValid->format('H:i') !== $time) {
            $error = "Please enter valid appointment information.";
        } elseif ($date < date('Y-m-d')) {
            $error = "Please select today or a future date.";
        } elseif (strlen($reason) > 1000) {
            $error = "Reason must be 1000 characters or less.";
        } else {

            // Confirm doctor exists and is active
            $check = $pdo->prepare("
                SELECT d.id
                FROM doctors d
                INNER JOIN users u ON u.id = d.user_id
                WHERE d.id = ? AND u.status = 'active'
            ");
            $check->execute([$doctorId]);

            if (!$check->fetch()) {
                $error = "Selected doctor is not available.";
            } else {

                // Prevent duplicate booking for same doctor/date/time
                $check = $pdo->prepare("
                    SELECT id FROM appointments
                    WHERE doctor_id = ?
                    AND appointment_date = ?
                    AND appointment_time = ?
                    AND status IN ('pending', 'confirmed')
                ");
                $check->execute([$doctorId, $date, $time]);

                if ($check->fetch()) {
                    $error = "This doctor already has an appointment at that time. Please choose another time.";
                } else {

                    $insert = $pdo->prepare("
                        INSERT INTO appointments
                        (patient_id, doctor_id, appointment_date, appointment_time, reason, status)
                        VALUES (?, ?, ?, ?, ?, 'pending')
                    ");

                    $insert->execute([
                        $patientId,
                        $doctorId,
                        $date,
                        $time,
                        $reason !== '' ? $reason : null
                    ]);

                    $success = "Appointment request submitted successfully! Your status is Pending.";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book Appointment | MediCare HMS</title>

<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: Arial, sans-serif;
    background: #f3f7fb;
    color: #263445;
}

.container {
    max-width: 950px;
    margin: 40px auto;
    padding: 20px;
}

.header {
    margin-bottom: 25px;
}

.header h1 {
    color: #173b59;
    margin-bottom: 8px;
}

.header p { color: #718096; }

.card {
    background: white;
    padding: 30px;
    border-radius: 14px;
    box-shadow: 0 5px 25px rgba(0,0,0,.05);
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.field { margin-bottom: 20px; }

.field.full { grid-column: 1 / -1; }

label {
    display: block;
    margin-bottom: 8px;
    font-weight: bold;
}

input, select, textarea {
    width: 100%;
    padding: 13px;
    border: 1px solid #d8e1ea;
    border-radius: 8px;
    font-size: 15px;
    background: white;
}

textarea {
    min-height: 120px;
    resize: vertical;
}

input:focus, select:focus, textarea:focus {
    outline: 2px solid #8ad8ca;
    border-color: #168b7c;
}

button {
    border: 0;
    background: #137c72;
    color: white;
    padding: 14px 25px;
    border-radius: 8px;
    font-size: 15px;
    font-weight: bold;
    cursor: pointer;
}

button:hover { background: #0d655d; }

.message {
    padding: 14px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.success { background: #e2f7ec; color: #17683a; }
.error { background: #fde8e8; color: #a32222; }

.back {
    display: inline-block;
    margin-bottom: 20px;
    color: #137c72;
    text-decoration: none;
}

@media(max-width: 650px) {
    .form-grid { grid-template-columns: 1fr; }
    .field.full { grid-column: auto; }
    .card { padding: 20px; }
}
</style>
</head>

<body>
<div class="container">

<a class="back" href="dashboard.php">← Back to Dashboard</a>

<div class="header">
    <h1>Book an Appointment</h1>
    <p>Choose your preferred doctor and request a hospital appointment.</p>
</div>

<div class="card">

<?php if ($success): ?>
    <div class="message success"><?= e($success) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="message error"><?= e($error) ?></div>
<?php endif; ?>

<form method="POST">

<input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

<div class="form-grid">

    <div class="field full">
        <label for="doctor_id">Select Doctor *</label>
        <select name="doctor_id" id="doctor_id" required>
            <option value="">Choose a doctor</option>

            <?php foreach ($doctors as $doctor): ?>
                <option value="<?= e($doctor['doctor_id']) ?>"
                    <?= (($_POST['doctor_id'] ?? '') == $doctor['doctor_id']) ? 'selected' : '' ?>>
                    <?= e($doctor['doctor_name']) ?>
                    — <?= e($doctor['specialization']) ?>
                    (<?= e($doctor['department_name'] ?? 'No department') ?>)
                </option>
            <?php endforeach; ?>

        </select>
    </div>

    <div class="field">
        <label for="appointment_date">Appointment Date *</label>
        <input
            type="date"
            id="appointment_date"
            name="appointment_date"
            min="<?= date('Y-m-d') ?>"
            value="<?= e($_POST['appointment_date'] ?? '') ?>"
            required
        >
    </div>

    <div class="field">
        <label for="appointment_time">Appointment Time *</label>
        <input
            type="time"
            id="appointment_time"
            name="appointment_time"
            value="<?= e($_POST['appointment_time'] ?? '') ?>"
            required
        >
    </div>

    <div class="field full">
        <label for="reason">Reason for Visit</label>
        <textarea
            id="reason"
            name="reason"
            maxlength="1000"
            placeholder="Briefly describe why you need an appointment..."
        ><?= e($_POST['reason'] ?? '') ?></textarea>
    </div>

</div>

<button type="submit">Submit Appointment Request</button>

</form>
</div>
</div>
</body>
</html>
