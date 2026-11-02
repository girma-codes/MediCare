
<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requireRole(['lab_technician']);

$fullName = $_SESSION['full_name'] ?? 'Laboratory Technician';

$requests = [];
$errorMessage = '';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function requestStatusLabel($status): string
{
    $labels = [
        'requested' => 'New Request',
        'sample_collected' => 'Sample Collected',
        'processing' => 'Processing',
        'result_entered' => 'Result Entered',
        'verified' => 'Verified',
        'cancelled' => 'Cancelled'
    ];

    return $labels[$status] ?? ucfirst($status);
}

try {

    $stmt = $pdo->query("
        SELECT
            lr.id,
            lr.priority,
            lr.status,
            lr.requested_at,

            p.id AS patient_id,
            p.patient_number,
            p.gender,
            p.phone AS patient_phone,

            patient_user.full_name AS patient_name,

            doctor_user.full_name AS doctor_name,

            GROUP_CONCAT(
                DISTINCT lt.test_name
                ORDER BY lt.test_name
                SEPARATOR ', '
            ) AS test_names,

            COUNT(DISTINCT lrt.test_id) AS total_tests

        FROM lab_requests lr

        INNER JOIN patients p
            ON p.id = lr.patient_id

        LEFT JOIN users patient_user
            ON patient_user.id = p.user_id

        INNER JOIN doctors d
            ON d.id = lr.doctor_id

        INNER JOIN users doctor_user
            ON doctor_user.id = d.user_id

        LEFT JOIN lab_request_tests lrt
            ON lrt.request_id = lr.id

        LEFT JOIN lab_tests lt
            ON lt.id = lrt.test_id

        GROUP BY
            lr.id,
            lr.priority,
            lr.status,
            lr.requested_at,
            p.id,
            p.patient_number,
            p.gender,
            p.phone,
            patient_user.full_name,
            doctor_user.full_name

        ORDER BY
            CASE
                WHEN lr.status = 'requested'
                     AND lr.priority = 'urgent' THEN 1
                WHEN lr.status = 'requested' THEN 2
                ELSE 3
            END,
            lr.requested_at DESC
    ");

    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log('Technician Requests Error: ' . $e->getMessage());

    $errorMessage = 'Unable to load laboratory requests. Please check the database.';
}

$totalRequests = count($requests);

$pendingRequests = 0;
$urgentRequests = 0;
$completedRequests = 0;

foreach ($requests as $request) {

    if ($request['status'] === 'requested') {
        $pendingRequests++;
    }

    if (
        $request['status'] === 'requested' &&
        $request['priority'] === 'urgent'
    ) {
        $urgentRequests++;
    }

    if (
        in_array(
            $request['status'],
            ['result_entered', 'verified'],
            true
        )
    ) {
        $completedRequests++;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Laboratory Requests | MediCare HMS</title>

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
    color: #1e293b;
}

.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 250px;
    height: 100vh;
    background: #102a43;
    padding: 25px 18px;
    color: white;
}

.brand {
    font-size: 23px;
    font-weight: bold;
    margin-bottom: 35px;
}

.brand span {
    color: #38bdf8;
}

.menu-title {
    font-size: 11px;
    color: #94a3b8;
    margin: 20px 10px 12px;
    text-transform: uppercase;
}

.sidebar a {
    display: flex;
    gap: 12px;
    align-items: center;
    color: #dbeafe;
    text-decoration: none;
    padding: 13px 12px;
    border-radius: 8px;
    margin-bottom: 6px;
    font-size: 14px;
}

.sidebar a:hover,
.sidebar a.active {
    background: #1e4968;
    color: white;
}

.sidebar a i {
    width: 18px;
}

.logout {
    margin-top: 30px;
    border-top: 1px solid #34506a;
    padding-top: 15px;
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
    margin-bottom: 28px;
}

.topbar h1 {
    font-size: 26px;
    margin-bottom: 8px;
}

.subtitle {
    color: #64748b;
    font-size: 14px;
    line-height: 1.5;
}

.user-info {
    background: white;
    padding: 13px 17px;
    border-radius: 9px;
    display: flex;
    gap: 10px;
    align-items: center;
    font-size: 13px;
}

.user-info i {
    color: #2563eb;
    font-size: 19px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    border: 1px solid #e8edf4;
    border-radius: 12px;
    padding: 20px;
}

.stat-card p {
    color: #64748b;
    font-size: 13px;
    margin-bottom: 13px;
}

.stat-card h2 {
    font-size: 28px;
}

.stat-card i {
    float: right;
    font-size: 20px;
    color: #2563eb;
}

.content-card {
    background: white;
    border: 1px solid #e8edf4;
    border-radius: 12px;
    overflow: hidden;
}

.card-header {
    padding: 22px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.card-header h2 {
    font-size: 18px;
}

.card-header p {
    font-size: 12px;
    color: #64748b;
    margin-top: 6px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 1100px;
    border-collapse: collapse;
}

th, td {
    padding: 15px 16px;
    text-align: left;
    border-top: 1px solid #edf1f5;
    font-size: 13px;
    vertical-align: middle;
}

th {
    background: #f8fafc;
    color: #64748b;
}

.patient-name {
    font-weight: 600;
    color: #1e293b;
}

.patient-number {
    color: #64748b;
    font-size: 12px;
    margin-top: 5px;
}

.tests {
    max-width: 230px;
    line-height: 1.6;
}

.badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    white-space: nowrap;
}

.priority-urgent {
    color: #b91c1c;
    background: #fee2e2;
}

.priority-normal {
    color: #475569;
    background: #e2e8f0;
}

.status-requested {
    color: #92400e;
    background: #fef3c7;
}

.status-sample_collected {
    color: #0e7490;
    background: #cffafe;
}

.status-processing {
    color: #6d28d9;
    background: #ede9fe;
}

.status-result_entered,
.status-verified {
    color: #166534;
    background: #dcfce7;
}

.status-cancelled {
    color: #991b1b;
    background: #fee2e2;
}

.view-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 12px;
    border-radius: 7px;
    background: #2563eb;
    color: white;
    text-decoration: none;
    font-size: 12px;
    font-weight: bold;
}

.view-btn:hover {
    background: #1d4ed8;
}

.empty {
    padding: 45px 20px;
    text-align: center;
    color: #64748b;
}

.empty i {
    font-size: 35px;
    color: #cbd5e1;
    margin-bottom: 15px;
}

.error {
    padding: 15px;
    background: #fee2e2;
    color: #991b1b;
    border-radius: 8px;
    margin-bottom: 20px;
}

@media(max-width: 1200px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 800px) {
    .sidebar {
        position: relative;
        width: 100%;
        height: auto;
    }

    .main {
        margin-left: 0;
        padding: 18px;
    }

    .topbar {
        align-items: flex-start;
        flex-direction: column;
    }
}

@media(max-width: 500px) {
    .stats {
        grid-template-columns: 1fr;
    }
}

</style>

</head>

<body>

<aside class="sidebar">

    <div class="brand">
        <i class="fa-solid fa-hospital"></i>
        Medi<span>Care</span>
    </div>

    <div class="menu-title">Laboratory</div>

    <a href="dashboard.php">
        <i class="fa-solid fa-chart-line"></i>
        Dashboard
    </a>

    <a href="requests.php" class="active">
        <i class="fa-solid fa-file-medical"></i>
        Lab Requests
    </a>

    <a href="../samples/index.php">
        <i class="fa-solid fa-vial"></i>
        Sample Collection
    </a>

    <a href="../results/index.php">
        <i class="fa-solid fa-flask"></i>
        Enter Results
    </a>

    <a href="../tests/index.php">
        <i class="fa-solid fa-list-check"></i>
        Test Catalog
    </a>

    <a href="../reports/index.php">
        <i class="fa-solid fa-chart-column"></i>
        Reports
    </a>

    <div class="logout">
        <a href="../../logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            Logout
        </a>
    </div>

</aside>


<main class="main">

    <header class="topbar">

        <div>
            <h1>Laboratory Requests</h1>

            <p class="subtitle">
                Review laboratory requests submitted by doctors.
            </p>
        </div>

        <div class="user-info">
            <i class="fa-solid fa-user-circle"></i>
            <?= e($fullName) ?>
        </div>

    </header>


    <?php if ($errorMessage): ?>

        <div class="error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= e($errorMessage) ?>
        </div>

    <?php endif; ?>


    <section class="stats">

        <div class="stat-card">
            <i class="fa-solid fa-file-medical"></i>
            <p>Total Requests</p>
            <h2><?= $totalRequests ?></h2>
        </div>

        <div class="stat-card">
            <i class="fa-solid fa-bell"></i>
            <p>Pending Requests</p>
            <h2><?= $pendingRequests ?></h2>
        </div>

        <div class="stat-card">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <p>Urgent Requests</p>
            <h2><?= $urgentRequests ?></h2>
        </div>

        <div class="stat-card">
            <i class="fa-solid fa-check-circle"></i>
            <p>Results Entered / Verified</p>
            <h2><?= $completedRequests ?></h2>
        </div>

    </section>


    <section class="content-card">

        <div class="card-header">

            <div>
                <h2>
                    <i class="fa-solid fa-list"></i>
                    All Laboratory Requests
                </h2>

                <p>
                    Urgent and new requests are displayed first.
                </p>
            </div>

        </div>


        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Requested Tests</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Requested At</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($requests) > 0): ?>

                    <?php foreach ($requests as $request): ?>

                        <tr>

                            <td>
                                <strong>
                                    #<?= (int)$request['id'] ?>
                                </strong>
                            </td>

                            <td>
                                <div class="patient-name">
                                    <?= e($request['patient_name'] ?? 'Unknown Patient') ?>
                                </div>

                                <div class="patient-number">
                                    <?= e($request['patient_number']) ?>
                                </div>
                            </td>

                            <td>
                                <?= e($request['doctor_name']) ?>
                            </td>

                            <td class="tests">
                                <?= e($request['test_names'] ?? 'No tests listed') ?>

                                <br>

                                <small>
                                    <?= (int)$request['total_tests'] ?> test(s)
                                </small>
                            </td>

                            <td>
                                <span class="badge priority-<?= e($request['priority']) ?>">
                                    <?= e(ucfirst($request['priority'])) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge status-<?= e($request['status']) ?>">
                                    <?= e(requestStatusLabel($request['status'])) ?>
                                </span>
                            </td>

                            <td>
                                <?= e($request['requested_at']) ?>
                            </td>

                            <td>
                                <a
                                    class="view-btn"
                                    href="request-details.php?id=<?= (int)$request['id'] ?>"
                                >
                                    <i class="fa-solid fa-eye"></i>
                                    View Details
                                </a>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" class="empty">
                            <i class="fa-solid fa-inbox"></i>

                            <h3>No Laboratory Requests Found</h3>

                            <p>
                                Requests sent by doctors will appear here.
                            </p>
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

</body>
</html>
