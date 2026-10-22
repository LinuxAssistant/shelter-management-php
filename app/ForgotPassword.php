<?php
declare(strict_types=1);

function passwordResetEmailConfigured(): bool {
    $base=(string)(getenv('APP_URL')?:'');
    $parts=parse_url($base);
    return getenv('PASSWORD_RESET_EMAIL_ENABLED')==='true'
        && filter_var($base,FILTER_VALIDATE_URL)!==false
        && $parts!==false && ($parts['scheme']??'')==='https'
        && !isset($parts['user']) && !isset($parts['pass'])
        && !isset($parts['query']) && !isset($parts['fragment'])
        && filter_var((string)getenv('MAIL_FROM'),FILTER_VALIDATE_EMAIL)!==false;
}

/** Send only when explicitly configured for an HTTPS public origin. */
function sendPasswordResetEmail(string $email,string $token): bool {
    if (!passwordResetEmailConfigured()) return false;
    $base=rtrim((string)getenv('APP_URL'),'/');
    $from=(string)getenv('MAIL_FROM');
    $url=$base.'/reset-password?token='.rawurlencode($token);
    $subject='Shelter Management password reset';
    $body="A password reset was requested for your account.\n\nOpen this link within 30 minutes:\n".$url."\n\nIf you did not request this, ignore this message.\n";
    return mail($email,$subject,$body,[
        'From'=>$from,
        'Content-Type'=>'text/plain; charset=UTF-8',
        'X-Auto-Response-Suppress'=>'All'
    ]);
}
function forgotPasswordForm(): void {
    header('Cache-Control: no-store');
    view('Forgot password','<form method="post" action="/forgot-password"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Employee email <input type="email" name="email" maxlength="255" autocomplete="email" required></label><button>Request reset link</button></form>');
}
function forgotPasswordSubmit(): void {
    verifyCsrf();
    header('Cache-Control: no-store');
    $email=strtolower(trim((string)($_POST['email']??'')));
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    $allowed=authRateHit('reset-ip',$ip,20,3600);
    if ($email!=='' && strlen($email)<=255 && filter_var($email,FILTER_VALIDATE_EMAIL)) {
        $allowed=authRateHit('reset-email',$email,3,3600) && $allowed;
        if ($allowed && passwordResetEmailConfigured()) {
            $stmt=db()->prepare('SELECT id,email FROM users WHERE email=? AND active=1 LIMIT 1');
            $stmt->bind_param('s',$email); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
            if ($user) {
                try { $token=issuePasswordResetToken((int)$user['id'],(string)$user['email']); }
                catch (DomainException $e) {
                    view('Check your email','<p>If the account exists and email delivery is configured, a password reset link will be sent.</p>'); return;
                }
                if (!sendPasswordResetEmail((string)$user['email'],$token)) {
                    revokePasswordResetToken($token);
                    passwordAudit('reset_delivery_failed',null,(int)$user['id']);
                }
            }
        }
    }
    view('Check your email','<p>If the account exists and email delivery is configured, a password reset link will be sent.</p>');
}
