
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

$success = '';
$error = '';

// Process stock movement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        empty($_SESSION['csrf_token']) ||
        empty($_POST['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token']
        )
    ) {
        $error = 'Invalid security token. Please refresh the page.';

    } else {

        $medicineId = filter_input(
            INPUT_POST,
            'medicine_id',
            FILTER_VALIDATE_INT
        );

        $movementType = $_POST['movement_type'] ?? '';

        $quantity = filter_input(
            INPUT_POST,
            'quantity',
            FILTER_VALIDATE_INT
        );

        $notes = trim($_POST['notes'] ?? '');

        if (!$medicineId || $medicineId < 1) {

            $error = 'Please select a valid medicine.';

        } elseif (!in_array($movementType, ['in', 'out'], true)) {

            $error = 'Please select a valid stock movement type.';

        } elseif ($quantity === false || $quantity === null || $quantity < 1) {

            $error = 'Quantity must be at least 1.';

        } elseif (strlen($notes) > 255) {

            $error = 'Notes cannot exceed 255 characters.';

        } else {

            try {

                $pdo->beginTransaction();

                // Lock medicine row during stock update
                $stmt = $pdo->prepare("
                    SELECT id, medicine_name, quantity
                    FROM medicines
                    WHERE id = :id
                    FOR UPDATE
                ");

                $stmt->execute([
                    ':id' => $medicineId
                ]);

                $medicine = $stmt->fetch();

                if (!$medicine) {

                    throw new RuntimeException(
                        'Selected medicine was not found.'
                    );

                }

                $previousQuantity = (int)$medicine['quantity'];

                // Calculate new quantity
                if ($movementType === 'in') {

                    if ($previousQuantity > PHP_INT_MAX - $quantity) {
                        throw new RuntimeException(
                            'Quantity is too large.'
                        );
                    }

                    $newQuantity = $previousQuantity + $quantity;

                } else {

                    if ($quantity > $previousQuantity) {
                        throw new RuntimeException(
                            'Insufficient stock. Current quantity is '
                            . $previousQuantity . '.'
                        );
                    }

                    $newQuantity = $previousQuantity - $quantity;
                }

                // Update medicine inventory
                $update = $pdo->prepare("
                    UPDATE medicines
                    SET quantity = :quantity
                    WHERE id = :id
                ");

                $update->execute([
                    ':quantity' => $newQuantity,
                    ':id' => $medicineId
                ]);

                // Save stock movement history
                $history = $pdo->prepare("
                    INSERT INTO stock_movements (
                        medicine_id,
                        movement_type,
                        quantity,
                        previous_quantity,
                        new_quantity,
                        notes,
                        created_by
                    ) VALUES (
                        :medicine_id,
                        :movement_type,
                        :quantity,
                        :previous_quantity,
                        :new_quantity,
                        :notes,
                        :created_by
                    )
                ");

                $history->execute([
                    ':medicine_id' => $medicineId,
                    ':movement_type' => $movementType,
                    ':quantity' => $quantity,
                    ':previous_quantity' => $previousQuantity,
                    ':new_quantity' => $newQuantity,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':created_by' => (int)$_SESSION['user_id']
                ]);

                $pdo->commit();

                $_SESSION['stock_success'] =
                    'Stock successfully updated for '
                    . $medicine['medicine_name']
                    . '. New quantity: '
                    . $newQuantity;

                header("Location: stock.php");
                exit;

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($e instanceof RuntimeException) {
                    $error = $e->getMessage();
                } else {
                    error_log($e->getMessage());
                    $error = 'Unable to update stock. Please try again.';
                }
            }
        }
    }
}

// Show success message after redirect
if (isset($_SESSION['stock_success'])) {
    $success = $_SESSION['stock_success'];
    unset($_SESSION['stock_success']);
}

// Fetch medicines
$medicineStmt = $pdo->query("
    SELECT id, medicine_name, category, quantity, expiry_date
    FROM medicines
    ORDER BY medicine_name ASC
");

$medicines = $medicineStmt->fetchAll();

// Statistics
$totalMedicines = (int)$pdo->query("
    SELECT COUNT(*) FROM medicines
")->fetchColumn();

$totalUnits = (int)$pdo->query("
    SELECT COALESCE(SUM(quantity), 0)
    FROM medicines
")->fetchColumn();

$totalMovements = (int)$pdo->query("
    SELECT COUNT(*) FROM stock_movements
")->fetchColumn();

$stockIn = (int)$pdo->query("
    SELECT COALESCE(SUM(quantity), 0)
    FROM stock_movements
    WHERE movement_type = 'in'
")->fetchColumn();

$stockOut = (int)$pdo->query("
    SELECT COALESCE(SUM(quantity), 0)
    FROM stock_movements
    WHERE movement_type = 'out'
")->fetchColumn();

// Fetch stock history
$historyStmt = $pdo->query("
    SELECT
        sm.id,
        sm.movement_type,
        sm.quantity,
        sm.previous_quantity,
        sm.new_quantity,
        sm.notes,
        sm.created_at,
        m.medicine_name,
        u.full_name AS staff_name
    FROM stock_movements sm
    INNER JOIN medicines m
        ON sm.medicine_id = m.id
    INNER JOIN users u
        ON sm.created_by = u.id
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 100
");

$history = $historyStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Stock Management | MediCare HMS</title>

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
            max-width: 1450px;
            margin: 35px auto;
            padding: 0 25px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .header h1 {
            font-size: 27px;
            margin-bottom: 7px;
        }

        .header p {
            color: #718096;
            font-size: 14px;
        }

        .back-btn {
            text-decoration: none;
            background: #e8eef7;
            color: #263f60;
            padding: 12px 17px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #edf0f5;
            box-shadow: 0 3px 15px rgba(0,0,0,.04);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #e9f3ff;
            color: #2874c7;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 13px;
        }

        .stat-card h2 {
            font-size: 25px;
            margin-bottom: 5px;
        }

        .stat-card p {
            color: #78869a;
            font-size: 13px;
        }

        .alert {
            padding: 14px 17px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .success {
            background: #e5f7ed;
            color: #16834b;
            border: 1px solid #b8ebcc;
        }

        .error {
            background: #fde9e7;
            color: #c0392b;
            border: 1px solid #f5c2bd;
        }

        .content {
            background: white;
            padding: 25px;
            border-radius: 12px;
            border: 1px solid #edf0f5;
            box-shadow: 0 3px 15px rgba(0,0,0,.04);
            margin-bottom: 25px;
        }

        .content h2 {
            font-size: 19px;
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #dce3ec;
            border-radius: 7px;
            outline: none;
            font-family: Arial, sans-serif;
            font-size: 14px;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #2874c7;
            box-shadow: 0 0 0 3px rgba(40,116,199,.10);
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .submit-btn {
            border: none;
            background: #2874c7;
            color: white;
            padding: 13px 22px;
            border-radius: 7px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
        }

        .submit-btn:hover {
            background: #185da7;
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
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
        }

        th {
            color: #64748b;
            background: #f8fafc;
            font-size: 12px;
            text-transform: uppercase;
        }

        .type-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }

        .type-in {
            color: #16834b;
            background: #e5f7ed;
        }

        .type-out {
            color: #c0392b;
            background: #fde9e7;
        }

        .empty {
            text-align: center;
            padding: 35px;
            color: #8491a3;
        }

        .hint {
            color: #718096;
            font-size: 12px;
            line-height: 1.5;
        }

        @media(max-width: 1100px) {
            .stats {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media(max-width: 650px) {
            .container {
                padding: 0 12px;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .full-width {
                grid-column: auto;
            }

            .content {
                padding: 16px;
            }
        }

    </style>

</head>

<body>

<div class="container">

    <!-- Header -->

    <div class="header">

        <div>
            <h1>
                <i class="fa-solid fa-boxes-stacked"></i>
                Stock Management
            </h1>

            <p>
                Manage medicine stock, stock movements and inventory history.
            </p>
        </div>

        <a href="index.php" class="back-btn">
            <i class="fa-solid fa-arrow-left"></i>
            Pharmacy Inventory
        </a>

    </div>

    <!-- Notifications -->

    <?php if ($success !== ''): ?>
        <div class="alert success">
            <i class="fa-solid fa-circle-check"></i>
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- Statistics -->

    <div class="stats">

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-pills"></i>
            </div>
            <h2><?= number_format($totalMedicines) ?></h2>
            <p>Total Medicines</p>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>
            <h2><?= number_format($totalUnits) ?></h2>
            <p>Total Units in Stock</p>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-arrow-down"></i>
            </div>
            <h2><?= number_format($stockIn) ?></h2>
            <p>Total Stock Added</p>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-arrow-up"></i>
            </div>
            <h2><?= number_format($stockOut) ?></h2>
            <p>Total Stock Reduced</p>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <h2><?= number_format($totalMovements) ?></h2>
            <p>Total Movements</p>
        </div>

    </div>

    <!-- Stock Adjustment Form -->

    <div class="content">

        <h2>
            <i class="fa-solid fa-arrows-rotate"></i>
            Update Medicine Stock
        </h2>

        <?php if (empty($medicines)): ?>

            <div class="empty">
                <i class="fa-solid fa-box-open"></i>
                <p>No medicines available. Add a medicine first.</p>
            </div>

        <?php else: ?>

            <form method="POST" action="stock.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['csrf_token']) ?>"
                >

                <div class="form-grid">

                    <div class="form-group">

                        <label for="medicine_id">
                            Select Medicine *
                        </label>

                        <select
                            name="medicine_id"
                            id="medicine_id"
                            required
                        >

                            <option value="">
                                -- Select Medicine --
                            </option>

                            <?php foreach ($medicines as $medicine): ?>

                                <option
                                    value="<?= (int)$medicine['id'] ?>"
                                    data-quantity="<?= (int)$medicine['quantity'] ?>"
                                >
                                    <?= e($medicine['medicine_name']) ?>
                                    (Current: <?= (int)$medicine['quantity'] ?>)
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="form-group">

                        <label for="movement_type">
                            Movement Type *
                        </label>

                        <select
                            name="movement_type"
                            id="movement_type"
                            required
                        >
                            <option value="">
                                -- Select Movement --
                            </option>

                            <option value="in">
                                Add Stock
                            </option>

                            <option value="out">
                                Reduce Stock
                            </option>

                        </select>

                    </div>

                    <div class="form-group">

                        <label for="quantity">
                            Quantity *
                        </label>

                        <input
                            type="number"
                            name="quantity"
                            id="quantity"
                            min="1"
                            step="1"
                            placeholder="Enter quantity"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="notes">
                            Notes
                        </label>

                        <input
                            type="text"
                            name="notes"
                            id="notes"
                            maxlength="255"
                            placeholder="Reason for stock movement"
                        >

                    </div>

                    <div class="form-group full-width">

                        <p class="hint" id="stock-info">
                            Select a medicine to see its current quantity.
                        </p>

                    </div>

                    <div class="form-group full-width">

                        <button
                            type="submit"
                            class="submit-btn"
                        >
                            <i class="fa-solid fa-save"></i>
                            Save Stock Movement
                        </button>

                    </div>

                </div>

            </form>

        <?php endif; ?>

    </div>

    <!-- Stock History -->

    <div class="content">

        <h2>
            <i class="fa-solid fa-clock-rotate-left"></i>
            Stock Movement History
        </h2>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>
                        <th>ID</th>
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

                <?php if (empty($history)): ?>

                    <tr>
                        <td colspan="9">
                            <div class="empty">
                                No stock movement history available.
                            </div>
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($history as $item): ?>

                        <tr>

                            <td>#<?= (int)$item['id'] ?></td>

                            <td>
                                <strong>
                                    <?= e($item['medicine_name']) ?>
                                </strong>
                            </td>

                            <td>

                                <?php if ($item['movement_type'] === 'in'): ?>

                                    <span class="type-badge type-in">
                                        <i class="fa-solid fa-arrow-down"></i>
                                        Stock In
                                    </span>

                                <?php else: ?>

                                    <span class="type-badge type-out">
                                        <i class="fa-solid fa-arrow-up"></i>
                                        Stock Out
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= number_format((int)$item['quantity']) ?>
                            </td>

                            <td>
                                <?= number_format((int)$item['previous_quantity']) ?>
                            </td>

                            <td>
                                <strong>
                                    <?= number_format((int)$item['new_quantity']) ?>
                                </strong>
                            </td>

                            <td>
                                <?= e($item['notes'] ?? 'N/A') ?>
                            </td>

                            <td>
                                <?= e($item['staff_name']) ?>
                            </td>

                            <td>
                                <?= e(date(
                                    'M d, Y h:i A',
                                    strtotime($item['created_at'])
                                )) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <p class="hint" style="margin-top:15px;">
            Showing the latest 100 stock movements.
        </p>

    </div>

</div>

<script>

const medicineSelect = document.getElementById('medicine_id');
const movementSelect = document.getElementById('movement_type');
const quantityInput = document.getElementById('quantity');
const stockInfo = document.getElementById('stock-info');

function updateStockInfo() {

    if (!medicineSelect || !movementSelect || !quantityInput) {
        return;
    }

    const selectedOption =
        medicineSelect.options[medicineSelect.selectedIndex];

    if (!selectedOption || !selectedOption.value) {
        stockInfo.textContent =
            'Select a medicine to see its current quantity.';
        return;
    }

    const currentQuantity =
        Number(selectedOption.dataset.quantity || 0);

    const movement = movementSelect.value;

    if (movement === 'in') {
        stockInfo.textContent =
            'Current stock: ' + currentQuantity +
            '. The entered quantity will be added to this amount.';

    } else if (movement === 'out') {
        stockInfo.textContent =
            'Current stock: ' + currentQuantity +
            '. You cannot reduce more than the available quantity.';

        quantityInput.max = currentQuantity;

    } else {
        stockInfo.textContent =
            'Current stock: ' + currentQuantity + '.';
    }

    if (movement !== 'out') {
        quantityInput.removeAttribute('max');
    }
}

if (medicineSelect && movementSelect) {
    medicineSelect.addEventListener('change', updateStockInfo);
    movementSelect.addEventListener('change', updateStockInfo);
}

</script>

</body>
</html>
