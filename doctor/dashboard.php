
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['doctor']);

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    die('User session not found. Please login again.');
}

// 1. DOCTOR INFORMATION
$doctorStmt = $pdo->prepare("
    SELECT
        d.id,
        d.specialization,
        d.phone,
        u.full_name,
        u.email,
        dep.name AS department_name
    FROM doctors d
    INNER JOIN users u ON d.user_id = u.id
    LEFT JOIN departments dep ON d.department_id = dep.id
    WHERE d.user_id = ?
    LIMIT 1
");
$doctorStmt->execute([$userId]);
$doctor = $doctorStmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    die('Doctor profile not found.');
}

$doctorId = (int)$doctor['id'];

// 2. DASHBOARD STATISTICS
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_appointments,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_appointments,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_appointments,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_appointments,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_appointments
    FROM appointments
    WHERE doctor_id = ?
");
$statsStmt->execute([$doctorId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// 3. TOTAL UNIQUE PATIENTS
$patientsStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT patient_id)
    FROM appointments
    WHERE doctor_id = ?
");
$patientsStmt->execute([$doctorId]);
$totalPatients = (int)$patientsStmt->fetchColumn();

// 4. TODAY'S APPOINTMENTS COUNT
$todayCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM appointments
    WHERE doctor_id = ?
    AND appointment_date = CURDATE()
");
$todayCountStmt->execute([$doctorId]);
$todayCount = (int)$todayCountStmt->fetchColumn();

// 5. TODAY'S APPOINTMENTS
$todayStmt = $pdo->prepare("
    SELECT
        a.id,
        a.appointment_date,
        a.appointment_time,
        a.reason,
        a.status,
        p.patient_number,
        u.full_name AS patient_name,
        p.phone AS patient_phone
    FROM appointments a
    INNER JOIN patients p ON a.patient_id = p.id
    LEFT JOIN users u ON p.user_id = u.id
    WHERE a.doctor_id = ?
    AND a.appointment_date = CURDATE()
    ORDER BY a.appointment_time ASC
");
$todayStmt->execute([$doctorId]);
$todayAppointments = $todayStmt->fetchAll(PDO::FETCH_ASSOC);

// 6. RECENT APPOINTMENTS
$recentStmt = $pdo->prepare("
    SELECT
        a.id,
        a.appointment_date,
        a.appointment_time,
        a.status,
        p.patient_number,
        u.full_name AS patient_name
    FROM appointments a
    INNER JOIN patients p ON a.patient_id = p.id
    LEFT JOIN users u ON p.user_id = u.id
    WHERE a.doctor_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
    LIMIT 8
");
$recentStmt->execute([$doctorId]);
$recentAppointments = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// 7. HELPER FUNCTIONS
function h($value) {
    return htmlspecialchars(
        (string)($value ?? 'N/A'),
        ENT_QUOTES,
        'UTF-8'
    );
}

function statusClass($status) {
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

<title>Doctor Dashboard | MediCare HMS</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f1f5f9;
    color: #1e293b;
}

/* SIDEBAR */
.sidebar {
    width: 250px;
    height: 100vh;
    background: #0f172a;
    color: white;
    position: fixed;
    left: 0;
    top: 0;
    padding: 25px 18px;
    overflow-y: auto;
    z-index: 10;
}

.brand {
    font-size: 23px;
    font-weight: bold;
    margin-bottom: 35px;
    color: #5eead4;
}

.doctor-info {
    padding: 15px 10px;
    border-bottom: 1px solid #334155;
    margin-bottom: 20px;
}

.doctor-info strong {
    display: block;
    margin-bottom: 7px;
}

.doctor-info small {
    color: #94a3b8;
}

.sidebar a {
    display: block;
    color: #cbd5e1;
    text-decoration: none;
    padding: 13px;
    margin: 5px 0;
    border-radius: 8px;
    transition: .3s;
    font-size: 14px;
}

.sidebar a.active,
.sidebar a:hover {
    background: #0d9488;
    color: white;
}

/* MAIN CONTENT */
.main {
    margin-left: 250px;
    padding: 30px;
}

/* TOPBAR */
.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 28px;
}

.topbar h1 {
    font-size: 28px;
    margin-bottom: 8px;
}

.muted {
    color: #64748b;
}

.logout {
    background: #fee2e2;
    color: #b91c1c;
    text-decoration: none;
    padding: 12px 18px;
    border-radius: 8px;
    font-weight: bold;
}

.logout:hover {
    background: #fecaca;
}

/* PROFILE CARD */
.profile-card {
    background: linear-gradient(120deg, #ccfbf1, #e0f2fe);
    padding: 25px;
    border-radius: 14px;
    margin-bottom: 28px;
    border: 1px solid #99f6e4;
}

.profile-card h2 {
    margin-bottom: 12px;
    color: #115e59;
    font-size: 22px;
}

.profile-details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.profile-details p {
    color: #334155;
    font-size: 14px;
}

/* STATISTICS */
.stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 24px;
    border-radius: 13px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
    border: 1px solid #e2e8f0;
    transition: .3s;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0,0,0,.08);
}

.stat-card p {
    color: #64748b;
    margin-bottom: 14px;
    font-size: 14px;
    font-weight: 600;
}

.stat-card h2 {
    font-size: 32px;
    color: #0f766e;
}

.stat-card.total h2 {
    color: #2563eb;
}

.stat-card.today h2 {
    color: #7c3aed;
}

.stat-card.pending h2 {
    color: #d97706;
}

.stat-card.confirmed h2 {
    color: #2563eb;
}

.stat-card.completed h2 {
    color: #16a34a;
}

/* CONTENT CARDS */
.content-card {
    background: white;
    padding: 24px;
    border-radius: 13px;
    margin-bottom: 25px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
    border: 1px solid #e2e8f0;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.content-card h2 {
    font-size: 19px;
}

.view-all {
    color: #0d9488;
    text-decoration: none;
    font-size: 14px;
    font-weight: bold;
}

.view-all:hover {
    text-decoration: underline;
}

/* TABLE */
.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

th, td {
    text-align: left;
    padding: 15px;
    border-bottom: 1px solid #e2e8f0;
}

th {
    background: #f8fafc;
    color: #475569;
    font-size: 13px;
}

td {
    font-size: 14px;
}

tbody tr:hover {
    background: #f8fafc;
}

/* STATUS */
.status {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    text-transform: capitalize;
}

.pending {
    background: #fef3c7;
    color: #92400e;
}

.confirmed {
    background: #dbeafe;
    color: #1d4ed8;
}

.completed {
    background: #dcfce7;
    color: #166534;
}

.cancelled {
    background: #fee2e2;
    color: #991b1b;
}

.empty {
    text-align: center;
    padding: 30px;
    color: #64748b;
}

/* FOOTER */
.footer {
    text-align: center;
    padding: 20px;
    color: #94a3b8;
    font-size: 13px;
}

/* RESPONSIVE */
@media(max-width: 1100px) {
    .stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media(max-width: 700px) {
    .sidebar {
        position: relative;
        width: 100%;
        height: auto;
    }

    .main {
        margin-left: 0;
        padding: 18px;
    }

    .stats {
        grid-template-columns: 1fr;
    }

    .profile-details {
        grid-template-columns: 1fr;
    }

    .topbar h1 {
        font-size: 23px;
    }
}
</style>
</head>

<body>

<!-- SIDEBAR -->
<aside class="sidebar">

    <div class="brand">MediCare HMS</div>

    <div class="doctor-info">
        <strong>Dr. <?= h($doctor['full_name']) ?></strong>
        <small><?= h($doctor['specialization']) ?></small>
    </div>

    <a href="dashboard.php" class="active">
        ▦ Dashboard
    </a>

    <a href="#today">
        ▣ Today's Appointments
    </a>

    <a href="appointments.php">
        ◷ Manage Appointments
    </a>

    <a href="medical-records.php">
        ✚ Medical Records
    </a>

    <a href="patient-history.php">
        ▤ Patient Medical History
    </a>

    <a href="#recent">
        ◷ Recent Appointments
    </a>

    <a href="profile.php">
    ◉ My Profile
</a>

    <a href="../logout.php">
        ⇥ Logout
    </a>

</aside>

<!-- MAIN CONTENT -->
<main class="main">

    <!-- TOPBAR -->
    <div class="topbar">
        <div>
            <h1>Doctor Dashboard</h1>
            <p class="muted">
                Welcome back, Dr. <?= h($doctor['full_name']) ?>
            </p>
        </div>

        <a href="../logout.php" class="logout">Logout</a>
    </div>

    <!-- DOCTOR PROFILE -->
    <section class="profile-card" id="profile">

        <h2>Dr. <?= h($doctor['full_name']) ?></h2>

        <div class="profile-details">

            <p>
                <strong>Department:</strong>
                <?= h($doctor['department_name']) ?>
            </p>

            <p>
                <strong>Specialization:</strong>
                <?= h($doctor['specialization']) ?>
            </p>

            <p>
                <strong>Email:</strong>
                <?= h($doctor['email']) ?>
            </p>

            <p>
                <strong>Phone:</strong>
                <?= h($doctor['phone']) ?>
            </p>

        </div>
    </section>

    <!-- STATISTICS -->
    <section class="stats">

        <div class="stat-card total">
            <p>Total Patients</p>
            <h2><?= $totalPatients ?></h2>
        </div>

        <div class="stat-card total">
            <p>Total Appointments</p>
            <h2><?= (int)($stats['total_appointments'] ?? 0) ?></h2>
        </div>

        <div class="stat-card today">
            <p>Today's Appointments</p>
            <h2><?= $todayCount ?></h2>
        </div>

        <div class="stat-card pending">
            <p>Pending Appointments</p>
            <h2><?= (int)($stats['pending_appointments'] ?? 0) ?></h2>
        </div>

        <div class="stat-card confirmed">
            <p>Confirmed Appointments</p>
            <h2><?= (int)($stats['confirmed_appointments'] ?? 0) ?></h2>
        </div>

        <div class="stat-card completed">
            <p>Completed Appointments</p>
            <h2><?= (int)($stats['completed_appointments'] ?? 0) ?></h2>
        </div>

    </section>

    <!-- QUICK ACCESS -->
    <section class="content-card">

        <div class="section-header">
            <h2>Doctor Workspace</h2>
        </div>

        <div class="stats">

            <div class="stat-card">
                <p>Appointment Management</p>
                <h2>📅</h2>
                <a href="appointments.php" class="view-all">
                    Manage Appointments →
                </a>
            </div>

            <div class="stat-card">
                <p>Create Medical Records</p>
                <h2>📋</h2>
                <a href="medical-records.php" class="view-all">
                    Open Medical Records →
                </a>
            </div>

            <div class="stat-card">
                <p>Patient Medical History</p>
                <h2>🗂️</h2>
                <a href="patient-history.php" class="view-all">
                    View Patient History →
                </a>
            </div>

        </div>

    </section>

    <!-- TODAY'S APPOINTMENTS -->
    <section class="content-card" id="today">

        <div class="section-header">
            <h2>Today's Appointments</h2>

            <a href="appointments.php" class="view-all">
                Manage Appointments
            </a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Patient Number</th>
                        <th>Time</th>
                        <th>Reason</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($todayAppointments)): ?>

                    <tr>
                        <td colspan="5" class="empty">
                            No appointments scheduled for today.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($todayAppointments as $appointment): ?>
                        <tr>
                            <td><?= h($appointment['patient_name']) ?></td>
                            <td><?= h($appointment['patient_number']) ?></td>
                            <td><?= h($appointment['appointment_time']) ?></td>
                            <td><?= h($appointment['reason']) ?></td>
                            <td>
                                <span class="status <?= statusClass($appointment['status']) ?>">
                                    <?= h($appointment['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>
        </div>
    </section>

    <!-- RECENT APPOINTMENTS -->
    <section class="content-card" id="recent">

        <div class="section-header">
            <h2>My Recent Appointments</h2>

            <a href="appointments.php" class="view-all">
                View All
            </a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Patient Number</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($recentAppointments)): ?>

                    <tr>
                        <td colspan="5" class="empty">
                            No appointments found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($recentAppointments as $appointment): ?>
                        <tr>
                            <td><?= h($appointment['patient_name']) ?></td>
                            <td><?= h($appointment['patient_number']) ?></td>
                            <td><?= h($appointment['appointment_date']) ?></td>
                            <td><?= h($appointment['appointment_time']) ?></td>
                            <td>
                                <span class="status <?= statusClass($appointment['status']) ?>">
                                    <?= h($appointment['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>
        </div>
    </section>

    <!-- FOOTER -->
    <div class="footer">
        MediCare Hospital Management System
        &copy; <?= date('Y') ?>
    </div>

</main>

</body>
</html>
