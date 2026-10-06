#!/usr/bin/env bash
# DevOps Lab 7: run on a fresh Ubuntu 24.04 EC2 classroom instance.
# Usage: sudo bash install_todolist.sh https://github.com/YOUR_USERNAME/YOUR_REPOSITORY.git
# Optional second argument: branch name. The default is main.
set -Eeuo pipefail

STAGE='checking the inputs'
trap 'printf "\nERROR while %s (line %s). Read the first error above, fix it, then run the script again.\n" "$STAGE" "$LINENO" >&2' ERR

if [[ $EUID -ne 0 ]]; then
    echo 'Run this script using sudo bash, as shown in the lab.' >&2
    exit 1
fi

REPO_URL=${1:-}
BRANCH=${2:-main}
if [[ ! $REPO_URL =~ ^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]; then
    echo 'Supply your GitHub HTTPS repository URL. Do not include a token or password.' >&2
    echo 'Example: sudo bash install_todolist.sh https://github.com/YOUR_USERNAME/YOUR_REPOSITORY.git' >&2
    exit 1
fi

source /etc/os-release
if [[ $ID != ubuntu || $VERSION_ID != 24.04 ]]; then
    echo 'This classroom script is written for Ubuntu Server 24.04 LTS. Check your AMI.' >&2
    exit 1
fi

# Keep the cloned repository outside Apache's public web folder.
WORK_DIR=$(mktemp -d)
trap 'rm -rf -- "$WORK_DIR"' EXIT
WEB_DIR=/var/www/html/todolist
PASSWORD_FILE=/etc/todolist/db-password
export DEBIAN_FRONTEND=noninteractive

STAGE='installing the software'
echo '[1/6] Installing Git, Apache, PHP and MySQL...'
apt-get update
apt-get install -y git apache2 php libapache2-mod-php php-mysql php-mbstring mysql-server curl openssl

STAGE='fetching the application from GitHub'
echo '[2/6] Downloading the chosen branch...'
git check-ref-format --branch "$BRANCH" >/dev/null
GIT_TERMINAL_PROMPT=0 git clone --single-branch --branch "$BRANCH" -- "$REPO_URL" "$WORK_DIR/source"

# These must be the updated Lab 7 files, named exactly as shown.
for APP_FILE in index.php db.php; do
    if [[ ! -f "$WORK_DIR/source/$APP_FILE" || -L "$WORK_DIR/source/$APP_FILE" ]]; then
        echo "Missing $APP_FILE in the repository root. Check the filename, commit and push it, then retry." >&2
        exit 1
    fi
    php -l "$WORK_DIR/source/$APP_FILE"
done
if ! grep -Fq '/etc/todolist/db-password' "$WORK_DIR/source/db.php"; then
    echo 'db.php is not the updated Lab 7 version. Replace it with the supplied file, commit and push.' >&2
    exit 1
fi
COMMIT=$(git -C "$WORK_DIR/source" rev-parse --short HEAD)

STAGE='preparing the server password'
echo '[3/6] Creating or reusing the server-only database password...'
install -d -o root -g www-data -m 0750 /etc/todolist
if [[ ! -f "$PASSWORD_FILE" ]]; then
    # Hexadecimal characters are safe to use in the SQL below.
    umask 077
    openssl rand -hex 24 > "$WORK_DIR/password"
    install -o root -g www-data -m 0640 "$WORK_DIR/password" "$PASSWORD_FILE"
fi
DB_PASSWORD=$(cat "$PASSWORD_FILE")
if [[ ! $DB_PASSWORD =~ ^[a-f0-9]{48}$ ]]; then
    echo 'The existing password file has an unexpected format. Ask your lecturer before changing it.' >&2
    exit 1
fi
chown root:www-data "$PASSWORD_FILE"
chmod 0640 "$PASSWORD_FILE"

STAGE='preparing MySQL'
echo '[4/6] Preparing the database and tasks table...'
systemctl enable --now mysql

# IF NOT EXISTS preserves the table and its saved tasks on later runs.
# Use the local MySQL administrator connection; do not print the password.
mysql <<SQL
CREATE DATABASE IF NOT EXISTS todolist CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS todolist.tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'todo_user'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER 'todo_user'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT SELECT, INSERT, DELETE ON todolist.tasks TO 'todo_user'@'localhost';
SQL
unset DB_PASSWORD

STAGE='deploying the PHP files'
echo '[5/6] Copying the application into Apache...'
install -d -o root -g www-data -m 0755 "$WEB_DIR"
# Only the two application files are deployed. The .git folder stays private.
install -o root -g www-data -m 0644 "$WORK_DIR/source/db.php" "$WEB_DIR/db.php"
install -o root -g www-data -m 0644 "$WORK_DIR/source/index.php" "$WEB_DIR/index.php"

STAGE='checking the deployed application'
echo '[6/6] Checking Apache, MySQL and the local web response...'
apache2ctl configtest
systemctl enable --now apache2
systemctl reload apache2
systemctl is-active --quiet apache2
systemctl is-active --quiet mysql
curl --fail --silent --show-error --max-time 15 http://127.0.0.1/todolist/ -o "$WORK_DIR/response.html"
if grep -Fq '<?php' "$WORK_DIR/response.html" || ! grep -Fq 'id="task-list"' "$WORK_DIR/response.html"; then
    echo 'The web response is not the expected To-Do application. Check Apache and the PHP files.' >&2
    exit 1
fi

printf '\nSUCCESS: server checks passed. Deployed branch %s, commit %s.\n' "$BRANCH" "$COMMIT"
echo 'Open http://YOUR_PUBLIC_IPV4/todolist/ using the current public IPv4 address shown in EC2.'
echo 'Now add, refresh and delete a test task in the browser. These checks confirm the complete path.'
echo 'Running this installer again fetches the latest chosen branch and keeps the saved tasks.'
