
<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin();
requireRole(['admin']);

date_default_timezone_set('Africa/Addis_Ababa');

if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money')) {
    function money($value) {
        return number_format((float)$value, 2);
    }
}

// Date filters
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

if (
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) ||
    $startDate > $endDate ||
    !checkdate((int)substr($startDate, 5, 2), (int)substr($startDate, 8, 2), (int)substr($startDate, 0, 4)) ||
    !checkdate((int)substr($endDate, 5, 2), (int)substr($endDate, 8, 2), (int)substr($endDate, 0, 4))
) {
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-d');
}

$startDateTime = $startDate . ' 00:00:00';
$endDateTime = $endDate . ' 23:59:59';

// Inventory Overview
$inventory = $pdo->query("
    SELECT
        COUNT(*) AS total_medicines,
        COALESCE(SUM(quantity), 0) AS total_units,
        COALESCE(SUM(quantity * unit_price), 0) AS inventory_value
    FROM medicines
")->fetch(PDO::FETCH_ASSOC);

// Low Stock
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS total,
           COALESCE(SUM(quantity), 0) AS units
    FROM medicines
    WHERE quantity <= 10
");
$stmt->execute();
$lowStock = $stmt->fetch(PDO::FETCH_ASSOC);

// Expiry Overview
$expiry = $pdo->query("
    SELECT
        COALESCE(SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END), 0) AS expired,
        COALESCE(SUM(CASE WHEN expiry_date BETWEEN CURDATE()
            AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            THEN 1 ELSE 0 END), 0) AS within_30,
        COALESCE(SUM(CASE WHEN expiry_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
            THEN 1 ELSE 0 END), 0) AS within_60
    FROM medicines
")->fetch(PDO::FETCH_ASSOC);

// Stock Movement Summary
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_movements,
        COALESCE(SUM(CASE WHEN movement_type = 'in'
            THEN quantity ELSE 0 END), 0) AS stock_in,
        COALESCE(SUM(CASE WHEN movement_type = 'out'
            THEN quantity ELSE 0 END), 0) AS stock_out
    FROM stock_movements
    WHERE created_at BETWEEN ? AND ?
");
$stmt->execute([$startDateTime, $endDateTime]);
$movements = $stmt->fetch(PDO::FETCH_ASSOC);

// Recent Stock Movements
$stmt = $pdo->prepare("
    SELECT sm.*, m.medicine_name, u.full_name
    FROM stock_movements sm
    JOIN medicines m ON sm.medicine_id = m.id
    LEFT JOIN users u ON sm.created_by = u.id
    WHERE sm.created_at BETWEEN ? AND ?
    ORDER BY sm.created_at DESC
    LIMIT 20
");
$stmt->execute([$startDateTime, $endDateTime]);
$movementHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Purchase Summary
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_requests,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END),0) AS pending,
        COALESCE(SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END),0) AS approved,
        COALESCE(SUM(CASE WHEN status = 'ordered' THEN 1 ELSE 0 END),0) AS ordered,
        COALESCE(SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END),0) AS received,
        COALESCE(SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END),0) AS rejected,
        COALESCE(SUM(CASE WHEN status = 'received' THEN total_cost ELSE 0 END),0) AS received_cost
    FROM purchase_requests
    WHERE created_at BETWEEN ? AND ?
");
$stmt->execute([$startDateTime, $endDateTime]);
$purchases = $stmt->fetch(PDO::FETCH_ASSOC);

// Recent Purchase Requests
$stmt = $pdo->prepare("
    SELECT pr.*, m.medicine_name, u.full_name
    FROM purchase_requests pr
    JOIN medicines m ON pr.medicine_id = m.id
    JOIN users u ON pr.requested_by = u.id
    WHERE pr.created_at BETWEEN ? AND ?
    ORDER BY pr.created_at DESC
    LIMIT 20
");
$stmt->execute([$startDateTime, $endDateTime]);
$purchaseHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Expiry List
$expiryList = $pdo->query("
    SELECT medicine_name, category, quantity, expiry_date,
        DATEDIFF(expiry_date, CURDATE()) AS days_remaining
    FROM medicines
    WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ORDER BY expiry_date ASC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Low Stock List
$lowStockList = $pdo->query("
    SELECT medicine_name, category, quantity, unit_price
    FROM medicines
    WHERE quantity <= 10
    ORDER BY quantity ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pharmacy Reports | MediCare HMS</title>

<link rel="stylesheet" href="../assets/css/admin.css">
<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f1f5f9;
    font-family: Arial, sans-serif;
    color: #1e293b;
}

/* SIDEBAR */
.sidebar {
    width: 250px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    background: #102a43;
    color: #fff;
    padding: 22px 15px;
    overflow-y: auto;
    z-index: 1000;
}

.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 5px 10px 25px;
    border-bottom: 1px solid rgba(255,255,255,.12);
    margin-bottom: 20px;
}

.sidebar-brand i {
    font-size: 27px;
    color: #60a5fa;
}

.sidebar-brand h2 {
    font-size: 19px;
    margin: 0;
    color: #fff;
}

.sidebar-brand span {
    display: block;
    font-size: 11px;
    color: #94a3b8;
    margin-top: 4px;
}

.sidebar-menu {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sidebar-menu li {
    margin-bottom: 6px;
}

.sidebar-menu a {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 13px 14px;
    text-decoration: none;
    color: #cbd5e1;
    border-radius: 8px;
    font-size: 14px;
    transition: .2s;
}

.sidebar-menu a i {
    width: 20px;
    text-align: center;
    font-size: 16px;
}

.sidebar-menu a:hover {
    background: rgba(255,255,255,.1);
    color: #fff;
}

.sidebar-menu a.active {
    background: #2563eb;
    color: #fff;
    font-weight: bold;
}

.sidebar-section {
    color: #64748b;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 18px 12px 8px;
}

/* MAIN */
.main-content {
    margin-left: 250px;
    min-height: 100vh;
}

.topbar {
    height: 70px;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
}

.topbar h3 {
    margin: 0;
    font-size: 17px;
}

.topbar-user {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #475569;
    font-size: 13px;
}

.topbar-user i {
    font-size: 20px;
    color: #2563eb;
}

/* PAGE */
.report-container {
    padding: 28px;
    max-width: 1600px;
    margin: auto;
}

.report-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 25px;
}

.report-header h1 {
    margin: 0 0 7px;
    color: #102a43;
    font-size: 27px;
}

.report-header p {
    margin: 0;
    color: #718096;
    font-size: 14px;
}

.report-links {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 17px;
    border: none;
    border-radius: 7px;
    background: #176b52;
    color: white;
    text-decoration: none;
    cursor: pointer;
    font-size: 13px;
    font-weight: bold;
}

.btn:hover {
    opacity: .88;
}

.btn-secondary {
    background: #475569;
}

.filter-card,
.report-card {
    background: white;
    padding: 22px;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
    margin-bottom: 24px;
}

.section-title {
    margin: 0 0 18px;
    color: #1e293b;
    font-size: 19px;
}

/* DATE FILTER */
.filter-form {
    display: flex;
    align-items: end;
    gap: 15px;
    flex-wrap: wrap;
}

.filter-form label {
    display: block;
    margin-bottom: 7px;
    font-weight: 600;
    font-size: 13px;
}

.filter-form input {
    padding: 11px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    min-width: 180px;
}

/* STATISTICS */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    padding: 22px;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
    border-left: 4px solid #176b52;
}

.stat-card h3 {
    font-size: 14px;
    color: #718096;
    margin: 0 0 12px;
}

.stat-card .number {
    font-size: 27px;
    font-weight: 700;
    color: #1e293b;
    overflow-wrap: anywhere;
}

.stat-card small {
    display: block;
    color: #718096;
    margin-top: 8px;
    font-size: 12px;
}

/* TWO COLUMNS */
.two-column {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    padding: 13px 0;
    border-bottom: 1px solid #eee;
    font-size: 14px;
}

.summary-row:last-child {
    border-bottom: none;
}

/* TABLES */
.table-responsive {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

th, td {
    padding: 13px;
    border-bottom: 1px solid #eee;
    text-align: left;
    font-size: 13px;
}

th {
    background: #f7f9fc;
    color: #475569;
    font-weight: bold;
}

tbody tr:hover {
    background: #f8fafc;
}

.status {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    background: #edf0f4;
    white-space: nowrap;
}

.status-in,
.status-received {
    background: #e5f7ed;
    color: #176b52;
}

.status-out {
    background: #ffe9e9;
    color: #b42318;
}

.status-pending {
    background: #fff1d6;
    color: #996000;
}

.empty {
    text-align: center;
    padding: 25px;
    color: #718096;
}

/* FOOTER */
.report-footer {
    text-align: center;
    color: #718096;
    padding: 20px;
    font-size: 12px;
}

/* PRINT */
@media print {
    .sidebar,
    .topbar,
    .filter-card,
    .report-links,
    .back-link {
        display: none !important;
    }

    .main-content {
        margin-left: 0;
    }

    .report-container {
        padding: 0;
        max-width: none;
    }

    body {
        background: #fff;
    }

    .report-card,
    .stat-card {
        box-shadow: none;
        border: 1px solid #ddd;
        break-inside: avoid;
    }

    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
    }

    .two-column {
        grid-template-columns: 1fr 1fr;
    }

    table {
        min-width: 0;
    }

    th, td {
        font-size: 10px;
        padding: 7px;
    }
}

/* RESPONSIVE */
@media(max-width: 1100px) {
    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 750px) {
    .sidebar {
        width: 68px;
        padding: 20px 8px;
    }

    .sidebar-brand {
        justify-content: center;
        padding: 5px 0 20px;
    }

    .sidebar-brand h2,
    .sidebar-brand span,
    .sidebar-menu a span,
    .sidebar-section {
        display: none;
    }

    .sidebar-menu a {
        justify-content: center;
        padding: 14px 5px;
    }

    .sidebar-menu a i {
        font-size: 18px;
    }

    .main-content {
        margin-left: 68px;
    }

    .topbar {
        padding: 0 15px;
    }

    .report-container {
        padding: 14px;
    }

    .stats-grid,
    .two-column {
        grid-template-columns: 1fr;
    }

    .filter-form {
        align-items: stretch;
        flex-direction: column;
    }

    .filter-form input {
        width: 100%;
    }

    .report-header h1 {
        font-size: 22px;
    }
}
</style>
</head>

<body>

<!-- SIDEBAR -->
<aside class="sidebar">

    <div class="sidebar-brand">
        <i class="fas fa-hospital"></i>
        <div>
            <h2>MediCare HMS</h2>
            <span>Pharmacy Management</span>
        </div>
    </div>

    <ul class="sidebar-menu">

        <li class="sidebar-section">Main Menu</li>

        <li>
            <a href="dashboard.php">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <li class="sidebar-section">Inventory</li>

        <li>
            <a href="index.php">
                <i class="fas fa-pills"></i>
                <span>Medicine Inventory</span>
            </a>
        </li>

        <li>
            <a href="add.php">
                <i class="fas fa-plus-circle"></i>
                <span>Add Medicine</span>
            </a>
        </li>

        <li>
            <a href="stock.php">
                <i class="fas fa-boxes-stacked"></i>
                <span>Stock Management</span>
            </a>
        </li>

        <li>
            <a href="expiry.php">
                <i class="fas fa-calendar-xmark"></i>
                <span>Expiry Management</span>
            </a>
        </li>

        <li>
            <a href="low_stock.php">
                <i class="fas fa-triangle-exclamation"></i>
                <span>Low Stock Alerts</span>
            </a>
        </li>

        <li class="sidebar-section">Purchasing & Reports</li>

        <li>
            <a href="purchase_request.php">
                <i class="fas fa-cart-shopping"></i>
                <span>Purchase Management</span>
            </a>
        </li>

        <li>
            <a href="reports.php" class="active">
                <i class="fas fa-file-lines"></i>
                <span>Inventory Reports</span>
            </a>
        </li>

        <li class="sidebar-section">Administration</li>

        <li>
            <a href="../admin/dashboard.php">
                <i class="fas fa-user-shield"></i>
                <span>Admin Dashboard</span>
            </a>
        </li>

    </ul>
</aside>

<!-- MAIN CONTENT -->
<div class="main-content">

    <header class="topbar">
        <h3>Inventory Reports</h3>

        <div class="topbar-user">
            <i class="fas fa-user-circle"></i>
            <span><?= h($_SESSION['full_name'] ?? 'Administrator') ?></span>
        </div>
    </header>

    <main class="report-container">

        <!-- HEADER -->
        <header class="report-header">
            <div>
                <h1>Pharmacy Reports</h1>
                <p>Inventory, stock movement, expiry and purchase analytics</p>
            </div>

            <div class="report-links">
                <a class="btn btn-secondary" href="index.php">
                    <i class="fas fa-arrow-left"></i>
                    Back to Pharmacy
                </a>

                <button class="btn" type="button" onclick="window.print()">
                    <i class="fas fa-print"></i>
                    Print Report
                </button>
            </div>
        </header>

        <!-- DATE FILTER -->
        <section class="filter-card">
            <h2 class="section-title">
                <i class="fas fa-calendar-days"></i>
                Report Date Filter
            </h2>

            <form method="GET" class="filter-form">

                <div>
                    <label for="start_date">Start Date</label>
                    <input
                        type="date"
                        id="start_date"
                        name="start_date"
                        value="<?= h($startDate) ?>"
                        max="<?= h($endDate) ?>"
                        required
                    >
                </div>

                <div>
                    <label for="end_date">End Date</label>
                    <input
                        type="date"
                        id="end_date"
                        name="end_date"
                        value="<?= h($endDate) ?>"
                        min="<?= h($startDate) ?>"
                        max="<?= date('Y-m-d') ?>"
                        required
                    >
                </div>

                <button class="btn" type="submit">
                    <i class="fas fa-filter"></i>
                    Apply Filter
                </button>

                <a href="reports.php" class="btn btn-secondary">
                    <i class="fas fa-rotate-left"></i>
                    Reset
                </a>

            </form>
        </section>

        <!-- INVENTORY STATISTICS -->
        <h2 class="section-title">Inventory Overview</h2>

        <div class="stats-grid">

            <div class="stat-card">
                <h3>Total Medicines</h3>
                <div class="number">
                    <?= (int)$inventory['total_medicines'] ?>
                </div>
                <small>Registered medicines</small>
            </div>

            <div class="stat-card">
                <h3>Total Stock Units</h3>
                <div class="number">
                    <?= number_format((int)$inventory['total_units']) ?>
                </div>
                <small>Available units</small>
            </div>

            <div class="stat-card">
                <h3>Inventory Value</h3>
                <div class="number">
                    <?= money($inventory['inventory_value']) ?>
                </div>
                <small>Estimated value</small>
            </div>

            <div class="stat-card">
                <h3>Low Stock Medicines</h3>
                <div class="number">
                    <?= (int)$lowStock['total'] ?>
                </div>
                <small><?= (int)$lowStock['units'] ?> units remaining</small>
            </div>

        </div>

        <!-- EXPIRY STATISTICS -->
        <h2 class="section-title">Expiry Overview</h2>

        <div class="stats-grid">

            <div class="stat-card">
                <h3>Expired Medicines</h3>
                <div class="number"><?= (int)$expiry['expired'] ?></div>
                <small>Past expiry date</small>
            </div>

            <div class="stat-card">
                <h3>Expires Within 30 Days</h3>
                <div class="number"><?= (int)$expiry['within_30'] ?></div>
                <small>Urgent attention</small>
            </div>

            <div class="stat-card">
                <h3>Expires Within 31–60 Days</h3>
                <div class="number"><?= (int)$expiry['within_60'] ?></div>
                <small>Upcoming expiry</small>
            </div>

            <div class="stat-card">
                <h3>Low Stock Units</h3>
                <div class="number"><?= (int)$lowStock['units'] ?></div>
                <small>Remaining units</small>
            </div>

        </div>

        <!-- MOVEMENT AND PURCHASE SUMMARY -->
        <div class="two-column">

            <section class="report-card">
                <h2 class="section-title">
                    <i class="fas fa-boxes-stacked"></i>
                    Stock Movement Summary
                </h2>

                <div class="summary-row">
                    <span>Total Movements</span>
                    <strong><?= (int)$movements['total_movements'] ?></strong>
                </div>

                <div class="summary-row">
                    <span>Stock In</span>
                    <strong><?= number_format((int)$movements['stock_in']) ?></strong>
                </div>

                <div class="summary-row">
                    <span>Stock Out</span>
                    <strong><?= number_format((int)$movements['stock_out']) ?></strong>
                </div>
            </section>

            <section class="report-card">
                <h2 class="section-title">
                    <i class="fas fa-cart-shopping"></i>
                    Purchase Summary
                </h2>

                <div class="summary-row">
                    <span>Total Requests</span>
                    <strong><?= (int)$purchases['total_requests'] ?></strong>
                </div>

                <div class="summary-row">
                    <span>Pending</span>
                    <strong><?= (int)$purchases['pending'] ?></strong>
                </div>

                <div class="summary-row">
                    <span>Approved</span>
                    <strong><?= (int)$purchases['approved'] ?></strong>
                </div>

                <div class="summary-row">
                    <span>Ordered</span>
                    <strong><?= (int)$purchases['ordered'] ?></strong>
                </div>

                <div class="summary-row">
                    <span>Received</span>
                    <strong><?= (int)$purchases['received'] ?></strong>
                </div>

                <div class="summary-row">
                    <span>Rejected</span>
                    <strong><?= (int)$purchases['rejected'] ?></strong>
                </div>

                <div class="summary-row">
                    <span>Total Received Purchase Cost</span>
                    <strong><?= money($purchases['received_cost']) ?></strong>
                </div>
            </section>

        </div>

        <!-- LOW STOCK TABLE -->
        <section class="report-card">

            <h2 class="section-title">
                <i class="fas fa-triangle-exclamation"></i>
                Low Stock Medicines (10 or Below)
            </h2>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if ($lowStockList): ?>

                        <?php foreach ($lowStockList as $item): ?>
                            <tr>
                                <td><?= h($item['medicine_name']) ?></td>
                                <td><?= h($item['category']) ?></td>
                                <td><?= (int)$item['quantity'] ?></td>
                                <td><?= money($item['unit_price']) ?></td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="empty">
                                <i class="fas fa-circle-check"></i>
                                All medicines are sufficiently stocked.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </section>

        <!-- EXPIRY TABLE -->
        <section class="report-card">

            <h2 class="section-title">
                <i class="fas fa-calendar-xmark"></i>
                Medicine Expiry Report
            </h2>

            <div class="table-responsive">
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
                    <?php if ($expiryList): ?>

                        <?php foreach ($expiryList as $item): ?>
                            <tr>
                                <td><?= h($item['medicine_name']) ?></td>
                                <td><?= h($item['category']) ?></td>
                                <td><?= (int)$item['quantity'] ?></td>
                                <td><?= h($item['expiry_date']) ?></td>
                                <td><?= (int)$item['days_remaining'] ?></td>

                                <td>
                                    <?php if ($item['days_remaining'] < 0): ?>
                                        <span class="status status-out">Expired</span>
                                    <?php elseif ($item['days_remaining'] <= 30): ?>
                                        <span class="status status-pending">Urgent</span>
                                    <?php else: ?>
                                        <span class="status">Expiring Soon</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty">
                                No medicines expiring within 60 days.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </section>

        <!-- STOCK MOVEMENT HISTORY -->
        <section class="report-card">

            <h2 class="section-title">
                <i class="fas fa-clock-rotate-left"></i>
                Stock Movement History
            </h2>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Movement</th>
                            <th>Quantity</th>
                            <th>Previous Stock</th>
                            <th>New Stock</th>
                            <th>Notes</th>
                            <th>Updated By</th>
                            <th>Date</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if ($movementHistory): ?>

                        <?php foreach ($movementHistory as $movement): ?>
                            <tr>
                                <td><?= h($movement['medicine_name']) ?></td>

                                <td>
                                    <span class="status <?= $movement['movement_type'] === 'in' ? 'status-in' : 'status-out' ?>">
                                        <?= h(strtoupper($movement['movement_type'])) ?>
                                    </span>
                                </td>

                                <td><?= (int)$movement['quantity'] ?></td>
                                <td><?= (int)$movement['previous_quantity'] ?></td>
                                <td><?= (int)$movement['new_quantity'] ?></td>
                                <td><?= h($movement['notes'] ?? '-') ?></td>
                                <td><?= h($movement['full_name'] ?? '-') ?></td>
                                <td><?= h($movement['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="empty">
                                No stock movements in this date range.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </section>

        <!-- PURCHASE HISTORY -->
        <section class="report-card">

            <h2 class="section-title">
                <i class="fas fa-file-invoice"></i>
                Purchase Request History
            </h2>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Medicine</th>
                            <th>Quantity</th>
                            <th>Total Cost</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th>Requested By</th>
                            <th>Date</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if ($purchaseHistory): ?>

                        <?php foreach ($purchaseHistory as $item): ?>
                            <tr>
                                <td>#<?= (int)$item['id'] ?></td>
                                <td><?= h($item['medicine_name']) ?></td>
                                <td><?= (int)$item['requested_quantity'] ?></td>
                                <td><?= money($item['total_cost']) ?></td>
                                <td><?= h($item['supplier_name'] ?? '-') ?></td>

                                <td>
                                    <span class="status
                                        <?= $item['status'] === 'received' ? 'status-received' :
                                            ($item['status'] === 'pending' ? 'status-pending' : '') ?>">
                                        <?= h(ucfirst($item['status'])) ?>
                                    </span>
                                </td>

                                <td><?= h($item['full_name']) ?></td>
                                <td><?= h($item['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="empty">
                                No purchase requests in this date range.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </section>

        <footer class="report-footer">
            MediCare Hospital Management System | Pharmacy Reports
        </footer>

    </main>
</div>

</body>
</html>
