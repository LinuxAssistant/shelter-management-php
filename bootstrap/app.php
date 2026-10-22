<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
foreach (file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key,$value] = explode('=', $line, 2);
    if (getenv(trim($key)) === false) putenv(trim($key) . '=' . trim($value));
}
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (getenv('SESSION_SECURE') === 'true' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')) ini_set('session.cookie_secure', '1');
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

function db(): mysqli {
    static $db = null;
    if ($db === null) {
        $db = new mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: '', getenv('DB_PASSWORD') ?: '', getenv('DB_NAME') ?: '', (int)(getenv('DB_PORT') ?: 3306));
        $db->set_charset('utf8mb4');
    }
    return $db;
}
function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function requireAuth(): void {
    if (empty($_SESSION['user_id'])) { header('Location: /login'); exit; }
    $now=time();
    if ((isset($_SESSION['last_activity']) && $now-(int)$_SESSION['last_activity']>1800) || (isset($_SESSION['signed_in_at']) && $now-(int)$_SESSION['signed_in_at']>43200)) {
        $_SESSION=[]; session_regenerate_id(true); header('Location: /login'); exit;
    }
    $_SESSION['signed_in_at']??=$now; $_SESSION['last_activity']=$now;
    $id=(int)$_SESSION['user_id'];
    $stmt=db()->prepare('SELECT active FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i',$id); $stmt->execute();
    $user=$stmt->get_result()->fetch_assoc();
    if (!$user || !(int)$user['active']) {
        $_SESSION=[];
        session_regenerate_id(true);
        header('Location: /login'); exit;
    }
}
function can(string $permission): bool {
    if (empty($_SESSION['user_id'])) return false;
    $stmt=db()->prepare('SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=? AND p.name=? LIMIT 1');
    $stmt->bind_param('is', $_SESSION['user_id'], $permission); $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}
function requirePermission(string $permission): void { requireAuth(); if (!can($permission)) { http_response_code(403); exit('Forbidden'); } }
function verifyCsrf(): void { if (!hash_equals(csrf(), (string)($_POST['_csrf'] ?? ''))) { http_response_code(419); exit('Invalid CSRF token'); } }
function view(string $title,string $body): void {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.escape($title).'</title><link rel="stylesheet" href="/assets/app.css"><main><nav><a href="/">Shelter Management</a> ';
    if (!empty($_SESSION['user_id'])) echo '<a href="/incidents">Incidents</a> <form method="post" action="/logout" class="inline"><input type="hidden" name="_csrf" value="'.csrf().'"><button>Log out</button></form>';
    echo '</nav><h1>'.escape($title).'</h1>'.$body.'</main></html>';
}
