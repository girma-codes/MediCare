
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

        .container {
            max-width: 850px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .header {
            margin-bottom: 22px;
        }

        .header h1 {
            font-size: 27px;
            margin-bottom: 8px;
        }

        .header p {
            color: #718096;
            font-size: 14px;
        }

        .card {
            background: white;
            padding: 28px;
            border-radius: 13px;
            border: 1px solid #edf0f5;
            box-shadow: 0 4px 18px rgba(0,0,0,.04);
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
        }

        input {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid #dce3ec;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }

        input:focus {
            border-color: #2874c7;
            box-shadow: 0 0 0 3px rgba(40,116,199,.1);
        }

        .hint {
            display: block;
            color: #8290a2;
            font-size: 12px;
            margin-top: 6px;
        }

        .errors {
            background: #fff0ef;
            border: 1px solid #ffd1cd;
            color: #b42318;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .errors ul {
            padding-left: 20px;
            margin-top: 7px;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 5px;
        }

        .btn {
            border: none;
            border-radius: 8px;
            padding: 12px 19px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }

        .save {
            background: #2874c7;
            color: white;
        }

        .cancel {
            background: #edf1f6;
            color: #34445a;
        }

        @media(max-width: 600px) {
            .form-grid {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .form-group.full {
                grid-column: auto;
            }

            .card {
                padding: 19px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h1><i class="fa-solid fa-circle-plus"></i> Add New Medicine</h1>
        <p>Register a medicine in the pharmacy inventory.</p>
    </div>

    <div class="card">

        <?php if (!empty($errors)): ?>
            <div class="errors">
                <strong>Please correct the following:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

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
                    <span class="hint">Leave blank if not applicable.</span>
                </div>

            </div>

            <div class="actions">
                <button type="submit" class="btn save">
                    <i class="fa-solid fa-floppy-disk"></i>
                    Save Medicine
                </button>

                <a href="index.php" class="btn cancel">
                    Cancel
                </a>
            </div>

        </form>

    </div>
</div>

</body>
</html>
