<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['patient']);
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = ?");
$stmt->execute([$userId]);
$patient = $stmt->fetch();

if (!$patient) {
    exit("Patient profile not found.");
}

$patientId = $patient['id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = "Invalid request. Please refresh the page.";
    } else {
        $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);

        if ($appointmentId) {
            $cancel = $pdo->prepare("
                UPDATE appointments
                SET status = 'cancelled'
                WHERE id = ?
                AND patient_id = ?
                AND status = 'pending'
            ");
            $cancel->execute([$appointmentId, $patientId]);

            if ($cancel->rowCount() > 0) {
                $message = "Appointment cancelled successfully.";
            } else {
                $error = "Appointment cannot be cancelled.";
            }
        } else {
            $error = "Invalid appointment.";
        }
    }
}

$stmt = $pdo->prepare("
    SELECT
        a.id,
        a.appointment_date,
        a.appointment_time,
        a.reason,
        a.status,
        u.full_name AS doctor_name,
        d.name AS department_name
    FROM appointments a
    INNER JOIN doctors doc ON a.doctor_id = doc.id
    INNER JOIN users u ON doc.user_id = u.id
    LEFT JOIN departments d ON doc.department_id = d.id
    WHERE a.patient_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");

$stmt->execute([$patientId]);
$appointments = $stmt->fetchAll();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Appointments | MediCare HMS</title>

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
    margin-bottom: 30px;
    gap: 15px;
    flex-wrap: wrap;
}

.header h1 {
    font-size: 27px;
}

.subtitle {
    color: #7b899a;
    margin-top: 7px;
}

.card {
    background: white;
    border-radius: 14px;
    padding: 25px;
    box-shadow: 0 5px 20px rgba(0,0,0,.04);
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
}

th, td {
    text-align: left;
    padding: 17px 12px;
    border-bottom: 1px solid #edf0f4;
}

th {
    color: #8290a2;
    font-size: 12px;
    text-transform: uppercase;
}

td {
    font-size: 14px;
}

.status {
    display: inline-block;
    padding: 7px 11px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    text-transform: capitalize;
}

.pending { background: #fff3d6; color: #a66b00; }
.confirmed { background: #dff7ec; color: #168253; }
.completed { background: #e5edff; color: #315caa; }
.cancelled { background: #ffe4e4; color: #bd3434; }

.cancel-btn {
    background: #fff0f0;
    color: #c03939;
    border: none;
    padding: 9px 13px;
    border-radius: 7px;
    cursor: pointer;
    font-weight: bold;
}

.cancel-btn:hover {
    background: #ffdada;
}

.empty {
    text-align: center;
    padding: 50px 15px;
    color: #8290a2;
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
}
</style>
</head>

<body>

<aside class="sidebar">
    <div class="logo">✚ MediCare</div>

    <a href="dashboard.php">Dashboard</a>
    <a href="appointments.php">Book Appointment</a>
    <a href="my-appointments.php" class="active">My Appointments</a>
    <a href="medical-history.php">Medical History</a>
    <a href="logout.php">Logout</a>
</aside>

<main class="main">

    <div class="header">
        <div>
            <h1>My Appointments</h1>
            <p class="subtitle">Manage and track your hospital appointments.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert danger"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="card">

        <?php if (count($appointments) > 0): ?>

        <table>
            <thead>
                <tr>
                    <th>Doctor</th>
                    <th>Department</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($appointments as $appointment): ?>
                <tr>
                    <td><?= e($appointment['doctor_name']) ?></td>
                    <td><?= e($appointment['department_name'] ?? 'N/A') ?></td>
                    <td><?= e($appointment['appointment_date']) ?></td>
                    <td><?= e(date('h:i A', strtotime($appointment['appointment_time']))) ?></td>
                    <td><?= e($appointment['reason']) ?></td>

                    <td>
                        <span class="status <?= e($appointment['status']) ?>">
                            <?= e($appointment['status']) ?>
                        </span>
                    </td>

                    <td>
                        <?php if ($appointment['status'] === 'pending'): ?>
                            <form method="POST"
                                  onsubmit="return confirm('Are you sure you want to cancel this appointment?');">

                                <input type="hidden" name="csrf_token"
                                       value="<?= e($_SESSION['csrf_token']) ?>">

                                <input type="hidden" name="appointment_id"
                                       value="<?= e($appointment['id']) ?>">

                                <button type="submit" class="cancel-btn">
                                    Cancel
                                </button>
                            </form>
                        <?php else: ?>
                            <span>—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            </tbody>
        </table>

        <?php else: ?>

            <div class="empty">
                <h2>No Appointments Found</h2>
                <p>You have not booked any appointments yet.</p>
                <br>
                <a href="appointments.php">Book an Appointment</a>
            </div>

        <?php endif; ?>

    </section>

</main>

</body>
</html>