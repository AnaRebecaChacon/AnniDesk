<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/security.php';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión | AnniDesk</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <main class="login-container">
        <section class="login-card">
            <h1>AnniDesk</h1>
            <p class="subtitle">Sistema de soporte técnico</p>

            <h2>Iniciar sesión</h2>

            <?php if (!empty($errorMessage)): ?>
                <p role="alert" class="login-error">
                    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>

            <form method="post" action="/login.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        csrfToken(),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <label for="email">Correo electrónico</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    autocomplete="username"
                    required
                >

                <label for="password">Contraseña</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >

                <button type="submit">Entrar</button>
            </form>

            <p class="help-text">
                Si necesitas acceso, contacta con el administrador.
            </p>
        </section>
    </main>
</body>
</html>
