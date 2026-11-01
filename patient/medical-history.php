
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['patient']);

$user_id = (int) $_SESSION['user_id'];

// Get logged-in patient's information
$patientStmt = $pdo->prepare("
    SELECT
        p.id,
        p.patient_number,
        u.full_name,
        u.email,
        p.date_of_birth,
        p.gender,
        p.phone,
        p.address,
        p.emergency_contact
    FROM patients p
    INNER JOIN users u ON p.user_id = u.id
    WHERE p.user_id = ?
    LIMIT 1
");
$patientStmt->execute([$user_id]);
$patient = $patientStmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    http_response_code(404);
    exit("Patient profile not found.");
}

$patient_id = (int) $patient['id'];

// Get medical records belonging only to this patient
$recordsStmt = $pdo->prepare("
    SELECT
        mr.id,
        mr.diagnosis,
        mr.treatment,
        mr.notes,
        mr.created_at,
        u.full_name AS doctor_name,
        d.specialization,
        dep.name AS department
    FROM medical_records mr
    INNER JOIN doctors d ON mr.doctor_id = d.id
    INNER JOIN users u ON d.user_id = u.id
    LEFT JOIN departments dep ON d.department_id = dep.id
    WHERE mr.patient_id = ?
    ORDER BY mr.created_at DESC
");
$recordsStmt->execute([$patient_id]);
$records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get appointment history belonging only to this patient
$appointmentsStmt = $pdo->prepare("
    SELECT
        a.id,
        a.appointment_date,
        a.appointment_time,
        a.reason,
        a.status,
        u.full_name AS doctor_name,
        d.specialization
    FROM appointments a
    INNER JOIN doctors d ON a.doctor_id = d.id
    INNER JOIN users u ON d.user_id = u.id
    WHERE a.patient_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");
$appointmentsStmt->execute([$patient_id]);
$appointments = $appointmentsStmt->fetchAll(PDO::FETCH_ASSOC);

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function statusClass($status) {
    $allowed = ['pending', 'confirmed', 'completed', 'cancelled'];
    return in_array($status, $allowed, true) ? $status : 'pending';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Medical History | MediCare HMS</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f3f6fa;
            color: #243447;
        }

        header {
            background: #102d46;
            color: white;
            padding: 22px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        header h2 {
            margin-bottom: 5px;
        }

        header nav {
            display: flex;
            gap: 20px;
        }

        header a {
            color: white;
            text-decoration: none;
        }

        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            margin-bottom: 8px;
        }

        .muted {
            color: #718096;
            line-height: 1.6;
        }

        .card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 3px 15px rgba(0,0,0,.05);
        }

        .card h2 {
            margin-bottom: 20px;
            font-size: 21px;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .profile-item small {
            display: block;
            color: #718096;
            margin-bottom: 7px;
        }

        .profile-item strong {
            overflow-wrap: anywhere;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0,0,0,.05);
        }

        .stat h3 {
            color: #087e8b;
            font-size: 30px;
            margin-bottom: 6px;
        }

        .record {
            border: 1px solid #e7edf3;
            border-left: 4px solid #087e8b;
            border-radius: 9px;
            padding: 20px;
            margin-bottom: 16px;
        }

        .record-header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }

        .record h3 {
            color: #087e8b;
            margin-bottom: 12px;
        }

        .record p {
            line-height: 1.7;
            margin-bottom: 10px;
            overflow-wrap: anywhere;
        }

        .status {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 12px;
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

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        th, td {
            text-align: left;
            padding: 14px;
            border-bottom: 1px solid #edf0f5;
        }

        th {
            background: #f5f8fb;
            color: #536477;
        }

        .empty {
            background: #f7fafc;
            padding: 25px;
            border-radius: 8px;
            color: #718096;
            text-align: center;
        }

        footer {
            text-align: center;
            padding: 25px;
            color: #718096;
        }

        @media(max-width: 750px) {
            .profile-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(max-width: 500px) {
            .profile-grid,
            .stats {
                grid-template-columns: 1fr;
            }

            header {
                padding: 20px;
            }

            .card {
                padding: 17px;
            }
        }
    </style>
</head>

<body>

<header>
    <div>
        <h2>MediCare HMS</h2>
        <p>Patient Portal</p>
    </div>

    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<main class="container">

    <section class="page-heading">
        <h1>My Medical History</h1>
        <p class="muted">
            View your personal medical information, previous treatments
            and appointment history.
        </p>
    </section>

    <!-- Statistics -->
    <section class="stats">
        <div class="stat">
            <h3><?= count($records) ?></h3>
            <p class="muted">Medical Records</p>
        </div>

        <div class="stat">
            <h3><?= count($appointments) ?></h3>
            <p class="muted">Total Appointments</p>
        </div>
    </section>

    <!-- Patient Information -->
    <section class="card">
        <h2>My Personal Information</h2>

        <div class="profile-grid">

            <div class="profile-item">
                <small>Full Name</small>
                <strong><?= h($patient['full_name']) ?></strong>
            </div>

            <div class="profile-item">
                <small>Patient Number</small>
                <strong><?= h($patient['patient_number']) ?></strong>
            </div>

            <div class="profile-item">
                <small>Email</small>
                <strong><?= h($patient['email']) ?></strong>
            </div>

            <div class="profile-item">
                <small>Phone</small>
                <strong><?= h($patient['phone']) ?: 'Not provided' ?></strong>
            </div>

            <div class="profile-item">
                <small>Date of Birth</small>
                <strong><?= h($patient['date_of_birth']) ?: 'Not provided' ?></strong>
            </div>

            <div class="profile-item">
                <small>Gender</small>
                <strong><?= h($patient['gender']) ?: 'Not provided' ?></strong>
            </div>

            <div class="profile-item">
                <small>Address</small>
                <strong><?= h($patient['address']) ?: 'Not provided' ?></strong>
            </div>

            <div class="profile-item">
                <small>Emergency Contact</small>
                <strong><?= h($patient['emergency_contact']) ?: 'Not provided' ?></strong>
            </div>

        </div>
    </section>

    <!-- Medical Records -->
    <section class="card">
        <h2>Medical Records & Treatment History</h2>

        <?php if (count($records) > 0): ?>

            <?php foreach ($records as $record): ?>
                <article class="record">

                    <div class="record-header">
                        <div>
                            <strong>Record #<?= (int)$record['id'] ?></strong>
                            <p class="muted">
                                <?= h(date('M d, Y', strtotime($record['created_at']))) ?>
                            </p>
                        </div>

                        <span class="status completed">
                            Recorded
                        </span>
                    </div>

                    <h3>Diagnosis</h3>
                    <p><?= nl2br(h($record['diagnosis'])) ?></p>

                    <h3>Treatment Plan</h3>
                    <p><?= nl2br(h($record['treatment'] ?: 'Not provided')) ?></p>

                    <h3>Additional Notes</h3>
                    <p><?= nl2br(h($record['notes'] ?: 'No additional notes')) ?></p>

                    <p>
                        <strong>Doctor:</strong>
                        <?= h($record['doctor_name']) ?>
                    </p>

                    <p>
                        <strong>Department:</strong>
                        <?= h($record['department'] ?: 'Not specified') ?>
                    </p>

                    <p>
                        <strong>Specialization:</strong>
                        <?= h($record['specialization']) ?>
                    </p>

                </article>
            <?php endforeach; ?>

        <?php else: ?>

            <div class="empty">
                No medical records are available yet.
            </div>

        <?php endif; ?>
    </section>

    <!-- Appointment History -->
    <section class="card">
        <h2>My Appointment History</h2>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Appointment ID</th>
                        <th>Doctor</th>
                        <th>Specialization</th>
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

                            <td>Dr. <?= h($appointment['doctor_name']) ?></td>

                            <td><?= h($appointment['specialization']) ?></td>

                            <td><?= h($appointment['appointment_date']) ?></td>

                            <td>
                                <?= h(date('h:i A', strtotime($appointment['appointment_time']))) ?>
                            </td>

                            <td><?= h($appointment['reason'] ?: 'Not specified') ?></td>

                            <td>
                                <span class="status <?= h(statusClass($appointment['status'])) ?>">
                                    <?= h($appointment['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="7" class="empty">
                            No appointment history found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>
    </section>

</main>

<footer>
    &copy; <?= date('Y') ?> MediCare Hospital Management System
</footer>

</body>
</html>
