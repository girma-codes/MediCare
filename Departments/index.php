
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin']);

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$search = trim($_GET['search'] ?? '');
$error = '';
$success = '';

$successMessages = [
    'added' => 'Department successfully added.',
    'updated' => 'Department successfully updated.',
    'deleted' => 'Department successfully deleted.'
];

$errorMessages = [
    'invalid' => 'Invalid department ID.',
    'assigned' => 'This department cannot be deleted because doctors are assigned to it.',
    'notfound' => 'Department was not found.',
    'delete_failed' => 'Unable to delete department. It may be connected to other records.'
];

$successCode = $_GET['success'] ?? '';
$errorCode = $_GET['error'] ?? '';

if (isset($successMessages[$successCode])) {
    $success = $successMessages[$successCode];
}

if (isset($errorMessages[$errorCode])) {
    $error = $errorMessages[$errorCode];
}

try {
    if ($search !== '') {
        $stmt = $pdo->prepare("
            SELECT id, name, description, created_at
            FROM departments
            WHERE name LIKE :search
               OR description LIKE :search
            ORDER BY name ASC
        ");

        $stmt->execute([
            'search' => "%$search%"
        ]);
    } else {
        $stmt = $pdo->query("
            SELECT id, name, description, created_at
            FROM departments
            ORDER BY name ASC
        ");
    }

    $departments = $stmt->fetchAll();

    $totalStmt = $pdo->query("
        SELECT COUNT(*) FROM departments
    ");

    $totalDepartments = (int) $totalStmt->fetchColumn();

} catch (PDOException $e) {
    error_log($e->getMessage());

    $departments = [];
    $totalDepartments = 0;

    $error = 'Unable to load departments. Please check the database.';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Department Management | MediCare HMS</title>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

:root {
    --primary: #1769aa;
    --dark: #102d4a;
    --bg: #f4f7fb;
    --border: #e8edf4;
    --muted: #8491a5;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Inter, Arial, sans-serif;
    background: var(--bg);
    color: #1e293b;
}

.page {
    max-width: 1450px;
    margin: auto;
    padding: 35px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 28px;
}

.header h1 {
    font-size: 25px;
    margin-bottom: 8px;
}

.header p {
    color: var(--muted);
    font-size: 13px;
}

.btn {
    display: inline-block;
    padding: 10px 14px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    font-family: inherit;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: #104e83;
}

.btn-light {
    background: white;
    color: var(--primary);
    border: 1px solid var(--border);
}

.btn-light:hover {
    background: #eff6ff;
}

.btn-danger {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.btn-danger:hover {
    background: #b91c1c;
    color: white;
}

.card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 23px;
    margin-bottom: 25px;
    box-shadow: 0 5px 20px rgba(20,50,80,.04);
}

.stat {
    max-width: 280px;
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
}

.stat-number {
    font-size: 32px;
    font-weight: 800;
    margin-top: 12px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 20px;
}

.section-header h2 {
    font-size: 17px;
    margin-bottom: 6px;
}

.section-header p {
    color: var(--muted);
    font-size: 12px;
}

.search-form {
    display: flex;
    gap: 8px;
}

.search-form input {
    width: 230px;
    max-width: 100%;
    padding: 11px 13px;
    border: 1px solid var(--border);
    border-radius: 8px;
    outline: none;
    font-family: inherit;
}

.search-form input:focus {
    border-color: var(--primary);
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 750px;
    border-collapse: collapse;
}

th, td {
    text-align: left;
    padding: 16px 14px;
    border-bottom: 1px solid var(--border);
    font-size: 12px;
}

th {
    color: #8290a5;
    background: #f9fbfd;
    font-weight: 700;
}

td {
    color: #475569;
}

tbody tr:hover {
    background: #f8fbff;
}

.department-name {
    color: #1e293b;
    font-weight: 700;
}

.description {
    max-width: 350px;
    line-height: 1.6;
}

.empty {
    text-align: center;
    padding: 40px;
    color: var(--muted);
}

.alert {
    padding: 14px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.5;
}

.success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.actions {
    display: flex;
    align-items: center;
    gap: 7px;
}

.delete-form {
    display: inline;
}

.back {
    display: inline-block;
    margin-bottom: 22px;
    text-decoration: none;
    color: var(--primary);
    font-size: 12px;
    font-weight: 600;
}

@media(max-width: 600px) {
    .page {
        padding: 20px 14px;
    }

    .header h1 {
        font-size: 21px;
    }

    .card {
        padding: 16px;
    }

    .search-form {
        width: 100%;
        flex-wrap: wrap;
    }

    .search-form input {
        flex: 1;
        min-width: 0;
    }
}
</style>
</head>

<body>

<main class="page">

    <a class="back" href="../admin/dashboard.php">
        ← Back to Admin Dashboard
    </a>

    <header class="header">
        <div>
            <h1>Department Management</h1>
            <p>Manage and view hospital departments.</p>
        </div>

        <a href="add.php" class="btn btn-primary">
            + Add Department
        </a>
    </header>

    <?php if ($success): ?>
        <div class="alert success">
            ✓ <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error">
            ⚠ <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <section class="card stat">

        <div class="stat-label">Total Departments</div>

        <div class="stat-number">
            <?= $totalDepartments ?>
        </div>

    </section>

    <section class="card">

        <div class="section-header">

            <div>
                <h2>Department Records</h2>
                <p>List of all registered hospital departments.</p>
            </div>

            <form method="GET" class="search-form">

                <input
                    type="search"
                    name="search"
                    placeholder="Search departments..."
                    value="<?= htmlspecialchars($search) ?>"
                >

                <button class="btn btn-primary" type="submit">
                    Search
                </button>

                <?php if ($search !== ''): ?>
                    <a href="index.php" class="btn btn-light">
                        Clear
                    </a>
                <?php endif; ?>

            </form>

        </div>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Department Name</th>
                        <th>Description</th>
                        <th>Date Created</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($departments)): ?>

                    <tr>
                        <td colspan="5" class="empty">
                            No departments found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($departments as $department): ?>

                        <tr>

                            <td>
                                #<?= (int) $department['id'] ?>
                            </td>

                            <td class="department-name">
                                <?= htmlspecialchars($department['name']) ?>
                            </td>

                            <td class="description">
                                <?= htmlspecialchars(
                                    $department['description'] ?? 'No description'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $department['created_at'] ?? 'N/A'
                                ) ?>
                            </td>

                            <td>

                                <div class="actions">

                                    <a
                                        class="btn btn-light"
                                        href="edit.php?id=<?= (int) $department['id'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="delete.php"
                                        class="delete-form"
                                        onsubmit="return confirm('Are you sure you want to delete this department?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $department['id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-danger"
                                        >
                                            Delete
                                        </button>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

</body>
</html>
