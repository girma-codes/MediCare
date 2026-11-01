
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();
requireRole(['admin']);

$stmt = $pdo->query("
    SELECT 
        d.id,
        d.user_id,
        u.full_name,
        u.email,
        u.status
    FROM doctors d
    INNER JOIN users u ON d.user_id = u.id
    ORDER BY d.id DESC
");

$doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalDoctors = count($doctors);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Management | MediCare HMS</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #1e293b;
            padding: 30px;
        }

        .container {
            max-width: 1150px;
            margin: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .subtitle {
            color: #64748b;
        }

        .actions {
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
            font-weight: bold;
            background: #0d9488;
        }

        .btn.secondary {
            background: #334155;
        }

        .summary {
            background: white;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        .summary h2 {
            font-size: 15px;
            color: #64748b;
            margin-bottom: 10px;
        }

        .count {
            font-size: 30px;
            font-weight: bold;
            color: #0d9488;
        }

        .table-box {
            background: white;
            border-radius: 12px;
            padding: 22px;
            overflow-x: auto;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #f8fafc;
            color: #475569;
        }

        .view-btn {
            display: inline-block;
            padding: 8px 13px;
            background: #e0f2fe;
            color: #0369a1;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #64748b;
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <div>
            <h1>Doctor Management</h1>
            <p class="subtitle">Manage and view hospital doctors</p>
        </div>

        <div class="actions">
            <a href="register.php" class="btn">+ Register Doctor</a>
            <a href="../admin/dashboard.php" class="btn secondary">
                Back to Dashboard
            </a>
        </div>
    </div>

    <div class="summary">
        <h2>Total Registered Doctors</h2>
        <div class="count">
            <?= $totalDoctors ?>
        </div>
    </div>

    <div class="table-box">
        <h2 style="margin-bottom: 18px;">Doctors List</h2>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Doctor Name</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                <?php if (empty($doctors)): ?>

                    <tr>
                        <td colspan="5" class="empty">
                            No doctors registered yet.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($doctors as $doctor): ?>
                        <tr>
                            <td><?= (int)$doctor['id'] ?></td>

                            <td>
                                <?= htmlspecialchars($doctor['full_name']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($doctor['email']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($doctor['status']) ?>
                            </td>

                            <td>
                                <a
                                    class="view-btn"
                                    href="view.php?id=<?= (int)$doctor['id'] ?>"
                                >
                                    View Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>
