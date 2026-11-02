
<?php
/**
 * MediCare HMS - Reusable Pharmacy Sidebar
 */

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

if (!function_exists('pharmacyEscape')) {
    function pharmacyEscape($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$pharmacyMenu = [
    [
        'title' => 'Dashboard',
        'icon'  => 'fa-chart-line',
        'url'   => 'dashboard.php',
        'group' => 'MAIN'
    ],
    [
        'title' => 'Medicine Inventory',
        'icon'  => 'fa-pills',
        'url'   => 'index.php',
        'group' => 'INVENTORY'
    ],
    [
        'title' => 'Add Medicine',
        'icon'  => 'fa-circle-plus',
        'url'   => 'add.php',
        'group' => 'INVENTORY'
    ],
    [
        'title' => 'Stock Management',
        'icon'  => 'fa-boxes-stacked',
        'url'   => 'stock.php',
        'group' => 'INVENTORY'
    ],
    [
        'title' => 'Expiry Management',
        'icon'  => 'fa-calendar-xmark',
        'url'   => 'expiry.php',
        'group' => 'INVENTORY'
    ],
    [
        'title' => 'Low Stock Alerts',
        'icon'  => 'fa-triangle-exclamation',
        'url'   => 'low_stock.php',
        'group' => 'INVENTORY'
    ],
    [
        'title' => 'Purchase Management',
        'icon'  => 'fa-cart-shopping',
        'url'   => 'purchase_request.php',
        'group' => 'MANAGEMENT'
    ],
    [
        'title' => 'Inventory Reports',
        'icon'  => 'fa-chart-pie',
        'url'   => 'reports.php',
        'group' => 'MANAGEMENT'
    ],
];

$sidebarGroups = [];

foreach ($pharmacyMenu as $item) {
    $sidebarGroups[$item['group']][] = $item;
}
?>

<style>
:root {
    --pharmacy-sidebar-width: 250px;
    --pharmacy-navy: #102a43;
    --pharmacy-blue: #2563eb;
}

.pharmacy-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: var(--pharmacy-sidebar-width);
    background: var(--pharmacy-navy);
    color: #fff;
    z-index: 1000;
    overflow-y: auto;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 4px 0 18px rgba(0,0,0,.08);
}

.pharmacy-sidebar * {
    box-sizing: border-box;
}

.pharmacy-brand {
    min-height: 85px;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 18px 22px;
    border-bottom: 1px solid rgba(255,255,255,.10);
}

.pharmacy-brand-icon {
    width: 43px;
    height: 43px;
    border-radius: 12px;
    background: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
}

.pharmacy-brand-text h2 {
    font-size: 18px;
    margin: 0;
    color: #fff;
    font-weight: 700;
}

.pharmacy-brand-text span {
    display: block;
    color: #a9bfd3;
    font-size: 11px;
    margin-top: 4px;
}

.pharmacy-sidebar-content {
    padding: 20px 12px;
    flex: 1;
}

.pharmacy-menu-group {
    margin-bottom: 22px;
}

.pharmacy-menu-heading {
    color: #8fa9c0;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.2px;
    padding: 0 12px;
    margin: 0 0 10px;
}

.pharmacy-menu-link {
    display: flex;
    align-items: center;
    gap: 13px;
    min-height: 45px;
    padding: 11px 13px;
    margin: 4px 0;
    border-radius: 9px;
    color: #d6e2ed;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: .2s ease;
}

.pharmacy-menu-link i {
    width: 19px;
    text-align: center;
    font-size: 15px;
}

.pharmacy-menu-link:hover {
    background: rgba(255,255,255,.09);
    color: #fff;
}

.pharmacy-menu-link.active {
    background: var(--pharmacy-blue);
    color: #fff;
    box-shadow: 0 4px 12px rgba(37,99,235,.25);
}

.pharmacy-sidebar-footer {
    padding: 15px 12px 20px;
    border-top: 1px solid rgba(255,255,255,.10);
}

.pharmacy-admin-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 13px;
    color: #d6e2ed;
    text-decoration: none;
    font-size: 13px;
    border-radius: 9px;
    transition: .2s ease;
}

.pharmacy-admin-link:hover {
    background: rgba(255,255,255,.09);
    color: #fff;
}

.pharmacy-admin-link i {
    width: 19px;
    text-align: center;
}

@media (max-width: 750px) {
    .pharmacy-sidebar {
        width: 68px;
    }

    .pharmacy-brand {
        justify-content: center;
        padding: 15px 5px;
    }

    .pharmacy-brand-text,
    .pharmacy-menu-heading,
    .pharmacy-menu-link span,
    .pharmacy-admin-link span {
        display: none;
    }

    .pharmacy-menu-link,
    .pharmacy-admin-link {
        justify-content: center;
        padding: 13px 5px;
    }

    .pharmacy-menu-link i,
    .pharmacy-admin-link i {
        font-size: 17px;
    }

    .pharmacy-sidebar-content {
        padding: 15px 7px;
    }
}
</style>

<aside class="pharmacy-sidebar">

    <div class="pharmacy-brand">
        <div class="pharmacy-brand-icon">
            <i class="fas fa-hospital"></i>
        </div>

        <div class="pharmacy-brand-text">
            <h2>MediCare HMS</h2>
            <span>PHARMACY MANAGEMENT</span>
        </div>
    </div>

    <div class="pharmacy-sidebar-content">

        <?php foreach ($sidebarGroups as $groupName => $items): ?>

            <div class="pharmacy-menu-group">

                <div class="pharmacy-menu-heading">
                    <?= pharmacyEscape($groupName) ?>
                </div>

                <?php foreach ($items as $item): ?>

                    <a
                        href="<?= pharmacyEscape($item['url']) ?>"
                        class="pharmacy-menu-link <?= $currentPage === $item['url'] ? 'active' : '' ?>"
                        <?= $currentPage === $item['url'] ? 'aria-current="page"' : '' ?>
                    >
                        <i class="fas <?= pharmacyEscape($item['icon']) ?>"></i>
                        <span><?= pharmacyEscape($item['title']) ?></span>
                    </a>

                <?php endforeach; ?>

            </div>

        <?php endforeach; ?>

    </div>

    <div class="pharmacy-sidebar-footer">

        <a href="../admin/dashboard.php" class="pharmacy-admin-link">
            <i class="fas fa-arrow-left"></i>
            <span>Back to Admin Dashboard</span>
        </a>

    </div>

</aside>
