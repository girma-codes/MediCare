
<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['doctor']);

$userId = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$successMessage = $_SESSION['doctor_flash_success'] ?? '';
$errorMessage = $_SESSION['doctor_flash_error'] ?? '';

unset(
    $_SESSION['doctor_flash_success'],
    $_SESSION['doctor_flash_error']
);

/*
|--------------------------------------------------------------------------
| GET DOCTOR PROFILE
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id
    FROM doctors
    WHERE user_id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$doctor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    exit('Doctor profile not found.');
}

$doctorId = (int) $doctor['id'];

/*
|--------------------------------------------------------------------------
| UPDATE APPOINTMENT AND SEND NOTIFICATION
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_appointment'])
) {

    try {

        $submittedToken = $_POST['csrf_token'] ?? '';

        if (
            !is_string($submittedToken) ||
            !hash_equals($csrfToken, $submittedToken)
        ) {
            throw new Exception('Invalid security token. Please refresh the page.');
        }

        $appointmentId = filter_var(
            $_POST['appointment_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $newStatus = $_POST['status'] ?? '';

        $allowedStatuses = [
            'confirmed',
            'completed',
            'cancelled'
        ];

        if (
            !$appointmentId ||
            !in_array($newStatus, $allowedStatuses, true)
        ) {
            throw new Exception('Invalid appointment information.');
        }

        $pdo->beginTransaction();

        /*
         * Get appointment belonging to this doctor.
         * Lock row to prevent simultaneous status changes.
         */
        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.status,
                p.user_id
            FROM appointments a
            INNER JOIN patients p ON p.id = a.patient_id
            WHERE a.id = ?
              AND a.doctor_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $appointmentId,
            $doctorId
        ]);

        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$appointment) {
            throw new Exception('Appointment not found.');
        }

        if (!in_array(
            $appointment['status'],
            ['pending', 'confirmed'],
            true
        )) {
            throw new Exception('This appointment can no longer be changed.');
        }

        if ($appointment['status'] === $newStatus) {
            throw new Exception('Appointment already has this status.');
        }

        if (empty($appointment['user_id'])) {
            throw new Exception('Patient account not found.');
        }

        /*
         * Update appointment.
         */
        $update = $pdo->prepare("
            UPDATE appointments
            SET status = ?
            WHERE id = ?
              AND doctor_id = ?
        ");

        $update->execute([
            $newStatus,
            $appointmentId,
            $doctorId
        ]);

        /*
         * Notification message.
         */
        $messages = [
            'confirmed' =>
                'Your appointment has been confirmed by your doctor.',

            'completed' =>
                'Your appointment has been completed. Thank you for visiting MediCare Hospital.',

            'cancelled' =>
                'Your appointment has been cancelled by your doctor.'
        ];

        /*
         * Send notification to patient's USER ID.
         * notifications.user_id references users.id.
         */
        $notify = $pdo->prepare("
            INSERT INTO notifications
                (user_id, message, is_read, created_at)
            VALUES
                (?, ?, 0, NOW())
        ");

        $notify->execute([
            $appointment['user_id'],
            $messages[$newStatus]
        ]);

        $pdo->commit();

        $_SESSION['doctor_flash_success'] =
            'Appointment updated successfully! Patient has been notified.';

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Doctor appointment error: ' . $e->getMessage()
        );

        $_SESSION['doctor_flash_error'] = $e instanceof PDOException
            ? 'Database error. Appointment was not updated.'
            : $e->getMessage();
    }

    header('Location: appointments.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| GET DOCTOR APPOINTMENTS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        a.id,
        a.appointment_date,
        a.appointment_time,
        a.reason,
        a.status,
        p.patient_number,
        p.phone,
        u.full_name AS patient_name

    FROM appointments a

    INNER JOIN patients p
        ON a.patient_id = p.id

    LEFT JOIN users u
        ON p.user_id = u.id

    WHERE a.doctor_id = ?

    ORDER BY
        a.appointment_date DESC,
        a.appointment_time DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$doctorId]);

$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($value): string {
    return htmlspecialchars(
        (string)($value ?? 'N/A'),
        ENT_QUOTES,
        'UTF-8'
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>My Appointments | MediCare HMS</title>

<style>

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    background: #f1f5f9;
    color: #1e293b;
}

header {
    background: #0f172a;
    color: white;
    padding: 22px 5%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

header h1 {
    font-size: 22px;
}

header a {
    color: white;
    text-decoration: none;
    background: #0d9488;
    padding: 11px 16px;
    border-radius: 7px;
}

main {
    max-width: 1400px;
    margin: 35px auto;
    padding: 0 20px;
}

.card {
    background: white;
    padding: 22px;
    border-radius: 12px;
    box-shadow: 0 3px 12px #00000008;
}

.card h2 {
    margin-bottom: 20px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}

th, td {
    text-align: left;
    padding: 14px;
    border-bottom: 1px solid #e2e8f0;
}

th {
    background: #f8fafc;
    color: #475569;
    font-size: 13px;
}

td {
    font-size: 14px;
}

.status {
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 12px;
    text-transform: capitalize;
}

.pending {
    background: #fef3c7;
    color: #92400e;
}

.confirmed {
    background: #dbeafe;
    color: #1d4ed8;
}

.completed {
    background: #dcfce7;
    color: #166534;
}

.cancelled {
    background: #fee2e2;
    color: #991b1b;
}

form {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

button {
    border: 0;
    padding: 8px 11px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
}

button:disabled {
    opacity: .6;
    cursor: not-allowed;
}

.confirm {
    background: #dbeafe;
    color: #1d4ed8;
}

.complete {
    background: #dcfce7;
    color: #166534;
}

.cancel {
    background: #fee2e2;
    color: #991b1b;
}

.empty {
    text-align: center;
    padding: 30px;
    color: #64748b;
}

.alert-success,
.alert-error {
    padding: 15px 18px;
    margin-bottom: 20px;
    border-radius: 9px;
    font-size: 14px;
}

.alert-success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.muted {
    color: #94a3b8;
    font-size: 12px;
}

@media(max-width:600px) {

    main {
        padding: 0 12px;
    }

    .card {
        padding: 14px;
    }

    header h1 {
        font-size: 18px;
    }

}

</style>

</head>

<body>

<header>

    <h1>MediCare HMS | My Appointments</h1>

    <a href="dashboard.php">
        Back to Dashboard
    </a>

</header>


<main>

<section class="card">

    <h2>Patient Appointments</h2>

    <?php if ($successMessage): ?>

        <div class="alert-success">
            <?= h($successMessage) ?>
        </div>

    <?php endif; ?>


    <?php if ($errorMessage): ?>

        <div class="alert-error">
            <?= h($errorMessage) ?>
        </div>

    <?php endif; ?>


    <div class="table-wrap">

    <table>

        <thead>

        <tr>
            <th>Patient</th>
            <th>Patient Number</th>
            <th>Phone</th>
            <th>Date</th>
            <th>Time</th>
            <th>Reason</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>

        </thead>


        <tbody>

        <?php if (!$appointments): ?>

            <tr>
                <td colspan="8" class="empty">
                    No appointments found.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($appointments as $a): ?>

            <tr>

                <td>
                    <?= h($a['patient_name']) ?>
                </td>

                <td>
                    <?= h($a['patient_number']) ?>
                </td>

                <td>
                    <?= h($a['phone']) ?>
                </td>

                <td>
                    <?= h($a['appointment_date']) ?>
                </td>

                <td>
                    <?= h($a['appointment_time']) ?>
                </td>

                <td>
                    <?= h($a['reason']) ?>
                </td>

                <td>

                    <span class="status <?= h($a['status']) ?>">
                        <?= h($a['status']) ?>
                    </span>

                </td>

                <td>

                <?php if (in_array(
                    $a['status'],
                    ['pending', 'confirmed'],
                    true
                )): ?>

                    <form method="POST">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= h($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="appointment_id"
                            value="<?= (int)$a['id'] ?>"
                        >

                        <?php if ($a['status'] === 'pending'): ?>

                            <button
                                type="submit"
                                name="status"
                                value="confirmed"
                                class="confirm"
                            >
                                Confirm
                            </button>

                        <?php endif; ?>


                        <button
                            type="submit"
                            name="status"
                            value="completed"
                            class="complete"
                            onclick="return confirm('Mark this appointment as completed?')"
                        >
                            Complete
                        </button>


                        <button
                            type="submit"
                            name="status"
                            value="cancelled"
                            class="cancel"
                            onclick="return confirm('Are you sure you want to cancel this appointment?')"
                        >
                            Cancel
                        </button>


                        <input
                            type="hidden"
                            name="update_appointment"
                            value="1"
                        >

                    </form>

                <?php else: ?>

                    <span class="muted">
                        No actions
                    </span>

                <?php endif; ?>

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
