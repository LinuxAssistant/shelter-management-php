<?php
declare(strict_types=1);

/** Return a bearer token only to the mail sender; store only its digest. */
function issuePasswordResetToken(int $userId, ?string $expectedEmail=null): string {
    $token=bin2hex(random_bytes(32)); $hash=hash('sha256',$token);
    $database=db(); $database->begin_transaction();
    try {
        // Every password mutation locks the user before locking token rows.
        $stmt=$database->prepare('SELECT id,email FROM users WHERE id=? AND active=1 FOR UPDATE');
        $stmt->bind_param('i',$userId); $stmt->execute();
        $user=$stmt->get_result()->fetch_assoc();
        if (!$user || ($expectedEmail!==null && $user['email']!==$expectedEmail)) throw new DomainException('Employee account changed. Please try again.');
        $stmt=$database->prepare('DELETE FROM password_reset_tokens WHERE user_id=?');
        $stmt->bind_param('i',$userId); $stmt->execute();
        $stmt=$database->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))');
        $stmt->bind_param('is',$userId,$hash); $stmt->execute();
        passwordAudit('reset_token_issued',isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null,$userId);
        $database->commit();
    } catch (Throwable $e) { $database->rollback(); throw $e; }
    return $token;
}

/** Recheck a token after locking its user, then consume it atomically. */
function consumePasswordResetToken(string $token,string $newPassword): bool {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token) || !validNewPassword($newPassword)) return false;
    $hash=hash('sha256',$token);
    $database=db();
    $stmt=$database->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash=?');
    $stmt->bind_param('s',$hash); $stmt->execute(); $candidate=$stmt->get_result()->fetch_assoc();
    if (!$candidate) return false;
    $userId=(int)$candidate['user_id'];
    $database->begin_transaction();
    try {
        $stmt=$database->prepare('SELECT id FROM users WHERE id=? AND active=1 FOR UPDATE');
        $stmt->bind_param('i',$userId); $stmt->execute();
        if (!$stmt->get_result()->fetch_row()) { $database->rollback(); return false; }
        $stmt=$database->prepare('SELECT id FROM password_reset_tokens WHERE token_hash=? AND user_id=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
        $stmt->bind_param('si',$hash,$userId); $stmt->execute();
        if (!$stmt->get_result()->fetch_row()) { $database->rollback(); return false; }
        $newHash=password_hash($newPassword,PASSWORD_DEFAULT);
        $stmt=$database->prepare('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?');
        $stmt->bind_param('si',$newHash,$userId); $stmt->execute();
        $stmt=$database->prepare('DELETE FROM password_reset_tokens WHERE user_id=?');
        $stmt->bind_param('i',$userId); $stmt->execute();
        passwordAudit('password_reset',null,$userId);
        $database->commit(); return true;
    } catch (Throwable $e) { $database->rollback(); throw $e; }
}
