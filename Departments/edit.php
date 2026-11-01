
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, name, description
    FROM departments
    WHERE id = :id
    LIMIT 1
");
$stmt->execute(['id' => $id]);
$department = $stmt->fetch();

if (!$department) {
    header('Location: index.php?error=not_found');
    exit;
}

$name = $department['name'];
$description = $department['description'] ?? '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Invalid request. Please refresh and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            $errors[] = 'Department name is required.';
        } elseif (strlen($name) > 100) {
            $errors[] = 'Department name must not exceed 100 characters.';
        }

        if (strlen($description) > 1000) {
            $errors[] = 'Description must not exceed 1000 characters.';
        }

        if (!$errors) {
            try {
                $check = $pdo->prepare("
                    SELECT id
                    FROM departments
                    WHERE LOWER(name) = LOWER(:name)
                    AND id != :id
                    LIMIT 1
                ");
                $check->execute([
                    'name' => $name,
                    'id' => $id
                ]);

                if ($check->fetch()) {
                    $errors[] = 'Another department already uses this name.';
                } else {
                    $update = $pdo->prepare("
                        UPDATE departments
                        SET name = :name,
                            description = :description
                        WHERE id = :id
                    ");

                    $update->execute([
                        'name' => $name,
                        'description' => $description !== '' ? $description : null,
                        'id' => $id
                    ]);

                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    header('Location: index.php?success=updated');
                    exit;
                }
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $errors[] = 'Unable to update department. Please try again.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Department | MediCare HMS</title>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Inter, Arial, sans-serif;
    background: #f4f7fb;
    color: #1e293b;
}

.container {
    max-width: 850px;
    margin: 45px auto;
    padding: 0 20px;
}

.back {
    display: inline-block;
    margin-bottom: 22px;
    color: #1769aa;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}

.card {
    background: white;
    border: 1px solid #e8edf4;
    border-radius: 15px;
    padding: 32px;
    box-shadow: 0 8px 25px rgba(20,50,80,.05);
}

.heading {
    margin-bottom: 28px;
}

.heading h1 {
    font-size: 24px;
    margin-bottom: 8px;
}

.heading p {
    color: #8491a5;
    font-size: 13px;
}

.form-group {
    margin-bottom: 22px;
}

label {
    display: block;
    margin-bottom: 9px;
    font-size: 13px;
    font-weight: 600;
}

input, textarea {
    width: 100%;
    padding: 13px 14px;
    border: 1px solid #dce3ec;
    border-radius: 8px;
    font-family: inherit;
    font-size: 13px;
    outline: none;
}

input:focus, textarea:focus {
    border-color: #1769aa;
    box-shadow: 0 0 0 3px rgba(23,105,170,.1);
}

textarea {
    min-height: 130px;
    resize: vertical;
}

.error-box {
    background: #fee2e2;
    border: 1px solid #fecaca;
    color: #991b1b;
    border-radius: 8px;
    padding: 14px 18px;
    margin-bottom: 22px;
    font-size: 12px;
    line-height: 1.8;
}

.help {
    color: #8491a5;
    font-size: 11px;
    margin-top: 7px;
}

.actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 28px;
}

.btn {
    display: inline-block;
    padding: 13px 20px;
    border-radius: 8px;
    border: none;
    text-decoration: none;
    font-family: inherit;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}

.primary {
    background: #1769aa;
    color: white;
}

.primary:hover {
    background: #104e83;
}

.secondary {
    background: #eef2f7;
    color: #475569;
}

@media(max-width: 600px) {
    .container {
        margin: 25px auto;
    }

    .card {
        padding: 22px 17px;
    }

    .heading h1 {
        font-size: 21px;
    }
}
</style>
</head>

<body>

<main class="container">

    <a href="index.php" class="back">
        ← Back to Departments
    </a>

    <section class="card">

        <header class="heading">
            <h1>Edit Department</h1>
            <p>Update the hospital department information.</p>
        </header>

        <?php if ($errors): ?>
            <div class="error-box">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="edit.php?id=<?= (int)$id ?>">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
            >

            <div class="form-group">
                <label for="name">Department Name *</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="100"
                    value="<?= htmlspecialchars($name) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="description">Department Description</label>
                <textarea
                    id="description"
                    name="description"
                    maxlength="1000"
                    placeholder="Describe this department..."
                ><?= htmlspecialchars($description) ?></textarea>
                <p class="help">Optional. Maximum 1000 characters.</p>
            </div>

            <div class="actions">
                <button type="submit" class="btn primary">
                    Save Changes
                </button>

                <a href="index.php" class="btn secondary">
                    Cancel
                </a>
            </div>

        </form>

    </section>

</main>

</body>
</html>
