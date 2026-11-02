
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
requireRole(['admin']);

$pageTitle = 'Medicine Purchase Management';
$success = '';
$error = '';

/* Safe output */
if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/* CSRF protection */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrfToken($token) {
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/* Handle all POST requests */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Security verification failed. Please refresh the page and try again.';

    } else {

        $action = trim((string)($_POST['action'] ?? ''));

        /* =====================================
           PURCHASE REQUEST ACTIONS
        ===================================== */
        if ($action !== '') {

            $requestId = filter_var(
                $_POST['request_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $allowedActions = ['approve', 'reject', 'ordered', 'receive'];

            if (!$requestId || $requestId <= 0) {
                $error = 'Invalid purchase request ID.';

            } elseif (!in_array($action, $allowedActions, true)) {
                $error = 'Invalid purchase action.';

            } else {

                try {
                    $pdo->beginTransaction();

                    /* Lock purchase request */
                    $stmt = $pdo->prepare("
                        SELECT *
                        FROM purchase_requests
                        WHERE id = ?
                        FOR UPDATE
                    ");
                    $stmt->execute([$requestId]);
                    $request = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$request) {
                        throw new Exception('Purchase request not found.');
                    }

                    /* APPROVE */
                    if ($action === 'approve') {

                        if ($request['status'] !== 'pending') {
                            throw new Exception(
                                'Only pending requests can be approved.'
                            );
                        }

                        $stmt = $pdo->prepare("
                            UPDATE purchase_requests
                            SET status = 'approved',
                                approved_by = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $_SESSION['user_id'],
                            $requestId
                        ]);

                        $success = 'Purchase request approved successfully.';

                    /* REJECT */
                    } elseif ($action === 'reject') {

                        if ($request['status'] !== 'pending') {
                            throw new Exception(
                                'Only pending requests can be rejected.'
                            );
                        }

                        $stmt = $pdo->prepare("
                            UPDATE purchase_requests
                            SET status = 'rejected',
                                approved_by = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $_SESSION['user_id'],
                            $requestId
                        ]);

                        $success = 'Purchase request rejected successfully.';

                    /* MARK ORDERED */
                    } elseif ($action === 'ordered') {

                        if ($request['status'] !== 'approved') {
                            throw new Exception(
                                'Only approved requests can be marked as ordered.'
                            );
                        }

                        $stmt = $pdo->prepare("
                            UPDATE purchase_requests
                            SET status = 'ordered'
                            WHERE id = ?
                        ");

                        $stmt->execute([$requestId]);

                        $success = 'Purchase order marked as ordered.';

                    /* RECEIVE STOCK */
                    } elseif ($action === 'receive') {

                        if ($request['status'] !== 'ordered') {
                            throw new Exception(
                                'Only ordered requests can be received.'
                            );
                        }

                        $receivedQuantity = (int)$request['requested_quantity'];

                        if ($receivedQuantity <= 0) {
                            throw new Exception(
                                'Invalid requested quantity.'
                            );
                        }

                        /* Lock medicine record */
                        $medicineStmt = $pdo->prepare("
                            SELECT quantity
                            FROM medicines
                            WHERE id = ?
                            FOR UPDATE
                        ");

                        $medicineStmt->execute([
                            $request['medicine_id']
                        ]);

                        $medicine = $medicineStmt->fetch(PDO::FETCH_ASSOC);

                        if (!$medicine) {
                            throw new Exception('Medicine not found.');
                        }

                        $previousQuantity = (int)$medicine['quantity'];

                        if (
                            $previousQuantity < 0 ||
                            $receivedQuantity > PHP_INT_MAX - $previousQuantity
                        ) {
                            throw new Exception(
                                'Stock quantity is outside the allowed limit.'
                            );
                        }

                        $newQuantity = $previousQuantity + $receivedQuantity;

                        /* Update medicine quantity */
                        $updateMedicine = $pdo->prepare("
                            UPDATE medicines
                            SET quantity = ?
                            WHERE id = ?
                        ");

                        $updateMedicine->execute([
                            $newQuantity,
                            $request['medicine_id']
                        ]);

                        /* Record stock movement */
                        $movement = $pdo->prepare("
                            INSERT INTO stock_movements
                            (
                                medicine_id,
                                movement_type,
                                quantity,
                                previous_quantity,
                                new_quantity,
                                notes,
                                created_by
                            )
                            VALUES (?, 'in', ?, ?, ?, ?, ?)
                        ");

                        $movement->execute([
                            $request['medicine_id'],
                            $receivedQuantity,
                            $previousQuantity,
                            $newQuantity,
                            'Received from purchase request #' . $requestId,
                            $_SESSION['user_id']
                        ]);

                        /* Mark request as received */
                        $updateRequest = $pdo->prepare("
                            UPDATE purchase_requests
                            SET status = 'received'
                            WHERE id = ?
                        ");

                        $updateRequest->execute([$requestId]);

                        $success = 'Stock received successfully! New stock: '
                            . number_format($newQuantity);
                    }

                    $pdo->commit();

                } catch (Exception $ex) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = $ex->getMessage();

                } catch (Throwable $ex) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log('Purchase action error: ' . $ex->getMessage());

                    $error = 'An unexpected error occurred. Please try again.';
                }
            }

        /* =====================================
           CREATE PURCHASE REQUEST
        ===================================== */
        } else {

            $medicineId = filter_var(
                $_POST['medicine_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $requestedQuantity = filter_var(
                $_POST['requested_quantity'] ?? null,
                FILTER_VALIDATE_INT
            );

            $unitPriceInput = trim((string)($_POST['unit_price'] ?? ''));

            $supplierName = trim((string)($_POST['supplier_name'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            $validPrice = (
                $unitPriceInput !== '' &&
                is_numeric($unitPriceInput) &&
                is_finite((float)$unitPriceInput)
            );

            $unitPrice = $validPrice ? (float)$unitPriceInput : -1;

            if (!$medicineId || $medicineId <= 0) {
                $error = 'Please select a valid medicine.';

            } elseif (!$requestedQuantity || $requestedQuantity <= 0) {
                $error = 'Requested quantity must be greater than zero.';

            } elseif (!$validPrice || $unitPrice < 0 || $unitPrice > 999999999) {
                $error = 'Please enter a valid unit price.';

            } elseif (strlen($supplierName) > 150) {
                $error = 'Supplier name must not exceed 150 characters.';

            } elseif (strlen($notes) > 1000) {
                $error = 'Notes must not exceed 1000 characters.';

            } else {

                try {

                    /* Check medicine exists */
                    $check = $pdo->prepare("
                        SELECT id
                        FROM medicines
                        WHERE id = ?
                    ");

                    $check->execute([$medicineId]);

                    if (!$check->fetch(PDO::FETCH_ASSOC)) {
                        $error = 'Selected medicine was not found.';

                    } else {

                        $totalCost = $requestedQuantity * $unitPrice;

                        if (!is_finite($totalCost) || $totalCost > 999999999999) {
                            throw new Exception(
                                'The total purchase cost is too large.'
                            );
                        }

                        $insert = $pdo->prepare("
                            INSERT INTO purchase_requests
                            (
                                medicine_id,
                                requested_quantity,
                                unit_price,
                                total_cost,
                                supplier_name,
                                notes,
                                requested_by
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");

                        $insert->execute([
                            $medicineId,
                            $requestedQuantity,
                            $unitPrice,
                            $totalCost,
                            $supplierName !== '' ? $supplierName : null,
                            $notes !== '' ? $notes : null,
                            $_SESSION['user_id']
                        ]);

                        $success = 'Purchase request created successfully!';
                    }

                } catch (Exception $ex) {
                    $error = $ex->getMessage();

                } catch (Throwable $ex) {
                    error_log('Purchase creation error: ' . $ex->getMessage());
                    $error = 'Unable to create purchase request. Please try again.';
                }
            }
        }
    }
}

/* Fetch medicines */
$stmt = $pdo->query("
    SELECT id, medicine_name, category, quantity, unit_price
    FROM medicines
    ORDER BY medicine_name ASC
");

$medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Fetch purchase requests */
$stmt = $pdo->query("
    SELECT
        pr.*,
        m.medicine_name,
        m.quantity AS current_stock,
        u.full_name AS requested_by_name
    FROM purchase_requests pr
    JOIN medicines m ON pr.medicine_id = m.id
    JOIN users u ON pr.requested_by = u.id
    ORDER BY pr.created_at DESC
");

$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Summary statistics */
$totalRequests = count($requests);
$pendingCount = 0;
$approvedCount = 0;
$orderedCount = 0;
$receivedCount = 0;
$rejectedCount = 0;

foreach ($requests as $request) {
    switch ($request['status']) {
        case 'pending':
            $pendingCount++;
            break;
        case 'approved':
            $approvedCount++;
            break;
        case 'ordered':
            $orderedCount++;
            break;
        case 'received':
            $receivedCount++;
            break;
        case 'rejected':
            $rejectedCount++;
            break;
    }
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

        .purchase-container {
            padding: 30px;
        }

        .page-header {
            margin-bottom: 25px;
        }

        .page-header h1 {
            margin: 0 0 8px;
            font-size: 27px;
            color: #102a43;
        }

        .page-header p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: #fff;
            padding: 19px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05);
        }

        .stat-card .stat-icon {
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            margin-bottom: 12px;
        }

        .stat-card h3 {
            font-size: 25px;
            margin: 0 0 5px;
        }

        .stat-card p {
            font-size: 12px;
            color: #64748b;
            margin: 0;
        }

        .stat-total .stat-icon {
            background: #dbeafe;
            color: #2563eb;
        }

        .stat-pending .stat-icon {
            background: #fef3c7;
            color: #b45309;
        }

        .stat-approved .stat-icon {
            background: #dcfce7;
            color: #15803d;
        }

        .stat-ordered .stat-icon {
            background: #e0f2fe;
            color: #0369a1;
        }

        .stat-received .stat-icon {
            background: #ede9fe;
            color: #6d28d9;
        }

        .purchase-card {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 12px rgba(0,0,0,.05);
        }

        .card-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 22px;
            font-size: 19px;
            color: #102a43;
        }

        .card-heading i {
            color: #2563eb;
        }

        .alert {
            padding: 14px 16px;
            margin-bottom: 18px;
            border-radius: 8px;
            font-size: 14px;
        }

        .success {
            background: #e5f7ed;
            color: #176b52;
            border: 1px solid #bbf7d0;
        }

        .error {
            background: #ffe9e9;
            color: #b42318;
            border: 1px solid #fecaca;
        }

        .purchase-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-size: 13px;
            font-weight: bold;
            color: #334155;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            width: 100%;
            font-family: inherit;
            font-size: 13px;
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }

        .form-group textarea {
            min-height: 90px;
            resize: vertical;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .submit-btn {
            background: #176b52;
            border: none;
            border-radius: 7px;
            padding: 13px 22px;
            cursor: pointer;
            color: white;
            font-weight: bold;
            font-size: 13px;
            transition: .2s;
        }

        .submit-btn:hover {
            background: #14543f;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .purchase-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1150px;
        }

        .purchase-table th,
        .purchase-table td {
            padding: 13px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            font-size: 12px;
            white-space: nowrap;
        }

        .purchase-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: bold;
        }

        .purchase-table tbody tr:hover {
            background: #f8fafc;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }

        .pending {
            color: #996000;
            background: #fff1d6;
        }

        .approved {
            color: #176b52;
            background: #e5f7ed;
        }

        .ordered {
            color: #0755a5;
            background: #e5f0ff;
        }

        .received {
            color: #6f42c1;
            background: #f0e8ff;
        }

        .rejected {
            color: #b42318;
            background: #ffe9e9;
        }

        .action-form {
            display: inline-block;
        }

        .action-btn {
            border: none;
            border-radius: 6px;
            padding: 8px 11px;
            cursor: pointer;
            color: white;
            margin: 2px;
            font-size: 11px;
            font-weight: bold;
        }

        .approve {
            background: #198754;
        }

        .reject {
            background: #dc3545;
        }

        .ordered-btn {
            background: #0d6efd;
        }

        .receive {
            background: #6f42c1;
        }

        .action-btn:hover {
            opacity: .85;
        }

        .completed-text {
            color: #15803d;
            font-size: 12px;
            font-weight: bold;
        }

        .no-action {
            color: #94a3b8;
            font-size: 12px;
        }

        .empty-state {
            text-align: center;
            padding: 35px;
            color: #64748b;
        }

        .empty-state i {
            font-size: 35px;
            margin-bottom: 12px;
        }

        .back-link {
            display: inline-block;
            margin-top: 5px;
            color: #2563eb;
            text-decoration: none;
            font-weight: bold;
            font-size: 13px;
        }

        @media(max-width: 1100px) {
            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media(max-width: 750px) {
            .main-content {
                margin-left: 68px;
            }

            .topbar {
                padding: 0 15px;
            }

            .purchase-container {
                padding: 18px;
            }

            .purchase-form {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .page-header h1 {
                font-size: 22px;
            }
        }

        @media(max-width: 420px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .purchase-card {
                padding: 17px;
            }
        }
    </style>
</head>

<body>

<?php
/* Reusable pharmacy sidebar */
require_once __DIR__ . '/../includes/pharmacy_sidebar.php';
?>

<div class="main-content">

    <header class="topbar">
        <h3>Purchase Management</h3>

        <div class="topbar-user">
            <i class="fas fa-user-circle"></i>
            <span><?= e($_SESSION['full_name'] ?? 'Administrator') ?></span>
        </div>
    </header>

    <main class="purchase-container">

        <div class="page-header">
            <h1>Medicine Purchase Management</h1>
            <p>Create, approve and manage medicine purchase requests.</p>
        </div>

        <?php if ($success): ?>
            <div class="alert success">
                <i class="fas fa-circle-check"></i>
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error">
                <i class="fas fa-circle-exclamation"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <!-- STATISTICS -->
        <div class="stats-grid">

            <div class="stat-card stat-total">
                <div class="stat-icon">
                    <i class="fas fa-cart-shopping"></i>
                </div>
                <h3><?= (int)$totalRequests ?></h3>
                <p>Total Requests</p>
            </div>

            <div class="stat-card stat-pending">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h3><?= (int)$pendingCount ?></h3>
                <p>Pending Requests</p>
            </div>

            <div class="stat-card stat-approved">
                <div class="stat-icon">
                    <i class="fas fa-check"></i>
                </div>
                <h3><?= (int)$approvedCount ?></h3>
                <p>Approved Requests</p>
            </div>

            <div class="stat-card stat-ordered">
                <div class="stat-icon">
                    <i class="fas fa-truck"></i>
                </div>
                <h3><?= (int)$orderedCount ?></h3>
                <p>Ordered Requests</p>
            </div>

            <div class="stat-card stat-received">
                <div class="stat-icon">
                    <i class="fas fa-box-open"></i>
                </div>
                <h3><?= (int)$receivedCount ?></h3>
                <p>Received Requests</p>
            </div>

        </div>

        <!-- CREATE PURCHASE REQUEST -->
        <section class="purchase-card">

            <h2 class="card-heading">
                <i class="fas fa-plus-circle"></i>
                Create Purchase Request
            </h2>

            <form method="POST" class="purchase-form">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <div class="form-group">
                    <label for="medicine_id">Medicine</label>

                    <select name="medicine_id" id="medicine_id" required>
                        <option value="">Select Medicine</option>

                        <?php foreach ($medicines as $medicine): ?>
                            <option value="<?= (int)$medicine['id'] ?>">
                                <?= e($medicine['medicine_name']) ?>
                                (Stock: <?= (int)$medicine['quantity'] ?>)
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="form-group">
                    <label for="requested_quantity">Requested Quantity</label>

                    <input
                        type="number"
                        name="requested_quantity"
                        id="requested_quantity"
                        min="1"
                        max="2147483647"
                        step="1"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="unit_price">Unit Price</label>

                    <input
                        type="number"
                        name="unit_price"
                        id="unit_price"
                        min="0"
                        max="999999999"
                        step="0.01"
                        value="0"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="supplier_name">Supplier Name</label>

                    <input
                        type="text"
                        name="supplier_name"
                        id="supplier_name"
                        maxlength="150"
                        placeholder="Supplier name"
                    >
                </div>

                <div class="form-group full-width">
                    <label for="notes">Notes</label>

                    <textarea
                        name="notes"
                        id="notes"
                        maxlength="1000"
                        placeholder="Additional information"
                    ></textarea>
                </div>

                <div class="full-width">
                    <button type="submit" class="submit-btn">
                        <i class="fas fa-plus"></i>
                        Create Purchase Request
                    </button>
                </div>

            </form>

        </section>

        <!-- PURCHASE REQUEST HISTORY -->
        <section class="purchase-card">

            <h2 class="card-heading">
                <i class="fas fa-clock-rotate-left"></i>
                Purchase Request History
            </h2>

            <div class="table-responsive">

                <table class="purchase-table">

                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Medicine</th>
                            <th>Current Stock</th>
                            <th>Requested Qty</th>
                            <th>Unit Price</th>
                            <th>Total Cost</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th>Requested By</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($requests): ?>

                        <?php foreach ($requests as $request): ?>

                            <tr>

                                <td>#<?= (int)$request['id'] ?></td>

                                <td>
                                    <strong><?= e($request['medicine_name']) ?></strong>
                                </td>

                                <td><?= (int)$request['current_stock'] ?></td>

                                <td><?= (int)$request['requested_quantity'] ?></td>

                                <td>
                                    <?= number_format((float)$request['unit_price'], 2) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= number_format((float)$request['total_cost'], 2) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= e($request['supplier_name'] ?? '-') ?>
                                </td>

                                <td>
                                    <span class="status <?= e($request['status']) ?>">
                                        <?= e(ucfirst($request['status'])) ?>
                                    </span>
                                </td>

                                <td><?= e($request['requested_by_name']) ?></td>

                                <td><?= e($request['created_at']) ?></td>

                                <td>

                                    <?php if ($request['status'] === 'pending'): ?>

                                        <form method="POST" class="action-form">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="request_id"
                                                   value="<?= (int)$request['id'] ?>">

                                            <button
                                                type="submit"
                                                class="action-btn approve"
                                                name="action"
                                                value="approve"
                                            >
                                                <i class="fas fa-check"></i>
                                                Approve
                                            </button>
                                        </form>

                                        <form
                                            method="POST"
                                            class="action-form"
                                            onsubmit="return confirm('Reject this request?');"
                                        >
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="request_id"
                                                   value="<?= (int)$request['id'] ?>">

                                            <button
                                                type="submit"
                                                class="action-btn reject"
                                                name="action"
                                                value="reject"
                                            >
                                                <i class="fas fa-times"></i>
                                                Reject
                                            </button>
                                        </form>

                                    <?php elseif ($request['status'] === 'approved'): ?>

                                        <form method="POST" class="action-form">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="request_id"
                                                   value="<?= (int)$request['id'] ?>">

                                            <button
                                                type="submit"
                                                class="action-btn ordered-btn"
                                                name="action"
                                                value="ordered"
                                            >
                                                <i class="fas fa-truck"></i>
                                                Mark Ordered
                                            </button>
                                        </form>

                                    <?php elseif ($request['status'] === 'ordered'): ?>

                                        <form
                                            method="POST"
                                            class="action-form"
                                            onsubmit="return confirm('Confirm that the medicine has arrived?');"
                                        >
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="request_id"
                                                   value="<?= (int)$request['id'] ?>">

                                            <button
                                                type="submit"
                                                class="action-btn receive"
                                                name="action"
                                                value="receive"
                                            >
                                                <i class="fas fa-box-open"></i>
                                                Receive Stock
                                            </button>
                                        </form>

                                    <?php elseif ($request['status'] === 'received'): ?>

                                        <span class="completed-text">
                                            <i class="fas fa-circle-check"></i>
                                            Stock Updated
                                        </span>

                                    <?php else: ?>

                                        <span class="no-action">No Action</span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="11">
                                <div class="empty-state">
                                    <i class="fas fa-box-open"></i>
                                    <p>No purchase requests found.</p>
                                </div>
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Pharmacy Inventory
        </a>

    </main>

</div>

</body>
</html>
