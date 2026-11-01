
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['doctor']);

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$doctorStmt = $pdo->prepare(
    "SELECT id, specialization
     FROM doctors
     WHERE user_id = ?"
);
$doctorStmt->execute([$_SESSION['user_id']]);
$doctor = $doctorStmt->fetch();

if (!$doctor) {
    exit('Doctor profile not found.');
}

$doctorId = (int)$doctor['id'];

// Only patients who have appointments with this doctor
$patientsStmt = $pdo->prepare(
    "SELECT DISTINCT
        p.id,
        p.patient_number,
        u.full_name,
        p.gender,
        p.phone
     FROM patients p
     INNER JOIN users u ON u.id = p.user_id
     INNER JOIN appointments a ON a.patient_id = p.id
     WHERE a.doctor_id = ?
     ORDER BY u.full_name ASC"
);
$patientsStmt->execute([$doctorId]);
$patients = $patientsStmt->fetchAll();

$selectedPatientId = filter_input(
    INPUT_GET,
    'patient_id',
    FILTER_VALIDATE_INT
);

$selectedPatient = null;
$records = [];
$error = '';

if ($selectedPatientId) {
    // Verify this patient has an appointment with this doctor
    $patientStmt = $pdo->prepare(
        "SELECT DISTINCT
            p.id,
            p.patient_number,
            p.gender,
            p.phone,
            p.address,
            u.full_name,
            u.email
         FROM patients p
         INNER JOIN users u ON u.id = p.user_id
         INNER JOIN appointments a ON a.patient_id = p.id
         WHERE p.id = ? AND a.doctor_id = ?
         LIMIT 1"
    );

    $patientStmt->execute([$selectedPatientId, $doctorId]);
    $selectedPatient = $patientStmt->fetch();

    if (!$selectedPatient) {
        $error = 'Patient not found or you do not have access.';
    } else {
        $recordsStmt = $pdo->prepare(
            "SELECT
                mr.id,
                mr.diagnosis,
                mr.treatment,
                mr.notes,
                mr.created_at,
                u.full_name AS doctor_name,
                d.specialization
             FROM medical_records mr
             INNER JOIN doctors d ON d.id = mr.doctor_id
             INNER JOIN users u ON u.id = d.user_id
             WHERE mr.patient_id = ?
             ORDER BY mr.created_at DESC"
        );

        $recordsStmt->execute([$selectedPatientId]);
        $records = $recordsStmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Medical History | MediCare</title>

<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    background: #f4f7fb;
    color: #263445;
}

.container {
    max-width: 1150px;
    margin: 35px auto;
    padding: 20px;
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
    color: #173b64;
    margin-bottom: 8px;
}

.header p {
    color: #718096;
}

.back {
    background: #173b64;
    color: white;
    text-decoration: none;
    padding: 11px 18px;
    border-radius: 8px;
}

.card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 22px;
    box-shadow: 0 4px 18px rgba(0,0,0,.05);
}

.card h2 {
    color: #173b64;
    margin-bottom: 18px;
}

select {
    width: 100%;
    max-width: 550px;
    padding: 13px;
    border: 1px solid #d8e0ea;
    border-radius: 8px;
    font-size: 15px;
    background: white;
}

.patient-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 18px;
}

.info-item {
    background: #f5f8fc;
    padding: 15px;
    border-radius: 8px;
}

.info-item small {
    display: block;
    color: #718096;
    margin-bottom: 7px;
}

.info-item strong {
    color: #173b64;
    overflow-wrap: anywhere;
}

.record {
    border: 1px solid #e1e8f0;
    border-left: 5px solid #2185d0;
    border-radius: 9px;
    padding: 20px;
    margin-bottom: 18px;
}

.record-header {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 18px;
}

.record-header h3 {
    color: #173b64;
}

.date {
    color: #718096;
    font-size: 13px;
}

.record-section {
    margin-top: 14px;
}

.record-section h4 {
    color: #2185d0;
    margin-bottom: 7px;
}

.record-section p {
    line-height: 1.6;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.empty, .error {
    padding: 18px;
    border-radius: 8px;
    margin-top: 15px;
}

.empty {
    background: #f0f6fc;
    color: #45627e;
}

.error {
    background: #fff0f0;
    color: #b42318;
}

@media(max-width:600px) {
    .container {
        padding: 12px;
        margin: 15px auto;
    }

    .card {
        padding: 17px;
    }
}
</style>
</head>

<body>

<div class="container">

    <div class="header">
        <div>
            <h1>Patient Medical History</h1>
            <p>Review and understand patient medical records.</p>
        </div>

        <a class="back" href="dashboard.php">← Dashboard</a>
    </div>

    <div class="card">
        <h2>Select Patient</h2>

        <form method="GET">
            <select name="patient_id"
                    onchange="this.form.submit()"
                    required>
                <option value="">Choose a patient</option>

                <?php foreach ($patients as $patient): ?>
                    <option
                        value="<?= (int)$patient['id'] ?>"
                        <?= ($selectedPatientId == $patient['id']) ? 'selected' : '' ?>>
                        <?= e($patient['full_name']) ?>
                        - <?= e($patient['patient_number']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if (empty($patients)): ?>
            <div class="empty">
                No patients with appointments were found.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($selectedPatient): ?>

        <div class="card">
            <h2>Patient Information</h2>

            <div class="patient-info">

                <div class="info-item">
                    <small>Full Name</small>
                    <strong><?= e($selectedPatient['full_name']) ?></strong>
                </div>

                <div class="info-item">
                    <small>Patient Number</small>
                    <strong><?= e($selectedPatient['patient_number']) ?></strong>
                </div>

                <div class="info-item">
                    <small>Gender</small>
                    <strong><?= e($selectedPatient['gender'] ?: 'Not provided') ?></strong>
                </div>

                <div class="info-item">
                    <small>Phone</small>
                    <strong><?= e($selectedPatient['phone'] ?: 'Not provided') ?></strong>
                </div>

                <div class="info-item">
                    <small>Email</small>
                    <strong><?= e($selectedPatient['email'] ?: 'Not provided') ?></strong>
                </div>

                <div class="info-item">
                    <small>Address</small>
                    <strong><?= e($selectedPatient['address'] ?: 'Not provided') ?></strong>
                </div>

            </div>
        </div>

        <div class="card">
            <h2>Medical Records (<?= count($records) ?>)</h2>

            <?php if (empty($records)): ?>

                <div class="empty">
                    No medical records have been created for this patient yet.
                </div>

            <?php else: ?>

                <?php foreach ($records as $record): ?>

                    <div class="record">

                        <div class="record-header">
                            <h3>Medical Record #<?= (int)$record['id'] ?></h3>

                            <span class="date">
                                <?= e(date('M d, Y h:i A', strtotime($record['created_at']))) ?>
                            </span>
                        </div>

                        <div class="record-section">
                            <h4>Diagnosis</h4>
                            <p><?= e($record['diagnosis']) ?></p>
                        </div>

                        <div class="record-section">
                            <h4>Treatment Plan</h4>
                            <p><?= e($record['treatment'] ?: 'Not provided') ?></p>
                        </div>

                        <div class="record-section">
                            <h4>Clinical Notes</h4>
                            <p><?= e($record['notes'] ?: 'No additional notes') ?></p>
                        </div>

                        <div class="record-section">
                            <h4>Created By</h4>
                            <p>
                                Dr. <?= e($record['doctor_name']) ?>
                                — <?= e($record['specialization']) ?>
                            </p>
                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>

</body>
</html>
