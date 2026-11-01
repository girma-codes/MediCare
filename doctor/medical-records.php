
<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();
requireRole(['doctor']);

$userId = (int)$_SESSION['user_id'];

// ========================================
// 1. CSRF SECURITY
// ========================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

// ========================================
// 2. GET DOCTOR INFORMATION
// ========================================

$doctorStmt = $pdo->prepare("
    SELECT
        d.id,
        u.full_name,
        u.email,
        d.specialization
    FROM doctors d
    INNER JOIN users u ON d.user_id = u.id
    WHERE d.user_id = ?
    LIMIT 1
");

$doctorStmt->execute([$userId]);
$doctor = $doctorStmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    die('Doctor profile not found.');
}

$doctorId = (int)$doctor['id'];

$error = '';
$success = '';

// ========================================
// 3. SAVE MEDICAL RECORD
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $submittedToken)) {

        $error = 'Security verification failed. Please refresh the page.';

    } else {

        $patientId = (int)($_POST['patient_id'] ?? 0);
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);

        $diagnosis = trim($_POST['diagnosis'] ?? '');
        $treatment = trim($_POST['treatment'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($patientId <= 0 || $appointmentId <= 0 || $diagnosis === '') {

            $error = 'Please select a patient, appointment and enter a diagnosis.';

        } elseif (mb_strlen($diagnosis) > 10000 ||
                  mb_strlen($treatment) > 10000 ||
                  mb_strlen($notes) > 10000) {

            $error = 'Medical record fields are too long.';

        } else {

            try {

                $pdo->beginTransaction();

                // Verify appointment belongs to this doctor and patient.
                $check = $pdo->prepare("
                    SELECT id
                    FROM appointments
                    WHERE id = ?
                      AND patient_id = ?
                      AND doctor_id = ?
                      AND status IN ('confirmed', 'completed')
                    LIMIT 1
                    FOR UPDATE
                ");

                $check->execute([
                    $appointmentId,
                    $patientId,
                    $doctorId
                ]);

                $validAppointment = $check->fetch(PDO::FETCH_ASSOC);

                if (!$validAppointment) {

                    throw new Exception(
                        'The selected appointment is not valid for this doctor and patient.'
                    );

                }

                // Save medical record.
                $insert = $pdo->prepare("
                    INSERT INTO medical_records
                    (
                        patient_id,
                        doctor_id,
                        appointment_id,
                        diagnosis,
                        treatment,
                        notes
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $insert->execute([
                    $patientId,
                    $doctorId,
                    $appointmentId,
                    $diagnosis,
                    $treatment !== '' ? $treatment : null,
                    $notes !== '' ? $notes : null
                ]);

                $pdo->commit();

                // Prevent duplicate form submission.
                $_SESSION['medical_record_success'] =
                    'Medical record saved successfully.';

                header("Location: medical-records.php");
                exit;

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($e->getMessage());

                $error = $e instanceof PDOException
                    ? 'Unable to save the medical record. Please check the database and try again.'
                    : $e->getMessage();
            }
        }
    }
}

// ========================================
// 4. SUCCESS MESSAGE
// ========================================

if (!empty($_SESSION['medical_record_success'])) {

    $success = $_SESSION['medical_record_success'];

    unset($_SESSION['medical_record_success']);
}

// ========================================
// 5. GET ELIGIBLE PATIENTS
// ========================================

$patientsStmt = $pdo->prepare("
    SELECT DISTINCT
        p.id AS patient_id,
        p.patient_number,
        p.phone,
        u.full_name

    FROM appointments a

    INNER JOIN patients p
        ON a.patient_id = p.id

    LEFT JOIN users u
        ON p.user_id = u.id

    WHERE a.doctor_id = ?
      AND a.status IN ('confirmed', 'completed')

    ORDER BY u.full_name ASC
");

$patientsStmt->execute([$doctorId]);

$eligiblePatients = $patientsStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// 6. GET ELIGIBLE APPOINTMENTS
// ========================================

$appointmentsStmt = $pdo->prepare("
    SELECT
        a.id,
        a.patient_id,
        a.appointment_date,
        a.appointment_time,
        a.status

    FROM appointments a

    WHERE a.doctor_id = ?
      AND a.status IN ('confirmed', 'completed')

    ORDER BY
        a.appointment_date DESC,
        a.appointment_time DESC
");

$appointmentsStmt->execute([$doctorId]);

$eligibleAppointments = $appointmentsStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// 7. GET RECENT MEDICAL RECORDS
// ========================================

$recordsStmt = $pdo->prepare("
    SELECT
        mr.id,
        mr.diagnosis,
        mr.treatment,
        mr.notes,
        mr.created_at,
        p.patient_number,
        u.full_name AS patient_name

    FROM medical_records mr

    INNER JOIN patients p
        ON mr.patient_id = p.id

    LEFT JOIN users u
        ON p.user_id = u.id

    WHERE mr.doctor_id = ?

    ORDER BY mr.created_at DESC

    LIMIT 20
");

$recordsStmt->execute([$doctorId]);

$records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// 8. HELPER FUNCTION
// ========================================

function h($value) {
    return htmlspecialchars(
        (string)($value ?? 'N/A'),
        ENT_QUOTES,
        'UTF-8'
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Medical Records | MediCare HMS</title>

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

/* HEADER */

header {
    background: #0f172a;
    color: white;
    padding: 22px 5%;

    display: flex;
    justify-content: space-between;
    align-items: center;

    gap: 15px;
    flex-wrap: wrap;
}

.brand {
    font-size: 22px;
    font-weight: bold;
    color: #5eead4;
}

.header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

header a {
    color: white;
    background: #0d9488;
    text-decoration: none;
    padding: 11px 16px;
    border-radius: 7px;
    font-weight: bold;
}

header a:hover {
    background: #0f766e;
}

/* MAIN */

main {
    max-width: 1250px;
    margin: 30px auto;
    padding: 0 20px;
}

/* PAGE INTRO */

.page-intro {
    margin-bottom: 25px;
}

.page-intro h1 {
    font-size: 27px;
    margin-bottom: 8px;
}

.page-intro p {
    color: #64748b;
    line-height: 1.6;
}

/* DOCTOR INFO */

.doctor-banner {
    background: linear-gradient(120deg, #ccfbf1, #e0f2fe);
    border: 1px solid #99f6e4;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
}

.doctor-banner h3 {
    color: #115e59;
    margin-bottom: 8px;
}

.doctor-banner p {
    color: #475569;
    font-size: 14px;
}

/* CARDS */

.card {
    background: white;
    padding: 25px;
    border-radius: 13px;
    margin-bottom: 25px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
    border: 1px solid #e2e8f0;
}

.card h2 {
    margin-bottom: 20px;
    font-size: 20px;
}

/* FORM */

.grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.field {
    margin-bottom: 5px;
}

.full {
    grid-column: 1 / -1;
}

label {
    display: block;
    font-weight: bold;
    margin-bottom: 9px;
    font-size: 14px;
    color: #334155;
}

input,
select,
textarea {
    width: 100%;
    padding: 13px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font: inherit;
    background: white;
    color: #1e293b;
    outline: none;
}

input:focus,
select:focus,
textarea:focus {
    border-color: #0d9488;
    box-shadow: 0 0 0 3px rgba(13,148,136,.12);
}

textarea {
    min-height: 120px;
    resize: vertical;
    line-height: 1.6;
}

.form-hint {
    font-size: 12px;
    color: #64748b;
    margin-top: 7px;
}

/* BUTTONS */

button {
    background: #0d9488;
    color: white;
    border: 0;
    padding: 14px 22px;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
    font-size: 14px;
    transition: .2s;
}

button:hover {
    background: #0f766e;
    transform: translateY(-1px);
}

button:disabled {
    background: #94a3b8;
    cursor: not-allowed;
    transform: none;
}

/* ALERTS */

.alert {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    line-height: 1.5;
}

.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

/* TABLE */

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
}

th,
td {
    text-align: left;
    padding: 15px;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: top;
}

th {
    background: #f8fafc;
    color: #475569;
    font-size: 13px;
}

td {
    font-size: 14px;
    line-height: 1.6;
}

tbody tr:hover {
    background: #f8fafc;
}

.empty {
    text-align: center;
    padding: 30px;
    color: #64748b;
}

/* RECORD DETAILS */

.record-text {
    max-width: 280px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.patient-number {
    color: #0f766e;
    font-weight: bold;
}

.record-date {
    white-space: nowrap;
    color: #64748b;
    font-size: 13px;
}

/* FOOTER */

footer {
    text-align: center;
    padding: 25px;
    color: #94a3b8;
    font-size: 13px;
}

/* RESPONSIVE */

@media(max-width: 750px) {

    header {
        padding: 18px;
    }

    main {
        padding: 0 14px;
        margin-top: 20px;
    }

    .grid {
        grid-template-columns: 1fr;
    }

    .full {
        grid-column: auto;
    }

    .card {
        padding: 18px;
    }

    .page-intro h1 {
        font-size: 23px;
    }

}

</style>

</head>

<body>

<!-- HEADER -->

<header>

    <div class="brand">
        MediCare HMS
    </div>

    <div class="header-actions">

        <a href="dashboard.php">
            Dashboard
        </a>

        <a href="appointments.php">
            Appointments
        </a>

        <a href="../logout.php">
            Logout
        </a>

    </div>

</header>

<main>

    <!-- PAGE INTRO -->

    <section class="page-intro">

        <h1>Patient Medical Records</h1>

        <p>
            Create, manage and review patient medical records.
        </p>

    </section>

    <!-- DOCTOR INFORMATION -->

    <section class="doctor-banner">

        <h3>
            Dr. <?= h($doctor['full_name']) ?>
        </h3>

        <p>
            Specialization:
            <?= h($doctor['specialization']) ?>
        </p>

        <p>
            Email:
            <?= h($doctor['email']) ?>
        </p>

    </section>

    <!-- CREATE MEDICAL RECORD -->

    <section class="card">

        <h2>Create Medical Record</h2>

        <?php if ($error): ?>

            <div class="alert error">
                <?= h($error) ?>
            </div>

        <?php endif; ?>

        <?php if ($success): ?>

            <div class="alert success">
                <?= h($success) ?>
            </div>

        <?php endif; ?>

        <?php if (!$eligiblePatients): ?>

            <div class="alert error">

                No patients with confirmed or completed appointments
                were found.

                Please make sure the patient has an eligible appointment
                with you before creating a medical record.

            </div>

        <?php else: ?>

        <form method="POST" action="">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($csrfToken) ?>"
            >

            <div class="grid">

                <!-- PATIENT -->

                <div class="field">

                    <label for="patient_id">
                        Select Patient *
                    </label>

                    <select
                        name="patient_id"
                        id="patient_id"
                        required
                    >

                        <option value="">
                            Choose a patient
                        </option>

                        <?php foreach ($eligiblePatients as $p): ?>

                            <option value="<?= (int)$p['patient_id'] ?>">

                                <?= h($p['full_name']) ?>

                                -
                                <?= h($p['patient_number']) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                    <p class="form-hint">
                        Select a patient assigned to your appointments.
                    </p>

                </div>

                <!-- APPOINTMENT -->

                <div class="field">

                    <label for="appointment_id">
                        Select Appointment *
                    </label>

                    <select
                        name="appointment_id"
                        id="appointment_id"
                        required
                        disabled
                    >

                        <option value="">
                            Select a patient first
                        </option>

                    </select>

                    <p class="form-hint">
                        Only confirmed or completed appointments are available.
                    </p>

                </div>

                <!-- DIAGNOSIS -->

                <div class="field full">

                    <label for="diagnosis">
                        Diagnosis *
                    </label>

                    <textarea
                        name="diagnosis"
                        id="diagnosis"
                        maxlength="10000"
                        placeholder="Enter the clinical diagnosis..."
                        required
                    ></textarea>

                </div>

                <!-- TREATMENT -->

                <div class="field full">

                    <label for="treatment">
                        Treatment Plan
                    </label>

                    <textarea
                        name="treatment"
                        id="treatment"
                        maxlength="10000"
                        placeholder="Enter the recommended treatment plan..."
                    ></textarea>

                </div>

                <!-- NOTES -->

                <div class="field full">

                    <label for="notes">
                        Additional Clinical Notes
                    </label>

                    <textarea
                        name="notes"
                        id="notes"
                        maxlength="10000"
                        placeholder="Enter additional clinical notes..."
                    ></textarea>

                </div>

                <!-- SUBMIT -->

                <div class="field full">

                    <button type="submit">
                        Save Medical Record
                    </button>

                </div>

            </div>

        </form>

        <?php endif; ?>

    </section>

    <!-- RECENT MEDICAL RECORDS -->

    <section class="card">

        <h2>My Recent Medical Records</h2>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>Patient</th>

                        <th>Patient Number</th>

                        <th>Diagnosis</th>

                        <th>Treatment</th>

                        <th>Additional Notes</th>

                        <th>Date Created</th>

                    </tr>

                </thead>

                <tbody>

                <?php if (!$records): ?>

                    <tr>

                        <td colspan="6" class="empty">
                            No medical records found.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($records as $record): ?>

                        <tr>

                            <td>
                                <?= h($record['patient_name']) ?>
                            </td>

                            <td>
                                <span class="patient-number">
                                    <?= h($record['patient_number']) ?>
                                </span>
                            </td>

                            <td>
                                <div class="record-text">
                                    <?= h($record['diagnosis']) ?>
                                </div>
                            </td>

                            <td>
                                <div class="record-text">
                                    <?= h($record['treatment'] ?: 'Not provided') ?>
                                </div>
                            </td>

                            <td>
                                <div class="record-text">
                                    <?= h($record['notes'] ?: 'No additional notes') ?>
                                </div>
                            </td>

                            <td>
                                <span class="record-date">
                                    <?= h($record['created_at']) ?>
                                </span>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

<footer>

    MediCare Hospital Management System
    &copy; <?= date('Y') ?>

</footer>

<script>

// ========================================
// PATIENT AND APPOINTMENT SELECTION
// ========================================

const patientSelect = document.getElementById('patient_id');

const appointmentSelect =
    document.getElementById('appointment_id');

// Appointment data provided by PHP.
const appointments = <?= json_encode(
    array_map(function($a) {

        return [
            'patient_id' => (int)$a['patient_id'],
            'appointment_id' => (int)$a['id'],
            'date' => $a['appointment_date'],
            'time' => $a['appointment_time'],
            'status' => $a['status']
        ];

    }, $eligibleAppointments),
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
) ?>;

// Update appointment list when patient changes.
if (patientSelect && appointmentSelect) {

    patientSelect.addEventListener('change', function() {

        const patientId = Number(this.value);

        appointmentSelect.innerHTML = '';

        if (!patientId) {

            appointmentSelect.add(
                new Option('Select a patient first', '')
            );

            appointmentSelect.disabled = true;

            return;
        }

        const matchingAppointments = appointments.filter(
            appointment => appointment.patient_id === patientId
        );

        if (matchingAppointments.length === 0) {

            appointmentSelect.add(
                new Option('No eligible appointments found', '')
            );

            appointmentSelect.disabled = true;

            return;
        }

        appointmentSelect.add(
            new Option('Choose an appointment', '')
        );

        matchingAppointments.forEach(appointment => {

            const label =
                appointment.date +
                ' | ' +
                appointment.time +
                ' | ' +
                appointment.status;

            appointmentSelect.add(
                new Option(
                    label,
                    appointment.appointment_id
                )
            );

        });

        appointmentSelect.disabled = false;

    });

}

</script>

</body>
</html>
