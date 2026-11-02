
<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin();
requireRole(['admin']);

$pageTitle = 'Low Stock Management';
$threshold = 10;

// Fetch medicines with low stock
$stmt = $pdo->prepare("
    SELECT id, medicine_name, category, quantity, unit_price, expiry_date
    FROM medicines
    WHERE quantity <= ?
    ORDER BY quantity ASC, medicine_name ASC
");
$stmt->execute([$threshold]);
$medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$totalLowStock = count($medicines);
$totalUnits = 0;

foreach ($medicines as $medicine) {
    $totalUnits += (int)$medicine['quantity'];
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?></title>

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

        /* MAIN CONTENT */
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
        .low-stock-container {
            padding: 30px;
        }

        .low-stock-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .low-stock-header h1 {
            margin: 0 0 8px;
            font-size: 27px;
            color: #102a43;
        }

        .low-stock-header p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }

        .back-link {
            text-decoration: none;
            color: #2563eb;
            font-weight: bold;
            background: #eff6ff;
            padding: 11px 16px;
            border-radius: 8px;
        }

        .back-link:hover {
            background: #dbeafe;
        }

        /* SUMMARY */
        .summary-card {
            background: #fff;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05);
            margin-bottom: 25px;
            border-left: 5px solid #dc2626;
        }

        .summary-card h3 {
            margin: 0 0 12px;
            color: #64748b;
            font-size: 15px;
        }

        .summary-card .number {
            font-size: 34px;
            font-weight: bold;
            color: #dc2626;
        }

        .summary-details {
            display: flex;
            flex-wrap: wrap;
            gap: 25px;
            margin-top: 12px;
            color: #64748b;
            font-size: 13px;
        }

        /* TABLE */
        .table-container {
            overflow-x: auto;
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05);
        }

        .table-container h2 {
            margin: 0 0 20px;
            font-size: 19px;
            color: #102a43;
        }

        .low-stock-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        .low-stock-table th,
        .low-stock-table td {
            padding: 14px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
        }

        .low-stock-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: bold;
        }

        .low-stock-table tbody tr:hover {
            background: #f8fafc;
        }

        .stock-badge {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            background: #fee2e2;
            color: #b91c1c;
            font-weight: bold;
            font-size: 11px;
        }

        .quantity-number {
            font-weight: bold;
            color: #dc2626;
        }

        .empty-message {
            padding: 35px 20px;
            text-align: center;
            color: #15803d;
            background: #f0fdf4;
            border-radius: 10px;
        }

        .empty-message i {
            font-size: 40px;
            margin-bottom: 12px;
        }

        .empty-message h3 {
            margin: 0 0 8px;
            color: #15803d;
        }

        .empty-message p {
            margin: 0;
            font-size: 13px;
        }

        /* RESPONSIVE */
        @media (max-width: 750px) {
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

            .low-stock-container {
                padding: 18px;
            }

            .low-stock-header h1 {
                font-size: 22px;
            }

            .summary-details {
                gap: 10px;
                flex-direction: column;
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
            <a href="low_stock.php" class="active">
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
            <a href="reports.php">
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
        <h3>Low Stock Management</h3>

        <div class="topbar-user">
            <i class="fas fa-user-circle"></i>
            <span><?= e($_SESSION['full_name'] ?? 'Administrator') ?></span>
        </div>
    </header>

    <main class="low-stock-container">

        <div class="low-stock-header">
            <div>
                <h1>Low Stock Management</h1>
                <p>Monitor medicines with 10 or fewer units.</p>
            </div>

            <a class="back-link" href="index.php">
                <i class="fas fa-arrow-left"></i>
                Pharmacy Inventory
            </a>
        </div>

        <!-- SUMMARY CARD -->
        <div class="summary-card">

            <h3>
                <i class="fas fa-triangle-exclamation"></i>
                Medicines with Low Stock
            </h3>

            <div class="number">
                <?= $totalLowStock ?>
            </div>

            <div class="summary-details">
                <span>
                    <i class="fas fa-box"></i>
                    Total remaining units: <strong><?= $totalUnits ?></strong>
                </span>

                <span>
                    <i class="fas fa-bell"></i>
                    Low stock threshold: <strong><?= $threshold ?> units or below</strong>
                </span>
            </div>

        </div>

        <!-- LOW STOCK TABLE -->
        <div class="table-container">

            <h2>
                <i class="fas fa-list"></i>
                Low Stock Report
            </h2>

            <?php if ($totalLowStock > 0): ?>

                <table class="low-stock-table">

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Medicine</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Expiry Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($medicines as $medicine): ?>

                            <tr>

                                <td>
                                    #<?= (int)$medicine['id'] ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= e($medicine['medicine_name']) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= e($medicine['category']) ?>
                                </td>

                                <td>
                                    <span class="quantity-number">
                                        <?= (int)$medicine['quantity'] ?>
                                    </span>
                                </td>

                                <td>
                                    <?= number_format((float)$medicine['unit_price'], 2) ?>
                                </td>

                                <td>
                                    <?= e($medicine['expiry_date']) ?>
                                </td>

                                <td>
                                    <span class="stock-badge">
                                        <i class="fas fa-exclamation-circle"></i>
                                        Low Stock
                                    </span>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php else: ?>

                <div class="empty-message">

                    <i class="fas fa-circle-check"></i>

                    <h3>All Medicines Are in Stock!</h3>

                    <p>
                        No medicines have reached the low-stock threshold.
                    </p>

                </div>

            <?php endif; ?>

        </div>

    </main>

</div>

</body>
</html>
