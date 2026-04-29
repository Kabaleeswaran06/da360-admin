<?php
// ── Auth Configuration ──
// Credentials are now stored in the `admin_users` database table.
// See schema: admin_users.sql

function sessionStart() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function isLoggedIn(): bool {
    sessionStart();
    return isset($_SESSION['da360_user']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: /da360-admin/login.php');
        exit;
    }
}

function getCurrentUser(): ?array {
    sessionStart();
    return $_SESSION['da360_user'] ?? null;
}

function attemptLogin(string $username, string $password): bool {
    require_once __DIR__ . '/db.php';

    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT id, username, password_hash, name, role
            FROM admin_users
            WHERE username = :username
              AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            sessionStart();
            $_SESSION['da360_user'] = [
                'id'       => $user['id'],
                'username' => $user['username'],
                'name'     => $user['name'],
                'role'     => $user['role'],
            ];
            return true;
        }
    } catch (Exception $e) {
        // Log error silently — don't expose DB details to the login page
        error_log('DA360 login error: ' . $e->getMessage());
    }

    return false;
}

function logout(): void {
    sessionStart();
    session_destroy();
    header('Location: /da360-admin/login.php');
    exit;
}
