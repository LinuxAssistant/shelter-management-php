<?php
declare(strict_types=1);

function passwordResetForm(): void {
    $token=(string)($_GET['token']??'');
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) { http_response_code(400); view('Invalid reset link','<p>This password reset link is invalid.</p>'); return; }
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    view('Reset password','<form method="post" action="/reset-password"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="token" value="'.escape($token).'"><label>New password (12 characters minimum) <input type="password" name="password" minlength="12" maxlength="1024" required autocomplete="new-password"></label><label>Confirm password <input type="password" name="confirmation" minlength="12" maxlength="1024" required autocomplete="new-password"></label><button>Reset password</button></form>');
}
function passwordResetSubmit(): void {
    verifyCsrf();
    header('Cache-Control: no-store');
    $token=(string)($_POST['token']??'');
    $password=(string)($_POST['password']??'');
    $confirmation=(string)($_POST['confirmation']??'');
    if ($password!==$confirmation || strlen($password)<12 || strlen($password)>1024) {
        http_response_code(422); view('Invalid password','<p>Passwords must match and be at least 12 characters.</p>'); return;
    }
    if (!consumePasswordResetToken($token,$password)) {
        http_response_code(400); view('Reset link expired','<p>The reset link is invalid, expired, or already used.</p>'); return;
    }
    $_SESSION=[]; session_destroy();
    view('Password reset','<p>Your password has been reset. <a href="/login">Sign in</a> with your new password.</p>');
}
