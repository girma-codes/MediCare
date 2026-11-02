
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$medicineName = '';
$category = '';
$quantity = '';
$unitPrice = '';
$expiryDate = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals(
        $_SESSION['csrf_token'],
        $_POST['csrf_token'] ?? ''
    )) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    }

    $medicineName = trim($_POST['medicine_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $quantity = trim($_POST['quantity'] ?? '');
    $unitPrice = trim($_POST['unit_price'] ?? '');
    $expiryDate = trim($_POST['expiry_date'] ?? '');

    if ($medicineName === '' || strlen($medicineName) > 150) {
        $errors[] = 'Medicine name is required (maximum 150 characters).';
    }

    if ($category === '' || strlen($category) > 100) {
        $errors[] = 'Category is required (maximum 100 characters).';
    }

    if (
        $quantity === '' ||
        filter_var($quantity, FILTER_VALIDATE_INT) === false ||
        (int)$quantity < 0
    ) {
        $errors[] = 'Quantity must be a non-negative whole number.';
    }

    if (
        $unitPrice === '' ||
        !is_numeric($unitPrice) ||
        (float)$unitPrice < 0
    ) {
        $errors[] = 'Enter a valid non-negative unit price.';
    }

    if ($expiryDate !== '') {
        $date = DateTime::createFromFormat('Y-m-d', $expiryDate);

        if (!$date || $date->format('Y-m-d') !== $expiryDate) {
            $errors[] = 'Enter a valid expiry date.';
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO medicines
                    (medicine_name, category, quantity, unit_price, expiry_date)
                VALUES
                    (:medicine_name, :category, :quantity, :unit_price, :expiry_date)
            ");

            $stmt->execute([
                ':medicine_name' => $medicineName,
                ':category' => $category,
                ':quantity' => (int)$quantity,
                ':unit_price' => (float)$unitPrice,
                ':expiry_date' => $expiryDate !== '' ? $expiryDate : null
            ]);

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: index.php?added=1');
            exit;

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = 'Unable to save the medicine. Please check the database.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Medicine | MediCare HMS</title>

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

        /* Main Layout */
        .pharmacy-layout {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 250px;
            flex-shrink: 0;
            background: #102a43;
            color: white;
            padding: 25px 16px;
        }

        .brand {
            font-size: 23px;
            font-weight: 800;
            padding: 0 12px 28px;
            border-bottom: 1px solid #ffffff20;
        }

        .brand span {
            color: #38bdf8;
        }

        .sidebar h4 {
            color: #9fb3c8;
            font-size: 11px;
            letter-spacing: 1.5px;
            margin: 28px 12px 12px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #d9e6f2;
            text-decoration: none;
            padding: 12px;
            margin: 5px 0;
            border-radius: 8px;
            font-size: 14px;
            transition: .2s;
        }

        .sidebar a i {
            width: 19px;
            text-align: center;
            font-size: 15px;
        }

        .sidebar a:hover {
            background: #1d4e89;
            color: white;
        }

        .sidebar a.active {
            background: #2563eb;
            color: white;
            font-weight: 700;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            min-width: 0;
            padding: 28px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 28px;
        }

        .topbar h1 {
            font-size: 27px;
            margin-bottom: 7px;
        }

        .topbar p {
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
        }

        /* Form Container */
        .form-container {
            max-width: 950px;
        }

        .form-card {
            background: white;
            padding: 30px;
            border-radius: 13px;
            border: 1px solid #edf0f5;
            box-shadow: 0 4px 18px rgba(0,0,0,.04);
        }

        .form-card-header {
            margin-bottom: 25px;
            padding-bottom: 18px;
            border-bottom: 1px solid #edf1f5;
        }

        .form-card-header h2 {
            font-size: 19px;
            margin-bottom: 7px;
            color: #102a43;
        }

        .form-card-header p {
            color: #718096;
            font-size: 13px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #34445a;
        }

        input {
            width: 100%;
            padding: 13px;
            border: 1px solid #dce3ec;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            background: #fff;
            transition: .2s;
        }

        input:focus {
            border-color: #2874c7;
            box-shadow: 0 0 0 3px rgba(40,116,199,.1);
        }

        .hint {
            display: block;
            color: #8290a2;
            font-size: 12px;
            margin-top: 7px;
        }

        /* Error Messages */
        .errors {
            background: #fff0ef;
            border: 1px solid #ffd1cd;
            color: #b42318;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 22px;
        }

        .errors ul {
            padding-left: 20px;
            margin-top: 7px;
        }

        .errors li {
            margin-bottom: 5px;
            font-size: 13px;
        }

        /* Buttons */
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 8px;
            padding-top: 20px;
            border-top: 1px solid #edf1f5;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            transition: .2s;
        }

        .save {
            background: #2563eb;
            color: white;
        }

        .save:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }

        .cancel {
            background: #edf1f6;
            color: #34445a;
        }

        .cancel:hover {
            background: #dce3ec;
        }

        .footer {
            text-align: center;
            padding: 25px 10px;
            color: #94a3b8;
            font-size: 12px;
        }

        /* Responsive */
        @media(max-width: 900px) {
            .sidebar {
                width: 220px;
            }

            .main-content {
                padding: 22px;
            }
        }

        @media(max-width: 750px) {
            .sidebar {
                width: 65px;
                padding: 20px 7px;
            }

            .brand {
                font-size: 17px;
                padding: 5px 3px 20px;
                text-align: center;
            }

            .sidebar h4 {
                display: none;
            }

            .sidebar a {
                justify-content: center;
                padding: 14px 4px;
                font-size: 0;
            }

            .sidebar a i {
                font-size: 18px;
            }

            .main-content {
                padding: 17px;
            }
        }

        @media(max-width: 600px) {
            .form-grid {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .form-group.full {
                grid-column: auto;
            }

            .form-card {
                padding: 19px;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<div class="pharmacy-layout">

    <!-- Sidebar -->
    <aside class="sidebar">

        <div class="brand">
            Medi<span>Care</span>
        </div>

        <h4>PHARMACY MENU</h4>

        <a href="dashboard.php">
            <i class="fa-solid fa-house"></i>
            Dashboard
        </a>

        <a href="index.php">
            <i class="fa-solid fa-capsules"></i>
            Medicine Inventory
        </a>

        <a href="add.php" class="active">
            <i class="fa-solid fa-circle-plus"></i>
            Add Medicine
        </a>

        <a href="stock.php">
            <i class="fa-solid fa-boxes-stacked"></i>
            Stock Management
        </a>

        <a href="expiry.php">
            <i class="fa-solid fa-calendar-xmark"></i>
            Expiry Management
        </a>

        <a href="low_stock.php">
            <i class="fa-solid fa-triangle-exclamation"></i>
            Low Stock Alerts
        </a>

        <a href="purchase_request.php">
            <i class="fa-solid fa-cart-shopping"></i>
            Purchase Management
        </a>

        <a href="reports.php">
            <i class="fa-solid fa-chart-column"></i>
            Inventory Reports
        </a>

        <h4>MAIN SYSTEM</h4>

        <a href="../admin/dashboard.php">
            <i class="fa-solid fa-arrow-left"></i>
            Admin Dashboard
        </a>

    </aside>

    <!-- Main Content -->
    <main class="main-content">

        <div class="topbar">
            <div>
                <h1>
                    <i class="fa-solid fa-circle-plus" style="color:#2563eb;"></i>
                    Add New Medicine
                </h1>
                <p>Register a new medicine in the pharmacy inventory.</p>
            </div>

            <div class="date-label">
                <i class="fa-regular fa-calendar"></i>
                <?= date('F d, Y') ?>
            </div>
        </div>

        <div class="form-container">

            <div class="form-card">

                <div class="form-card-header">
                    <h2>Medicine Information</h2>
                    <p>Enter the medicine details below to add it to your inventory.</p>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="errors">
                        <strong>
                            <i class="fa-solid fa-circle-exclamation"></i>
                            Please correct the following:
                        </strong>

                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['csrf_token']) ?>"
                    >

                    <div class="form-grid">

                        <div class="form-group full">
                            <label for="medicine_name">Medicine Name *</label>

                            <input
                                type="text"
                                id="medicine_name"
                                name="medicine_name"
                                maxlength="150"
                                value="<?= e($medicineName) ?>"
                                placeholder="e.g. Paracetamol 500mg"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="category">Category *</label>

                            <input
                                type="text"
                                id="category"
                                name="category"
                                maxlength="100"
                                value="<?= e($category) ?>"
                                placeholder="e.g. Painkiller"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="quantity">Quantity *</label>

                            <input
                                type="number"
                                id="quantity"
                                name="quantity"
                                min="0"
                                step="1"
                                value="<?= e($quantity) ?>"
                                placeholder="e.g. 100"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="unit_price">Unit Price *</label>

                            <input
                                type="number"
                                id="unit_price"
                                name="unit_price"
                                min="0"
                                step="0.01"
                                value="<?= e($unitPrice) ?>"
                                placeholder="e.g. 5.50"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="expiry_date">Expiry Date</label>

                            <input
                                type="date"
                                id="expiry_date"
                                name="expiry_date"
                                value="<?= e($expiryDate) ?>"
                            >

                            <span class="hint">
                                Leave blank if not applicable.
                            </span>
                        </div>

                    </div>

                    <div class="actions">

                        <button type="submit" class="btn save">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save Medicine
                        </button>

                        <a href="index.php" class="btn cancel">
                            <i class="fa-solid fa-xmark"></i>
                            Cancel
                        </a>

                    </div>

                </form>

            </div>

        </div>

        <div class="footer">
            © <?= date('Y') ?> MediCare Hospital Management System.
            Pharmacy Management Module.
        </div>

    </main>

</div>

</body>
</html>
