<?php
// A session remembers the form token and the short success message.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if (!isset($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
$error = '';
$tasks = [];
$ready = false;

// Convert special characters into text before displaying them in HTML.
function escapeText(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    require __DIR__ . '/db.php';
    $ready = true;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check that the submitted form belongs to this browser session.
        $token = $_POST['token'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['token'], $token)) {
            $error = 'This form has expired. Refresh the page and try again.';
            http_response_code(400);
        } elseif (($_POST['action'] ?? '') === 'add') {
            $task = is_string($_POST['task'] ?? null) ? trim($_POST['task']) : '';

            if ($task === '' || !mb_check_encoding($task, 'UTF-8') || mb_strlen($task, 'UTF-8') > 255) {
                $error = 'Enter a task between 1 and 255 characters long.';
                http_response_code(400);
            } else {
                // The placeholder keeps task text separate from the SQL command.
                $stmt = $conn->prepare('INSERT INTO tasks (task) VALUES (?)');
                $stmt->bind_param('s', $task);
                $stmt->execute();
                $stmt->close();
                $_SESSION['message'] = 'Task added and saved to the database.';

                // Redirect so that refreshing does not submit the task again.
                header('Location: index.php', true, 303);
                exit;
            }
        } elseif (($_POST['action'] ?? '') === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || $id === null) {
                $error = 'Choose a valid task to delete.';
                http_response_code(400);
            } else {
                // Delete only the row whose numeric ID was submitted.
                $stmt = $conn->prepare('DELETE FROM tasks WHERE id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $deleted = $stmt->affected_rows;
                $stmt->close();
                $_SESSION['message'] = $deleted ? 'Task deleted.' : 'That task has already been deleted.';
                header('Location: index.php', true, 303);
                exit;
            }
        } else {
            $error = 'Choose Add task or Delete.';
            http_response_code(400);
        }
    }

    // The newest tasks appear first. Refreshing reads the saved rows again.
    $result = $conn->query('SELECT id, task FROM tasks ORDER BY id DESC');
    $tasks = $result->fetch_all(MYSQLI_ASSOC);
    $conn->close();
} catch (Throwable $exception) {
    // Keep technical details in the server log rather than showing them publicly.
    error_log('To-Do List: ' . $exception->getMessage());
    http_response_code(503);
    $ready = false;
    $error = 'The database is not ready. Complete the Lab 7 installer, or ask your lecturer to help check the Apache error log.';
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>To-Do List | DevOps Lab 7</title>
    <style>
        :root { color-scheme: light; --ink:#13293d; --muted:#526577; --red:#8c1e1e; --teal:#007a87; }
        * { box-sizing:border-box; }
        body { margin:0; background:#f2f5f7; color:var(--ink); font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif; }
        main { width:min(720px,calc(100% - 32px)); margin:56px auto; }
        .eyebrow { color:var(--red); font-size:.78rem; font-weight:750; letter-spacing:.14em; text-transform:uppercase; }
        h1 { margin:8px 0; font-size:clamp(2rem,6vw,3rem); line-height:1.15; letter-spacing:-.04em; }
        .intro,footer { color:var(--muted); }
        .panel { margin-top:28px; padding:28px; background:white; border:1px solid #dce4ea; border-radius:16px; box-shadow:0 12px 36px #13293d09; }
        label { display:block; font-weight:700; margin-bottom:9px; }
        .add-row { display:flex; gap:10px; }
        input { min-width:0; flex:1; font:inherit; border:1px solid #a6b5c2; border-radius:8px; padding:12px; }
        button { font:inherit; font-weight:650; cursor:pointer; border:0; border-radius:8px; padding:12px 18px; background:var(--red); color:white; }
        button:disabled { opacity:.5; cursor:not-allowed; }
        :focus-visible { outline:3px solid var(--teal); outline-offset:3px; }
        .hint { color:var(--muted); font-size:.84rem; margin:8px 0 0; }
        .list-title { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:28px; }
        h2 { font-size:1.15rem; margin:0; }
        .count { color:var(--teal); background:#e9f7f6; padding:3px 12px; border-radius:30px; font-size:.85rem; }
        ul { list-style:none; padding:0; margin:12px 0 0; }
        li { display:flex; justify-content:space-between; align-items:center; gap:18px; border-top:1px solid #e2e8ed; padding:15px 0; }
        .task { overflow-wrap:anywhere; }
        .delete { padding:7px 12px; color:var(--red); border:1px solid #e4c8c8; background:#fff8f8; font-size:.85rem; }
        .notice { padding:13px 16px; margin:20px 0 0; border-radius:8px; background:#e9f7f1; border-left:4px solid #207554; }
        .error { background:#fff2ee; border-color:#a83c26; }
        .empty { color:var(--muted); padding:20px 0 4px; }
        footer { font-size:.82rem; padding:18px 2px; }
        @media(max-width:500px) { main{margin-top:30px}.panel{padding:20px}.add-row{flex-direction:column} }
    </style>
</head>
<body>
<main>
    <div class="eyebrow">DevOps Principles and Practice · Lab 7</div>
    <h1>To-Do List</h1>
    <p class="intro">Add a task, refresh the page and see your database at work.</p>

    <?php if ($message !== ''): ?>
        <p class="notice" role="status"><?= escapeText($message) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="notice error" role="alert"><?= escapeText($error) ?></p>
    <?php endif; ?>

    <section class="panel" aria-label="Your task list">
        <form method="post" action="index.php">
            <input type="hidden" name="token" value="<?= escapeText($_SESSION['token']) ?>">
            <input type="hidden" name="action" value="add">
            <label for="task">What needs doing?</label>
            <div class="add-row">
                <input id="task" name="task" type="text" maxlength="255" required placeholder="e.g. Test the deployment" aria-describedby="task-hint" <?= $ready ? '' : 'disabled' ?>>
                <button type="submit" <?= $ready ? '' : 'disabled' ?>>Add task</button>
            </div>
            <p class="hint" id="task-hint">Use example tasks for this classroom activity. Maximum 255 characters.</p>
        </form>

        <div class="list-title">
            <h2>Saved tasks</h2>
            <span class="count"><?= count($tasks) ?> saved</span>
        </div>
        <ul id="task-list">
        <?php foreach ($tasks as $row): ?>
            <li>
                <span class="task"><?= escapeText($row['task']) ?></span>
                <form method="post" action="index.php">
                    <input type="hidden" name="token" value="<?= escapeText($_SESSION['token']) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button class="delete" type="submit" aria-label="Delete task: <?= escapeText($row['task']) ?>">Delete</button>
                </form>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php if ($ready && count($tasks) === 0): ?>
            <p class="empty">Your list is empty. Add your first task above.</p>
        <?php endif; ?>
    </section>
    <footer>Classroom demo · PHP + MySQL on Ubuntu EC2 · One shared list per server</footer>
</main>
</body>
</html>
