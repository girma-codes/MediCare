
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin']);

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Escape output
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// Search and filter
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';

$allowedFilters = ['all', 'low', 'out', 'expired', 'expiring'];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(medicine_name LIKE :name OR category LIKE :category)";
    $keyword = '%' . $search . '%';
    $params[':name'] = $keyword;
    $params[':category'] = $keyword;
}

// Filters
if ($filter === 'low') {
    $where[] = "quantity BETWEEN 1 AND 10";
} elseif ($filter === 'out') {
    $where[] = "quantity <= 0";
} elseif ($filter === 'expired') {
    $where[] = "expiry_date IS NOT NULL AND expiry_date < CURDATE()";
} elseif ($filter === 'expiring') {
    $where[] = "expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
}

// Fetch medicines
$sql = "
    SELECT id, medicine_name, category, quantity,
           unit_price, expiry_date, created_at
    FROM medicines
";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$totalMedicines = (int)$pdo->query(
    "SELECT COUNT(*) FROM medicines"
)->fetchColumn();

$totalUnits = (int)$pdo->query(
    "SELECT COALESCE(SUM(quantity), 0) FROM medicines"
)->fetchColumn();

$lowStock = (int)$pdo->query(
    "SELECT COUNT(*) FROM medicines WHERE quantity BETWEEN 1 AND 10"
)->fetchColumn();

$outOfStock = (int)$pdo->query(
    "SELECT COUNT(*) FROM medicines WHERE quantity <= 0"
)->fetchColumn();

$expired = (int)$pdo->query(
    "SELECT COUNT(*) FROM medicines
     WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE()"
)->fetchColumn();

$expiringSoon = (int)$pdo->query(
    "SELECT COUNT(*) FROM medicines
     WHERE expiry_date BETWEEN CURDATE()
     AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
)->fetchColumn();

// Medicine status
function medicineStatus(array $medicine): array {
    if ((int)$medicine['quantity'] <= 0) {
        return ['Out of Stock', 'out'];
    }

    if (
        !empty($medicine['expiry_date']) &&
        $medicine['expiry_date'] < date('Y-m-d')
    ) {
        return ['Expired', 'expired'];
    }

    if (
        !empty($medicine['expiry_date']) &&
        $medicine['expiry_date'] <= date('Y-m-d', strtotime('+30 days'))
    ) {
        return ['Expiring Soon', 'warning'];
    }

    if ((int)$medicine['quantity'] <= 10) {
        return ['Low Stock', 'low'];
    }

    return ['Available', 'available'];
}

// Success messages
$notice = '';
$errorNotice = '';

if (isset($_GET['added'])) {
    $notice = 'Medicine successfully added to inventory.';
} elseif (isset($_GET['updated'])) {
    $notice = 'Medicine successfully updated.';
} elseif (isset($_GET['deleted'])) {
    $notice = 'Medicine successfully deleted.';
}

// Error messages
if (isset($_GET['error'])) {
    $errorMessages = [
        'invalid' => 'Invalid medicine information.',
        'notfound' => 'Medicine was not found.',
        'delete' => 'Unable to delete medicine. It may have stock history.'
    ];

    $errorNotice = $errorMessages[$_GET['error']]
        ?? 'Something went wrong. Please try again.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Medicine Inventory | MediCare HMS</title>

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
:root {
    --blue: #2563eb;
    --blue-dark: #185da7;
    --page: #f4f7fb;
    --text: #263445;
    --muted: #718096;
    --border: #e9eef5;
    --white: #ffffff;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: var(--page);
    color: var(--text);
}

.main-content {
    margin-left: 250px;
    min-height: 100vh;
    padding: 1px 28px 35px;
}

.container {
    max-width: 1500px;
    margin: 30px auto;
}

/* HEADER */
.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

.header h1 {
    font-size: 27px;
    margin-bottom: 7px;
}

.header h1 i {
    color: var(--blue);
    margin-right: 7px;
}

.header p {
    color: var(--muted);
    font-size: 14px;
}

.header-actions {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
}

.back-btn,
.add-btn {
    text-decoration: none;
    padding: 11px 15px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: .2s;
}

.back-btn {
    background: #e8eef7;
    color: #263f60;
}

.back-btn:hover {
    background: #dce7f5;
}

.add-btn {
    background: var(--blue);
    color: white;
}

.add-btn:hover {
    background: var(--blue-dark);
}

/* ALERTS */
.alert-success,
.alert-error {
    padding: 14px 17px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 13px;
}

.alert-success {
    background: #e5f7ed;
    color: #16834b;
    border: 1px solid #b8ebcc;
}

.alert-error {
    background: #fde9e7;
    color: #c0392b;
    border: 1px solid #f5c2bd;
}

/* STATISTICS */
.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    padding: 19px;
    border-radius: 12px;
    border: 1px solid var(--border);
    box-shadow: 0 3px 15px rgba(0,0,0,.035);
    display: flex;
    align-items: center;
    gap: 14px;
}

.stat-icon {
    width: 45px;
    height: 45px;
    border-radius: 11px;
    background: #e9f3ff;
    color: var(--blue);
    display: grid;
    place-items: center;
    font-size: 17px;
    flex-shrink: 0;
}

.stat-card h2 {
    font-size: 24px;
    margin-bottom: 5px;
}

.stat-card p {
    color: #78869a;
    font-size: 12px;
}

/* CONTENT */
.content {
    background: white;
    padding: 23px;
    border-radius: 12px;
    border: 1px solid var(--border);
    box-shadow: 0 3px 15px rgba(0,0,0,.035);
}

.content-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 18px;
}

.content-header h2 {
    font-size: 18px;
}

.content-header h2 i {
    color: var(--blue);
    margin-right: 7px;
}

/* SEARCH */
.search-form {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.search-form input,
.search-form select {
    padding: 11px 12px;
    border: 1px solid #dce3ec;
    border-radius: 7px;
    outline: none;
    background: white;
    color: var(--text);
}

.search-form input {
    width: 230px;
}

.search-form input:focus,
.search-form select:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(40,116,199,.1);
}

.search-form button {
    background: var(--blue);
    color: white;
    border: none;
    border-radius: 7px;
    padding: 11px 16px;
    cursor: pointer;
}

.search-form button:hover {
    background: var(--blue-dark);
}

.result-count {
    color: var(--muted);
    font-size: 12px;
    margin-bottom: 14px;
}

.clear-link {
    color: var(--blue);
    text-decoration: none;
    margin-left: 8px;
}

/* TABLE */
.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 950px;
}

th, td {
    padding: 14px 11px;
    text-align: left;
    border-bottom: 1px solid #edf0f5;
    font-size: 12px;
    vertical-align: middle;
}

th {
    color: #64748b;
    background: #f8fafc;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .5px;
}

tbody tr:hover {
    background: #fafcff;
}

/* STATUS */
.badge {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: bold;
    white-space: nowrap;
}

.available {
    color: #16834b;
    background: #e5f7ed;
}

.low, .warning {
    color: #a66a00;
    background: #fff3d8;
}

.out, .expired {
    color: #c0392b;
    background: #fde9e7;
}

/* ACTIONS */
.action-buttons {
    display: flex;
    gap: 7px;
    align-items: center;
}

.action-buttons form {
    margin: 0;
}

.edit-btn, .delete-btn {
    display: inline-block;
    padding: 8px 10px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 11px;
    white-space: nowrap;
    cursor: pointer;
    text-decoration: none;
}

.edit-btn {
    background: #e8f1ff;
    color: var(--blue);
}

.edit-btn:hover {
    background: #d5e6ff;
}

.delete-btn {
    background: #fde9e7;
    color: #c0392b;
    border: none;
}

.delete-btn:hover {
    background: #c0392b;
    color: white;
}

/* EMPTY STATE */
.empty {
    text-align: center;
    padding: 45px 15px;
    color: #8491a3;
}

.empty i {
    display: block;
    font-size: 35px;
    margin-bottom: 12px;
}

/* RESPONSIVE */
@media (max-width: 1150px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .main-content {
        padding-left: 20px;
        padding-right: 20px;
    }
}

@media (max-width: 750px) {
    .main-content {
        margin-left: 68px;
        padding-left: 15px;
        padding-right: 15px;
    }
}

@media (max-width: 560px) {
    .container {
        margin-top: 20px;
    }

    .header h1 {
        font-size: 21px;
    }

    .header-actions {
        width: 100%;
    }

    .header-actions a {
        flex: 1;
        justify-content: center;
    }

    .stats {
        grid-template-columns: 1fr 1fr;
        gap: 9px;
    }

    .stat-card {
        padding: 12px 10px;
        gap: 9px;
    }

    .stat-icon {
        width: 36px;
        height: 36px;
        font-size: 14px;
    }

    .stat-card h2 {
        font-size: 20px;
    }

    .stat-card p {
        font-size: 10px;
    }

    .content {
        padding: 13px;
    }

    .search-form,
    .search-form input,
    .search-form select,
    .search-form button {
        width: 100%;
    }
}
</style>
</head>

<body>

<?php
// Shared Pharmacy Sidebar
require_once __DIR__ . '/../includes/pharmacy_sidebar.php';
?>

<main class="main-content">
    <div class="container">

        <!-- HEADER -->
        <div class="header">
            <div>
                <h1>
                    <i class="fa-solid fa-prescription-bottle-medical"></i>
                    Pharmacy Management
                </h1>
                <p>Manage medicines, inventory, stock levels and expiry information.</p>
            </div>

            <div class="header-actions">
                <a href="stock.php" class="back-btn">
                    <i class="fa-solid fa-boxes-stacked"></i>
                    Stock
                </a>

                <a href="expiry.php" class="back-btn">
                    <i class="fa-solid fa-calendar-xmark"></i>
                    Expiry
                </a>

                <a href="add.php" class="add-btn">
                    <i class="fa-solid fa-plus"></i>
                    Add Medicine
                </a>
            </div>
        </div>

        <!-- SUCCESS MESSAGE -->
        <?php if ($notice !== ''): ?>
            <div class="alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <?= e($notice) ?>
            </div>
        <?php endif; ?>

        <!-- ERROR MESSAGE -->
        <?php if ($errorNotice !== ''): ?>
            <div class="alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= e($errorNotice) ?>
            </div>
        <?php endif; ?>

        <!-- STATISTICS -->
        <div class="stats">

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-pills"></i>
                </div>
                <div>
                    <h2><?= number_format($totalMedicines) ?></h2>
                    <p>Total Medicines</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-boxes-stacked"></i>
                </div>
                <div>
                    <h2><?= number_format($totalUnits) ?></h2>
                    <p>Total Stock Units</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <h2><?= number_format($lowStock) ?></h2>
                    <p>Low Stock (1–10)</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-box-open"></i>
                </div>
                <div>
                    <h2><?= number_format($outOfStock) ?></h2>
                    <p>Out of Stock</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-calendar-xmark"></i>
                </div>
                <div>
                    <h2><?= number_format($expired) ?></h2>
                    <p>Expired Medicines</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div>
                    <h2><?= number_format($expiringSoon) ?></h2>
                    <p>Expiring Within 30 Days</p>
                </div>
            </div>

        </div>

        <!-- MEDICINE INVENTORY -->
        <section class="content">

            <div class="content-header">
                <h2>
                    <i class="fa-solid fa-boxes-stacked"></i>
                    Medicine Inventory
                </h2>

                <form method="GET" class="search-form">
                    <input
                        type="text"
                        name="search"
                        placeholder="Search medicine or category..."
                        value="<?= e($search) ?>"
                    >

                    <select name="filter">
                        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>
                            All Medicines
                        </option>
                        <option value="low" <?= $filter === 'low' ? 'selected' : '' ?>>
                            Low Stock
                        </option>
                        <option value="out" <?= $filter === 'out' ? 'selected' : '' ?>>
                            Out of Stock
                        </option>
                        <option value="expired" <?= $filter === 'expired' ? 'selected' : '' ?>>
                            Expired
                        </option>
                        <option value="expiring" <?= $filter === 'expiring' ? 'selected' : '' ?>>
                            Expiring Soon
                        </option>
                    </select>

                    <button type="submit">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        Search
                    </button>
                </form>
            </div>

            <div class="result-count">
                Showing <?= count($medicines) ?> medicine(s)

                <?php if ($search !== '' || $filter !== 'all'): ?>
                    <a href="index.php" class="clear-link">Clear filters</a>
                <?php endif; ?>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Medicine Name</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Expiry Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($medicines)): ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty">
                                    <i class="fa-solid fa-box-open"></i>
                                    <strong>No medicines found.</strong>
                                    <p style="margin-top:7px;">
                                        Try changing your search or filter.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($medicines as $medicine): ?>
                            <?php
                            [$statusText, $statusClass] = medicineStatus($medicine);
                            ?>

                            <tr>
                                <td>#<?= (int)$medicine['id'] ?></td>

                                <td>
                                    <strong><?= e($medicine['medicine_name']) ?></strong>
                                </td>

                                <td><?= e($medicine['category']) ?></td>

                                <td>
                                    <strong><?= number_format((int)$medicine['quantity']) ?></strong>
                                </td>

                                <td>
                                    <?= number_format((float)$medicine['unit_price'], 2) ?>
                                </td>

                                <td>
                                    <?php if (!empty($medicine['expiry_date'])): ?>
                                        <?= e(date(
                                            'M d, Y',
                                            strtotime($medicine['expiry_date'])
                                        )) ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="badge <?= e($statusClass) ?>">
                                        <?= e($statusText) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-buttons">

                                        <a
                                            href="edit.php?id=<?= (int)$medicine['id'] ?>"
                                            class="edit-btn"
                                        >
                                            <i class="fa-solid fa-pen"></i>
                                            Edit
                                        </a>

                                        <form
                                            method="POST"
                                            action="delete.php"
                                            onsubmit="return confirm('Are you sure you want to delete this medicine?');"
                                        >
                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$medicine['id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($_SESSION['csrf_token']) ?>"
                                            >

                                            <button type="submit" class="delete-btn">
                                                <i class="fa-solid fa-trash"></i>
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
    </div>
</main>

</body>
</html>
