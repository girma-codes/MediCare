
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin']);

if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';

$allowed = ['all', 'expired', '30', '60', '90'];

if (!in_array($filter, $allowed, true)) {
    $filter = 'all';
}

$where = ["expiry_date IS NOT NULL"];
$params = [];

if ($search !== '') {
    $where[] = "(medicine_name LIKE :name OR category LIKE :category)";
    $keyword = '%' . $search . '%';
    $params[':name'] = $keyword;
    $params[':category'] = $keyword;
}

if ($filter === 'expired') {
    $where[] = "expiry_date < CURDATE()";

} elseif ($filter === '30') {
    $where[] = "expiry_date BETWEEN CURDATE()
                 AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";

} elseif ($filter === '60') {
    $where[] = "expiry_date BETWEEN CURDATE()
                 AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)";

} elseif ($filter === '90') {
    $where[] = "expiry_date BETWEEN CURDATE()
                 AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
}

$sql = "
    SELECT id, medicine_name, category, quantity,
           unit_price, expiry_date
    FROM medicines
    WHERE " . implode(' AND ', $where) . "
    ORDER BY expiry_date ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$medicines = $stmt->fetchAll();

// Expiry statistics
$expiredCount = (int)$pdo->query("
    SELECT COUNT(*) FROM medicines
    WHERE expiry_date IS NOT NULL
    AND expiry_date < CURDATE()
")->fetchColumn();

$days30 = (int)$pdo->query("
    SELECT COUNT(*) FROM medicines
    WHERE expiry_date BETWEEN CURDATE()
    AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
")->fetchColumn();

$days60 = (int)$pdo->query("
    SELECT COUNT(*) FROM medicines
    WHERE expiry_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
")->fetchColumn();

$days90 = (int)$pdo->query("
    SELECT COUNT(*) FROM medicines
    WHERE expiry_date > DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
")->fetchColumn();

$totalExpiring = $expiredCount + $days30 + $days60 + $days90;

// Expiry status
function expiryStatus(string $date): array {

    $today = new DateTimeImmutable('today');
    $expiry = new DateTimeImmutable($date);

    $days = (int)$today->diff($expiry)->format('%r%a');

    if ($days < 0) {
        return ['Expired', 'expired', $days];
    }

    if ($days <= 30) {
        return ['Expires within 30 days', 'critical', $days];
    }

    if ($days <= 60) {
        return ['Expires within 60 days', 'warning', $days];
    }

    if ($days <= 90) {
        return ['Expires within 90 days', 'notice', $days];
    }

    return ['Valid', 'valid', $days];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Expiry Management | MediCare HMS</title>

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
            color: #263445;
        }

        /* MAIN LAYOUT */

        .app-layout {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR */

        .sidebar {
            width: 250px;
            min-width: 250px;
            background: #102a43;
            color: white;
            padding: 25px 15px;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar-brand {
            font-size: 21px;
            font-weight: bold;
            padding: 0 12px 30px;
            letter-spacing: .3px;
        }

        .sidebar-brand i {
            color: #60a5fa;
            margin-right: 8px;
        }

        .sidebar-menu {
            list-style: none;
        }

        .sidebar-menu li {
            margin-bottom: 7px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 14px;
            color: #d5e1ee;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            transition: .2s;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: #2563eb;
            color: white;
        }

        .sidebar-menu i {
            width: 20px;
            text-align: center;
        }

        /* MAIN AREA */

        .main-area {
            flex: 1;
            margin-left: 250px;
            min-width: 0;
        }

        .topbar {
            height: 70px;
            background: white;
            border-bottom: 1px solid #edf0f5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }

        .topbar strong {
            color: #102a43;
            font-size: 15px;
        }

        .topbar span {
            color: #718096;
            font-size: 13px;
        }

        .container {
            max-width: 1450px;
            margin: 30px auto;
            padding: 0 25px;
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
            margin-bottom: 8px;
        }

        .header p {
            color: #718096;
            font-size: 14px;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .link {
            display: inline-block;
            text-decoration: none;
            padding: 12px 16px;
            border-radius: 8px;
            background: #e8eef7;
            color: #263f60;
            font-weight: bold;
            font-size: 13px;
            transition: .2s;
        }

        .link:hover {
            background: #dce7f5;
        }

        /* SAFETY NOTICE */

        .safety {
            background: #fff4e5;
            color: #8a4b08;
            padding: 14px 17px;
            border-radius: 8px;
            margin-bottom: 20px;
            line-height: 1.6;
            font-size: 13px;
            border: 1px solid #f8dfb8;
        }

        /* STATISTICS */

        .cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .card,
        .content {
            background: white;
            border: 1px solid #edf0f5;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0,0,0,.04);
        }

        .card {
            padding: 20px;
        }

        .card h2 {
            font-size: 27px;
            margin: 12px 0 5px;
        }

        .card p {
            font-size: 13px;
            color: #718096;
        }

        .card i {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: #e9f3ff;
            color: #2874c7;
        }

        /* CONTENT */

        .content {
            padding: 23px;
            margin-bottom: 25px;
        }

        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }

        .content-header h2 {
            font-size: 19px;
        }

        /* SEARCH AND FILTER */

        .search-form {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        input,
        select,
        button {
            padding: 11px 12px;
            border: 1px solid #dce3ec;
            border-radius: 7px;
            font-size: 13px;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: #2874c7;
            box-shadow: 0 0 0 3px rgba(40,116,199,.10);
        }

        button {
            background: #2874c7;
            color: white;
            border: none;
            cursor: pointer;
            font-weight: bold;
        }

        button:hover {
            background: #185da7;
        }

        /* TABLE */

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th,
        td {
            text-align: left;
            padding: 15px 12px;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
        }

        th {
            background: #f8fafc;
            color: #64748b;
            text-transform: uppercase;
            font-size: 12px;
        }

        /* STATUS BADGES */

        .badge {
            display: inline-block;
            padding: 6px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            white-space: nowrap;
        }

        .expired {
            color: #c0392b;
            background: #fde9e7;
        }

        .critical {
            color: #a66a00;
            background: #fff0d1;
        }

        .warning {
            color: #8b6500;
            background: #fff6d8;
        }

        .notice {
            color: #2874c7;
            background: #e8f1ff;
        }

        .valid {
            color: #16834b;
            background: #e5f7ed;
        }

        .empty {
            text-align: center;
            padding: 40px;
            color: #718096;
        }

        /* RESPONSIVE DESIGN */

        @media(max-width: 1100px) {
            .cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(max-width: 750px) {

            .sidebar {
                width: 68px;
                min-width: 68px;
                padding: 20px 8px;
            }

            .sidebar-brand {
                font-size: 0;
                padding: 0 12px 25px;
            }

            .sidebar-brand i {
                font-size: 23px;
            }

            .sidebar-menu a {
                justify-content: center;
                padding: 13px 5px;
            }

            .sidebar-menu a span {
                display: none;
            }

            .main-area {
                margin-left: 68px;
            }

            .topbar {
                padding: 0 15px;
            }

            .container {
                padding: 0 12px;
            }
        }

        @media(max-width: 550px) {

            .cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .content {
                padding: 13px;
            }

            .search-form {
                width: 100%;
            }

            .search-form input,
            .search-form select,
            .search-form button {
                width: 100%;
            }

            .header h1 {
                font-size: 22px;
            }

            .actions {
                width: 100%;
            }
        }

        @media(max-width: 380px) {
            .cards {
                grid-template-columns: 1fr;
            }
        }

    </style>

</head>

<body>

<div class="app-layout">

    <!-- SIDEBAR -->

    <aside class="sidebar">

        <div class="sidebar-brand">
            <i class="fa-solid fa-hospital"></i>
            MediCare
        </div>

        <ul class="sidebar-menu">

            <li>
                <a href="dashboard.php">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li>
                <a href="index.php">
                    <i class="fa-solid fa-pills"></i>
                    <span>Medicine Inventory</span>
                </a>
            </li>

            <li>
                <a href="add.php">
                    <i class="fa-solid fa-plus-circle"></i>
                    <span>Add Medicine</span>
                </a>
            </li>

            <li>
                <a href="stock.php">
                    <i class="fa-solid fa-boxes-stacked"></i>
                    <span>Stock Management</span>
                </a>
            </li>

            <li>
                <a href="expiry.php" class="active">
                    <i class="fa-solid fa-calendar-xmark"></i>
                    <span>Expiry Management</span>
                </a>
            </li>

            <li>
                <a href="low_stock.php">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Low Stock Alerts</span>
                </a>
            </li>

            <li>
                <a href="purchase_request.php">
                    <i class="fa-solid fa-cart-shopping"></i>
                    <span>Purchase Management</span>
                </a>
            </li>

            <li>
                <a href="reports.php">
                    <i class="fa-solid fa-chart-column"></i>
                    <span>Inventory Reports</span>
                </a>
            </li>

            <li>
                <a href="../admin/dashboard.php">
                    <i class="fa-solid fa-house"></i>
                    <span>Admin Dashboard</span>
                </a>
            </li>

        </ul>

    </aside>

    <!-- MAIN AREA -->

    <main class="main-area">

        <div class="topbar">

            <strong>
                <i class="fa-solid fa-hospital-user"></i>
                Pharmacy Management
            </strong>

            <span><?= date('M d, Y') ?></span>

        </div>

        <div class="container">

            <!-- HEADER -->

            <div class="header">

                <div>
                    <h1>
                        <i class="fa-solid fa-calendar-xmark"></i>
                        Expiry Management
                    </h1>

                    <p>
                        Monitor medicine expiry dates and protect patient safety.
                    </p>
                </div>

                <div class="actions">

                    <a class="link" href="index.php">
                        <i class="fa-solid fa-pills"></i>
                        Pharmacy Inventory
                    </a>

                    <a class="link" href="../admin/dashboard.php">
                        <i class="fa-solid fa-arrow-left"></i>
                        Dashboard
                    </a>

                </div>

            </div>

            <!-- SAFETY NOTICE -->

            <div class="safety">

                <strong>
                    <i class="fa-solid fa-shield-halved"></i>
                    Patient Safety Notice:
                </strong>

                Expired medicines must be quarantined and must not be dispensed.
                Check the manufacturer's expiry instructions and your hospital's
                medicine-handling policy.

            </div>

            <!-- STATISTICS -->

            <div class="cards">

                <div class="card">

                    <i class="fa-solid fa-calendar-xmark"></i>

                    <h2><?= number_format($expiredCount) ?></h2>

                    <p>Expired Medicines</p>

                </div>

                <div class="card">

                    <i class="fa-solid fa-triangle-exclamation"></i>

                    <h2><?= number_format($days30) ?></h2>

                    <p>Expiring Within 30 Days</p>

                </div>

                <div class="card">

                    <i class="fa-solid fa-clock"></i>

                    <h2><?= number_format($days60) ?></h2>

                    <p>Expiring in 31–60 Days</p>

                </div>

                <div class="card">

                    <i class="fa-solid fa-calendar-check"></i>

                    <h2><?= number_format($days90) ?></h2>

                    <p>Expiring in 61–90 Days</p>

                </div>

            </div>

            <!-- EXPIRY REPORT -->

            <div class="content">

                <div class="content-header">

                    <h2>
                        <i class="fa-solid fa-list"></i>
                        Expiry Report
                    </h2>

                    <form method="GET" class="search-form">

                        <input
                            type="text"
                            name="search"
                            placeholder="Search medicine..."
                            value="<?= e($search) ?>"
                        >

                        <select name="filter">

                            <option value="all"
                                <?= $filter === 'all' ? 'selected' : '' ?>>
                                All Dates
                            </option>

                            <option value="expired"
                                <?= $filter === 'expired' ? 'selected' : '' ?>>
                                Expired
                            </option>

                            <option value="30"
                                <?= $filter === '30' ? 'selected' : '' ?>>
                                Within 30 Days
                            </option>

                            <option value="60"
                                <?= $filter === '60' ? 'selected' : '' ?>>
                                Within 60 Days
                            </option>

                            <option value="90"
                                <?= $filter === '90' ? 'selected' : '' ?>>
                                Within 90 Days
                            </option>

                        </select>

                        <button type="submit">
                            <i class="fa-solid fa-search"></i>
                            Search
                        </button>

                    </form>

                </div>

                <div class="table-wrap">

                    <table>

                        <thead>

                            <tr>
                                <th>ID</th>
                                <th>Medicine</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Expiry Date</th>
                                <th>Days Remaining</th>
                                <th>Status</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php if (!$medicines): ?>

                            <tr>
                                <td colspan="7">

                                    <div class="empty">
                                        <i class="fa-solid fa-circle-check"></i>

                                        <p>
                                            No medicines found for this filter.
                                        </p>
                                    </div>

                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($medicines as $medicine): ?>

                                <?php
                                [$status, $class, $days] =
                                    expiryStatus($medicine['expiry_date']);
                                ?>

                                <tr>

                                    <td>
                                        #<?= (int)$medicine['id'] ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= e($medicine['medicine_name']) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= e($medicine['category']) ?>
                                    </td>

                                    <td>
                                        <?= number_format((int)$medicine['quantity']) ?>
                                    </td>

                                    <td>
                                        <?= e(date(
                                            'M d, Y',
                                            strtotime($medicine['expiry_date'])
                                        )) ?>
                                    </td>

                                    <td>

                                        <?php if ($days < 0): ?>

                                            <?= abs($days) ?> days ago

                                        <?php else: ?>

                                            <?= $days ?> days

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <span class="badge <?= e($class) ?>">
                                            <?= e($status) ?>
                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </main>

</div>

</body>
</html>
