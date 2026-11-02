
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin', 'receptionist']);

$patientId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$patientId || $patientId <= 0) {
    http_response_code(400);
    exit('Invalid patient ID.');
}

function e($value) {
    return htmlspecialchars((string)($value ?? 'N/A'), ENT_QUOTES, 'UTF-8');
}

// Get patient information
$sql = "
    SELECT
        p.id,
        p.patient_number,
        p.date_of_birth,
        p.gender,
        p.phone,
        p.address,
        p.emergency_contact,
        p.created_at,
        u.full_name,
        u.email,
        u.status
    FROM patients p
    LEFT JOIN users u ON p.user_id = u.id
    WHERE p.id = ?
    LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$patientId]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    http_response_code(404);
    exit('Patient not found.');
}

// Get patient's appointment history
$appointmentStmt = $pdo->prepare("
    SELECT
        a.id,
        a.appointment_date,
        a.appointment_time,
        a.reason,
        a.status,
        du.full_name AS doctor_name,
        d.specialization,
        dep.name AS department
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.id
    JOIN users du ON d.user_id = du.id
    LEFT JOIN departments dep ON d.department_id = dep.id
    WHERE a.patient_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");

$appointmentStmt->execute([$patientId]);
$appointments = $appointmentStmt->fetchAll(PDO::FETCH_ASSOC);

// Appointment statistics
$totalAppointments = count($appointments);
$upcomingAppointments = 0;
$completedAppointments = 0;
$pendingAppointments = 0;

foreach ($appointments as $appointment) {
    $appointmentDateTime = strtotime(
        $appointment['appointment_date'] . ' ' . $appointment['appointment_time']
    );

    if (
        $appointmentDateTime >= time() &&
        in_array($appointment['status'], ['pending', 'confirmed'], true)
    ) {
        $upcomingAppointments++;
    }

    if ($appointment['status'] === 'completed') {
        $completedAppointments++;
    }

    if ($appointment['status'] === 'pending') {
        $pendingAppointments++;
    }
}

$statusClasses = [
    'pending' => 'pending',
    'confirmed' => 'confirmed',
    'completed' => 'completed',
    'cancelled' => 'cancelled'
];

$dashboardUrl = ($_SESSION['role'] ?? '') === 'receptionist'
    ? '../receptionist/dashboard.php'
    : '../admin/dashboard.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Patient Details | MediCare HMS</title>

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    background: #f1f5f9;
    color: #1e293b;
    padding: 30px;
}

.container {
    max-width: 1200px;
    margin: auto;
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
    margin-bottom: 7px;
}

.subtitle {
    color: #64748b;
}

.header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 18px;
    background: #2563eb;
    color: white;
    border-radius: 8px;
    text-decoration: none;
    font-size: 14px;
    font-weight: bold;
}

.button:hover {
    background: #1d4ed8;
}

.button.back {
    background: white;
    color: #334155;
    border: 1px solid #cbd5e1;
}

.card {
    background: white;
    padding: 28px;
    border-radius: 14px;
    box-shadow: 0 4px 20px rgba(0,0,0,.04);
    margin-bottom: 25px;
}

.profile {
    text-align: center;
    padding: 20px;
    border-bottom: 1px solid #e2e8f0;
    margin-bottom: 25px;
}

.avatar {
    width: 75px;
    height: 75px;
    border-radius: 50%;
    background: #dbeafe;
    color: #1d4ed8;
    display: grid;
    place-items: center;
    margin: auto;
    font-size: 28px;
    font-weight: bold;
}

.profile h2 {
    margin: 13px 0 6px;
}

.patient-number {
    color: #2563eb;
    font-weight: bold;
}

.section-title {
    font-size: 20px;
    margin-bottom: 20px;
}

.details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 17px;
}

.item {
    padding: 16px;
    background: #f8fafc;
    border-radius: 9px;
}

.item label {
    display: block;
    color: #64748b;
    font-size: 13px;
    margin-bottom: 8px;
}

.item strong {
    overflow-wrap: anywhere;
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 17px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    padding: 21px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
}

.stat-card i {
    font-size: 21px;
    color: #2563eb;
    margin-bottom: 13px;
}

.stat-card h3 {
    font-size: 27px;
    margin-bottom: 6px;
}

.stat-card p {
    font-size: 13px;
    color: #64748b;
}

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th, td {
    padding: 15px 12px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
}

th {
    background: #f8fafc;
    color: #64748b;
    font-size: 12px;
    text-transform: uppercase;
}

.status {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    text-transform: capitalize;
}

.pending {
    background: #fff0c2;
    color: #8a6200;
}

.confirmed {
    background: #d8edff;
    color: #075b9b;
}

.completed {
    background: #d8f5e5;
    color: #187044;
}

.cancelled {
    background: #ffe0e0;
    color: #a12626;
}

.empty {
    text-align: center;
    padding: 35px;
    color: #64748b;
}

.empty i {
    display: block;
    font-size: 30px;
    margin-bottom: 12px;
    color: #94a3b8;
}

.footer {
    text-align: center;
    color: #94a3b8;
    font-size: 12px;
    padding: 15px;
}

@media(max-width: 850px) {
    .stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media(max-width: 600px) {
    body {
        padding: 15px;
    }

    .details,
    .stats {
        grid-template-columns: 1fr;
    }

    .card {
        padding: 18px;
    }

    .header h1 {
        font-size: 23px;
    }
}
</style>
</head>

<body>

<div class="container">

    <header class="header">
        <div>
            <h1>Patient Details</h1>
            <p class="subtitle">Complete patient information and appointment history.</p>
        </div>

        <div class="header-actions">
            <a href="index.php" class="button back">
                <i class="fas fa-arrow-left"></i>
                Back to Patients
            </a>

            <a href="../receptionist/appointments.php" class="button">
                <i class="fas fa-calendar-plus"></i>
                Appointments
            </a>
        </div>
    </header>

    <!-- Patient Information -->
    <section class="card">

        <div class="profile">
            <div class="avatar">
                <?= e(strtoupper(substr($patient['full_name'] ?? 'P', 0, 1))) ?>
            </div>

            <h2><?= e($patient['full_name']) ?></h2>

            <div class="patient-number">
                <?= e($patient['patient_number']) ?>
            </div>
        </div>

        <h2 class="section-title">Personal Information</h2>

        <div class="details">

            <div class="item">
                <label>Full Name</label>
                <strong><?= e($patient['full_name']) ?></strong>
            </div>

            <div class="item">
                <label>Patient Number</label>
                <strong><?= e($patient['patient_number']) ?></strong>
            </div>

            <div class="item">
                <label>Email Address</label>
                <strong><?= e($patient['email']) ?></strong>
            </div>

            <div class="item">
                <label>Gender</label>
                <strong><?= e(ucfirst($patient['gender'] ?? 'N/A')) ?></strong>
            </div>

            <div class="item">
                <label>Date of Birth</label>
                <strong><?= e($patient['date_of_birth']) ?></strong>
            </div>

            <div class="item">
                <label>Phone Number</label>
                <strong><?= e($patient['phone']) ?></strong>
            </div>

            <div class="item">
                <label>Emergency Contact</label>
                <strong><?= e($patient['emergency_contact']) ?></strong>
            </div>

            <div class="item">
                <label>Account Status</label>
                <strong><?= e(ucfirst($patient['status'] ?? 'N/A')) ?></strong>
            </div>

            <div class="item">
                <label>Address</label>
                <strong><?= e($patient['address']) ?></strong>
            </div>

            <div class="item">
                <label>Registration Date</label>
                <strong><?= e($patient['created_at']) ?></strong>
            </div>

        </div>
    </section>

    <!-- Appointment Statistics -->
    <h2 class="section-title">Appointment Overview</h2>

    <section class="stats">

        <div class="stat-card">
            <i class="fas fa-calendar-days"></i>
            <h3><?= $totalAppointments ?></h3>
            <p>Total Appointments</p>
        </div>

        <div class="stat-card">
            <i class="fas fa-calendar-check"></i>
            <h3><?= $upcomingAppointments ?></h3>
            <p>Upcoming Appointments</p>
        </div>

        <div class="stat-card">
            <i class="fas fa-hourglass-half"></i>
            <h3><?= $pendingAppointments ?></h3>
            <p>Pending Appointments</p>
        </div>

        <div class="stat-card">
            <i class="fas fa-circle-check"></i>
            <h3><?= $completedAppointments ?></h3>
            <p>Completed Appointments</p>
        </div>

    </section>

    <!-- Appointment History -->
    <section class="card">

        <h2 class="section-title">
            <i class="fas fa-history"></i>
            Patient Appointment History
        </h2>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Appointment ID</th>
                        <th>Doctor</th>
                        <th>Department</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Reason</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($appointments) > 0): ?>

                    <?php foreach ($appointments as $appointment): ?>
                        <tr>
                            <td>#<?= (int)$appointment['id'] ?></td>

                            <td>
                                Dr. <?= e($appointment['doctor_name']) ?>
                                <br>
                                <small><?= e($appointment['specialization']) ?></small>
                            </td>

                            <td><?= e($appointment['department']) ?></td>

                            <td><?= e($appointment['appointment_date']) ?></td>

                            <td>
                                <?= e(date(
                                    'h:i A',
                                    strtotime($appointment['appointment_time'])
                                )) ?>
                            </td>

                            <td><?= e($appointment['reason'] ?: 'Not specified') ?></td>

                            <td>
                                <span class="status <?= e($statusClasses[$appointment['status']] ?? 'pending') ?>">
                                    <?= e($appointment['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="7" class="empty">
                            <i class="fas fa-calendar-xmark"></i>
                            No appointment history found for this patient.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </section>

    <p style="margin:20px 0;">
        <a href="<?= e($dashboardUrl) ?>" class="button back">
            <i class="fas fa-arrow-left"></i>
            Back to Dashboard
        </a>
    </p>

    <footer class="footer">
        &copy; <?= date('Y') ?> MediCare Hospital Management System.
        All rights reserved.
    </footer>

</div>

</body>
</html>
