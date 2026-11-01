
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin']);

$patientId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$patientId) {
    http_response_code(400);
    exit('Invalid patient ID.');
}

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

function e($value) {
    return htmlspecialchars((string)($value ?? 'N/A'), ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Details | MediCare HMS</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 30px;
    font-family: Arial, sans-serif;
    background: #f1f5f9;
    color: #1e293b;
}

.container {
    max-width: 950px;
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

.card {
    background: white;
    padding: 28px;
    border-radius: 14px;
    box-shadow: 0 4px 20px rgba(0,0,0,.04);
}

.profile {
    text-align: center;
    padding: 25px;
    border-bottom: 1px solid #e2e8f0;
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

.profile h2 { margin-bottom: 5px; }

.patient-number {
    color: #2563eb;
    font-weight: bold;
}

.details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 22px;
    padding-top: 25px;
}

.item {
    padding: 15px;
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

.button {
    display: inline-block;
    padding: 12px 18px;
    background: #2563eb;
    color: white;
    border-radius: 8px;
    text-decoration: none;
}

.back {
    background: #e2e8f0;
    color: #334155;
}

@media(max-width: 600px) {
    body { padding: 15px; }
    .details { grid-template-columns: 1fr; }
}
</style>
</head>

<body>

<div class="container">

    <div class="header">
        <div>
            <h1>Patient Details</h1>
            <p>Complete patient information</p>
        </div>

        <a href="index.php" class="button back">
            ← Back to Patients
        </a>
    </div>

    <div class="card">

        <div class="profile">
            <div class="avatar">
                <?= e(strtoupper(substr($patient['full_name'] ?? 'P', 0, 1))) ?>
            </div>

            <h2><?= e($patient['full_name']) ?></h2>

            <div class="patient-number">
                <?= e($patient['patient_number']) ?>
            </div>
        </div>

        <h3>Personal Information</h3>

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

    </div>

    <p style="margin-top:20px">
        <a href="../admin/dashboard.php" class="button back">
            ← Back to Dashboard
        </a>
    </p>

</div>

</body>
</html>
