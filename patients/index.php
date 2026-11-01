
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin']);

// Fetch all registered patients
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
    ORDER BY p.id DESC
";

$stmt = $pdo->query($sql);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPatients = count($patients);
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

/* Main Layout */

.main-container {
    max-width: 1450px;
    margin: auto;
    padding: 35px;
}

/* Header */

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

/* Buttons */

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

/* Statistics */

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
    color: #2563eb;
}

/* Patient Table */

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

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
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

.view-btn:hover {
    background: #dbeafe;
}

/* Empty State */

.empty-state {
    text-align: center;
    padding: 45px 20px;
    color: #64748b;
}

.empty-state h3 {
    color: #334155;
    margin-bottom: 8px;
}

/* Footer */

.page-footer {
    margin-top: 25px;
    color: #64748b;
    font-size: 13px;
    text-align: center;
}

/* Responsive */

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
}
</style>

</head>

<body>

<div class="main-container">

    <!-- Page Header -->

    <header class="page-header">

        <div class="page-title">

            <h1>Patient Management</h1>

            <p>
                Manage and view registered hospital patients.
            </p>

        </div>

        <div class="header-actions">

            <a href="../admin/dashboard.php" class="btn btn-secondary">
                ← Dashboard
            </a>

            <a href="register.php" class="btn btn-primary">
                + Register Patient
            </a>

        </div>

    </header>


    <!-- Statistics -->

    <section class="stats-grid">

        <div class="stat-card">

            <span>Total Registered Patients</span>

            <h2>
                <?= number_format($totalPatients) ?>
            </h2>

        </div>

    </section>


    <!-- Patient Table -->

    <section class="table-card">

        <div class="table-header">

            <div>

                <h2>Patient Records</h2>

                <p>
                    List of all registered hospital patients.
                </p>

            </div>

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

                        <th>Action</th>

                    </tr>

                </thead>


                <tbody>

                <?php if ($totalPatients > 0): ?>

                    <?php foreach ($patients as $patient): ?>

                        <tr>

                            <td class="patient-number">

                                <?= htmlspecialchars($patient['patient_number'] ?? 'N/A') ?>

                            </td>


                            <td class="patient-name">

                                <?= htmlspecialchars($patient['full_name'] ?? 'N/A') ?>

                            </td>


                            <td>

                                <?= htmlspecialchars($patient['email'] ?? 'N/A') ?>

                            </td>


                            <td>

                                <span class="gender">

                                    <?= htmlspecialchars(ucfirst($patient['gender'] ?? 'N/A')) ?>

                                </span>

                            </td>


                            <td>

                                <?= htmlspecialchars($patient['phone'] ?? 'N/A') ?>

                            </td>


                            <td>

                                <?= htmlspecialchars($patient['date_of_birth'] ?? 'N/A') ?>

                            </td>


                            <td>

                                <a
                                    href="view.php?id=<?= (int)$patient['id'] ?>"
                                    class="view-btn"
                                >

                                    View Details

                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="7">

                            <div class="empty-state">

                                <h3>No Patients Found</h3>

                                <p>
                                    There are no registered patients yet.
                                </p>

                                <br>

                                <a href="register.php" class="btn btn-primary">
                                    Register First Patient
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
