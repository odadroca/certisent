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

function bearer_token_from_headers(): ?string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (!$hdr) return null;
    if (stripos($hdr, 'Bearer ') !== 0) return null;
    $t = trim(substr($hdr, 7));
    return $t !== '' ? $t : null;
}

/**
 * API auth for worker endpoints.
 *
 * - Validates Bearer token against api_keys (hashed).
 * - Enforces scope.
 * - Fallback: .env API_WORKER_KEY (legacy), treated as full-scope.
 */
function require_api_scope(string $scope): array {
    $token = bearer_token_from_headers();
    if (!$token) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'missing_bearer']);
        exit;
    }

    // Legacy fallback (kept for upgrade safety)
    $legacy = (string)cfg('API_WORKER_KEY', '');
    if ($legacy !== '' && hash_equals($legacy, $token)) {
        return ['id'=>0,'name'=>'legacy','scopes_json'=>json_encode(['*'])];
    }

    $hash = hash('sha256', $token);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM api_keys WHERE token_hash_sha256 = :h AND is_active = 1 LIMIT 1');
    $stmt->execute([':h'=>$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'invalid_token']);
        exit;
    }
    $scopes = json_decode((string)$row['scopes_json'], true);
    if (!is_array($scopes)) $scopes = [];
    $allowed = in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'insufficient_scope']);
        exit;
    }
    // Update last_used_at (best-effort)
    try {
        $pdo->prepare('UPDATE api_keys SET last_used_at = :t WHERE id = :id')->execute([':t'=>db_now_utc(), ':id'=>$row['id']]);
    } catch (Throwable $e) {
        // ignore
    }
    return $row;
}
