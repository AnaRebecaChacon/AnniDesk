<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/security.php';
initializeWebRequest();
require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/views/layouts/helpers.php';

$pdo = getDatabaseConnection();
$user = getAuthenticatedUser($pdo);

if ($user === null) {
    header('Location: /');
    exit;
}

if ($user['role_id'] !== 1) {
    http_response_code(403);
    exit('Solo el perfil solicitante puede crear tickets.');
}

csrfToken();

$errors = [];
$success = isset($_GET['created']) && $_GET['created'] === '1';
$title = '';
$description = '';
$categoryId = '';
$priority = 'medium';
$priorities = ticketPriorityLabels();
$validPriorities = array_keys($priorities);

try {
    $stmt = $pdo->query(
        'SELECT id, name FROM categories
         WHERE is_active = 1
         ORDER BY name'
    );

    $categories = $stmt->fetchAll();
    $categoryIds = array_fill_keys(array_column($categories, 'id'), true);
} catch (Throwable $exception) {
    error_log('Error al cargar categorías: ' . $exception->getMessage());
    http_response_code(500);
    exit('No se pudieron cargar las categorías. Inténtalo más tarde.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Solicitud no válida. Recarga la página e inténtalo de nuevo.');
    }

    $titleInput = $_POST['title'] ?? '';
    $descriptionInput = $_POST['description'] ?? '';
    $categoryInput = $_POST['category_id'] ?? '';
    $priorityInput = $_POST['priority'] ?? 'medium';

    if (!is_string($titleInput) || !is_string($descriptionInput)) {
        $errors[] = 'El título y la descripción deben ser texto válido.';
        $titleInput = '';
        $descriptionInput = '';
    }

    $title = trim($titleInput);
    $description = trim($descriptionInput);

    if ($title === '' || mb_strlen($title) > 200) {
        $errors[] = 'El título es obligatorio y debe tener máximo 200 caracteres.';
    }

    if ($description === '' || mb_strlen($description) > 10000) {
        $errors[] = 'La descripción es obligatoria y debe tener máximo 10 000 caracteres.';
    }

    if (!is_string($categoryInput) ||
        ($categoryInput !== '' &&
         (!ctype_digit($categoryInput) || (int) $categoryInput < 1))) {
        $errors[] = 'Selecciona una categoría válida.';
    }

    if (!is_string($priorityInput) ||
        !in_array($priorityInput, $validPriorities, true)) {
        $errors[] = 'Selecciona una prioridad válida.';
    }

    $categoryId = !is_string($categoryInput) || $categoryInput === ''
        ? null
        : (int) $categoryInput;
    $priority = is_string($priorityInput) ? $priorityInput : 'medium';

    if (!$errors && $categoryId !== null && !isset($categoryIds[$categoryId])) {
        $errors[] = 'La categoría seleccionada no está disponible.';
    }

    if (!$errors) {
        try {
            require_once __DIR__ . '/../app/controllers/TicketController.php';

            createTicket(
                $user['id'],
                $title,
                $description,
                $categoryId,
                $priority
            );

            header('Location: /tickets.php?created=1');
            exit;
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Error al crear ticket: ' . $exception->getMessage());
            $errors[] = 'No se pudo crear el ticket. Inténtalo nuevamente.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Crear ticket | AnniDesk</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php
$currentPage = '/tickets.php';
require __DIR__ . '/../app/views/layouts/navigation.php';
?>
<main class="tickets-wrap ticket-create-page">
    <header class="tickets-toolbar">
        <div>
            <h1>Crear solicitud</h1>
            <p>Describe el problema para que el equipo pueda ayudarte.</p>
        </div>
    </header>
    <section class="tickets-table-wrap ticket-form">
        <p class="eyebrow">Nueva solicitud</p>
        <h2>Crear una solicitud de soporte</h2>

        <?php if ($success): ?>
            <div class="form-alert form-success" role="status">
                ¡Ticket creado correctamente!
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="form-alert form-error" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="/tickets.php">
            <input type="hidden" name="csrf_token"
                value="<?= e(csrfToken()) ?>">

            <label for="title">Título del problema</label>
            <input id="title" name="title" type="text" maxlength="200"
                value="<?= e($title) ?>" required>

            <label for="description">Descripción</label>
            <textarea id="description" name="description" maxlength="10000" required><?= e($description) ?></textarea>

            <label for="category_id">Categoría</label>
            <select id="category_id" name="category_id">
                <option value="">Sin categoría</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>"
                        <?= (string) $categoryId === (string) $category['id'] ? 'selected' : '' ?>>
                        <?= e($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="priority">Prioridad</label>
            <select id="priority" name="priority">
                <?php foreach ($priorities as $value => $label): ?>
                    <option value="<?= $value ?>"
                        <?= $priority === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Crear ticket</button>
        </form>
    </section>
</main>
</body>
</html>
