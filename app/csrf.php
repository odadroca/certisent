<?php
declare(strict_types=1);

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string {
    $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="_csrf" value="'.$t.'">';
}

function csrf_verify(): void {
    $sent = $_POST['_csrf'] ?? '';
    $ok = is_string($sent) && hash_equals($_SESSION['_csrf'] ?? '', $sent);
    if (!$ok) {
        http_response_code(400);
        echo "Bad request (CSRF).";
        exit;
    }
}
