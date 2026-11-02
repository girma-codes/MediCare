
<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requireRole(['doctor']);

$stmt = $pdo->prepare("
    SELECT id FROM doctors
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    exit('Doctor profile not found.');
}

$doctorId = (int)$doctor['id'];

$stmt = $pdo->prepare("
    SELECT
        lr.id,
        lr.requested_at,
        lr.priority,
        lr.status,
        p.patient_number,
        u.full_name AS patient_name,
        COUNT(lrt.id) AS total_tests
    FROM lab_requests lr
    INNER JOIN patients p ON p.id = lr.patient_id
    LEFT JOIN users u ON u.id = p.user_id
    LEFT JOIN lab_request_tests lrt ON lrt.request_id = lr.id
    WHERE lr.doctor_id = ?
    GROUP BY
        lr.id,
        lr.requested_at,
        lr.priority,
        lr.status,
        p.patient_number,
        u.full_name
    ORDER BY lr.requested_at DESC
");

$stmt->execute([$doctorId]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Lab Requests | MediCare HMS</title>

    <link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 30px;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #243247;
        }

        .container { max-width: 1150px; margin: auto; }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        h1 { margin: 0 0 8px; }
        .subtitle { color: #718096; margin: 0; }

        .card {
            background: white;
            border-radius: 13px;
            padding: 23px;
            box-shadow: 0 4px 18px rgba(0,0,0,.05);
            overflow-x: auto;
        }

        .btn {
            background: #2563eb;
            color: white;
            text-decoration: none;
            padding: 13px 18px;
            border-radius: 8px;
            font-weight: bold;
            display: inline-block;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th, td {
            text-align: left;
            padding: 15px 12px;
            border-bottom: 1px solid #edf0f5;
        }

        th { background: #f8faff; color: #536176; }

        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .normal { background: #e7f5ff; color: #1864ab; }
        .urgent { background: #fff0f0; color: #c92a2a; }
        .requested { background: #fff3bf; color: #8a6100; }
        .sample_collected { background: #e3fafc; color: #087f8c; }
        .processing { background: #e5dbff; color: #6741d9; }
        .result_entered { background: #d3f9d8; color: #2b8a3e; }
        .verified { background: #d3f9d8; color: #2b8a3e; }
        .cancelled { background: #ffe3e3; color: #c92a2a; }

        .success {
            background: #d3f9d8;
            color: #2b8a3e;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .empty {
            text-align: center;
            padding: 40px;
            color: #718096;
        }

        @media(max-width: 600px) {
            body { padding: 15px; }
            .header { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>

<body>
<div class="container">

    <div class="header">
        <div>
            <h1><i class="fa-solid fa-flask"></i> Laboratory Requests</h1>
            <p class="subtitle">Manage your patients' laboratory test requests.</p>
        </div>

        <a href="create.php" class="btn">
            <i class="fa-solid fa-plus"></i> New Lab Request
        </a>
    </div>

    <?php if (!empty($_SESSION['success_message'])): ?>
        <div class="success">
            <i class="fa-solid fa-circle-check"></i>
            <?= e($_SESSION['success_message']) ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <div class="card">

        <?php if (empty($requests)): ?>

            <div class="empty">
                <i class="fa-solid fa-flask" style="font-size:40px"></i>
                <h3>No Laboratory Requests Yet</h3>
                <p>Create your first laboratory request.</p>
                <a href="create.php" class="btn">Create Request</a>
            </div>

        <?php else: ?>

            <table>
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>Patient</th>
                        <th>Patient Number</th>
                        <th>Tests</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Requested At</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td>#<?= (int)$request['id'] ?></td>

                        <td><?= e($request['patient_name'] ?: 'Unknown') ?></td>

                        <td><?= e($request['patient_number']) ?></td>

                        <td><?= (int)$request['total_tests'] ?></td>

                        <td>
                            <span class="badge <?= e($request['priority']) ?>">
                                <?= e(ucfirst($request['priority'])) ?>
                            </span>
                        </td>

                        <td>
                            <span class="badge <?= e($request['status']) ?>">
                                <?= e(ucwords(str_replace('_', ' ', $request['status']))) ?>
                            </span>
                        </td>

                        <td><?= e($request['requested_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </div>
</div>
</body>
</html>
