
<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

requireLogin();
requireRole(['admin']);

$success = '';
$error = '';

// Fetch medicines
$stmt = $pdo->query("
    SELECT id, medicine_name, category, quantity, unit_price
    FROM medicines
    ORDER BY medicine_name ASC
");
$medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $medicineId = (int)($_POST['medicine_id'] ?? 0);
    $requestedQuantity = (int)($_POST['requested_quantity'] ?? 0);
    $unitPrice = (float)($_POST['unit_price'] ?? 0);
    $supplierName = trim($_POST['supplier_name'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($medicineId <= 0 || $requestedQuantity <= 0 || $unitPrice < 0) {
        $error = 'Please enter valid purchase request details.';
    } else {
        try {
            $check = $pdo->prepare(
                "SELECT id FROM medicines WHERE id = ?"
            );
            $check->execute([$medicineId]);

            if (!$check->fetch()) {
                $error = 'Selected medicine was not found.';
            } else {
                $totalCost = $requestedQuantity * $unitPrice;

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
                    $supplierName ?: null,
                    $notes ?: null,
                    $_SESSION['user_id']
                ]);

                $success = 'Purchase request created successfully!';
            }
        } catch (PDOException $e) {
            $error = 'Database error. Please try again.';
        }
    }
}

// Fetch purchase requests
$stmt = $pdo->query("
    SELECT
        pr.*,
        m.medicine_name,
        u.full_name AS requested_by_name
    FROM purchase_requests pr
    JOIN medicines m ON pr.medicine_id = m.id
    JOIN users u ON pr.requested_by = u.id
    ORDER BY pr.created_at DESC
");
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medicine Purchase Requests</title>
    <link rel="stylesheet" href="../assets/css/admin.css">

    <style>
        .purchase-container {
            padding: 25px;
        }

        .purchase-card {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 12px rgba(0,0,0,.06);
        }

        .purchase-card h2 {
            margin-bottom: 20px;
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

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 7px;
            width: 100%;
        }

        .form-group textarea {
            min-height: 90px;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .submit-btn {
            background: #176b52;
            color: white;
            border: none;
            border-radius: 7px;
            padding: 13px 22px;
            cursor: pointer;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .purchase-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        .purchase-table th,
        .purchase-table td {
            padding: 13px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        .purchase-table th {
            background: #f5f7f6;
        }

        .status {
            padding: 5px 10px;
            border-radius: 20px;
            background: #fff1d6;
            color: #8a5700;
            font-size: 12px;
        }

        .alert {
            padding: 13px;
            margin-bottom: 18px;
            border-radius: 7px;
        }

        .success {
            background: #e5f7ed;
            color: #176b52;
        }

        .error {
            background: #ffe9e9;
            color: #b42318;
        }

        @media(max-width: 700px) {
            .purchase-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="purchase-container">

    <h1>Medicine Purchase Management</h1>
    <p>Create and manage medicine purchase requests.</p>

    <?php if ($success): ?>
        <div class="alert success">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="purchase-card">
        <h2>Create Purchase Request</h2>

        <form method="POST" class="purchase-form">

            <div class="form-group">
                <label>Medicine</label>
                <select name="medicine_id" required>
                    <option value="">Select Medicine</option>

                    <?php foreach ($medicines as $medicine): ?>
                        <option value="<?= (int)$medicine['id'] ?>">
                            <?= htmlspecialchars($medicine['medicine_name']) ?>
                            (Stock: <?= (int)$medicine['quantity'] ?>)
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="form-group">
                <label>Requested Quantity</label>
                <input
                    type="number"
                    name="requested_quantity"
                    min="1"
                    required
                >
            </div>

            <div class="form-group">
                <label>Unit Price</label>
                <input
                    type="number"
                    name="unit_price"
                    min="0"
                    step="0.01"
                    value="0"
                    required
                >
            </div>

            <div class="form-group">
                <label>Supplier Name</label>
                <input
                    type="text"
                    name="supplier_name"
                    placeholder="Enter supplier name"
                >
            </div>

            <div class="form-group full-width">
                <label>Notes</label>
                <textarea
                    name="notes"
                    placeholder="Enter additional information..."
                ></textarea>
            </div>

            <div class="full-width">
                <button type="submit" class="submit-btn">
                    Create Purchase Request
                </button>
            </div>

        </form>
    </div>

    <div class="purchase-card">
        <h2>Purchase Request History</h2>

        <div class="table-responsive">
            <table class="purchase-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Medicine</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Total Cost</th>
                        <th>Supplier</th>
                        <th>Status</th>
                        <th>Requested By</th>
                        <th>Date</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (count($requests) > 0): ?>

                    <?php foreach ($requests as $request): ?>
                        <tr>
                            <td><?= (int)$request['id'] ?></td>

                            <td>
                                <?= htmlspecialchars($request['medicine_name']) ?>
                            </td>

                            <td>
                                <?= (int)$request['requested_quantity'] ?>
                            </td>

                            <td>
                                <?= number_format((float)$request['unit_price'], 2) ?>
                            </td>

                            <td>
                                <?= number_format((float)$request['total_cost'], 2) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($request['supplier_name'] ?? '-') ?>
                            </td>

                            <td>
                                <span class="status">
                                    <?= htmlspecialchars(ucfirst($request['status'])) ?>
                                </span>
                            </td>

                            <td>
                                <?= htmlspecialchars($request['requested_by_name']) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($request['created_at']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="9">
                            No purchase requests found.
                        </td>
                    </tr>

                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <a href="index.php">← Back to Pharmacy</a>

</div>

</body>
</html>
