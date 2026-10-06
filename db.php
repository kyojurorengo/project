<?php
// This file stays in GitHub. The password stays on the EC2 server.
// The installer creates this password file outside Apache's web folder.
$passwordFile = getenv('TODOLIST_PASSWORD_FILE') ?: '/etc/todolist/db-password';

if (!is_readable($passwordFile)) {
    throw new RuntimeException('The database password file is missing or unreadable. Run the Lab 7 installer.');
}

$password = trim(file_get_contents($passwordFile));
if ($password === '') {
    throw new RuntimeException('The database password file is empty.');
}

// Report database errors to the application so it can show a useful message.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('TODOLIST_DB_HOST') ?: 'localhost';
$conn = new mysqli($host, 'todo_user', $password, 'todolist');
$conn->set_charset('utf8mb4');
unset($password);
