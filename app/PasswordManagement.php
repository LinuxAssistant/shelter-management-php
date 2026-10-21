<?php
declare(strict_types=1);

function passwordChangeForm(): void {
    requireAuth();
    view('Change password','<form method="post" action="/account/password"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Current password <input type="password" name="current_password" autocomplete="current-password" required></label><label>New password (at least 12 characters) <input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label><label>Confirm new password <input type="password" name="confirm_password" minlength="12" autocomplete="new-password" required></label><button>Change password</button></form>');
}
function passwordChangeSave(): void {
    requireAuth(); verifyCsrf();
    $current=(string)($_POST['current_password']??'');
    $new=(string)($_POST['new_password']??'');
    $confirm=(string)($_POST['confirm_password']??'');
    if (strlen($new)<12 || strlen($new)>1024 || !hash_equals($new,$confirm)) {
        http_response_code(422); view('Invalid password','<p>New passwords must match and contain at least 12 characters.</p><a href="/account/password">Try again</a>'); return;
    }
    $id=(int)$_SESSION['user_id'];
    $stmt=db()->prepare('SELECT password_hash FROM users WHERE id=? AND active=1');
    $stmt->bind_param('i',$id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc();
    if (!$row || !password_verify($current,$row['password_hash'])) {
        http_response_code(403); view('Password unchanged','<p>Current password was incorrect.</p><a href="/account/password">Try again</a>'); return;
    }
    if (password_verify($new,$row['password_hash'])) {
        http_response_code(422); view('Password unchanged','<p>Choose a different password.</p><a href="/account/password">Try again</a>'); return;
    }
    $hash=password_hash($new,PASSWORD_DEFAULT);
    $oldHash=$row['password_hash'];
    $stmt=db()->prepare('UPDATE users SET password_hash=? WHERE id=? AND password_hash=? AND active=1');
    $stmt->bind_param('sis',$hash,$id,$oldHash); $stmt->execute();
    if ($stmt->affected_rows!==1) { http_response_code(409); exit('Password changed concurrently. Please sign in again.'); }
    $_SESSION=[]; session_regenerate_id(true); session_destroy();
    view('Password updated','<p>Password updated. Please <a href="/login">sign in again</a>.</p>');
}
