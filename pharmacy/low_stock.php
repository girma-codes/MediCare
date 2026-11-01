
<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin();
requireRole(['admin']);

$pageTitle = 'Low Stock Management';
$threshold = 10;

$stmt = $pdo->prepare("
    SELECT id, medicine_name, category, quantity, unit_price, expiry_date
    FROM medicines
    WHERE quantity <= ?
    ORDER BY quantity ASC, medicine_name ASC
");
$stmt->execute([$threshold]);
$medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalLowStock = count($medicines);
$totalUnits = 0;

foreach ($medicines as $medicine) {
    $totalUnits += (int)$medicine['quantity'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet"
          href="../assets/css/admin.css">

    <style>
        .low-stock-container {
            padding: 25px;
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
        }

        .summary-card {
            background: #fff;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,.06);
            margin-bottom: 25px;
        }

        .summary-card h3 {
            margin: 0 0 10px;
            color: #64748b;
        }

        .summary-card .number {
            font-size: 30px;
            font-weight: bold;
            color: #dc2626;
        }

        .table-container {
            overflow-x: auto;
            background: #fff;
            padding: 20px;
            border-radius: 12px;
        }

        .low-stock-table {
            width: 100%;
            border-collapse: collapse;
        }

        .low-stock-table th,
        .low-stock-table td {
            padding: 13px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .low-stock-table th {
            background: #f8fafc;
        }

        .stock-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #fee2e2;
            color: #b91c1c;
            font-weight: bold;
            font-size: 12px;
        }

        .back-link {
            text-decoration: none;
            color: #2563eb;
            font-weight: bold;
        }

        .empty-message {
            padding: 25px;
            text-align: center;
            color: #15803d;
            background: #f0fdf4;
            border-radius: 10px;
        }
    </style>
</head>

<body>

<div class="low-stock-container">

    <div class="low-stock-header">
        <div>
            <h1>Low Stock Management</h1>
            <p>Monitor medicines with 10 or fewer units.</p>
        </div>

        <a class="back-link"
           href="index.php">
            ← Pharmacy Inventory
        </a>
    </div>

    <div class="summary-card">
        <h3>Medicines with Low Stock</h3>
        <div class="number"><?= $totalLowStock ?></div>
        <p>Total remaining units: <?= $totalUnits ?></p>
        <p>Low stock threshold: 10 units or below</p>
    </div>

    <div class="table-container">
        <h2>Low Stock Report</h2>

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
                            <td>#<?= (int)$medicine['id'] ?></td>

                            <td>
                                <strong>
                                    <?= htmlspecialchars($medicine['medicine_name']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= htmlspecialchars($medicine['category'] ?? '') ?>
                            </td>

                            <td>
                                <?= (int)$medicine['quantity'] ?>
                            </td>

                            <td>
                                <?= number_format((float)$medicine['unit_price'], 2) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($medicine['expiry_date'] ?? '') ?>
                            </td>

                            <td>
                                <span class="stock-badge">
                                    Low Stock
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php else: ?>

            <div class="empty-message">
                <h3>All Medicines Are in Stock!</h3>
                <p>No medicines have reached the low-stock threshold.</p>
            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
