<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require dirname(__DIR__) . '/bootstrap/app.php';
$email=$argv[1] ?? ''; $badge=$argv[2] ?? '';
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || $badge==='') exit("Usage: php scripts/create-admin.php EMAIL BADGE\n");
echo "Password: "; $password=trim((string)fgets(STDIN));
if (strlen($password)<12) exit("Password must have at least 12 characters\n");
$hash=password_hash($password,PASSWORD_DEFAULT);
$stmt=db()->prepare('INSERT INTO users(email,badge_number,password_hash) VALUES(?,?,?)'); $stmt->bind_param('sss',$email,$badge,$hash); $stmt->execute(); $id=db()->insert_id;
$stmt=db()->prepare("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE name='Administrator'"); $stmt->bind_param('i',$id); $stmt->execute(); echo "Administrator created\n";
