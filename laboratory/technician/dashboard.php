
<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';

// Authentication and authorization
requireLogin();
requireRole(['lab_technician']);

$fullName = $_SESSION['full_name'] ?? 'Laboratory Technician';

$errorMessage = '';

$stats = [
    'total_requests' => 0,
    'pending_requests' => 0,
    'urgent_requests' => 0,
    'pending_samples' => 0,
    'processing_tests' => 0,
    'results_entered' => 0
];

$recentRequests = [];

try {

    // =========================================
    // 1. LABORATORY DASHBOARD STATISTICS
    // =========================================

    $statsQuery = $pdo->query("
        SELECT
            (SELECT COUNT(*)
             FROM lab_requests) AS total_requests,

            (SELECT COUNT(*)
             FROM lab_requests
             WHERE status = 'requested') AS pending_requests,

            (SELECT COUNT(*)
             FROM lab_requests
             WHERE status = 'requested'
             AND priority = 'urgent') AS urgent_requests,

            (SELECT COUNT(*)
             FROM lab_samples
             WHERE status = 'pending') AS pending_samples,

            (SELECT COUNT(*)
             FROM lab_requests
             WHERE status = 'processing') AS processing_tests,

            (SELECT COUNT(*)
             FROM lab_requests
             WHERE status = 'result_entered') AS results_entered
    ");

    $stats = $statsQuery->fetch(PDO::FETCH_ASSOC);


    // =========================================
    // 2. RECENT LABORATORY REQUESTS
    // =========================================

    $recentQuery = $pdo->query("
        SELECT
            lr.id,
            lr.priority,
            lr.status,
            lr.requested_at,

            p.patient_number,

            patient_user.full_name AS patient_name,

            doctor_user.full_name AS doctor_name,

            COUNT(DISTINCT lrt.id) AS total_tests

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

        GROUP BY
            lr.id,
            lr.priority,
            lr.status,
            lr.requested_at,
            p.patient_number,
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

        LIMIT 10
    ");

    $recentRequests = $recentQuery->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log(
        'Laboratory Dashboard Error: ' . $e->getMessage()
    );

    $errorMessage = 'Unable to load laboratory dashboard data. Please try again.';
}


// =========================================
// 3. HELPER FUNCTIONS
// =========================================

function e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function statusLabel($status): string
{
    $labels = [
        'requested' => 'Requested',
        'sample_collected' => 'Sample Collected',
        'processing' => 'Processing',
        'result_entered' => 'Result Entered',
        'verified' => 'Verified',
        'cancelled' => 'Cancelled'
    ];

    return $labels[$status] ?? ucfirst($status);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Laboratory Dashboard | MediCare HMS</title>

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

        /* =================================
           SIDEBAR
        ================================= */

        .sidebar {
            width: 250px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: #102a43;
            color: white;
            padding: 25px 18px;
            overflow-y: auto;
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
            margin: 22px 10px 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #dbeafe;
            text-decoration: none;
            padding: 13px 12px;
            border-radius: 8px;
            margin-bottom: 6px;
            font-size: 14px;
            transition: .2s;
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


        /* =================================
           MAIN CONTENT
        ================================= */

        .main {
            margin-left: 250px;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
        }

        .topbar h1 {
            font-size: 26px;
            margin-bottom: 7px;
        }

        .subtitle {
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
        }

        .user-info {
            background: white;
            padding: 13px 18px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,.04);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-info i {
            color: #2563eb;
            font-size: 20px;
        }


        /* =================================
           STATISTICS
        ================================= */

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: white;
            padding: 22px;
            border-radius: 13px;
            box-shadow: 0 3px 14px rgba(0,0,0,.04);
            border: 1px solid #e8edf4;
            transition: transform .2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }

        .stat-top p {
            color: #64748b;
            font-size: 14px;
            font-weight: 600;
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 11px;
            display: grid;
            place-items: center;
            font-size: 19px;
        }

        .blue {
            background: #e0f2fe;
            color: #0284c7;
        }

        .orange {
            background: #ffedd5;
            color: #ea580c;
        }

        .red {
            background: #fee2e2;
            color: #dc2626;
        }

        .purple {
            background: #ede9fe;
            color: #7c3aed;
        }

        .green {
            background: #dcfce7;
            color: #16a34a;
        }

        .cyan {
            background: #cffafe;
            color: #0891b2;
        }

        .stat-card h2 {
            font-size: 31px;
            margin-bottom: 7px;
            color: #1e293b;
        }

        .stat-card .description {
            color: #94a3b8;
            font-size: 12px;
        }


        /* =================================
           QUICK ACTIONS
        ================================= */

        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 28px;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            background: white;
            color: #334155;
            border: 1px solid #e2e8f0;
            padding: 12px 17px;
            border-radius: 9px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: .2s;
        }

        .action-btn:hover {
            border-color: #2563eb;
            color: #2563eb;
            background: #eff6ff;
        }


        /* =================================
           REQUEST TABLE
        ================================= */

        .content-card {
            background: white;
            border-radius: 13px;
            border: 1px solid #e8edf4;
            box-shadow: 0 3px 14px rgba(0,0,0,.04);
            overflow: hidden;
        }

        .card-header {
            padding: 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .card-header h2 {
            font-size: 18px;
        }

        .card-header i {
            color: #2563eb;
            margin-right: 7px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        th,
        td {
            padding: 16px 18px;
            text-align: left;
            border-top: 1px solid #edf1f5;
            font-size: 13px;
        }

        th {
            color: #64748b;
            background: #f8fafc;
            font-weight: 600;
        }

        td {
            color: #334155;
        }

        .request-id {
            color: #2563eb;
            font-weight: bold;
        }

        .patient-name {
            font-weight: 600;
        }


        /* =================================
           STATUS BADGES
        ================================= */

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            white-space: nowrap;
            background: #e0f2fe;
            color: #0369a1;
        }

        .status.requested {
            background: #fef3c7;
            color: #92400e;
        }

        .status.sample_collected {
            background: #cffafe;
            color: #0e7490;
        }

        .status.processing {
            background: #ede9fe;
            color: #6d28d9;
        }

        .status.result_entered {
            background: #dcfce7;
            color: #166534;
        }

        .status.verified {
            background: #dcfce7;
            color: #166534;
        }

        .status.cancelled,
        .status.rejected {
            background: #fee2e2;
            color: #991b1b;
        }


        /* =================================
           PRIORITY
        ================================= */

        .priority {
            font-weight: bold;
            font-size: 12px;
        }

        .priority.urgent {
            color: #dc2626;
        }

        .priority.normal {
            color: #64748b;
        }


        /* =================================
           EMPTY AND ERROR
        ================================= */

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

        .empty p {
            margin-top: 8px;
            font-size: 14px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }


        /* =================================
           RESPONSIVE DESIGN
        ================================= */

        @media (max-width: 1200px) {

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

        }

        @media (max-width: 800px) {

            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                padding: 18px;
            }

            .brand {
                margin-bottom: 15px;
            }

            .sidebar .menu-title {
                display: none;
            }

            .menu-links {
                display: flex;
                flex-wrap: wrap;
                gap: 5px;
            }

            .sidebar a {
                margin: 0;
                padding: 10px;
            }

            .logout {
                margin-top: 12px;
                padding-top: 10px;
            }

            .main {
                margin-left: 0;
                padding: 20px;
            }

        }

        @media (max-width: 550px) {

            .stats {
                grid-template-columns: 1fr;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .main {
                padding: 14px;
            }

            .topbar h1 {
                font-size: 22px;
            }

        }

    </style>

</head>


<body>


<!-- =================================
     SIDEBAR
================================= -->

<aside class="sidebar">

    <div class="brand">

        <i class="fa-solid fa-hospital"></i>

        Medi<span>Care</span>

    </div>


    <div class="menu-title">
        Laboratory
    </div>


    <div class="menu-links">

        <a href="dashboard.php" class="active">

            <i class="fa-solid fa-chart-line"></i>

            Dashboard

        </a>


       <a href="requests.php">

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

    </div>


    <div class="logout">

        <a href="../../logout.php">

            <i class="fa-solid fa-right-from-bracket"></i>

            Logout

        </a>

    </div>

</aside>



<!-- =================================
     MAIN CONTENT
================================= -->

<main class="main">


    <!-- TOP BAR -->

    <header class="topbar">

        <div>

            <h1>
                Laboratory Dashboard
            </h1>

            <p class="subtitle">

                Welcome back,
                <?= e($fullName) ?>.

                Here is your laboratory overview.

            </p>

        </div>


        <div class="user-info">

            <i class="fa-solid fa-user-circle"></i>

            <span>
                Laboratory Technician
            </span>

        </div>

    </header>



    <!-- ERROR MESSAGE -->

    <?php if (!empty($errorMessage)): ?>

        <div class="error">

            <i class="fa-solid fa-circle-exclamation"></i>

            <?= e($errorMessage) ?>

        </div>

    <?php endif; ?>



    <!-- =================================
         DASHBOARD STATISTICS
    ================================= -->

    <section class="stats">


        <!-- TOTAL REQUESTS -->

        <div class="stat-card">

            <div class="stat-top">

                <p>Total Requests</p>

                <div class="stat-icon blue">

                    <i class="fa-solid fa-file-medical"></i>

                </div>

            </div>

            <h2>
                <?= (int)$stats['total_requests'] ?>
            </h2>

            <p class="description">
                All laboratory requests
            </p>

        </div>



        <!-- PENDING REQUESTS -->

        <div class="stat-card">

            <div class="stat-top">

                <p>Pending Requests</p>

                <div class="stat-icon orange">

                    <i class="fa-solid fa-bell"></i>

                </div>

            </div>

            <h2>
                <?= (int)$stats['pending_requests'] ?>
            </h2>

            <p class="description">
                New requests waiting for technician
            </p>

        </div>



        <!-- URGENT REQUESTS -->

        <div class="stat-card">

            <div class="stat-top">

                <p>Urgent Requests</p>

                <div class="stat-icon red">

                    <i class="fa-solid fa-triangle-exclamation"></i>

                </div>

            </div>

            <h2>
                <?= (int)$stats['urgent_requests'] ?>
            </h2>

            <p class="description">
                Requests requiring priority attention
            </p>

        </div>



        <!-- PENDING SAMPLES -->

        <div class="stat-card">

            <div class="stat-top">

                <p>Pending Samples</p>

                <div class="stat-icon cyan">

                    <i class="fa-solid fa-vial"></i>

                </div>

            </div>

            <h2>
                <?= (int)$stats['pending_samples'] ?>
            </h2>

            <p class="description">
                Samples waiting for collection
            </p>

        </div>



        <!-- PROCESSING TESTS -->

        <div class="stat-card">

            <div class="stat-top">

                <p>Processing Tests</p>

                <div class="stat-icon purple">

                    <i class="fa-solid fa-microscope"></i>

                </div>

            </div>

            <h2>
                <?= (int)$stats['processing_tests'] ?>
            </h2>

            <p class="description">
                Tests currently processing
            </p>

        </div>



        <!-- RESULTS ENTERED -->

        <div class="stat-card">

            <div class="stat-top">

                <p>Results Entered</p>

                <div class="stat-icon green">

                    <i class="fa-solid fa-file-circle-check"></i>

                </div>

            </div>

            <h2>
                <?= (int)$stats['results_entered'] ?>
            </h2>

            <p class="description">
                Waiting for verification
            </p>

        </div>


    </section>



    <!-- =================================
         QUICK ACTIONS
    ================================= -->

    <section class="quick-actions">

        <a href="../requests/index.php" class="action-btn">

            <i class="fa-solid fa-file-medical"></i>

            View Lab Requests

        </a>


        <a href="../samples/index.php" class="action-btn">

            <i class="fa-solid fa-vial"></i>

            Sample Collection

        </a>


        <a href="../tests/index.php" class="action-btn">

            <i class="fa-solid fa-list-check"></i>

            Manage Tests

        </a>

    </section>



    <!-- =================================
         RECENT LAB REQUESTS
    ================================= -->

    <section class="content-card">


        <div class="card-header">

            <h2>

                <i class="fa-solid fa-clock-rotate-left"></i>

                Recent Laboratory Requests

            </h2>

        </div>



        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>Request ID</th>

                        <th>Patient</th>

                        <th>Patient Number</th>

                        <th>Doctor</th>

                        <th>Total Tests</th>

                        <th>Priority</th>

                        <th>Status</th>

                        <th>Requested At</th>

                    </tr>

                </thead>


                <tbody>


                <?php if (count($recentRequests) > 0): ?>


                    <?php foreach ($recentRequests as $request): ?>


                        <tr>


                            <td class="request-id">

                                #<?= (int)$request['id'] ?>

                            </td>


                            <td class="patient-name">

                                <?= e(
                                    $request['patient_name'] ?? 'Unknown'
                                ) ?>

                            </td>


                            <td>

                                <?= e($request['patient_number']) ?>

                            </td>


                            <td>

                                <?= e($request['doctor_name']) ?>

                            </td>


                            <td>

                                <?= (int)$request['total_tests'] ?>

                            </td>


                            <td>

                                <span class="priority <?= e($request['priority']) ?>">

                                    <?php if ($request['priority'] === 'urgent'): ?>

                                        <i class="fa-solid fa-circle-exclamation"></i>

                                    <?php endif; ?>

                                    <?= e(ucfirst($request['priority'])) ?>

                                </span>

                            </td>


                            <td>

                                <span class="status <?= e($request['status']) ?>">

                                    <?= e(
                                        statusLabel($request['status'])
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <?= e($request['requested_at']) ?>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                <?php else: ?>


                    <tr>

                        <td colspan="8" class="empty">

                            <i class="fa-solid fa-inbox"></i>

                            <h3>No Laboratory Requests Found</h3>

                            <p>
                                New requests from doctors will appear here.
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
