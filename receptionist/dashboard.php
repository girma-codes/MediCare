
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['receptionist']);

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Dashboard statistics
$stats = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM patients) AS total_patients,
        (SELECT COUNT(*) FROM doctors) AS total_doctors,
        (SELECT COUNT(*) FROM appointments) AS total_appointments,
        (SELECT COUNT(*) FROM appointments WHERE status = 'pending') AS pending_appointments,
        (SELECT COUNT(*) FROM appointments WHERE status = 'confirmed') AS confirmed_appointments,
        (SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()) AS today_appointments
")->fetch(PDO::FETCH_ASSOC);

// Today's appointments
$stmt = $pdo->prepare("
    SELECT
        a.id,
        a.appointment_date,
        a.appointment_time,
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
    WHERE a.appointment_date = CURDATE()
    ORDER BY a.appointment_time ASC
    LIMIT 8
");
$stmt->execute();
$todayAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent appointments
$stmt = $pdo->query("
    SELECT
        a.id,
        a.appointment_date,
        a.appointment_time,
        a.status,
        p.patient_number,
        pu.full_name AS patient_name,
        du.full_name AS doctor_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    JOIN users pu ON p.user_id = pu.id
    JOIN doctors d ON a.doctor_id = d.id
    JOIN users du ON d.user_id = du.id
    ORDER BY a.id DESC
    LIMIT 5
");
$recentAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusClasses = [
    'pending' => 'pending',
    'confirmed' => 'confirmed',
    'completed' => 'completed',
    'cancelled' => 'cancelled'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receptionist Dashboard | MediCare HMS</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: #f3f6fb;
    color: #243449;
}

.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 250px;
    height: 100vh;
    background: #102a43;
    color: white;
    padding: 25px 16px;
    z-index: 100;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 5px 10px 30px;
    border-bottom: 1px solid rgba(255,255,255,.12);
}

.brand-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: #2563eb;
    display: grid;
    place-items: center;
    font-size: 20px;
}

.brand h2 { font-size: 19px; }
.brand small { color: #a9bdd0; }

.nav-title {
    margin: 30px 10px 12px;
    color: #8fa9c1;
    font-size: 11px;
    letter-spacing: 1px;
    text-transform: uppercase;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 13px 14px;
    margin-bottom: 7px;
    border-radius: 9px;
    text-decoration: none;
    color: #c7d5e3;
    font-size: 14px;
    transition: .2s;
}

.nav-link:hover,
.nav-link.active {
    background: #2563eb;
    color: white;
}

.nav-link i {
    width: 20px;
    text-align: center;
}

.sidebar-bottom {
    position: absolute;
    bottom: 20px;
    left: 16px;
    right: 16px;
}

.main {
    margin-left: 250px;
    padding: 30px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 30px;
}

.topbar h1 {
    font-size: 27px;
    margin-bottom: 7px;
}

.subtitle {
    color: #7b8b9e;
    font-size: 14px;
}

.date-box {
    background: white;
    padding: 13px 18px;
    border-radius: 10px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
    color: #52657a;
    font-size: 14px;
}

.welcome {
    background: linear-gradient(120deg, #102a43, #2563eb);
    color: white;
    border-radius: 16px;
    padding: 28px;
    margin-bottom: 27px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
}

.welcome h2 {
    font-size: 23px;
    margin-bottom: 10px;
}

.welcome p {
    color: #d8e6f5;
    font-size: 14px;
    line-height: 1.6;
}

.welcome-icon {
    font-size: 65px;
    opacity: .85;
}

.section-title {
    font-size: 19px;
    margin-bottom: 18px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 13px;
    padding: 21px;
    box-shadow: 0 3px 14px rgba(0,0,0,.04);
    display: flex;
    align-items: center;
    gap: 15px;
}

.stat-icon {
    width: 49px;
    height: 49px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    font-size: 20px;
}

.blue { background: #e4efff; color: #2563eb; }
.green { background: #dcfce7; color: #15803d; }
.orange { background: #fff0d9; color: #d97706; }
.purple { background: #f0e8ff; color: #7c3aed; }
.red { background: #ffe4e6; color: #e11d48; }

.stat-info h3 {
    font-size: 25px;
    margin-bottom: 5px;
}

.stat-info p {
    font-size: 13px;
    color: #8291a3;
}

/* Quick Actions */
.quick-actions {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.action-card {
    background: white;
    padding: 22px 18px;
    border-radius: 12px;
    text-decoration: none;
    color: #243449;
    border: 1px solid #edf1f6;
    transition: .25s;
    display: block;
}

.action-card:hover {
    transform: translateY(-4px);
    border-color: #2563eb;
    box-shadow: 0 8px 22px rgba(37,99,235,.10);
}

.action-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    font-size: 21px;
    margin-bottom: 15px;
}

.action-card h3 {
    font-size: 15px;
    margin-bottom: 8px;
}

.action-card p {
    font-size: 12px;
    color: #8291a3;
    line-height: 1.5;
}

.panel {
    background: white;
    padding: 23px;
    border-radius: 13px;
    margin-bottom: 25px;
    box-shadow: 0 3px 14px rgba(0,0,0,.04);
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
}

.panel-header h2 { font-size: 18px; }

.view-all {
    text-decoration: none;
    color: #2563eb;
    font-size: 13px;
    font-weight: bold;
}

.table-wrap { overflow-x: auto; }

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

th, td {
    padding: 14px 12px;
    text-align: left;
    border-bottom: 1px solid #edf1f6;
    font-size: 13px;
}

th {
    color: #8291a3;
    background: #f8fafc;
    font-size: 12px;
}

.status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    text-transform: capitalize;
}

.pending { background: #fff0c2; color: #8a6200; }
.confirmed { background: #d8edff; color: #075b9b; }
.completed { background: #d8f5e5; color: #187044; }
.cancelled { background: #ffe0e0; color: #a12626; }

.empty {
    padding: 25px;
    text-align: center;
    color: #8291a3;
}

.footer {
    text-align: center;
    color: #91a0b1;
    font-size: 12px;
    padding: 15px;
}

@media(max-width: 1150px) {
    .quick-actions {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 1000px) {
    .stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media(max-width: 750px) {
    .sidebar {
        width: 68px;
        padding: 20px 8px;
    }

    .brand {
        justify-content: center;
        padding: 5px 0 25px;
    }

    .brand-text, .nav-title, .nav-link span {
        display: none;
    }

    .nav-link {
        justify-content: center;
        padding: 14px 5px;
    }

    .nav-link i { font-size: 18px; }

    .sidebar-bottom {
        left: 8px;
        right: 8px;
    }

    .main {
        margin-left: 68px;
        padding: 20px 14px;
    }
}

@media(max-width: 520px) {
    .stats, .quick-actions {
        grid-template-columns: 1fr;
    }

    .topbar {
        align-items: flex-start;
        flex-direction: column;
    }

    .welcome { padding: 22px; }
    .welcome-icon { font-size: 42px; }
    .welcome h2 { font-size: 19px; }
    .panel { padding: 16px; }
}
</style>
</head>

<body>

<aside class="sidebar">

    <div class="brand">
        <div class="brand-icon">
            <i class="fas fa-hospital"></i>
        </div>
        <div class="brand-text">
            <h2>MediCare</h2>
            <small>Hospital Management</small>
        </div>
    </div>

    <p class="nav-title">Main Menu</p>

    <a href="dashboard.php" class="nav-link active">
        <i class="fas fa-chart-pie"></i>
        <span>Dashboard</span>
    </a>

    <a href="../patients/index.php" class="nav-link">
        <i class="fas fa-users"></i>
        <span>Patients</span>
    </a>

    <a href="appointments.php" class="nav-link">
        <i class="fas fa-calendar-check"></i>
        <span>Appointments</span>
    </a>

    <p class="nav-title">Account</p>

    <a href="../logout.php" class="nav-link">
        <i class="fas fa-sign-out-alt"></i>
        <span>Logout</span>
    </a>

    <div class="sidebar-bottom">
        <small style="color:#8fa9c1;font-size:11px;">
            MediCare HMS v1.0
        </small>
    </div>

</aside>

<main class="main">

    <header class="topbar">
        <div>
            <h1>Receptionist Dashboard</h1>
            <p class="subtitle">Manage patients and hospital appointments.</p>
        </div>

        <div class="date-box">
            <i class="far fa-calendar"></i>
            <?= date('F d, Y') ?>
        </div>
    </header>

    <section class="welcome">
        <div>
            <h2>Welcome to MediCare HMS!</h2>
            <p>
                Manage daily appointments, assist patients and coordinate
                with doctors from one central dashboard.
            </p>
        </div>

        <div class="welcome-icon">
            <i class="fas fa-user-nurse"></i>
        </div>
    </section>

    <h2 class="section-title">Overview</h2>

    <section class="stats">

        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <h3><?= (int)$stats['total_patients'] ?></h3>
                <p>Total Patients</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-user-doctor"></i></div>
            <div class="stat-info">
                <h3><?= (int)$stats['total_doctors'] ?></h3>
                <p>Available Doctors</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-calendar-days"></i></div>
            <div class="stat-info">
                <h3><?= (int)$stats['total_appointments'] ?></h3>
                <p>Total Appointments</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
            <div class="stat-info">
                <h3><?= (int)$stats['today_appointments'] ?></h3>
                <p>Today's Appointments</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon red"><i class="fas fa-hourglass-half"></i></div>
            <div class="stat-info">
                <h3><?= (int)$stats['pending_appointments'] ?></h3>
                <p>Pending Appointments</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <h3><?= (int)$stats['confirmed_appointments'] ?></h3>
                <p>Confirmed Appointments</p>
            </div>
        </div>

    </section>

    <h2 class="section-title">Quick Actions</h2>

    <section class="quick-actions">

        <a href="../patients/register.php" class="action-card">
            <div class="action-icon blue">
                <i class="fas fa-user-plus"></i>
            </div>
            <h3>Register Patient</h3>
            <p>Register a new patient in the hospital system.</p>
        </a>

        <a href="../patients/index.php" class="action-card">
            <div class="action-icon green">
                <i class="fas fa-users"></i>
            </div>
            <h3>View Patients</h3>
            <p>Search and view registered patient information.</p>
        </a>

        <a href="appointments.php" class="action-card">
            <div class="action-icon purple">
                <i class="fas fa-calendar-plus"></i>
            </div>
            <h3>Register Appointment</h3>
            <p>Create a new patient appointment.</p>
        </a>

        <a href="appointments.php" class="action-card">
            <div class="action-icon orange">
                <i class="fas fa-list-check"></i>
            </div>
            <h3>Manage Appointments</h3>
            <p>Search and update appointment status.</p>
        </a>

    </section>

    <section class="panel">

        <div class="panel-header">
            <h2>Today's Appointments</h2>
            <a href="appointments.php" class="view-all">View All</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Patient ID</th>
                        <th>Doctor</th>
                        <th>Time</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($todayAppointments): ?>
                    <?php foreach ($todayAppointments as $appointment): ?>
                        <tr>
                            <td><?= h($appointment['patient_name']) ?></td>
                            <td><?= h($appointment['patient_number']) ?></td>
                            <td>
                                Dr. <?= h($appointment['doctor_name']) ?>
                                <br>
                                <small><?= h($appointment['specialization']) ?></small>
                            </td>
                            <td><?= date('h:i A', strtotime($appointment['appointment_time'])) ?></td>
                            <td>
                                <span class="status <?= h($statusClasses[$appointment['status']] ?? 'pending') ?>">
                                    <?= h($appointment['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="empty">No appointments scheduled for today.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </section>

    <section class="panel">

        <div class="panel-header">
            <h2>Recent Appointments</h2>
            <a href="appointments.php" class="view-all">View All</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Appointment ID</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($recentAppointments): ?>
                    <?php foreach ($recentAppointments as $appointment): ?>
                        <tr>
                            <td>#<?= (int)$appointment['id'] ?></td>
                            <td>
                                <?= h($appointment['patient_name']) ?>
                                <br>
                                <small><?= h($appointment['patient_number']) ?></small>
                            </td>
                            <td>Dr. <?= h($appointment['doctor_name']) ?></td>
                            <td><?= h($appointment['appointment_date']) ?></td>
                            <td>
                                <span class="status <?= h($statusClasses[$appointment['status']] ?? 'pending') ?>">
                                    <?= h($appointment['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="empty">No appointments registered yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </section>

    <footer class="footer">
        &copy; <?= date('Y') ?> MediCare Hospital Management System.
        All rights reserved.
    </footer>

</main>

</body>
</html>
