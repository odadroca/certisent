<?php
declare(strict_types=1);

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function has_role(array $u, string $role): bool {
    // order of power: admin > viewer > auditor (auditor is read-only)
    $map = ['auditor'=>1,'viewer'=>2,'admin'=>3];
    return ($map[$u['role']] ?? 0) >= ($map[$role] ?? 0);
}

function require_role(string $role): array {
    $u = require_login();
    if (!has_role($u, $role)) {
        http_response_code(403);
        echo "Forbidden.";
        exit;
    }
    return $u;
}

function login_user(array $userRow): void {
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$userRow['id'],
        'email' => $userRow['email'],
        'role' => $userRow['role'],
    ];
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function user_agent(): string {
    return $_SERVER['HTTP_USER_AGENT'] ?? '';
}
