
<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requireRole(['lab_technician']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['result_message'] ?? '';
unset($_SESSION['result_message']);
$error = '';

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Save or update a pending laboratory result.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $testId = filter_input(INPUT_POST, 'test_id', FILTER_VALIDATE_INT);
    $resultValue = trim($_POST['result_value'] ?? '');
    $resultUnit = trim($_POST['result_unit'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if (!$token || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid security token. Please refresh the page.';
    } elseif (!$requestId || !$testId || $resultValue === '') {
        $error = 'Please select a valid test and enter its result.';
    } else {
        try {
            $pdo->beginTransaction();

            // Confirm that this test belongs to this request.
            // Lock the request while saving.
            $stmt = $pdo->prepare("
                SELECT r.id, r.status, s.status AS sample_status
                FROM lab_requests r
                INNER JOIN lab_samples s ON s.request_id = r.id
                INNER JOIN lab_request_tests rt ON rt.request_id = r.id
                WHERE r.id = ?
                  AND rt.test_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$requestId, $testId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new Exception('Request or requested test not found.');
            }

            if (!in_array($request['status'], ['processing', 'result_entered'], true)) {
                throw new Exception('This request is not ready for result entry.');
            }

            if ($request['sample_status'] !== 'received') {
                throw new Exception('The sample must be received before entering results.');
            }

            // Check whether a result already exists for this request and test.
            $stmt = $pdo->prepare("
                SELECT id, status
                FROM lab_results
                WHERE request_id = ? AND test_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$requestId, $testId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing && $existing['status'] !== 'pending') {
                throw new Exception('This result has already been verified or rejected and cannot be edited here.');
            }

            if ($existing) {
                $stmt = $pdo->prepare("
                    UPDATE lab_results
                    SET result_value = ?,
                        result_unit = ?,
                        remarks = ?,
                        entered_by = ?,
                        entered_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $resultValue,
                    $resultUnit ?: null,
                    $remarks ?: null,
                    $_SESSION['user_id'],
                    $existing['id']
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO lab_results
                    (request_id, test_id, result_value, result_unit,
                     remarks, entered_by, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending')
                ");
                $stmt->execute([
                    $requestId,
                    $testId,
                    $resultValue,
                    $resultUnit ?: null,
                    $remarks ?: null,
                    $_SESSION['user_id']
                ]);
            }

            // Mark this individual requested test as completed.
            $stmt = $pdo->prepare("
                UPDATE lab_request_tests
                SET status = 'completed'
                WHERE request_id = ? AND test_id = ?
            ");
            $stmt->execute([$requestId, $testId]);

            // Check whether every requested test has a result.
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS total_tests,
                       SUM(
                           CASE WHEN EXISTS (
                               SELECT 1
                               FROM lab_results lr
                               WHERE lr.request_id = rt.request_id
                                 AND lr.test_id = rt.test_id
                           ) THEN 1 ELSE 0 END
                       ) AS completed_tests
                FROM lab_request_tests rt
                WHERE rt.request_id = ?
            ");
            $stmt->execute([$requestId]);
            $progress = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                (int)$progress['total_tests'] > 0 &&
                (int)$progress['total_tests'] === (int)$progress['completed_tests']
            ) {
                $stmt = $pdo->prepare("
                    UPDATE lab_requests
                    SET status = 'result_entered'
                    WHERE id = ?
                ");
                $stmt->execute([$requestId]);
            }

            $pdo->commit();

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['result_message'] =
                'Laboratory result saved successfully and is pending doctor verification.';

            header('Location: results.php');
            exit;

        } catch (Exception $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $ex->getMessage();
        }
    }
}

// Fetch requests whose samples have been received.
$stmt = $pdo->query("
    SELECT
        r.id AS request_id,
        r.priority,
        r.status AS request_status,
        r.requested_at,
        p.patient_number,
        patient_user.full_name AS patient_name,
        s.sample_code,
        s.sample_type,
        rt.test_id,
        rt.status AS test_status,
        t.test_name,
        t.category,
        t.unit AS default_unit,
        lr.id AS result_id,
        lr.result_value,
        lr.result_unit,
        lr.remarks,
        lr.status AS result_status
    FROM lab_requests r
    INNER JOIN patients p ON p.id = r.patient_id
    LEFT JOIN users patient_user ON patient_user.id = p.user_id
    INNER JOIN lab_samples s ON s.request_id = r.id
    INNER JOIN lab_request_tests rt ON rt.request_id = r.id
    INNER JOIN lab_tests t ON t.id = rt.test_id
    LEFT JOIN lab_results lr
        ON lr.request_id = r.id AND lr.test_id = t.id
    WHERE s.status = 'received'
      AND r.status IN ('processing', 'result_entered')
    ORDER BY
        CASE WHEN r.priority = 'urgent' THEN 0 ELSE 1 END,
        r.requested_at ASC,
        r.id DESC,
        t.test_name ASC
");

$tests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalTests = count($tests);
$pendingTests = 0;
$enteredTests = 0;

foreach ($tests as $test) {
    if (!$test['result_id']) {
        $pendingTests++;
    } else {
        $enteredTests++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results Entry | MediCare HMS</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #243247;
        }

        .sidebar {
            width: 245px;
            min-height: 100vh;
            background: #10243e;
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            padding: 25px 16px;
        }

        .brand {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 35px;
            padding-left: 10px;
        }

        .brand span {
            color: #36c6a0;
        }

        .sidebar a {
            display: block;
            color: #d8e3ef;
            text-decoration: none;
            padding: 13px 12px;
            margin: 5px 0;
            border-radius: 8px;
            font-size: 14px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #1e3a5c;
            color: white;
        }

        .sidebar i {
            width: 25px;
        }

        .main {
            margin-left: 245px;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        h1 {
            font-size: 26px;
            margin-bottom: 7px;
        }

        .subtitle {
            color: #718096;
            font-size: 14px;
        }

        .user {
            background: white;
            padding: 11px 16px;
            border-radius: 10px;
            font-size: 14px;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .success {
            background: #e7f8ef;
            color: #167345;
        }

        .danger {
            background: #fff0f0;
            color: #b42318;
        }

        .notice {
            background: #fff7e6;
            color: #8a5a00;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            line-height: 1.6;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 12px #1b355008;
        }

        .stat-card p {
            color: #718096;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .stat-card h2 {
            font-size: 27px;
        }

        .request-card {
            background: white;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px #1b355008;
        }

        .request-heading {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 17px;
            padding-bottom: 15px;
            border-bottom: 1px solid #edf0f5;
        }

        .request-heading h2 {
            font-size: 18px;
            margin-bottom: 6px;
        }

        .request-heading p {
            color: #718096;
            font-size: 13px;
            line-height: 1.7;
        }

        .urgent {
            color: #b42318;
            font-weight: bold;
        }

        .normal {
            color: #64748b;
        }

        .test-card {
            border: 1px solid #e7edf4;
            border-radius: 10px;
            padding: 18px;
            margin-top: 14px;
        }

        .test-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .test-title h3 {
            font-size: 16px;
        }

        .category {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }

        .status {
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            background: #fff3d9;
            color: #9a6700;
            text-transform: capitalize;
        }

        .status.pending {
            background: #fff3d9;
            color: #9a6700;
        }

        .status.entered {
            background: #e4f2ff;
            color: #1769aa;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .field {
            margin-bottom: 13px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 7px;
        }

        input, textarea {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #dce3ec;
            border-radius: 7px;
            outline: none;
            font-family: inherit;
            font-size: 13px;
        }

        input:focus, textarea:focus {
            border-color: #087f67;
            box-shadow: 0 0 0 3px #087f6715;
        }

        textarea {
            resize: vertical;
            min-height: 75px;
        }

        .btn {
            border: none;
            border-radius: 7px;
            padding: 11px 16px;
            background: #087f67;
            color: white;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
        }

        .btn:hover {
            background: #066650;
        }

        .btn:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .saved-result {
            background: #f5f9ff;
            border-radius: 8px;
            padding: 14px;
            font-size: 13px;
            line-height: 1.8;
        }

        .empty {
            background: white;
            text-align: center;
            padding: 50px 20px;
            border-radius: 12px;
            color: #718096;
        }

        .empty i {
            display: block;
            font-size: 38px;
            margin-bottom: 14px;
            color: #a5b4c5;
        }

        @media (max-width: 900px) {
            .sidebar {
                width: 200px;
            }

            .main {
                margin-left: 200px;
                padding: 20px;
            }

            .stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 650px) {
            .sidebar {
                position: static;
                width: 100%;
                min-height: auto;
            }

            .main {
                margin-left: 0;
                padding: 17px;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar">
    <div class="brand">
        <i class="fas fa-heartbeat"></i> Medi<span>Care</span>
    </div>

    <a href="dashboard.php">
        <i class="fas fa-chart-line"></i> Dashboard
    </a>

    <a href="requests.php">
        <i class="fas fa-file-medical"></i> Laboratory Requests
    </a>

    <a href="samples.php">
        <i class="fas fa-vial"></i> Sample Receiving
    </a>

    <a href="results.php" class="active">
        <i class="fas fa-flask"></i> Results Entry
    </a>

    <a href="../tests/index.php">
        <i class="fas fa-list"></i> Test Catalog
    </a>

    <a href="../../auth/logout.php">
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
</aside>

<main class="main">

    <div class="topbar">
        <div>
            <h1>Laboratory Results Entry</h1>
            <p class="subtitle">
                Enter actual laboratory results for received samples.
            </p>
        </div>

        <div class="user">
            <i class="fas fa-user-circle"></i>
            Laboratory Technician
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert success">
            <i class="fas fa-check-circle"></i>
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert danger">
            <i class="fas fa-exclamation-circle"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="notice">
        <i class="fas fa-info-circle"></i>
        Enter only results obtained from the actual laboratory examination.
        All saved results remain pending until a Laboratory Doctor verifies them.
    </div>

    <section class="stats">
        <div class="stat-card">
            <p>Total Requested Tests</p>
            <h2><?= $totalTests ?></h2>
        </div>

        <div class="stat-card">
            <p>Waiting for Results</p>
            <h2><?= $pendingTests ?></h2>
        </div>

        <div class="stat-card">
            <p>Results Entered</p>
            <h2><?= $enteredTests ?></h2>
        </div>
    </section>

    <?php if (count($tests) > 0): ?>

        <?php
        $currentRequest = null;
        foreach ($tests as $test):
            if ($currentRequest !== $test['request_id']):
                $currentRequest = $test['request_id'];
        ?>
                <section class="request-card">
                    <div class="request-heading">
                        <div>
                            <h2>
                                Laboratory Request #<?= (int)$test['request_id'] ?>
                            </h2>

                            <p>
                                Patient:
                                <strong><?= e($test['patient_name'] ?: 'Unknown Patient') ?></strong>
                                &nbsp; | &nbsp;
                                Patient Number:
                                <?= e($test['patient_number']) ?>
                                <br>
                                Sample:
                                <strong><?= e($test['sample_code']) ?></strong>
                                (<?= e($test['sample_type']) ?>)
                            </p>
                        </div>

                        <div class="<?= $test['priority'] === 'urgent' ? 'urgent' : 'normal' ?>">
                            <?= e(ucfirst($test['priority'])) ?> Priority
                        </div>
                    </div>

        <?php endif; ?>

                    <div class="test-card">
                        <div class="test-title">
                            <div>
                                <h3><?= e($test['test_name']) ?></h3>
                                <p class="category">
                                    Category: <?= e($test['category']) ?>
                                </p>
                            </div>

                            <?php if ($test['result_id']): ?>
                                <span class="status entered">
                                    Pending Verification
                                </span>
                            <?php else: ?>
                                <span class="status pending">
                                    Awaiting Result
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($test['result_id']): ?>

                            <div class="saved-result">
                                <strong>Entered Result:</strong>
                                <?= e($test['result_value']) ?>
                                <?= e($test['result_unit']) ?>
                                <br>

                                <strong>Remarks:</strong>
                                <?= e($test['remarks'] ?: 'No remarks') ?>
                                <br>

                                <strong>Status:</strong>
                                Pending Doctor Verification
                            </div>

                            <p class="category" style="margin-top:10px;">
                                You may update this result while it is pending verification.
                            </p>

                        <?php endif; ?>

                        <form method="POST" style="margin-top:15px;">
                            <input type="hidden" name="csrf_token"
                                   value="<?= e($_SESSION['csrf_token']) ?>">

                            <input type="hidden" name="request_id"
                                   value="<?= (int)$test['request_id'] ?>">

                            <input type="hidden" name="test_id"
                                   value="<?= (int)$test['test_id'] ?>">

                            <div class="form-grid">
                                <div class="field">
                                    <label>Test Result *</label>
                                    <input
                                        type="text"
                                        name="result_value"
                                        required
                                        maxlength="1000"
                                        value="<?= $test['result_id'] ? e($test['result_value']) : '' ?>"
                                        placeholder="Enter actual laboratory result"
                                    >
                                </div>

                                <div class="field">
                                    <label>Result Unit</label>
                                    <input
                                        type="text"
                                        name="result_unit"
                                        maxlength="50"
                                        value="<?= $test['result_id']
                                            ? e($test['result_unit'])
                                            : e($test['default_unit']) ?>"
                                        placeholder="Unit of measurement"
                                    >
                                </div>

                                <div class="field full">
                                    <label>Remarks</label>
                                    <textarea
                                        name="remarks"
                                        maxlength="2000"
                                        placeholder="Optional laboratory remarks..."
                                    ><?= $test['result_id'] ? e($test['remarks']) : '' ?></textarea>
                                </div>
                            </div>

                            <button type="submit" class="btn">
                                <i class="fas fa-save"></i>
                                <?= $test['result_id'] ? 'Update Pending Result' : 'Save Result' ?>
                            </button>
                        </form>
                    </div>

        <?php
            // Close each request card when the next request starts.
            $nextTest = $tests[array_search($test, $tests) + 1] ?? null;

            if (!$nextTest || $nextTest['request_id'] !== $test['request_id']):
        ?>
                </section>
        <?php
            endif;
        endforeach;
        ?>

    <?php else: ?>

        <div class="empty">
            <i class="fas fa-flask"></i>
            <h3>No Tests Waiting for Results</h3>
            <p>
                Tests will appear here after their samples have been received.
            </p>
        </div>

    <?php endif; ?>

</main>

</body>
</html>
