
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin', 'receptionist']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$success = '';
$error = '';

// Activate / Deactivate patient
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';
    $patientId = filter_input(INPUT_POST, 'patient_id', FILTER_VALIDATE_INT);
    $newStatus = $_POST['new_status'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Security verification failed. Please refresh the page.';
    } elseif (!$patientId || !in_array($newStatus, ['active', 'inactive'], true)) {
        $error = 'Invalid patient request.';
    } else {
        try {
            $check = $pdo->prepare("
                SELECT p.id, p.user_id
                FROM patients p
                INNER JOIN users u ON p.user_id = u.id
                WHERE p.id = ?
                LIMIT 1
            ");
            $check->execute([$patientId]);
            $targetPatient = $check->fetch(PDO::FETCH_ASSOC);

            if (!$targetPatient) {
                $error = 'Patient not found.';
            } else {
                $update = $pdo->prepare("
                    UPDATE users
                    SET status = ?
                    WHERE id = ?
                ");

                $update->execute([
                    $newStatus,
                    $targetPatient['user_id']
                ]);

                $success = $newStatus === 'active'
                    ? 'Patient account activated successfully.'
                    : 'Patient account deactivated successfully.';
            }

        } catch (PDOException $ex) {
            $error = 'Unable to update patient status. Please try again.';
        }
    }
}

// Search and filter values
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

if (!in_array($statusFilter, ['', 'active', 'inactive'], true)) {
    $statusFilter = '';
}

// Total statistics - independent of search filters
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN u.status = 'inactive' OR u.status IS NULL THEN 1 ELSE 0 END) AS inactive
    FROM patients p
    LEFT JOIN users u ON p.user_id = u.id
");

$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$totalPatients = (int)($stats['total'] ?? 0);
$activePatients = (int)($stats['active'] ?? 0);
$inactivePatients = (int)($stats['inactive'] ?? 0);

// Build filtered patient query
$sql = "
    SELECT
        p.id,
        p.patient_number,
        p.gender,
        p.phone,
        p.date_of_birth,
        p.address,
        p.emergency_contact,
        p.created_at,
        u.full_name,
        u.email,
        u.status
    FROM patients p
    LEFT JOIN users u ON p.user_id = u.id
    WHERE 1 = 1
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            u.full_name LIKE ?
            OR p.patient_number LIKE ?
            OR p.phone LIKE ?
        )
    ";

    $searchTerm = '%' . $search . '%';

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($statusFilter !== '') {
    $sql .= " AND u.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY p.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filteredCount = count($patients);

$dashboardUrl = $_SESSION['role'] === 'receptionist'
    ? '../receptionist/dashboard.php'
    : '../admin/dashboard.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Patient Management | MediCare HMS</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f1f5f9;
    color: #1e293b;
}

.main-container {
    max-width: 1450px;
    margin: auto;
    padding: 35px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 30px;
}

.page-title h1 {
    font-size: 28px;
    margin-bottom: 8px;
}

.page-title p {
    color: #64748b;
    font-size: 14px;
}

.header-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 12px 18px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: .2s;
    border: none;
    cursor: pointer;
}

.btn-primary {
    background: #2563eb;
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-secondary {
    background: white;
    color: #334155;
    border: 1px solid #cbd5e1;
}

.btn-secondary:hover {
    background: #f8fafc;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 28px;
}

.stat-card {
    background: white;
    padding: 24px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 3px 12px rgba(0,0,0,.03);
}

.stat-card span {
    color: #64748b;
    font-size: 14px;
}

.stat-card h2 {
    margin-top: 12px;
    font-size: 30px;
}

.blue { color: #2563eb; }
.green { color: #16a34a; }
.red { color: #dc2626; }

.table-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 3px 12px rgba(0,0,0,.03);
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 22px;
    flex-wrap: wrap;
    gap: 10px;
}

.table-header h2 {
    font-size: 20px;
}

.table-header p {
    font-size: 13px;
    color: #64748b;
    margin-top: 5px;
}

/* Search and Filter */
.filter-form {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) 200px auto auto;
    gap: 12px;
    margin-bottom: 22px;
    align-items: center;
}

.filter-form input,
.filter-form select {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 14px;
    background: white;
    outline: none;
}

.filter-form input:focus,
.filter-form select:focus {
    border-color: #2563eb;
}

.clear-btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    padding: 12px 16px;
    background: #f1f5f9;
    color: #334155;
    border-radius: 8px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
}

.results-info {
    font-size: 13px;
    color: #64748b;
    margin-bottom: 15px;
}

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1050px;
}

thead {
    background: #f8fafc;
}

th {
    color: #64748b;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .5px;
    font-weight: 600;
}

th, td {
    padding: 16px 14px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}

td {
    font-size: 14px;
}

tbody tr:hover {
    background: #f8fafc;
}

.patient-number {
    color: #2563eb;
    font-weight: 700;
}

.patient-name {
    font-weight: 600;
    color: #1e293b;
}

.gender {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 12px;
}

.status {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

.status-active {
    background: #dcfce7;
    color: #166534;
}

.status-inactive {
    background: #fee2e2;
    color: #991b1b;
}

.view-btn {
    display: inline-block;
    background: #eff6ff;
    color: #2563eb;
    text-decoration: none;
    padding: 8px 12px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 12px;
}

.action-buttons {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}

.status-btn {
    padding: 8px 11px;
    border: none;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}

.deactivate-btn {
    background: #fee2e2;
    color: #991b1b;
}

.activate-btn {
    background: #dcfce7;
    color: #166534;
}

.status-btn:hover {
    opacity: .8;
}

.empty-state {
    text-align: center;
    padding: 45px 20px;
    color: #64748b;
}

.empty-state h3 {
    color: #334155;
    margin-bottom: 8px;
}

.alert {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
}

.page-footer {
    margin-top: 25px;
    color: #64748b;
    font-size: 13px;
    text-align: center;
}

@media(max-width: 800px) {
    .filter-form {
        grid-template-columns: 1fr 1fr;
    }
}

@media(max-width: 650px) {
    .main-container {
        padding: 18px;
    }

    .page-header {
        align-items: flex-start;
    }

    .page-title h1 {
        font-size: 23px;
    }

    .header-actions {
        width: 100%;
    }

    .header-actions .btn {
        flex: 1;
    }

    .table-card {
        padding: 16px;
    }

    .filter-form {
        grid-template-columns: 1fr;
    }
}
</style>

</head>

<body>

<div class="main-container">

    <header class="page-header">

        <div class="page-title">
            <h1>Patient Management</h1>
            <p>Manage and view registered hospital patients.</p>
        </div>

        <div class="header-actions">

            <a href="<?= e($dashboardUrl) ?>" class="btn btn-secondary">
                ← Dashboard
            </a>

            <a href="register.php" class="btn btn-primary">
                + Register Patient
            </a>

        </div>

    </header>

    <?php if ($success): ?>
        <div class="alert alert-success">
            ✓ <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error">
            ⚠ <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="stats-grid">

        <div class="stat-card">
            <span>Total Registered Patients</span>
            <h2 class="blue"><?= number_format($totalPatients) ?></h2>
        </div>

        <div class="stat-card">
            <span>Active Patients</span>
            <h2 class="green"><?= number_format($activePatients) ?></h2>
        </div>

        <div class="stat-card">
            <span>Inactive Patients</span>
            <h2 class="red"><?= number_format($inactivePatients) ?></h2>
        </div>

    </section>

    <section class="table-card">

        <div class="table-header">
            <div>
                <h2>Patient Records</h2>
                <p>Search and manage registered hospital patients.</p>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <form method="GET" action="index.php" class="filter-form">

            <input
                type="search"
                name="search"
                placeholder="Search name, patient ID or phone..."
                value="<?= e($search) ?>"
            >

            <select name="status">
                <option value="">All Patient Status</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                    Active Patients
                </option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                    Inactive Patients
                </option>
            </select>

            <button type="submit" class="btn btn-primary">
                Search
            </button>

            <a href="index.php" class="clear-btn">
                Clear Filters
            </a>

        </form>

        <div class="results-info">
            Showing <strong><?= number_format($filteredCount) ?></strong>
            matching patient(s).
        </div>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>Patient ID</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Gender</th>
                        <th>Phone</th>
                        <th>Date of Birth</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($filteredCount > 0): ?>

                    <?php foreach ($patients as $patient): ?>

                        <?php
                        $isActive = ($patient['status'] ?? '') === 'active';
                        ?>

                        <tr>

                            <td class="patient-number">
                                <?= e($patient['patient_number'] ?? 'N/A') ?>
                            </td>

                            <td class="patient-name">
                                <?= e($patient['full_name'] ?? 'N/A') ?>
                            </td>

                            <td><?= e($patient['email'] ?? 'N/A') ?></td>

                            <td>
                                <span class="gender">
                                    <?= e(ucfirst($patient['gender'] ?? 'N/A')) ?>
                                </span>
                            </td>

                            <td><?= e($patient['phone'] ?? 'N/A') ?></td>

                            <td><?= e($patient['date_of_birth'] ?? 'N/A') ?></td>

                            <td>
                                <span class="status <?= $isActive ? 'status-active' : 'status-inactive' ?>">
                                    <?= $isActive ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>

                            <td>
                                <div class="action-buttons">

                                    <a href="view.php?id=<?= (int)$patient['id'] ?>"
                                       class="view-btn">
                                        View
                                    </a>

                                    <form method="POST"
                                          action="index.php"
                                          onsubmit="return confirm('Are you sure you want to <?= $isActive ? 'deactivate' : 'activate' ?> this patient account?');">

                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION['csrf_token']) ?>">

                                        <input type="hidden" name="patient_id"
                                               value="<?= (int)$patient['id'] ?>">

                                        <input type="hidden" name="new_status"
                                               value="<?= $isActive ? 'inactive' : 'active' ?>">

                                        <button type="submit"
                                                class="status-btn <?= $isActive ? 'deactivate-btn' : 'activate-btn' ?>">
                                            <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                        </button>

                                    </form>

                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <h3>No Patients Found</h3>
                                <p>No patients match your search or filter.</p>
                                <br>
                                <a href="index.php" class="btn btn-secondary">
                                    Clear Filters
                                </a>
                            </div>
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <footer class="page-footer">
        MediCare Hospital Management System
    </footer>

</div>

</body>
</html>
