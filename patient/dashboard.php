
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['patient']);

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Patient';

// Get patient profile
$stmt = $pdo->prepare("
    SELECT *
    FROM patients
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    exit("Patient profile not found. Please contact the receptionist.");
}

$patientId = $patient['id'];

// Count appointments
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM appointments
    WHERE patient_id = ?
");
$stmt->execute([$patientId]);
$totalAppointments = (int)$stmt->fetchColumn();

// Count pending appointments
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM appointments
    WHERE patient_id = ? AND status = 'pending'
");
$stmt->execute([$patientId]);
$pendingAppointments = (int)$stmt->fetchColumn();

// Count medical records
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM medical_records
    WHERE patient_id = ?
");
$stmt->execute([$patientId]);
$totalRecords = (int)$stmt->fetchColumn();

// Get recent appointments
$stmt = $pdo->prepare("
    SELECT
        a.id,
        a.appointment_date,
        a.appointment_time,
        a.reason,
        a.status,
        u.full_name AS doctor_name,
        d.specialization
    FROM appointments a
    LEFT JOIN doctors d ON a.doctor_id = d.id
    LEFT JOIN users u ON d.user_id = u.id
    WHERE a.patient_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
    LIMIT 5
");
$stmt->execute([$patientId]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard | MediCare HMS</title>

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

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100vh;
            background: #102b46;
            color: white;
            padding: 28px 18px;
        }

        .brand {
            font-size: 23px;
            font-weight: bold;
            margin-bottom: 38px;
        }

        .brand span {
            color: #35c6a5;
        }

        .nav-link {
            display: block;
            color: #dce7f2;
            text-decoration: none;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 8px;
            transition: .2s;
        }

        .nav-link:hover,
        .nav-link.active {
            background: #1e496d;
            color: white;
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

        .welcome h1 {
            font-size: 27px;
            margin-bottom: 7px;
        }

        .welcome p {
            color: #718096;
        }

        .logout {
            background: #e74c3c;
            color: white;
            padding: 11px 18px;
            text-decoration: none;
            border-radius: 7px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 13px;
            box-shadow: 0 4px 15px rgba(0,0,0,.04);
            border-left: 4px solid #20b99a;
        }

        .stat-card p {
            color: #718096;
            margin-bottom: 12px;
        }

        .stat-card h2 {
            font-size: 30px;
            color: #173b59;
        }

        .panel {
            background: white;
            padding: 24px;
            border-radius: 13px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,.04);
        }

        .panel h2 {
            margin-bottom: 20px;
            font-size: 20px;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .profile-item small {
            display: block;
            color: #718096;
            margin-bottom: 6px;
        }

        .profile-item strong {
            color: #263445;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        th, td {
            text-align: left;
            padding: 14px 12px;
            border-bottom: 1px solid #edf1f5;
        }

        th {
            color: #718096;
            font-size: 13px;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            background: #e8f4ff;
            color: #1769aa;
        }

        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .action {
            display: inline-block;
            background: #137c72;
            color: white;
            text-decoration: none;
            padding: 12px 18px;
            border-radius: 8px;
        }

        .action.secondary {
            background: #e9f1f7;
            color: #173b59;
        }

        footer {
            text-align: center;
            color: #8794a3;
            padding: 25px 0;
        }

        @media (max-width: 850px) {
            .sidebar {
                position: static;
                width: 100%;
                height: auto;
            }

            .main {
                margin-left: 0;
                padding: 20px;
            }

            .cards {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 500px) {
            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .profile-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar">
    <div class="brand">MediCare <span>HMS</span></div>

    <a class="nav-link active" href="dashboard.php">Dashboard</a>
    <a class="nav-link" href="appointments.php">Book Appointment</a>
    <a class="nav-link" href="my-appointments.php">My Appointments</a>
    <a class="nav-link" href="medical-history.php">Medical History</a>
    <a class="nav-link" href="profile.php">My Profile</a>
    <a class="nav-link" href="notifications.php">Notifications</a>
    <a class="nav-link" href="../logout.php">Logout</a>
</aside>

<main class="main">

    <header class="topbar">
        <div class="welcome">
            <h1>Patient Dashboard</h1>
            <p>Welcome back, <?= e($fullName) ?>!</p>
        </div>

        <a class="logout" href="../logout.php">Logout</a>
    </header>

    <section class="cards">
        <div class="stat-card">
            <p>Total Appointments</p>
            <h2><?= $totalAppointments ?></h2>
        </div>

        <div class="stat-card">
            <p>Pending Appointments</p>
            <h2><?= $pendingAppointments ?></h2>
        </div>

        <div class="stat-card">
            <p>Medical Records</p>
            <h2><?= $totalRecords ?></h2>
        </div>
    </section>

    <section class="panel">
        <h2>My Personal Information</h2>

        <div class="profile-grid">
            <div class="profile-item">
                <small>Full Name</small>
                <strong><?= e($fullName) ?></strong>
            </div>

            <div class="profile-item">
                <small>Patient Number</small>
                <strong><?= e($patient['patient_number']) ?></strong>
            </div>

            <div class="profile-item">
                <small>Phone</small>
                <strong><?= e($patient['phone']) ?></strong>
            </div>

            <div class="profile-item">
                <small>Gender</small>
                <strong><?= e($patient['gender']) ?></strong>
            </div>
        </div>
    </section>

    <section class="panel">
        <h2>Quick Actions</h2>

        <div class="quick-actions">
            <a class="action" href="appointments.php">Book Appointment</a>
            <a class="action secondary" href="medical-history.php">View Medical History</a>
            <a class="action secondary" href="my-appointments.php">My Appointments</a>
        </div>
    </section>

    <section class="panel">
        <h2>My Recent Appointments</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Doctor</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Specialization</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (count($appointments) > 0): ?>
                    <?php foreach ($appointments as $appointment): ?>
                        <tr>
                            <td><?= e($appointment['doctor_name'] ?? 'Not assigned') ?></td>
                            <td><?= e($appointment['appointment_date']) ?></td>
                            <td><?= e($appointment['appointment_time']) ?></td>
                            <td><?= e($appointment['specialization'] ?? 'Not specified') ?></td>
                            <td>
                                <span class="status">
                                    <?= e(ucfirst($appointment['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">No appointments found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer>
        &copy; <?= date('Y') ?> MediCare Hospital Management System
    </footer>

</main>

</body>
</html>
