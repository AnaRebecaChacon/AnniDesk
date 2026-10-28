<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$roleId = (int) $user['role_id'];
$currentPage = $currentPage ?? '';
$roleNames = userRoleLabels();
$roleName = $roleNames[$roleId] ?? 'Usuario';
$navigationItems = [
    ['label' => 'Panel', 'href' => '/dashboard.php', 'visible' => true],
    ['label' => 'Tickets', 'href' => '/gestion_tickets.php', 'visible' => true],
    [
        'label' => 'Crear solicitud',
        'href' => '/tickets.php',
        'visible' => $roleId === 1,
    ],
    [
        'label' => 'Usuarios',
        'href' => '/usuarios.php',
        'visible' => $roleId === 4,
    ],
    [
        'label' => 'Categorías',
        'href' => '/categorias.php',
        'visible' => in_array($roleId, [3, 4, 5], true),
    ],
    [
        'label' => 'Historial',
        'href' => '/historial.php',
        'visible' => in_array($roleId, [3, 4, 5], true),
    ],
    [
        'label' => 'Auditoría',
        'href' => '/auditoria.php',
        'visible' => in_array($roleId, [4, 5], true),
    ],
];
?>
<header class="app-topbar">
    <a class="app-brand" href="/dashboard.php">
        <span class="app-brand-mark" aria-hidden="true">A</span>
        <span>AnniDesk<small>Centro de soporte</small></span>
    </a>
    <nav class="app-nav" aria-label="Navegación principal">
        <?php foreach ($navigationItems as $item): ?>
            <?php if ($item['visible']): ?>
                <a
                    href="<?= e($item['href']) ?>"
                    <?= $currentPage === $item['href'] ? 'aria-current="page"' : '' ?>
                ><?= e($item['label']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="app-account">
        <div class="app-account-info">
            <span class="app-account-name"><?= e($user['full_name']) ?></span>
            <span class="app-account-role"><?= e($roleName) ?></span>
        </div>
        <form method="post" action="/logout.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <button type="submit" class="button-quiet">Salir</button>
        </form>
    </div>
</header>
