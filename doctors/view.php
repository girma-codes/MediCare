
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();
requireRole(['admin']);

$doctorId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$doctorId) {
    die('Invalid doctor ID.');
}

$stmt = $pdo->prepare("
    SELECT
        d.id,
        d.specialization,
        d.license_number,
        d.phone,
        d.created_at,
        u.full_name,
        u.email,
        u.status,
        dep.name AS department_name
    FROM doctors d
    INNER JOIN users u ON d.user_id = u.id
    LEFT JOIN departments dep ON d.department_id = dep.id
    WHERE d.id = ?
    LIMIT 1
");

$stmt->execute([$doctorId]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    die('Doctor not found.');
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
    <title>Doctor Details | MediCare HMS</title>

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
            padding: 30px 15px;
        }

        .container {
            max-width: 850px;
            margin: auto;
        }

        .card {
            background: white;
            border-radius: 14px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,.06);
        }

        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        h1 {
            font-size: 27px;
            margin-bottom: 7px;
        }

        .subtitle {
            color: #64748b;
        }

        .profile {
            background: #ecfeff;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 25px;
        }

        .profile h2 {
            color: #0f766e;
            margin-bottom: 8px;
        }

        .profile p {
            color: #64748b;
        }

        .details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .item {
            padding: 16px;
            background: #f8fafc;
            border-radius: 9px;
        }

        .item small {
            display: block;
            color: #64748b;
            margin-bottom: 8px;
        }

        .item strong {
            overflow-wrap: anywhere;
        }

        .actions {
            margin-top: 25px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 12px 18px;
            border-radius: 8px;
            text-decoration: none;
            color: white;
            background: #0d9488;
            font-weight: bold;
        }

        .secondary {
            background: #334155;
        }

        @media(max-width: 600px) {
            .details {
                grid-template-columns: 1fr;
            }

            .card {
                padding: 20px;
            }
        }
    </style>
</head>

<body>

<div class="container">
    <div class="card">

        <div class="top">
            <div>
                <h1>Doctor Details</h1>
                <p class="subtitle">Complete doctor information</p>
            </div>
        </div>

        <div class="profile">
            <h2><?= e($doctor['full_name']) ?></h2>
            <p>Doctor ID: DOC-<?= (int)$doctor['id'] ?></p>
        </div>

        <div class="details">

            <div class="item">
                <small>Full Name</small>
                <strong><?= e($doctor['full_name']) ?></strong>
            </div>

            <div class="item">
                <small>Email Address</small>
                <strong><?= e($doctor['email']) ?></strong>
            </div>

            <div class="item">
                <small>Department</small>
                <strong><?= e($doctor['department_name']) ?></strong>
            </div>

            <div class="item">
                <small>Specialization</small>
                <strong><?= e($doctor['specialization']) ?></strong>
            </div>

            <div class="item">
                <small>License Number</small>
                <strong><?= e($doctor['license_number']) ?></strong>
            </div>

            <div class="item">
                <small>Phone Number</small>
                <strong><?= e($doctor['phone']) ?></strong>
            </div>

            <div class="item">
                <small>Account Status</small>
                <strong><?= e($doctor['status']) ?></strong>
            </div>

            <div class="item">
                <small>Registration Date</small>
                <strong><?= e($doctor['created_at']) ?></strong>
            </div>

        </div>

        <div class="actions">
            <a href="index.php" class="btn">Back to Doctors</a>
            <a href="../admin/dashboard.php" class="btn secondary">
                Back to Dashboard
            </a>
        </div>

    </div>
</div>

</body>
</html>
