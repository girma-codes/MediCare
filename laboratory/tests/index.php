
<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requireRole(['admin', 'lab_technician', 'lab_doctor']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_token'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($csrf, $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please refresh.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save') {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['test_name'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $price = $_POST['price'] ?? '0';
                $normalRange = trim($_POST['normal_range'] ?? '');
                $unit = trim($_POST['unit'] ?? '');

                if ($name === '' || $category === '' ||
                    !is_numeric($price) || (float)$price < 0) {
                    throw new RuntimeException('Enter valid test details.');
                }

                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        "UPDATE lab_tests SET
                         test_name=?, category=?, description=?,
                         price=?, normal_range=?, unit=?
                         WHERE id=?"
                    );
                    $stmt->execute([
                        $name, $category, $description ?: null,
                        $price, $normalRange ?: null, $unit ?: null, $id
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO lab_tests
                         (test_name, category, description, price,
                          normal_range, unit)
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([
                        $name, $category, $description ?: null,
                        $price, $normalRange ?: null, $unit ?: null
                    ]);
                }

                $success = 'Laboratory test saved successfully.';
            } elseif ($action === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare(
                    "UPDATE lab_tests
                     SET status = IF(status='active','inactive','active')
                     WHERE id=?"
                );
                $stmt->execute([$id]);
                $success = 'Test status updated.';
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare(
                    "DELETE FROM lab_tests WHERE id=?"
                );
                $stmt->execute([$id]);
                $success = 'Test deleted.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$editTest = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM lab_tests WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editTest = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$tests = $pdo->query(
    "SELECT * FROM lab_tests ORDER BY created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratory Test Catalog | MediCare</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:#f1f5f9;color:#172033}
header{background:#123b64;color:white;padding:20px 5%;display:flex;justify-content:space-between;align-items:center}
header a{color:white;text-decoration:none}
main{max-width:1150px;margin:30px auto;padding:0 18px}
h1{margin-bottom:8px}
.subtitle{color:#64748b;margin-bottom:25px}
.card{background:white;padding:24px;border-radius:13px;margin-bottom:22px;box-shadow:0 4px 18px #0f172a0b}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
label{display:block;font-weight:bold;margin-bottom:7px}
input,textarea{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:7px;font-size:14px}
textarea{min-height:75px}
button,.btn{border:0;border-radius:7px;padding:10px 15px;background:#2563eb;color:white;cursor:pointer;text-decoration:none;display:inline-block}
.secondary{background:#64748b}
.danger{background:#dc2626}
.success{background:#dcfce7;color:#166534;padding:12px;border-radius:7px;margin-bottom:15px}
.error{background:#fee2e2;color:#991b1b;padding:12px;border-radius:7px;margin-bottom:15px}
table{width:100%;border-collapse:collapse;min-width:750px}
th,td{text-align:left;padding:13px;border-bottom:1px solid #e2e8f0}
th{background:#f8fafc}
.table-wrap{overflow-x:auto}
.status{font-weight:bold}
.active{color:#15803d}.inactive{color:#dc2626}
.actions{display:flex;gap:6px;flex-wrap:wrap}
@media(max-width:650px){.form-grid{grid-template-columns:1fr}header{padding:17px;gap:10px}}
</style>
</head>
<body>
<header>
    <strong>MediCare | Laboratory</strong>
    <a href="../technician/dashboard.php">Back to Dashboard</a>
</header>
<main>
    <h1>Laboratory Test Catalog</h1>
    <p class="subtitle">Manage available laboratory tests and their details.</p>

    <?php if ($success): ?><div class="success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

    <section class="card">
        <h2><?= $editTest ? 'Edit Laboratory Test' : 'Add New Laboratory Test' ?></h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= h($editTest['id'] ?? 0) ?>">

            <div class="form-grid">
                <div>
                    <label>Test Name</label>
                    <input name="test_name" required maxlength="150"
                        value="<?= h($editTest['test_name'] ?? '') ?>">
                </div>
                <div>
                    <label>Category</label>
                    <input name="category" required maxlength="100"
                        placeholder="e.g. Hematology"
                        value="<?= h($editTest['category'] ?? '') ?>">
                </div>
                <div>
                    <label>Price</label>
                    <input type="number" name="price" min="0" step="0.01"
                        value="<?= h($editTest['price'] ?? '0.00') ?>">
                </div>
                <div>
                    <label>Unit</label>
                    <input name="unit" maxlength="50"
                        value="<?= h($editTest['unit'] ?? '') ?>">
                </div>
                <div>
                    <label>Normal Range</label>
                    <input name="normal_range" maxlength="150"
                        value="<?= h($editTest['normal_range'] ?? '') ?>">
                </div>
                <div>
                    <label>Description</label>
                    <textarea name="description"><?= h($editTest['description'] ?? '') ?></textarea>
                </div>
            </div>
            <br>
            <button type="submit">Save Test</button>
            <?php if ($editTest): ?>
                <a class="btn secondary" href="index.php">Cancel Edit</a>
            <?php endif; ?>
        </form>
    </section>

    <section class="card">
        <h2>All Laboratory Tests (<?= count($tests) ?>)</h2>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th><th>Test</th><th>Category</th>
                    <th>Price</th><th>Normal Range</th><th>Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tests as $test): ?>
                <tr>
                    <td><?= h($test['id']) ?></td>
                    <td><?= h($test['test_name']) ?></td>
                    <td><?= h($test['category']) ?></td>
                    <td><?= h($test['price']) ?></td>
                    <td><?= h($test['normal_range'] ?: '-') ?></td>
                    <td class="status <?= h($test['status']) ?>"><?= h($test['status']) ?></td>
                    <td>
                        <div class="actions">
                            <a class="btn" href="?edit=<?= h($test['id']) ?>">Edit</a>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="id" value="<?= h($test['id']) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <button class="secondary" type="submit">Toggle</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Delete this test?')">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="id" value="<?= h($test['id']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <button class="danger" type="submit">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$tests): ?>
                <tr><td colspan="7">No laboratory tests found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>
</main>
</body>
</html>
