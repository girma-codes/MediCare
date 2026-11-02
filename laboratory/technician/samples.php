
<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requireRole(['lab_technician']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

if (isset($_SESSION['sample_message'])) {
    $message = $_SESSION['sample_message'];
    unset($_SESSION['sample_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $sampleId = filter_input(INPUT_POST, 'sample_id', FILTER_VALIDATE_INT);

    if (!$token || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid security token. Please refresh the page.';
    } elseif (!$sampleId || $sampleId < 1) {
        $error = 'Invalid sample selected.';
    } else {
        try {
            $pdo->beginTransaction();

            // Lock the sample row to prevent duplicate receiving.
            $stmt = $pdo->prepare("
                SELECT
                    s.id,
                    s.request_id,
                    s.status AS sample_status,
                    r.status AS request_status
                FROM lab_samples s
                INNER JOIN lab_requests r ON r.id = s.request_id
                WHERE s.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$sampleId]);
            $sample = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sample) {
                throw new Exception('Sample not found.');
            }

            if ($sample['sample_status'] !== 'collected') {
                throw new Exception('Only collected samples can be received.');
            }

            if ($sample['request_status'] !== 'sample_collected') {
                throw new Exception('The laboratory request is not ready for receiving.');
            }

            // Mark sample as received.
            $stmt = $pdo->prepare("
                UPDATE lab_samples
                SET status = 'received',
                    received_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$sampleId]);

            // Move the laboratory request into processing.
            $stmt = $pdo->prepare("
                UPDATE lab_requests
                SET status = 'processing'
                WHERE id = ?
            ");
            $stmt->execute([$sample['request_id']]);

            $pdo->commit();

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['sample_message'] =
                'Sample received successfully. Laboratory processing has started.';

            header('Location: samples.php');
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

// Fetch samples for the technician.
$stmt = $pdo->query("
    SELECT
        s.id AS sample_id,
        s.request_id,
        s.sample_code,
        s.sample_type,
        s.status AS sample_status,
        s.collected_at,
        s.received_at,
        r.priority,
        r.status AS request_status,
        r.requested_at,
        p.patient_number,
        u.full_name AS patient_name,
        d_user.full_name AS doctor_name
    FROM lab_samples s
    INNER JOIN lab_requests r ON r.id = s.request_id
    INNER JOIN patients p ON p.id = r.patient_id
    LEFT JOIN users u ON u.id = p.user_id
    LEFT JOIN doctors d ON d.id = r.doctor_id
    LEFT JOIN users d_user ON d_user.id = d.user_id
    ORDER BY
        CASE WHEN s.status = 'collected' THEN 0 ELSE 1 END,
        r.priority DESC,
        s.collected_at DESC
");

$samples = $stmt->fetchAll(PDO::FETCH_ASSOC);

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sample Receiving | MediCare HMS</title>
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #243247;
        }

        .sidebar {
            width: 245px;
            min-height: 100vh;
            background: #10243e;
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            padding: 25px 16px;
        }

        .brand {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 35px;
            padding-left: 10px;
        }

        .brand span {
            color: #36c6a0;
        }

        .sidebar a {
            display: block;
            color: #d8e3ef;
            text-decoration: none;
            padding: 13px 12px;
            margin: 5px 0;
            border-radius: 8px;
            font-size: 14px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #1e3a5c;
            color: white;
        }

        .sidebar i {
            width: 25px;
        }

        .main {
            margin-left: 245px;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        h1 {
            font-size: 26px;
            margin-bottom: 7px;
        }

        .subtitle {
            color: #718096;
            font-size: 14px;
        }

        .user {
            background: white;
            padding: 11px 16px;
            border-radius: 10px;
            font-size: 14px;
            box-shadow: 0 2px 10px #1b355010;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .success {
            background: #e7f8ef;
            color: #167345;
        }

        .danger {
            background: #fff0f0;
            color: #b42318;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 12px #1b355008;
        }

        .stat-card p {
            color: #718096;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .stat-card h2 {
            font-size: 27px;
        }

        .table-card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px #1b355008;
        }

        .table-heading {
            margin-bottom: 18px;
        }

        .table-heading h2 {
            font-size: 19px;
            margin-bottom: 6px;
        }

        .table-heading p {
            color: #718096;
            font-size: 13px;
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
            text-align: left;
            padding: 14px 12px;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
        }

        th {
            color: #718096;
            background: #f8fafc;
            font-weight: 600;
        }

        .sample-code {
            font-weight: bold;
            color: #176b9b;
        }

        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .collected {
            background: #fff3d9;
            color: #9a6700;
        }

        .received {
            background: #e4f2ff;
            color: #1769aa;
        }

        .priority {
            color: #b42318;
            font-weight: bold;
        }

        .normal {
            color: #64748b;
            font-weight: normal;
        }

        .btn {
            border: none;
            border-radius: 7px;
            padding: 9px 12px;
            background: #087f67;
            color: white;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
        }

        .btn:hover {
            background: #066650;
        }

        .completed {
            color: #16804a;
            font-size: 12px;
            font-weight: bold;
        }

        .empty {
            text-align: center;
            padding: 45px 15px;
            color: #718096;
        }

        .empty i {
            display: block;
            font-size: 35px;
            margin-bottom: 12px;
            color: #a5b4c5;
        }

        @media (max-width: 900px) {
            .sidebar {
                width: 200px;
            }

            .main {
                margin-left: 200px;
                padding: 20px;
            }

            .stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 650px) {
            .sidebar {
                position: static;
                width: 100%;
                min-height: auto;
            }

            .main {
                margin-left: 0;
                padding: 17px;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="brand"><i class="fas fa-heartbeat"></i> Medi<span>Care</span></div>

    <a href="dashboard.php">
        <i class="fas fa-chart-line"></i> Dashboard
    </a>

    <a href="requests.php">
        <i class="fas fa-file-medical"></i> Laboratory Requests
    </a>

    <a href="samples.php" class="active">
        <i class="fas fa-vial"></i> Sample Receiving
    </a>

    <a href="results.php">
        <i class="fas fa-flask"></i> Results Entry
    </a>

    <a href="../tests/index.php">
        <i class="fas fa-list"></i> Test Catalog
    </a>

    <a href="../../auth/logout.php">
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
</aside>

<main class="main">
    <div class="topbar">
        <div>
            <h1>Sample Receiving & Processing</h1>
            <p class="subtitle">Receive collected samples and start laboratory processing.</p>
        </div>
        <div class="user">
            <i class="fas fa-user-circle"></i>
            Laboratory Technician
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert success">
            <i class="fas fa-check-circle"></i>
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert danger">
            <i class="fas fa-exclamation-circle"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php
    $waitingCount = 0;
    $receivedCount = 0;

    foreach ($samples as $sample) {
        if ($sample['sample_status'] === 'collected') {
            $waitingCount++;
        } elseif ($sample['sample_status'] === 'received') {
            $receivedCount++;
        }
    }
    ?>

    <section class="stats">
        <div class="stat-card">
            <p>Total Samples</p>
            <h2><?= count($samples) ?></h2>
        </div>

        <div class="stat-card">
            <p>Waiting for Receiving</p>
            <h2><?= $waitingCount ?></h2>
        </div>

        <div class="stat-card">
            <p>Received / Processing</p>
            <h2><?= $receivedCount ?></h2>
        </div>
    </section>

    <section class="table-card">
        <div class="table-heading">
            <h2><i class="fas fa-vials"></i> Sample Records</h2>
            <p>Review each sample before confirming laboratory receipt.</p>
        </div>

        <?php if (count($samples) > 0): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Sample Code</th>
                            <th>Patient</th>
                            <th>Request</th>
                            <th>Sample Type</th>
                            <th>Priority</th>
                            <th>Sample Status</th>
                            <th>Collected At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($samples as $sample): ?>
                        <tr>
                            <td class="sample-code">
                                <?= e($sample['sample_code']) ?>
                            </td>

                            <td>
                                <strong><?= e($sample['patient_name'] ?: 'Unknown Patient') ?></strong><br>
                                <small><?= e($sample['patient_number']) ?></small>
                            </td>

                            <td>
                                #<?= (int)$sample['request_id'] ?>
                            </td>

                            <td><?= e($sample['sample_type']) ?></td>

                            <td class="<?= $sample['priority'] === 'urgent' ? 'priority' : 'normal' ?>">
                                <?= e(ucfirst($sample['priority'])) ?>
                            </td>

                            <td>
                                <span class="badge <?= e($sample['sample_status']) ?>">
                                    <?= e(str_replace('_', ' ', $sample['sample_status'])) ?>
                                </span>
                            </td>

                            <td><?= e($sample['collected_at']) ?></td>

                            <td>
                                <?php if ($sample['sample_status'] === 'collected'): ?>
                                    <form method="POST"
                                          onsubmit="return confirm('Confirm that this sample has physically arrived at the laboratory and is ready for processing?');">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="sample_id"
                                               value="<?= (int)$sample['sample_id'] ?>">
                                        <button type="submit" class="btn">
                                            <i class="fas fa-check"></i>
                                            Receive & Start
                                        </button>
                                    </form>
                                <?php elseif ($sample['sample_status'] === 'received'): ?>
                                    <span class="completed">
                                        <i class="fas fa-check-circle"></i>
                                        Processing Started
                                    </span>
                                <?php else: ?>
                                    <span><?= e(ucfirst($sample['sample_status'])) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty">
                <i class="fas fa-vial"></i>
                <h3>No Samples Found</h3>
                <p>Collected samples will appear here.</p>
            </div>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
