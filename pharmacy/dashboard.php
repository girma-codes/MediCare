
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
requireRole(['admin']);

$pageTitle = 'Pharmacy Dashboard';

// Dashboard statistics
$totalMedicines = 0;
$totalStock = 0;
$inventoryValue = 0;
$lowStock = 0;
$expiredMedicines = 0;
$expiringSoon = 0;
$totalPurchaseRequests = 0;
$pendingRequests = 0;

$recentMovements = [];
$recentRequests = [];
$lowStockMedicines = [];
$dashboardError = null;

try {
    // Medicine statistics
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total_medicines,
            COALESCE(SUM(quantity), 0) AS total_stock,
            COALESCE(SUM(quantity * unit_price), 0) AS inventory_value,
            SUM(CASE WHEN quantity <= 10 THEN 1 ELSE 0 END) AS low_stock
        FROM medicines
    ");

    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalMedicines = (int)($stats['total_medicines'] ?? 0);
    $totalStock = (int)($stats['total_stock'] ?? 0);
    $inventoryValue = (float)($stats['inventory_value'] ?? 0);
    $lowStock = (int)($stats['low_stock'] ?? 0);

    // Expired medicines
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM medicines
        WHERE expiry_date IS NOT NULL
        AND expiry_date < CURDATE()
    ");

    $expiredMedicines = (int)$stmt->fetchColumn();

    // Medicines expiring within 60 days
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM medicines
        WHERE expiry_date >= CURDATE()
        AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ");

    $expiringSoon = (int)$stmt->fetchColumn();

    // Purchase requests
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM purchase_requests
    ");

    $totalPurchaseRequests = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM purchase_requests
        WHERE status = 'pending'
    ");

    $pendingRequests = (int)$stmt->fetchColumn();

    // Recent stock movements
    $stmt = $pdo->query("
        SELECT sm.*, m.medicine_name
        FROM stock_movements sm
        LEFT JOIN medicines m ON sm.medicine_id = m.id
        ORDER BY sm.created_at DESC
        LIMIT 5
    ");

    $recentMovements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent purchase requests
    $stmt = $pdo->query("
        SELECT pr.*, m.medicine_name
        FROM purchase_requests pr
        LEFT JOIN medicines m ON pr.medicine_id = m.id
        ORDER BY pr.created_at DESC
        LIMIT 5
    ");

    $recentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Low stock medicines
    $stmt = $pdo->query("
        SELECT *
        FROM medicines
        WHERE quantity <= 10
        ORDER BY quantity ASC
        LIMIT 5
    ");

    $lowStockMedicines = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Pharmacy dashboard error: ' . $e->getMessage());
    $dashboardError = 'Dashboard data could not be loaded. Please check the database.';
}

// Escape output safely
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// Purchase request status class
if (!function_exists('statusClass')) {
    function statusClass($status): string {
        return match ($status) {
            'pending'  => 'pending',
            'approved' => 'approved',
            'ordered'  => 'ordered',
            'received' => 'received',
            'rejected' => 'rejected',
            default    => 'neutral'
        };
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> | MediCare HMS</title>

    <link rel="stylesheet" href="../assets/css/admin.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f7fb;
            color: #1e293b;
            font-family: Arial, sans-serif;
        }

        /* Main content starts after fixed sidebar */
        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            min-width: 0;
            padding: 28px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 30px;
        }

        .topbar h1 {
            margin: 0 0 7px;
            font-size: 27px;
        }

        .topbar p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }

        .date-label {
            background: white;
            padding: 12px 16px;
            border-radius: 10px;
            color: #475569;
            font-size: 13px;
            box-shadow: 0 3px 12px #0f172a08;
            white-space: nowrap;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 22px;
            border-radius: 13px;
            box-shadow: 0 4px 15px #0f172a08;
            border: 1px solid #e8edf3;
        }

        .stat-card .label {
            color: #64748b;
            font-size: 13px;
            margin-bottom: 13px;
        }

        .stat-card .number {
            font-size: 27px;
            font-weight: 800;
            color: #102a43;
            overflow-wrap: anywhere;
        }

        .stat-card .icon {
            font-size: 22px;
            margin-bottom: 12px;
        }

        .blue {
            border-top: 4px solid #3b82f6;
        }

        .green {
            border-top: 4px solid #10b981;
        }

        .orange {
            border-top: 4px solid #f59e0b;
        }

        .red {
            border-top: 4px solid #ef4444;
        }

        .quick-title {
            font-size: 19px;
            margin: 0 0 15px;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 25px;
        }

        .action-card {
            background: white;
            border: 1px solid #e8edf3;
            padding: 18px;
            border-radius: 12px;
            text-decoration: none;
            color: #1e293b;
            transition: .2s;
        }

        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px #0f172a12;
        }

        .action-card .action-icon {
            font-size: 22px;
            color: #2563eb;
        }

        .action-card strong {
            display: block;
            margin: 9px 0 5px;
        }

        .action-card small {
            color: #64748b;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 22px;
        }

        .panel {
            background: white;
            border-radius: 13px;
            padding: 22px;
            border: 1px solid #e8edf3;
            box-shadow: 0 4px 15px #0f172a08;
            min-width: 0;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
        }

        .panel-header h3 {
            margin: 0;
            font-size: 17px;
        }

        .panel-header a {
            color: #2563eb;
            text-decoration: none;
            font-size: 13px;
            white-space: nowrap;
        }

        .panel-header a:hover {
            text-decoration: underline;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            white-space: nowrap;
            font-size: 13px;
        }

        th,
        td {
            text-align: left;
            padding: 13px 10px;
            border-bottom: 1px solid #edf1f5;
        }

        th {
            color: #64748b;
            font-weight: 600;
            background: #f8fafc;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .pending {
            background: #fef3c7;
            color: #92400e;
        }

        .approved {
            background: #dbeafe;
            color: #1e40af;
        }

        .ordered {
            background: #ede9fe;
            color: #5b21b6;
        }

        .received {
            background: #d1fae5;
            color: #065f46;
        }

        .rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .neutral {
            background: #e2e8f0;
            color: #334155;
        }

        .movement-in {
            color: #059669;
            font-weight: 700;
        }

        .movement-out {
            color: #dc2626;
            font-weight: 700;
        }

        .empty {
            text-align: center;
            color: #94a3b8;
            padding: 25px;
        }

        .alert-list {
            display: grid;
            gap: 12px;
        }

        .alert-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 13px;
            background: #f8fafc;
            border-radius: 9px;
        }

        .alert-item strong {
            display: block;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .alert-item small {
            color: #64748b;
        }

        .alert-quantity {
            font-weight: 800;
            color: #dc2626;
            white-space: nowrap;
        }

        .footer {
            text-align: center;
            padding: 25px 10px;
            color: #94a3b8;
            font-size: 12px;
        }

        .dashboard-error {
            background: #fee2e2;
            color: #991b1b;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        @media (max-width: 1100px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .quick-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 750px) {
            .main-content {
                margin-left: 68px;
                padding: 17px;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-card .number {
                font-size: 21px;
            }

            .quick-actions {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .action-card {
                padding: 14px;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .topbar h1 {
                font-size: 23px;
            }

            .panel {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../includes/pharmacy_sidebar.php'; ?>

<main class="main-content">

    <!-- Page Header -->
    <div class="topbar">
        <div>
            <h1>Pharmacy Dashboard</h1>
            <p>Monitor medicines, inventory, stock and purchases.</p>
        </div>

        <div class="date-label">
            <i class="fas fa-calendar-days"></i>
            <?= e(date('F d, Y')) ?>
        </div>
    </div>

    <?php if ($dashboardError): ?>
        <div class="dashboard-error">
            <i class="fas fa-circle-exclamation"></i>
            <?= e($dashboardError) ?>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <section class="stats-grid">

        <div class="stat-card blue">
            <div class="icon">💊</div>
            <div class="label">Total Medicines</div>
            <div class="number"><?= number_format($totalMedicines) ?></div>
        </div>

        <div class="stat-card green">
            <div class="icon">📦</div>
            <div class="label">Total Stock Units</div>
            <div class="number"><?= number_format($totalStock) ?></div>
        </div>

        <div class="stat-card blue">
            <div class="icon">💰</div>
            <div class="label">Inventory Value</div>
            <div class="number"><?= number_format($inventoryValue, 2) ?></div>
        </div>

        <div class="stat-card orange">
            <div class="icon">⚠️</div>
            <div class="label">Low Stock Medicines</div>
            <div class="number"><?= number_format($lowStock) ?></div>
        </div>

        <div class="stat-card red">
            <div class="icon">🚫</div>
            <div class="label">Expired Medicines</div>
            <div class="number"><?= number_format($expiredMedicines) ?></div>
        </div>

        <div class="stat-card orange">
            <div class="icon">⏳</div>
            <div class="label">Expiring Within 60 Days</div>
            <div class="number"><?= number_format($expiringSoon) ?></div>
        </div>

        <div class="stat-card blue">
            <div class="icon">🛒</div>
            <div class="label">Purchase Requests</div>
            <div class="number"><?= number_format($totalPurchaseRequests) ?></div>
        </div>

        <div class="stat-card orange">
            <div class="icon">📋</div>
            <div class="label">Pending Requests</div>
            <div class="number"><?= number_format($pendingRequests) ?></div>
        </div>

    </section>

    <!-- Quick Actions -->
    <h2 class="quick-title">Quick Actions</h2>

    <section class="quick-actions">

        <a href="add.php" class="action-card">
            <div class="action-icon"><i class="fas fa-circle-plus"></i></div>
            <strong>Add Medicine</strong>
            <small>Register a new medicine</small>
        </a>

        <a href="stock.php" class="action-card">
            <div class="action-icon"><i class="fas fa-boxes-stacked"></i></div>
            <strong>Manage Stock</strong>
            <small>Record stock in and out</small>
        </a>

        <a href="purchase_request.php" class="action-card">
            <div class="action-icon"><i class="fas fa-cart-shopping"></i></div>
            <strong>Purchase Requests</strong>
            <small>Manage medicine purchases</small>
        </a>

        <a href="reports.php" class="action-card">
            <div class="action-icon"><i class="fas fa-chart-pie"></i></div>
            <strong>View Reports</strong>
            <small>Analyze pharmacy activities</small>
        </a>

    </section>

    <!-- Recent Activities -->
    <section class="content-grid">

        <!-- Recent Stock Movements -->
        <div class="panel">

            <div class="panel-header">
                <h3>Recent Stock Movements</h3>
                <a href="stock.php">View All</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Type</th>
                            <th>Quantity</th>
                            <th>Date</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!empty($recentMovements)): ?>

                        <?php foreach ($recentMovements as $movement): ?>
                            <tr>
                                <td>
                                    <?= e($movement['medicine_name'] ?? 'Unknown') ?>
                                </td>

                                <td class="<?= ($movement['movement_type'] ?? '') === 'in' ? 'movement-in' : 'movement-out' ?>">
                                    <?= e(strtoupper($movement['movement_type'] ?? '')) ?>
                                </td>

                                <td>
                                    <?= number_format((int)$movement['quantity']) ?>
                                </td>

                                <td>
                                    <?= e(date('M d, Y', strtotime($movement['created_at']))) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="4" class="empty">
                                No stock movements found.
                            </td>
                        </tr>

                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <!-- Recent Purchase Requests -->
        <div class="panel">

            <div class="panel-header">
                <h3>Recent Purchase Requests</h3>
                <a href="purchase_request.php">View All</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Quantity</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!empty($recentRequests)): ?>

                        <?php foreach ($recentRequests as $request): ?>
                            <tr>
                                <td>
                                    <?= e($request['medicine_name'] ?? 'Unknown') ?>
                                </td>

                                <td>
                                    <?= number_format((int)$request['requested_quantity']) ?>
                                </td>

                                <td>
                                    <span class="badge <?= e(statusClass($request['status'])) ?>">
                                        <?= e(ucfirst($request['status'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="3" class="empty">
                                No purchase requests found.
                            </td>
                        </tr>

                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

    </section>

    <!-- Low Stock Alerts -->
    <section class="panel">

        <div class="panel-header">
            <h3>
                <i class="fas fa-triangle-exclamation" style="color:#f59e0b;"></i>
                Low Stock Alerts
            </h3>

            <a href="low_stock.php">Manage Alerts</a>
        </div>

        <div class="alert-list">

            <?php if (!empty($lowStockMedicines)): ?>

                <?php foreach ($lowStockMedicines as $medicine): ?>

                    <div class="alert-item">

                        <div>
                            <strong><?= e($medicine['medicine_name']) ?></strong>
                            <small><?= e($medicine['category']) ?></small>
                        </div>

                        <div class="alert-quantity">
                            <?= number_format((int)$medicine['quantity']) ?> units
                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty">
                    No low stock medicines. Inventory is healthy.
                </div>

            <?php endif; ?>

        </div>

    </section>

    <div class="footer">
        © <?= date('Y') ?> MediCare Hospital Management System.
        Pharmacy Management Module.
    </div>

</main>

</body>
</html>
