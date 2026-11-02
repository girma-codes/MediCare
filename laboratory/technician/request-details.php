
<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requireRole(['lab_technician']);

$fullName = $_SESSION['full_name'] ?? 'Laboratory Technician';
$technicianId = (int) $_SESSION['user_id'];

$errorMessage = '';
$successMessage = '';

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$requestId || $requestId < 1) {
    http_response_code(400);
    exit('Invalid laboratory request ID.');
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function statusLabel($status): string
{
    $labels = [
        'requested' => 'New Request',
        'sample_collected' => 'Sample Collected',
        'processing' => 'Processing',
        'result_entered' => 'Result Entered',
        'verified' => 'Verified',
        'cancelled' => 'Cancelled'
    ];

    return $labels[$status] ?? ucfirst($status);
}


// =========================================
// HANDLE SAMPLE COLLECTION
// =========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $errorMessage = 'Invalid request. Please refresh the page.';

    } else {

        $sampleType = trim($_POST['sample_type'] ?? '');

        $allowedSampleTypes = [
            'Blood',
            'Urine',
            'Stool',
            'Sputum',
            'Swab',
            'Other'
        ];

        if (!in_array($sampleType, $allowedSampleTypes, true)) {

            $errorMessage = 'Please select a valid sample type.';

        } else {

            try {

                $pdo->beginTransaction();

                // Lock the request to prevent duplicate collection.
                $requestStmt = $pdo->prepare("
                    SELECT id, status
                    FROM lab_requests
                    WHERE id = :id
                    FOR UPDATE
                ");

                $requestStmt->execute([
                    'id' => $requestId
                ]);

                $lockedRequest = $requestStmt->fetch(PDO::FETCH_ASSOC);

                if (!$lockedRequest) {

                    throw new RuntimeException(
                        'Laboratory request not found.'
                    );

                }

                if ($lockedRequest['status'] !== 'requested') {

                    throw new RuntimeException(
                        'This request is no longer waiting for sample collection.'
                    );

                }

                // Check whether a sample already exists.
                $existingStmt = $pdo->prepare("
                    SELECT id
                    FROM lab_samples
                    WHERE request_id = :request_id
                    LIMIT 1
                ");

                $existingStmt->execute([
                    'request_id' => $requestId
                ]);

                if ($existingStmt->fetch()) {

                    throw new RuntimeException(
                        'A sample has already been registered for this request.'
                    );

                }

                // Generate unique sample code.
                $sampleCode = 'SMP-' .
                    date('Ymd') . '-' .
                    strtoupper(bin2hex(random_bytes(4)));

                // Register collected sample.
                $insertSample = $pdo->prepare("
                    INSERT INTO lab_samples (
                        request_id,
                        sample_code,
                        sample_type,
                        collected_by,
                        collected_at,
                        status
                    )
                    VALUES (
                        :request_id,
                        :sample_code,
                        :sample_type,
                        :collected_by,
                        NOW(),
                        'collected'
                    )
                ");

                $insertSample->execute([
                    'request_id' => $requestId,
                    'sample_code' => $sampleCode,
                    'sample_type' => $sampleType,
                    'collected_by' => $technicianId
                ]);

                // Update request status.
                $updateRequest = $pdo->prepare("
                    UPDATE lab_requests
                    SET status = 'sample_collected'
                    WHERE id = :id
                      AND status = 'requested'
                ");

                $updateRequest->execute([
                    'id' => $requestId
                ]);

                if ($updateRequest->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Unable to update laboratory request status.'
                    );
                }

                $pdo->commit();

                // Regenerate CSRF token after successful submission.
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $csrfToken = $_SESSION['csrf_token'];

                $successMessage =
                    'Sample collected successfully. Sample Code: ' .
                    $sampleCode;

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Sample Collection Error: ' . $e->getMessage()
                );

                if ($e instanceof RuntimeException) {
                    $errorMessage = $e->getMessage();
                } else {
                    $errorMessage =
                        'Unable to register sample. Please check the system and try again.';
                }
            }
        }
    }
}


// =========================================
// FETCH REQUEST DETAILS
// =========================================

try {

    $detailsStmt = $pdo->prepare("
        SELECT
            lr.id,
            lr.priority,
            lr.status,
            lr.requested_at,

            p.id AS patient_id,
            p.patient_number,
            p.date_of_birth,
            p.gender,
            p.phone AS patient_phone,
            p.address AS patient_address,
            p.emergency_contact,

            patient_user.full_name AS patient_name,

            doctor_user.full_name AS doctor_name,
            doctor_user.email AS doctor_email,

            lr.medical_record_id

        FROM lab_requests lr

        INNER JOIN patients p
            ON p.id = lr.patient_id

        LEFT JOIN users patient_user
            ON patient_user.id = p.user_id

        INNER JOIN doctors d
            ON d.id = lr.doctor_id

        INNER JOIN users doctor_user
            ON doctor_user.id = d.user_id

        WHERE lr.id = :id
        LIMIT 1
    ");

    $detailsStmt->execute([
        'id' => $requestId
    ]);

    $request = $detailsStmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        http_response_code(404);
        exit('Laboratory request not found.');
    }


    // Fetch requested tests.
    $testsStmt = $pdo->prepare("
        SELECT
            lt.id,
            lt.test_name,
            lt.category,
            lt.description,
            lt.normal_range,
            lt.unit,
            lrt.status AS request_test_status

        FROM lab_request_tests lrt

        INNER JOIN lab_tests lt
            ON lt.id = lrt.test_id

        WHERE lrt.request_id = :request_id

        ORDER BY lt.test_name
    ");

    $testsStmt->execute([
        'request_id' => $requestId
    ]);

    $tests = $testsStmt->fetchAll(PDO::FETCH_ASSOC);


    // Fetch registered sample, if available.
    $sampleStmt = $pdo->prepare("
        SELECT
            ls.id,
            ls.sample_code,
            ls.sample_type,
            ls.collected_at,
            ls.received_at,
            ls.status,
            collector.full_name AS collector_name

        FROM lab_samples ls

        LEFT JOIN users collector
            ON collector.id = ls.collected_by

        WHERE ls.request_id = :request_id

        ORDER BY ls.id DESC
        LIMIT 1
    ");

    $sampleStmt->execute([
        'request_id' => $requestId
    ]);

    $sample = $sampleStmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log(
        'Request Details Error: ' . $e->getMessage()
    );

    http_response_code(500);

    exit('Unable to load laboratory request details.');
}

$canCollect =
    $request['status'] === 'requested' &&
    !$sample;

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Request Details | MediCare HMS</title>

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
            color: #1e293b;
        }

        .sidebar {
            width: 250px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: #102a43;
            padding: 25px 18px;
            color: white;
        }

        .brand {
            font-size: 23px;
            font-weight: bold;
            margin-bottom: 35px;
        }

        .brand span {
            color: #38bdf8;
        }

        .menu-title {
            font-size: 11px;
            color: #94a3b8;
            margin: 20px 10px 12px;
            text-transform: uppercase;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #dbeafe;
            text-decoration: none;
            padding: 13px 12px;
            border-radius: 8px;
            margin-bottom: 6px;
            font-size: 14px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #1e4968;
            color: white;
        }

        .sidebar a i {
            width: 18px;
        }

        .logout {
            margin-top: 30px;
            border-top: 1px solid #34506a;
            padding-top: 15px;
        }

        .main {
            margin-left: 250px;
            padding: 30px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        .topbar h1 {
            font-size: 26px;
            margin-bottom: 7px;
        }

        .subtitle {
            font-size: 14px;
            color: #64748b;
        }

        .user-info {
            background: white;
            padding: 13px 17px;
            border-radius: 9px;
            font-size: 13px;
        }

        .user-info i {
            color: #2563eb;
            margin-right: 7px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #2563eb;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 20px;
        }

        .alert {
            padding: 15px 18px;
            border-radius: 9px;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .alert.success {
            background: #dcfce7;
            color: #166534;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .card {
            background: white;
            border: 1px solid #e8edf4;
            border-radius: 12px;
            padding: 23px;
            margin-bottom: 20px;
            box-shadow: 0 3px 12px rgba(0,0,0,.03);
        }

        .card h2 {
            font-size: 18px;
            margin-bottom: 20px;
        }

        .card h2 i {
            color: #2563eb;
            margin-right: 8px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid #edf1f5;
            font-size: 13px;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #64748b;
        }

        .info-value {
            font-weight: 600;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }

        .urgent {
            background: #fee2e2;
            color: #b91c1c;
        }

        .normal {
            background: #e2e8f0;
            color: #475569;
        }

        .status {
            background: #fef3c7;
            color: #92400e;
        }

        .collected {
            background: #dcfce7;
            color: #166534;
        }

        .test-item {
            border: 1px solid #e8edf4;
            padding: 15px;
            border-radius: 9px;
            margin-bottom: 12px;
        }

        .test-item h3 {
            font-size: 14px;
            margin-bottom: 7px;
        }

        .test-item p {
            font-size: 12px;
            color: #64748b;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 9px;
        }

        select {
            width: 100%;
            padding: 13px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            background: white;
        }

        select:focus {
            outline: none;
            border-color: #2563eb;
        }

        .help-text {
            font-size: 12px;
            color: #64748b;
            margin-top: 8px;
            line-height: 1.5;
        }

        .submit-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            padding: 14px;
            background: #2563eb;
            border: none;
            color: white;
            border-radius: 8px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
        }

        .submit-btn:hover {
            background: #1d4ed8;
        }

        .submit-btn:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        .sample-code {
            font-size: 23px;
            color: #047857;
            font-weight: bold;
            letter-spacing: 1px;
            margin: 10px 0;
            overflow-wrap: anywhere;
        }

        .empty {
            text-align: center;
            color: #64748b;
            padding: 20px;
        }

        @media(max-width: 850px) {

            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
            }

            .main {
                margin-left: 0;
                padding: 18px;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }
        }

    </style>

</head>

<body>


<!-- SIDEBAR -->

<aside class="sidebar">

    <div class="brand">
        <i class="fa-solid fa-hospital"></i>
        Medi<span>Care</span>
    </div>

    <div class="menu-title">Laboratory</div>

    <a href="dashboard.php">
        <i class="fa-solid fa-chart-line"></i>
        Dashboard
    </a>

    <a href="requests.php" class="active">
        <i class="fa-solid fa-file-medical"></i>
        Lab Requests
    </a>

    <a href="../samples/index.php">
        <i class="fa-solid fa-vial"></i>
        Sample Collection
    </a>

    <a href="../results/index.php">
        <i class="fa-solid fa-flask"></i>
        Enter Results
    </a>

    <a href="../tests/index.php">
        <i class="fa-solid fa-list-check"></i>
        Test Catalog
    </a>

    <a href="../reports/index.php">
        <i class="fa-solid fa-chart-column"></i>
        Reports
    </a>

    <div class="logout">
        <a href="../../logout.php">
            <i class="fa-solid fa-right-from-bracket"></i>
            Logout
        </a>
    </div>

</aside>


<!-- MAIN CONTENT -->

<main class="main">

    <header class="topbar">

        <div>
            <h1>Request Details</h1>

            <p class="subtitle">
                Review patient information and manage sample collection.
            </p>
        </div>

        <div class="user-info">
            <i class="fa-solid fa-user-circle"></i>
            <?= e($fullName) ?>
        </div>

    </header>


    <a href="requests.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i>
        Back to Laboratory Requests
    </a>


    <?php if ($successMessage): ?>

        <div class="alert success">
            <i class="fa-solid fa-circle-check"></i>
            <?= e($successMessage) ?>
        </div>

    <?php endif; ?>


    <?php if ($errorMessage): ?>

        <div class="alert error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= e($errorMessage) ?>
        </div>

    <?php endif; ?>


    <!-- REQUEST SUMMARY -->

    <section class="card">

        <h2>
            <i class="fa-solid fa-file-medical"></i>
            Laboratory Request #<?= (int)$request['id'] ?>
        </h2>

        <div class="grid">

            <div>

                <div class="info-row">
                    <span class="info-label">Request ID</span>
                    <span class="info-value">
                        #<?= (int)$request['id'] ?>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Priority</span>
                    <span class="info-value">
                        <span class="badge <?= e($request['priority']) ?>">
                            <?= e(ucfirst($request['priority'])) ?>
                        </span>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Status</span>
                    <span class="info-value">
                        <span class="badge status">
                            <?= e(statusLabel($request['status'])) ?>
                        </span>
                    </span>
                </div>

            </div>

            <div>

                <div class="info-row">
                    <span class="info-label">Requested At</span>
                    <span class="info-value">
                        <?= e($request['requested_at']) ?>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Requesting Doctor</span>
                    <span class="info-value">
                        <?= e($request['doctor_name']) ?>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Doctor Email</span>
                    <span class="info-value">
                        <?= e($request['doctor_email']) ?>
                    </span>
                </div>

            </div>

        </div>

    </section>


    <!-- PATIENT INFORMATION -->

    <section class="card">

        <h2>
            <i class="fa-solid fa-user-injured"></i>
            Patient Information
        </h2>

        <div class="grid">

            <div>

                <div class="info-row">
                    <span class="info-label">Patient Name</span>
                    <span class="info-value">
                        <?= e($request['patient_name'] ?? 'Unknown') ?>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Patient Number</span>
                    <span class="info-value">
                        <?= e($request['patient_number']) ?>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Gender</span>
                    <span class="info-value">
                        <?= e($request['gender'] ?? 'Not provided') ?>
                    </span>
                </div>

            </div>

            <div>

                <div class="info-row">
                    <span class="info-label">Date of Birth</span>
                    <span class="info-value">
                        <?= e($request['date_of_birth'] ?? 'Not provided') ?>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Phone</span>
                    <span class="info-value">
                        <?= e($request['patient_phone'] ?? 'Not provided') ?>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Address</span>
                    <span class="info-value">
                        <?= e($request['patient_address'] ?? 'Not provided') ?>
                    </span>
                </div>

            </div>

        </div>

    </section>


    <!-- REQUESTED TESTS -->

    <section class="card">

        <h2>
            <i class="fa-solid fa-microscope"></i>
            Requested Laboratory Tests
        </h2>

        <?php if (count($tests) > 0): ?>

            <?php foreach ($tests as $test): ?>

                <div class="test-item">

                    <h3>
                        <?= e($test['test_name']) ?>
                    </h3>

                    <p>
                        Category:
                        <?= e($test['category']) ?>
                    </p>

                    <?php if (!empty($test['description'])): ?>

                        <p>
                            <?= e($test['description']) ?>
                        </p>

                    <?php endif; ?>

                    <p>
                        Test Status:
                        <?= e(ucfirst($test['request_test_status'])) ?>
                    </p>

                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <div class="empty">
                No tests are attached to this request.
            </div>

        <?php endif; ?>

    </section>


    <!-- SAMPLE COLLECTION -->

    <section class="card">

        <h2>
            <i class="fa-solid fa-vial"></i>
            Sample Collection
        </h2>


        <?php if ($sample): ?>

            <div class="alert success">

                <i class="fa-solid fa-circle-check"></i>

                A sample has already been registered for this request.

            </div>

            <p>Sample Code</p>

            <div class="sample-code">
                <?= e($sample['sample_code']) ?>
            </div>

            <div class="info-row">
                <span class="info-label">Sample Type</span>
                <span class="info-value">
                    <?= e($sample['sample_type']) ?>
                </span>
            </div>

            <div class="info-row">
                <span class="info-label">Collected By</span>
                <span class="info-value">
                    <?= e($sample['collector_name'] ?? 'Unknown') ?>
                </span>
            </div>

            <div class="info-row">
                <span class="info-label">Collected At</span>
                <span class="info-value">
                    <?= e($sample['collected_at'] ?? 'Not recorded') ?>
                </span>
            </div>

            <div class="info-row">
                <span class="info-label">Sample Status</span>
                <span class="info-value">
                    <span class="badge collected">
                        <?= e(ucfirst($sample['status'])) ?>
                    </span>
                </span>
            </div>


        <?php elseif ($canCollect): ?>

            <div class="alert success">
                <i class="fa-solid fa-info-circle"></i>

                Confirm that the physical sample has actually been collected
                before submitting this form.
            </div>


            <form method="POST" action="">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <div class="form-group">

                    <label for="sample_type">
                        Select Sample Type
                    </label>

                    <select
                        name="sample_type"
                        id="sample_type"
                        required
                    >

                        <option value="">
                            -- Select Sample Type --
                        </option>

                        <option value="Blood">
                            Blood
                        </option>

                        <option value="Urine">
                            Urine
                        </option>

                        <option value="Stool">
                            Stool
                        </option>

                        <option value="Sputum">
                            Sputum
                        </option>

                        <option value="Swab">
                            Swab
                        </option>

                        <option value="Other">
                            Other
                        </option>

                    </select>

                    <p class="help-text">
                        Select the actual sample type collected from the patient.
                    </p>

                </div>


                <button
                    type="submit"
                    class="submit-btn"
                    onclick="return confirm('Have you physically collected this sample? Continue?');"
                >

                    <i class="fa-solid fa-vial-circle-check"></i>

                    Confirm Sample Collection

                </button>

            </form>


        <?php else: ?>

            <div class="alert error">

                Sample collection is not available because this request
                is not in the requested status.

            </div>

        <?php endif; ?>


    </section>


</main>

</body>
</html>
