
<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin']);

$adminName = $_SESSION['full_name'] ?? 'Administrator';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$successMessage = $_SESSION['admin_flash_success'] ?? '';
$errorMessage = $_SESSION['admin_flash_error'] ?? '';

unset($_SESSION['admin_flash_success'], $_SESSION['admin_flash_error']);

/*
|--------------------------------------------------------------------------
| ADMIN APPOINTMENT STATUS MANAGEMENT
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_appointment'])
) {

    try {

        $submittedToken = $_POST['csrf_token'] ?? '';

        if (
            !is_string($submittedToken) ||
            !hash_equals($csrfToken, $submittedToken)
        ) {
            throw new Exception('Invalid security token. Please try again.');
        }

        $appointmentId = filter_var(
            $_POST['appointment_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $newStatus = $_POST['new_status'] ?? '';

        if (
            !$appointmentId ||
            !in_array($newStatus, ['confirmed', 'cancelled'], true)
        ) {
            throw new Exception('Invalid appointment information.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.status,
                p.user_id
            FROM appointments a
            INNER JOIN patients p ON p.id = a.patient_id
            WHERE a.id = :appointment_id
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            'appointment_id' => $appointmentId
        ]);

        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$appointment) {
            throw new Exception('Appointment not found.');
        }

        if ($appointment['status'] !== 'pending') {
            throw new Exception(
                'Only pending appointments can be confirmed or cancelled.'
            );
        }

        if (empty($appointment['user_id'])) {
            throw new Exception('Patient account not found.');
        }

        $update = $pdo->prepare("
            UPDATE appointments
            SET status = :status
            WHERE id = :id
              AND status = 'pending'
        ");

        $update->execute([
            'status' => $newStatus,
            'id' => $appointmentId
        ]);

        if ($update->rowCount() !== 1) {
            throw new Exception('Appointment could not be updated.');
        }

        if ($newStatus === 'confirmed') {
            $notificationMessage =
                'Good news! Your appointment has been confirmed.';
        } else {
            $notificationMessage =
                'Your appointment has been cancelled by the hospital administrator.';
        }

        $notify = $pdo->prepare("
            INSERT INTO notifications
                (user_id, message, is_read, created_at)
            VALUES
                (:user_id, :message, 0, NOW())
        ");

        $notify->execute([
            'user_id' => $appointment['user_id'],
            'message' => $notificationMessage
        ]);

        $pdo->commit();

        $_SESSION['admin_flash_success'] =
            'Appointment status updated successfully! Patient has been notified.';

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Admin appointment update error: ' . $e->getMessage()
        );

        $_SESSION['admin_flash_error'] =
            $e instanceof PDOException
                ? 'Database error. Appointment was not updated.'
                : $e->getMessage();
    }

    header('Location: dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| DASHBOARD DATA
|--------------------------------------------------------------------------
*/

$stats = [
    'patients' => 0,
    'doctors' => 0,
    'appointments' => 0,
    'departments' => 0,
    'pending' => 0
];

$expiryStats = [
    'expired' => 0,
    'within_30' => 0,
    'within_60' => 0
];

$appointments = [];
$pendingAppointments = [];
$expiryMedicines = [];

/*
|--------------------------------------------------------------------------
| PHARMACY LOW STOCK VARIABLES
|--------------------------------------------------------------------------
*/

$lowStockMedicines = [];
$lowStockCount = 0;
$totalLowStockUnits = 0;
$lowStockThreshold = 10;

$dbError = false;

try {

    /*
     * GENERAL STATISTICS
     */

    $stats['patients'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM patients")
        ->fetchColumn();

    $stats['doctors'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM doctors")
        ->fetchColumn();

    $stats['appointments'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM appointments")
        ->fetchColumn();

    $stats['departments'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM departments")
        ->fetchColumn();

    $stats['pending'] = (int) $pdo
        ->query("
            SELECT COUNT(*)
            FROM appointments
            WHERE status = 'pending'
        ")
        ->fetchColumn();


    /*
    |--------------------------------------------------------------------------
    | PHARMACY EXPIRY STATISTICS
    |--------------------------------------------------------------------------
    */

    $expiryStats['expired'] = (int) $pdo->query("
        SELECT COUNT(*)
        FROM medicines
        WHERE expiry_date IS NOT NULL
          AND expiry_date < CURDATE()
    ")->fetchColumn();


    $expiryStats['within_30'] = (int) $pdo->query("
        SELECT COUNT(*)
        FROM medicines
        WHERE expiry_date BETWEEN CURDATE()
          AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ")->fetchColumn();


    $expiryStats['within_60'] = (int) $pdo->query("
        SELECT COUNT(*)
        FROM medicines
        WHERE expiry_date BETWEEN
              DATE_ADD(CURDATE(), INTERVAL 31 DAY)
          AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ")->fetchColumn();


    $expirySql = $pdo->query("
        SELECT
            id,
            medicine_name,
            category,
            quantity,
            expiry_date,
            DATEDIFF(expiry_date, CURDATE()) AS days_remaining
        FROM medicines
        WHERE expiry_date IS NOT NULL
          AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY expiry_date ASC
        LIMIT 10
    ");

    $expiryMedicines = $expirySql->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | PHARMACY LOW STOCK STATISTICS
    |--------------------------------------------------------------------------
    */

    $lowStockStmt = $pdo->prepare("
        SELECT
            id,
            medicine_name,
            category,
            quantity,
            unit_price,
            expiry_date
        FROM medicines
        WHERE quantity <= :threshold
        ORDER BY quantity ASC, medicine_name ASC
    ");

    $lowStockStmt->execute([
        'threshold' => $lowStockThreshold
    ]);

    $lowStockMedicines = $lowStockStmt->fetchAll(PDO::FETCH_ASSOC);

    $lowStockCount = count($lowStockMedicines);

    foreach ($lowStockMedicines as $medicine) {
        $totalLowStockUnits += (int) $medicine['quantity'];
    }


    /*
    |--------------------------------------------------------------------------
    | RECENT APPOINTMENTS
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            a.id,
            a.appointment_date,
            a.appointment_time,
            a.status,
            p.patient_number,
            u.full_name AS patient_name,
            du.full_name AS doctor_name
        FROM appointments a
        INNER JOIN patients p ON a.patient_id = p.id
        LEFT JOIN users u ON p.user_id = u.id
        INNER JOIN doctors d ON a.doctor_id = d.id
        INNER JOIN users du ON d.user_id = du.id
        ORDER BY
            a.appointment_date DESC,
            a.appointment_time DESC
        LIMIT 7
    ";

    $appointments = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | PENDING APPOINTMENTS
    |--------------------------------------------------------------------------
    */

    $pendingSql = "
        SELECT
            a.id,
            a.appointment_date,
            a.appointment_time,
            a.reason,
            a.status,
            p.patient_number,
            pu.full_name AS patient_name,
            du.full_name AS doctor_name,
            d.specialization,
            dep.name AS department_name
        FROM appointments a
        INNER JOIN patients p ON a.patient_id = p.id
        INNER JOIN users pu ON p.user_id = pu.id
        INNER JOIN doctors d ON a.doctor_id = d.id
        INNER JOIN users du ON d.user_id = du.id
        LEFT JOIN departments dep ON d.department_id = dep.id
        WHERE a.status = 'pending'
        ORDER BY
            a.appointment_date ASC,
            a.appointment_time ASC
    ";

    $pendingAppointments = $pdo
        ->query($pendingSql)
        ->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    $dbError = true;

    error_log(
        'Admin Dashboard database error: ' . $e->getMessage()
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | MediCare HMS</title>

    <link rel="stylesheet" href="../assets/css/admin.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>

        .alert-success,
        .alert-error {
            padding: 15px 18px;
            margin-bottom: 22px;
            border-radius: 10px;
            font-size: 14px;
        }

        .alert-success {
            color: #166534;
            background: #dcfce7;
            border: 1px solid #86efac;
        }

        .alert-error {
            color: #991b1b;
            background: #fee2e2;
            border: 1px solid #fca5a5;
        }

        .pending-section {
            margin-top: 35px;
        }

        .pending-badge {
            display: inline-block;
            background: #fff1db;
            color: #b45309;
            padding: 5px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .appointment-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .appointment-actions form {
            margin: 0;
        }

        .action-btn {
            border: none;
            padding: 8px 12px;
            border-radius: 7px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            color: white;
        }

        .confirm-btn {
            background: #16a34a;
        }

        .cancel-btn {
            background: #dc2626;
        }

        .confirm-btn:hover {
            background: #15803d;
        }

        .cancel-btn:hover {
            background: #b91c1c;
        }

        .pending-count {
            background: #fff7ed;
            color: #c2410c;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 7px;
        }

        .reason-text {
            max-width: 180px;
            overflow-wrap: anywhere;
        }


        /* PHARMACY EXPIRY ALERTS */

        .expiry-section {
            margin-top: 30px;
            margin-bottom: 30px;
        }

        .expiry-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .expiry-heading h2 {
            font-size: 21px;
            margin-bottom: 6px;
        }

        .expiry-heading p {
            color: #718096;
            font-size: 13px;
        }

        .expiry-link {
            display: inline-block;
            padding: 10px 16px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        .expiry-link:hover {
            background: #1d4ed8;
        }

        .expiry-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 18px;
        }

        .expiry-stat {
            background: white;
            border: 1px solid #edf0f5;
            border-radius: 10px;
            padding: 18px;
        }

        .expiry-stat h3 {
            font-size: 26px;
            margin-bottom: 7px;
        }

        .expiry-stat p {
            font-size: 13px;
            color: #718096;
        }

        .expiry-stat.danger {
            border-left: 4px solid #dc2626;
        }

        .expiry-stat.warning {
            border-left: 4px solid #f59e0b;
        }

        .expiry-stat.info {
            border-left: 4px solid #2563eb;
        }

        .expiry-table-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #edf0f5;
        }

        .expiry-table-card h3 {
            margin-bottom: 18px;
        }

        .expiry-status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .expiry-status.expired {
            background: #fee2e2;
            color: #b91c1c;
        }

        .expiry-status.soon {
            background: #fff1db;
            color: #b45309;
        }

        .expiry-empty {
            text-align: center;
            padding: 25px;
            color: #16834b;
        }

        .expiry-safety-notice {
            padding: 15px 18px;
            margin-bottom: 18px;
            border-radius: 10px;
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fed7aa;
            font-size: 13px;
            line-height: 1.6;
        }

        .expiry-safety-notice i {
            margin-right: 6px;
        }


        /* PHARMACY LOW STOCK ALERTS */

        .low-stock-section {
            margin: 30px 0;
        }

        .low-stock-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 18px;
        }

        .low-stock-heading h2 {
            font-size: 21px;
            margin-bottom: 6px;
        }

        .low-stock-heading p {
            color: #718096;
            font-size: 13px;
        }

        .low-stock-link {
            display: inline-block;
            padding: 10px 16px;
            background: #dc2626;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        .low-stock-link:hover {
            background: #b91c1c;
        }

        .low-stock-summary {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 18px;
        }

        .low-stock-stat {
            background: white;
            border: 1px solid #edf0f5;
            border-left: 4px solid #dc2626;
            border-radius: 10px;
            padding: 18px;
        }

        .low-stock-stat h3 {
            font-size: 27px;
            margin-bottom: 7px;
            color: #dc2626;
        }

        .low-stock-stat p {
            color: #718096;
            font-size: 13px;
        }

        .low-stock-table-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #edf0f5;
        }

        .low-stock-table-card h3 {
            margin-bottom: 18px;
        }

        .low-stock-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            background: #fee2e2;
            color: #b91c1c;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .low-stock-warning {
            padding: 15px 18px;
            margin-bottom: 18px;
            border-radius: 10px;
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fed7aa;
            font-size: 13px;
            line-height: 1.6;
        }

        .low-stock-empty {
            text-align: center;
            padding: 25px;
            color: #16834b;
        }

        @media (max-width: 768px) {

            .appointment-actions {
                min-width: 150px;
            }

            .table-container {
                overflow-x: auto;
            }

            .expiry-summary {
                grid-template-columns: 1fr;
            }

            .expiry-table-card {
                padding: 14px;
            }

            .low-stock-summary {
                grid-template-columns: 1fr;
            }

            .low-stock-table-card {
                padding: 14px;
            }

        }

    </style>

</head>

<body>

<div class="dashboard">

    <!-- SIDEBAR -->

    <aside class="sidebar" id="sidebar">

        <div class="brand">

            <div class="brand-icon">+</div>

            <div>
                <h2>MediCare</h2>
                <span>Hospital Management</span>
            </div>

        </div>

        <div class="menu-label">MAIN MENU</div>

        <nav class="navigation">

            <a href="dashboard.php" class="nav-link active">
                <span>▦</span> Dashboard
            </a>

            <a href="../patients/index.php" class="nav-link">
                <span>♙</span> Patients
            </a>

            <a href="../doctors/index.php" class="nav-link">
                <span>⚕</span> Doctors
            </a>

            <a href="../departments/index.php" class="nav-link">
                <span>▤</span> Departments
            </a>

            <a href="../appointments/index.php" class="nav-link">
                <span>▣</span> Appointments

                <?php if ($stats['pending'] > 0): ?>
                    <span class="pending-count">
                        <?= (int) $stats['pending'] ?>
                    </span>
                <?php endif; ?>

            </a>

            <a href="../medical-records/index.php" class="nav-link">
                <span>▥</span> Medical Records
            </a>

            <a href="../pharmacy/index.php" class="nav-link">
                <span>✚</span> Pharmacy
            </a>

            <a href="../laboratory/index.php" class="nav-link">
                <span>▧</span> Laboratory
            </a>

            <a href="../billing/index.php" class="nav-link">
                <span>＄</span> Billing
            </a>

            <a href="../reports/index.php" class="nav-link">
                <span>▨</span> Reports
            </a>

        </nav>


        <!-- ADMIN PROFILE -->

        <div class="sidebar-bottom">

            <div class="admin-profile">

                <div class="avatar">A</div>

                <div class="profile-info">

                    <strong>
                        <?= htmlspecialchars($adminName) ?>
                    </strong>

                    <span>Administrator</span>

                </div>

            </div>

            <form action="../auth/logout.php" method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($csrfToken) ?>"
                >

                <button class="logout-btn" type="submit">
                    ↪ Logout
                </button>

            </form>

        </div>

    </aside>


    <!-- MAIN CONTENT -->

    <main class="main-content">

        <!-- TOPBAR -->

        <header class="topbar">

            <button
                class="menu-toggle"
                id="menuToggle"
                type="button"
                aria-label="Toggle navigation"
            >
                ☰
            </button>

            <div>

                <h3>Dashboard Overview</h3>

                <p>Welcome back to MediCare HMS</p>

            </div>

            <div class="topbar-right">

                <div class="date">
                    <?= date('F d, Y') ?>
                </div>

                <div class="top-avatar">A</div>

            </div>

        </header>


        <!-- PAGE CONTENT -->

        <section class="content">

            <!-- WELCOME -->

            <div class="welcome-section">

                <div>

                    <h1>
                        Good Day,
                        <?= htmlspecialchars($adminName) ?> 👋
                    </h1>

                    <p>
                        Here is what's happening in your hospital today.
                    </p>

                </div>

                <span class="system-status">
                    ● System Active
                </span>

            </div>


            <?php if ($successMessage): ?>

                <div class="alert-success">
                    <?= htmlspecialchars($successMessage) ?>
                </div>

            <?php endif; ?>


            <?php if ($errorMessage): ?>

                <div class="alert-error">
                    <?= htmlspecialchars($errorMessage) ?>
                </div>

            <?php endif; ?>


            <?php if ($dbError): ?>

                <div class="alert-error">
                    Database information could not be loaded.
                    Please check your database tables and column names.
                </div>

            <?php endif; ?>


            <!-- PHARMACY EXPIRY ALERTS -->

            <div class="expiry-section">

                <div class="expiry-heading">

                    <div>

                        <h2>
                            <i class="fa-solid fa-prescription-bottle-medical"></i>
                            Pharmacy Expiry Alerts
                        </h2>

                        <p>
                            Monitor medicine expiry dates and protect patient safety.
                        </p>

                    </div>

                    <a href="../pharmacy/expiry.php" class="expiry-link">

                        <i class="fa-solid fa-calendar-xmark"></i>

                        View Expiry Management

                    </a>

                </div>


                <!-- EXPIRY STATISTICS -->

                <div class="expiry-summary">

                    <div class="expiry-stat danger">

                        <h3>
                            <?= number_format($expiryStats['expired']) ?>
                        </h3>

                        <p>
                            <i class="fa-solid fa-circle-exclamation"></i>
                            Expired Medicines
                        </p>

                    </div>


                    <div class="expiry-stat warning">

                        <h3>
                            <?= number_format($expiryStats['within_30']) ?>
                        </h3>

                        <p>
                            <i class="fa-solid fa-clock"></i>
                            Expiring Within 30 Days
                        </p>

                    </div>


                    <div class="expiry-stat info">

                        <h3>
                            <?= number_format($expiryStats['within_60']) ?>
                        </h3>

                        <p>
                            <i class="fa-solid fa-calendar-days"></i>
                            Expiring in 31–60 Days
                        </p>

                    </div>

                </div>


                <!-- PATIENT SAFETY NOTICE -->

                <?php if (
                    $expiryStats['expired'] > 0 ||
                    $expiryStats['within_30'] > 0
                ): ?>

                    <div class="expiry-safety-notice">

                        <i class="fa-solid fa-triangle-exclamation"></i>

                        <strong>Patient Safety Alert:</strong>

                        Expired or soon-to-expire medicines require immediate
                        review. Expired medicines must be quarantined and
                        must not be dispensed.

                    </div>

                <?php else: ?>

                    <div class="alert-success">

                        <i class="fa-solid fa-circle-check"></i>

                        No expired or soon-to-expire medicines requiring
                        immediate review.

                    </div>

                <?php endif; ?>


                <!-- MEDICINES REQUIRING ATTENTION -->

                <div class="expiry-table-card">

                    <h3>
                        <i class="fa-solid fa-list"></i>
                        Medicines Requiring Attention
                    </h3>

                    <div class="table-container">

                        <table>

                            <thead>

                                <tr>
                                    <th>Medicine</th>
                                    <th>Category</th>
                                    <th>Quantity</th>
                                    <th>Expiry Date</th>
                                    <th>Days Remaining</th>
                                    <th>Status</th>
                                </tr>

                            </thead>

                            <tbody>

                            <?php if (!empty($expiryMedicines)): ?>

                                <?php foreach ($expiryMedicines as $medicine): ?>

                                    <?php
                                    $days = (int) $medicine['days_remaining'];
                                    $isExpired = $days < 0;
                                    ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $medicine['medicine_name']
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                $medicine['category'] ?? 'N/A'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= number_format(
                                                (int) $medicine['quantity']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                date(
                                                    'M d, Y',
                                                    strtotime($medicine['expiry_date'])
                                                )
                                            ) ?>
                                        </td>

                                        <td>

                                            <?php if ($isExpired): ?>

                                                <?= abs($days) ?> days ago

                                            <?php else: ?>

                                                <?= $days ?> days

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <?php if ($isExpired): ?>

                                                <span class="expiry-status expired">
                                                    Expired
                                                </span>

                                            <?php else: ?>

                                                <span class="expiry-status soon">
                                                    Expiring Soon
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="6">

                                        <div class="expiry-empty">

                                            <i class="fa-solid fa-circle-check"></i>

                                            <p>
                                                No medicines are expired or
                                                expiring within the next 30 days.
                                            </p>

                                        </div>

                                    </td>

                                </tr>

                            <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>


            <!-- PHARMACY LOW STOCK ALERTS -->

            <div class="low-stock-section">

                <div class="low-stock-heading">

                    <div>

                        <h2>
                            <i class="fa-solid fa-box-open"></i>
                            Pharmacy Low Stock Alerts
                        </h2>

                        <p>
                            Monitor medicines with 10 or fewer units remaining.
                        </p>

                    </div>

                    <a href="../pharmacy/low_stock.php" class="low-stock-link">

                        <i class="fa-solid fa-arrow-up-right-from-square"></i>

                        View Low Stock Management

                    </a>

                </div>


                <!-- LOW STOCK STATISTICS -->

                <div class="low-stock-summary">

                    <div class="low-stock-stat">

                        <h3>
                            <?= number_format($lowStockCount) ?>
                        </h3>

                        <p>
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            Medicines with Low Stock
                        </p>

                    </div>


                    <div class="low-stock-stat">

                        <h3>
                            <?= number_format($totalLowStockUnits) ?>
                        </h3>

                        <p>
                            <i class="fa-solid fa-boxes-stacked"></i>
                            Total Remaining Units
                        </p>

                    </div>

                </div>


                <!-- LOW STOCK WARNING -->

                <?php if ($lowStockCount > 0): ?>

                    <div class="low-stock-warning">

                        <i class="fa-solid fa-triangle-exclamation"></i>

                        <strong>Low Stock Alert:</strong>

                        <?= number_format($lowStockCount) ?>
                        medicine(s) have reached the minimum stock
                        threshold of <?= $lowStockThreshold ?> units.

                        Please review the inventory and arrange replenishment.

                    </div>

                <?php else: ?>

                    <div class="alert-success">

                        <i class="fa-solid fa-circle-check"></i>

                        All medicines are above the low-stock threshold.

                    </div>

                <?php endif; ?>


                <!-- LOW STOCK MEDICINE TABLE -->

                <div class="low-stock-table-card">

                    <h3>
                        <i class="fa-solid fa-list"></i>
                        Medicines Requiring Restocking
                    </h3>

                    <div class="table-container">

                        <table>

                            <thead>

                                <tr>
                                    <th>Medicine</th>
                                    <th>Category</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Expiry Date</th>
                                    <th>Status</th>
                                </tr>

                            </thead>

                            <tbody>

                            <?php if (!empty($lowStockMedicines)): ?>

                                <?php foreach ($lowStockMedicines as $medicine): ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $medicine['medicine_name']
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                $medicine['category'] ?? 'N/A'
                                            ) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= number_format(
                                                    (int) $medicine['quantity']
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= number_format(
                                                (float) $medicine['unit_price'],
                                                2
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                $medicine['expiry_date'] ?? 'N/A'
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="low-stock-badge">
                                                Low Stock
                                            </span>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="6">

                                        <div class="low-stock-empty">

                                            <i class="fa-solid fa-circle-check"></i>

                                            <p>
                                                All medicines are sufficiently stocked.
                                            </p>

                                        </div>

                                    </td>

                                </tr>

                            <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>


            <!-- GENERAL STATISTICS -->

            <div class="stats-grid">

                <div class="stat-card">

                    <div class="stat-icon blue">♙</div>

                    <p>Total Patients</p>

                    <h2>
                        <?= number_format($stats['patients']) ?>
                    </h2>

                    <span>Registered patients</span>

                </div>


                <div class="stat-card">

                    <div class="stat-icon green">⚕</div>

                    <p>Total Doctors</p>

                    <h2>
                        <?= number_format($stats['doctors']) ?>
                    </h2>

                    <span>Registered doctors</span>

                </div>


                <div class="stat-card">

                    <div class="stat-icon orange">▣</div>

                    <p>Appointments</p>

                    <h2>
                        <?= number_format($stats['appointments']) ?>
                    </h2>

                    <span>All appointments</span>

                </div>


                <div class="stat-card">

                    <div class="stat-icon purple">▤</div>

                    <p>Departments</p>

                    <h2>
                        <?= number_format($stats['departments']) ?>
                    </h2>

                    <span>Hospital departments</span>

                </div>

            </div>


            <!-- PENDING APPOINTMENTS MANAGEMENT -->

            <div class="pending-section">

                <div class="section-heading">

                    <div>

                        <h2>

                            Pending Appointments

                            <?php if ($stats['pending'] > 0): ?>

                                <span class="pending-count">
                                    <?= number_format($stats['pending']) ?>
                                </span>

                            <?php endif; ?>

                        </h2>

                        <p>
                            Review appointment requests and approve or cancel them.
                        </p>

                    </div>

                </div>


                <div class="table-container">

                    <table>

                        <thead>

                            <tr>
                                <th>Patient ID</th>
                                <th>Patient Name</th>
                                <th>Doctor</th>
                                <th>Department</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Reason</th>
                                <th>Actions</th>
                            </tr>

                        </thead>


                        <tbody>

                        <?php if (!empty($pendingAppointments)): ?>

                            <?php foreach ($pendingAppointments as $appointment): ?>

                                <tr>

                                    <td>
                                        <?= htmlspecialchars(
                                            $appointment['patient_number'] ?? 'N/A'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $appointment['patient_name'] ?? 'N/A'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $appointment['doctor_name'] ?? 'N/A'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $appointment['department_name'] ?? 'N/A'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $appointment['appointment_date'] ?? ''
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            $appointment['appointment_time'] ?? ''
                                        ) ?>
                                    </td>

                                    <td class="reason-text">
                                        <?= htmlspecialchars(
                                            $appointment['reason'] ?? 'Not provided'
                                        ) ?>
                                    </td>

                                    <td>

                                        <div class="appointment-actions">

                                            <form
                                                method="POST"
                                                action="dashboard.php"
                                                onsubmit="return confirm('Are you sure you want to confirm this appointment?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= htmlspecialchars($csrfToken) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="appointment_id"
                                                    value="<?= (int) $appointment['id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="new_status"
                                                    value="confirmed"
                                                >

                                                <button
                                                    type="submit"
                                                    name="update_appointment"
                                                    class="action-btn confirm-btn"
                                                >
                                                    ✓ Confirm
                                                </button>

                                            </form>


                                            <form
                                                method="POST"
                                                action="dashboard.php"
                                                onsubmit="return confirm('Are you sure you want to cancel this appointment?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= htmlspecialchars($csrfToken) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="appointment_id"
                                                    value="<?= (int) $appointment['id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="new_status"
                                                    value="cancelled"
                                                >

                                                <button
                                                    type="submit"
                                                    name="update_appointment"
                                                    class="action-btn cancel-btn"
                                                >
                                                    ✕ Cancel
                                                </button>

                                            </form>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>

                                <td colspan="8" class="empty-state">
                                    No pending appointments.
                                    All requests have been reviewed.
                                </td>

                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>


            <!-- RECENT APPOINTMENTS -->

            <div class="section-heading" style="margin-top: 35px;">

                <div>

                    <h2>Recent Appointments</h2>

                    <p>Latest hospital appointment records</p>

                </div>

                <a href="../appointments/index.php" class="view-all">
                    View All →
                </a>

            </div>


            <div class="table-container">

                <table>

                    <thead>

                        <tr>
                            <th>Patient ID</th>
                            <th>Patient Name</th>
                            <th>Doctor</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                        </tr>

                    </thead>


                    <tbody>

                    <?php if (!empty($appointments)): ?>

                        <?php foreach ($appointments as $appointment): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars(
                                        $appointment['patient_number'] ?? 'N/A'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $appointment['patient_name'] ?? 'N/A'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $appointment['doctor_name'] ?? 'N/A'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $appointment['appointment_date'] ?? ''
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $appointment['appointment_time'] ?? ''
                                    ) ?>
                                </td>

                                <td>

                                    <?php
                                    $status = strtolower(
                                        $appointment['status'] ?? 'pending'
                                    );
                                    ?>

                                    <span class="status <?= htmlspecialchars($status) ?>">

                                        <?= htmlspecialchars(ucfirst($status)) ?>

                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="6" class="empty-state">
                                No appointments available yet.
                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>


            <!-- FOOTER -->

            <footer class="footer">

                © <?= date('Y') ?>

                MediCare Hospital Management System.

                All rights reserved.

            </footer>

        </section>

    </main>

</div>


<script src="../assets/js/admin.js"></script>

</body>

</html>
