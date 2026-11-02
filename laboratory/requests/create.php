
<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requireRole(['doctor']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

// Get the logged-in doctor's database ID
$stmt = $pdo->prepare(
    "SELECT id FROM doctors WHERE user_id = ? LIMIT 1"
);
$stmt->execute([$_SESSION['user_id']]);
$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    exit('Doctor profile not found. Please contact the administrator.');
}

$doctorId = (int)$doctor['id'];

// Get registered patients
$patients = $pdo->query("
    SELECT p.id, p.patient_number, u.full_name
    FROM patients p
    LEFT JOIN users u ON u.id = p.user_id
    ORDER BY u.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get active laboratory tests
$tests = $pdo->query("
    SELECT id, test_name, category, price, unit
    FROM lab_tests
    WHERE status = 'active'
    ORDER BY test_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Process submitted request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $errors[] = 'Invalid request. Please refresh the page.';
    }

    $patientId = filter_input(INPUT_POST, 'patient_id', FILTER_VALIDATE_INT);
    $priority = $_POST['priority'] ?? 'normal';

    $selectedTests = $_POST['tests'] ?? [];

    if (!is_array($selectedTests)) {
        $selectedTests = [];
    }

    $selectedTests = array_values(array_unique(
        array_filter(
            array_map('intval', $selectedTests),
            fn($id) => $id > 0
        )
    ));

    if (!$patientId) {
        $errors[] = 'Please select a patient.';
    }

    if (!in_array($priority, ['normal', 'urgent'], true)) {
        $errors[] = 'Invalid priority selection.';
    }

    if (empty($selectedTests)) {
        $errors[] = 'Please select at least one laboratory test.';
    }

    // Confirm patient exists
    if ($patientId) {
        $checkPatient = $pdo->prepare(
            "SELECT id FROM patients WHERE id = ?"
        );
        $checkPatient->execute([$patientId]);

        if (!$checkPatient->fetch()) {
            $errors[] = 'Selected patient does not exist.';
        }
    }

    // Confirm every selected test is active
    if (!empty($selectedTests)) {
        $placeholders = implode(',', array_fill(0, count($selectedTests), '?'));

        $checkTests = $pdo->prepare("
            SELECT id FROM lab_tests
            WHERE status = 'active'
            AND id IN ($placeholders)
        ");

        $checkTests->execute($selectedTests);
        $validTestIds = array_map(
            'intval',
            $checkTests->fetchAll(PDO::FETCH_COLUMN)
        );

        sort($validTestIds);
        $compareTestIds = $selectedTests;
        sort($compareTestIds);

        if ($validTestIds !== $compareTestIds) {
            $errors[] = 'One or more selected tests are invalid or inactive.';
        }
    }

    // Save request and selected tests together
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $insertRequest = $pdo->prepare("
                INSERT INTO lab_requests
                    (patient_id, doctor_id, priority, status)
                VALUES (?, ?, ?, 'requested')
            ");

            $insertRequest->execute([
                $patientId,
                $doctorId,
                $priority
            ]);

            $requestId = (int)$pdo->lastInsertId();

            $insertTest = $pdo->prepare("
                INSERT INTO lab_request_tests
                    (request_id, test_id)
                VALUES (?, ?)
            ");

            foreach ($selectedTests as $testId) {
                $insertTest->execute([$requestId, $testId]);
            }

            $pdo->commit();

            $_SESSION['success_message'] =
                "Laboratory request #$requestId created successfully.";

            header("Location: index.php");
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());
            $errors[] = 'Unable to save the request. Please try again.';
        }
    }
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Lab Request | MediCare HMS</title>

    <link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 30px;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #243247;
        }

        .container {
            max-width: 1050px;
            margin: auto;
        }

        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        h1 { margin: 0 0 8px; font-size: 27px; }
        .subtitle { color: #718096; margin: 0; }

        .card {
            background: white;
            border-radius: 13px;
            padding: 25px;
            margin-bottom: 22px;
            box-shadow: 0 4px 18px rgba(0,0,0,.05);
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 9px;
        }

        select {
            width: 100%;
            padding: 13px;
            border: 1px solid #dce3ec;
            border-radius: 8px;
            background: white;
            font-size: 15px;
            margin-bottom: 22px;
        }

        .test-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 14px;
        }

        .test {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            border: 1px solid #e1e7ef;
            border-radius: 10px;
            padding: 17px;
            cursor: pointer;
        }

        .test:hover {
            border-color: #2563eb;
            background: #f8faff;
        }

        .test input {
            width: 18px;
            height: 18px;
            margin-top: 3px;
        }

        .test strong { display: block; margin-bottom: 6px; }
        .test small { color: #718096; line-height: 1.6; }

        .price {
            display: block;
            margin-top: 8px;
            font-weight: bold;
            color: #087f5b;
        }

        .btn {
            display: inline-block;
            padding: 13px 20px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
        }

        .primary { background: #2563eb; color: white; }
        .secondary { background: #e9eef5; color: #243247; }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .error {
            background: #fff0f0;
            color: #b42318;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        @media(max-width: 600px) {
            body { padding: 15px; }
            .top { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>

<body>
<div class="container">

    <div class="top">
        <div>
            <h1><i class="fa-solid fa-flask"></i> Create Lab Request</h1>
            <p class="subtitle">Order laboratory tests for a patient.</p>
        </div>

        <a class="btn secondary" href="index.php">
            <i class="fa-solid fa-arrow-left"></i> Back to Requests
        </a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= e($error) ?>
        </div>
    <?php endforeach; ?>

    <form method="POST">

        <input type="hidden" name="csrf_token"
               value="<?= e($_SESSION['csrf_token']) ?>">

        <div class="card">
            <h2>1. Patient Information</h2>

            <label for="patient_id">Select Patient</label>

            <select name="patient_id" id="patient_id" required>
                <option value="">-- Select Patient --</option>

                <?php foreach ($patients as $patient): ?>
                    <option
                        value="<?= (int)$patient['id'] ?>"
                        <?= (isset($patientId) && $patientId == $patient['id']) ? 'selected' : '' ?>
                    >
                        <?= e($patient['full_name'] ?: 'Unknown Patient') ?>
                        — <?= e($patient['patient_number']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="card">
            <h2>2. Select Laboratory Tests</h2>
            <p class="subtitle">You can select more than one test.</p>

            <br>

            <div class="test-grid">

                <?php foreach ($tests as $test): ?>
                    <label class="test">

                        <input
                            type="checkbox"
                            name="tests[]"
                            value="<?= (int)$test['id'] ?>"
                            <?= (
                                isset($selectedTests) &&
                                in_array((int)$test['id'], $selectedTests, true)
                            ) ? 'checked' : '' ?>
                        >

                        <span>
                            <strong><?= e($test['test_name']) ?></strong>

                            <small>
                                Category: <?= e($test['category']) ?><br>
                                Unit: <?= e($test['unit'] ?: 'Not specified') ?>
                            </small>

                            <span class="price">
                                <?= number_format((float)$test['price'], 2) ?> ETB
                            </span>
                        </span>

                    </label>
                <?php endforeach; ?>

                <?php if (empty($tests)): ?>
                    <p>No active laboratory tests are available.</p>
                <?php endif; ?>

            </div>
        </div>

        <div class="card">
            <h2>3. Request Priority</h2>

            <label for="priority">Select Priority</label>

            <select name="priority" id="priority">
                <option value="normal"
                    <?= (!isset($priority) || $priority === 'normal') ? 'selected' : '' ?>>
                    Normal
                </option>

                <option value="urgent"
                    <?= (isset($priority) && $priority === 'urgent') ? 'selected' : '' ?>>
                    Urgent
                </option>
            </select>

            <div class="actions">
                <button type="submit" class="btn primary">
                    <i class="fa-solid fa-paper-plane"></i>
                    Submit Lab Request
                </button>

                <a href="index.php" class="btn secondary">Cancel</a>
            </div>
        </div>

    </form>
</div>
</body>
</html>
