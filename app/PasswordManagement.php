<?php
declare(strict_types=1);

function passwordChangeForm(): void {
    requireAuth();
    header('Cache-Control: no-store');
    view('Change password','<form method="post" action="/account/password"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Current password <input type="password" name="current_password" autocomplete="current-password" required></label><label>New password (at least 12 characters) <input type="password" name="new_password" minlength="12" maxlength="72" autocomplete="new-password" required></label><label>Confirm new password <input type="password" name="confirm_password" minlength="12" maxlength="72" autocomplete="new-password" required></label><button>Change password</button></form>');
}
function passwordChangeSave(): void {
    requireAuth(); verifyCsrf();
    header('Cache-Control: no-store');
    $current=(string)($_POST['current_password']??'');
    $new=(string)($_POST['new_password']??'');
    $confirm=(string)($_POST['confirm_password']??'');
    if (!validNewPassword($new) || !hash_equals($new,$confirm)) {
        http_response_code(422); view('Invalid password','<p>Passwords must match, contain at least 12 characters, and fit within 72 bytes.</p>'); return;
    }
    $id=(int)$_SESSION['user_id'];
    if (!authRateHit('password-change',(string)$id,5)) {
        http_response_code(429); header('Retry-After: 900'); exit('Too many attempts. Please wait 15 minutes.');
    }
    $database=db(); $database->begin_transaction();
    try {
        $stmt=$database->prepare('SELECT password_hash,session_version FROM users WHERE id=? AND active=1 FOR UPDATE');
        $stmt->bind_param('i',$id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc();
        if (!$row || (int)$row['session_version']!==(int)$_SESSION['session_version'] || !password_verify($current,$row['password_hash'])) {
            $database->rollback();
            http_response_code(403); view('Password unchanged','<p>Current password was incorrect or your session expired. Please sign in again.</p>'); return;
        }
        if (password_verify($new,$row['password_hash'])) {
            $database->rollback();
            http_response_code(422); view('Password unchanged','<p>Choose a different password.</p>'); return;
        }
        $hash=password_hash($new,PASSWORD_DEFAULT);
        $stmt=$database->prepare('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?');
        $stmt->bind_param('si',$hash,$id); $stmt->execute();
        $stmt=$database->prepare('DELETE FROM password_reset_tokens WHERE user_id=?');
        $stmt->bind_param('i',$id); $stmt->execute();
        passwordAudit('password_changed',$id,$id);
        authRateClear('password-change',(string)$id);
        $database->commit();
    } catch (Throwable $e) { $database->rollback(); throw $e; }
    $_SESSION=[]; session_regenerate_id(true); session_destroy();
    view('Password updated','<p>Password updated. Please <a href="/login">sign in again</a>.</p>');
}
