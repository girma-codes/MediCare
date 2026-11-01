
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT
        mr.id,
        mr.diagnosis,
        mr.treatment,
        mr.notes,
        mr.created_at,
        p.patient_number,
        patient_user.full_name AS patient_name,
        doctor_user.full_name AS doctor_name,
        d.name AS department_name
    FROM medical_records mr
    INNER JOIN patients p
        ON p.id = mr.patient_id
    INNER JOIN users patient_user
        ON patient_user.id = p.user_id
    INNER JOIN doctors doc
        ON doc.id = mr.doctor_id
    INNER JOIN users doctor_user
        ON doctor_user.id = doc.user_id
    LEFT JOIN departments d
        ON d.id = doc.department_id
";

$params = [];

if ($search !== '') {
    $sql .= "
        WHERE
            patient_user.full_name LIKE :patient_name
            OR p.patient_number LIKE :patient_number
            OR doctor_user.full_name LIKE :doctor_name
            OR mr.diagnosis LIKE :diagnosis
    ";

    $keyword = '%' . $search . '%';

    $params = [
        ':patient_name' => $keyword,
        ':patient_number' => $keyword,
        ':doctor_name' => $keyword,
        ':diagnosis' => $keyword
    ];
}

$sql .= " ORDER BY mr.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$totalRecords = (int)$pdo
    ->query("SELECT COUNT(*) FROM medical_records")
    ->fetchColumn();

$totalPatients = (int)$pdo
    ->query("SELECT COUNT(DISTINCT patient_id) FROM medical_records")
    ->fetchColumn();

$totalDoctors = (int)$pdo
    ->query("SELECT COUNT(DISTINCT doctor_id) FROM medical_records")
    ->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Records | MediCare HMS</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

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

        .container {
            max-width: 1400px;
            margin: 35px auto;
            padding: 0 25px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .topbar h1 {
            font-size: 27px;
            margin-bottom: 7px;
        }

        .topbar p {
            color: #718096;
            font-size: 14px;
        }

        .back-btn {
            text-decoration: none;
            background: #e8eef7;
            color: #263f60;
            padding: 11px 17px;
            border-radius: 8px;
            font-weight: 600;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0,0,0,.04);
            border: 1px solid #edf0f5;
        }

        .stat-card .icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e9f3ff;
            color: #2874c7;
            margin-bottom: 13px;
            font-size: 18px;
        }

        .stat-card h2 {
            font-size: 25px;
            margin-bottom: 5px;
        }

        .stat-card p {
            color: #78869a;
            font-size: 13px;
        }

        .content-card {
            background: white;
            border-radius: 12px;
            padding: 23px;
            box-shadow: 0 3px 15px rgba(0,0,0,.04);
            border: 1px solid #edf0f5;
        }

        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .content-header h2 {
            font-size: 19px;
        }

        .search-form {
            display: flex;
            gap: 8px;
        }

        .search-form input {
            width: 260px;
            max-width: 100%;
            padding: 11px 13px;
            border: 1px solid #dce3ec;
            border-radius: 7px;
            outline: none;
        }

        .search-form input:focus {
            border-color: #2874c7;
        }

        .search-form button {
            background: #2874c7;
            color: white;
            border: none;
            border-radius: 7px;
            padding: 11px 17px;
            cursor: pointer;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th, td {
            padding: 15px 12px;
            text-align: left;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
            vertical-align: top;
        }

        th {
            color: #64748b;
            background: #f8fafc;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        td strong {
            color: #263445;
        }

        .muted {
            display: block;
            color: #8390a2;
            font-size: 12px;
            margin-top: 5px;
        }

        .diagnosis {
            max-width: 220px;
            white-space: normal;
            line-height: 1.5;
        }

        .empty {
            text-align: center;
            padding: 45px 15px;
            color: #8491a3;
        }

        .empty i {
            font-size: 35px;
            margin-bottom: 12px;
            display: block;
        }

        .result-count {
            color: #718096;
            font-size: 13px;
            margin-bottom: 15px;
        }

        @media (max-width: 700px) {
            .container {
                margin: 20px auto;
                padding: 0 12px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .content-card {
                padding: 15px;
            }

            .search-form {
                width: 100%;
            }

            .search-form input {
                flex: 1;
                width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="topbar">
        <div>
            <h1><i class="fa-solid fa-file-medical"></i> Medical Records</h1>
            <p>View and manage hospital medical record information.</p>
        </div>

        <a href="../admin/dashboard.php" class="back-btn">
            <i class="fa-solid fa-arrow-left"></i> Dashboard
        </a>
    </div>

    <div class="stats">

        <div class="stat-card">
            <div class="icon"><i class="fa-solid fa-file-medical"></i></div>
            <h2><?= number_format($totalRecords) ?></h2>
            <p>Total Medical Records</p>
        </div>

        <div class="stat-card">
            <div class="icon"><i class="fa-solid fa-users"></i></div>
            <h2><?= number_format($totalPatients) ?></h2>
            <p>Patients With Records</p>
        </div>

        <div class="stat-card">
            <div class="icon"><i class="fa-solid fa-user-doctor"></i></div>
            <h2><?= number_format($totalDoctors) ?></h2>
            <p>Doctors With Records</p>
        </div>

    </div>

    <div class="content-card">

        <div class="content-header">
            <h2>All Medical Records</h2>

            <form method="GET" class="search-form">
                <input
                    type="text"
                    name="search"
                    placeholder="Search patient, doctor, diagnosis..."
                    value="<?= e($search) ?>"
                >

                <button type="submit">
                    <i class="fa-solid fa-search"></i> Search
                </button>
            </form>
        </div>

        <div class="result-count">
            Showing <?= count($records) ?> record(s)
            <?php if ($search !== ''): ?>
                for "<strong><?= e($search) ?></strong>"
                <a href="index.php" style="color:#2874c7; margin-left:8px;">
                    Clear search
                </a>
            <?php endif; ?>
        </div>

        <div class="table-wrap">

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Diagnosis</th>
                        <th>Treatment</th>
                        <th>Created Date</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($records)): ?>

                    <tr>
                        <td colspan="6">
                            <div class="empty">
                                <i class="fa-solid fa-folder-open"></i>
                                <strong>No medical records found.</strong>
                                <p style="margin-top:7px;">
                                    Try another search keyword.
                                </p>
                            </div>
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($records as $record): ?>

                        <tr>
                            <td>#<?= (int)$record['id'] ?></td>

                            <td>
                                <strong><?= e($record['patient_name']) ?></strong>
                                <span class="muted">
                                    <?= e($record['patient_number']) ?>
                                </span>
                            </td>

                            <td>
                                <strong><?= e($record['doctor_name']) ?></strong>
                                <span class="muted">
                                    <?= e($record['department_name'] ?? 'No Department') ?>
                                </span>
                            </td>

                            <td>
                                <div class="diagnosis">
                                    <?= e($record['diagnosis']) ?>
                                </div>
                            </td>

                            <td>
                                <div class="diagnosis">
                                    <?= e($record['treatment'] ?: 'Not specified') ?>
                                </div>
                            </td>

                            <td>
                                <?= e(date(
                                    'M d, Y',
                                    strtotime($record['created_at'])
                                )) ?>

                                <span class="muted">
                                    <?= e(date(
                                        'h:i A',
                                        strtotime($record['created_at'])
                                    )) ?>
                                </span>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>

        </div>
    </div>

</div>

</body>
</html>
