<?php
declare(strict_types=1);

/**
 * Store only a SHA-256 digest of the bearer token. Return the raw token once,
 * so a future mail-delivery integration can send it to the verified mailbox.
 * Never print the token in a web response or log.
 */
function issuePasswordResetToken(int $userId): string {
    $token=bin2hex(random_bytes(32));
    $hash=hash('sha256',$token);
    $db=db();
    $db->begin_transaction();
    try {
        $stmt=$db->prepare('DELETE FROM password_reset_tokens WHERE user_id=?');
        $stmt->bind_param('i',$userId); $stmt->execute();
        $stmt=$db->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))');
        $stmt->bind_param('is',$userId,$hash); $stmt->execute();
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
    return $token;
}

/**
 * Consume the token and change password in one transaction. Locking the token
 * row prevents concurrent requests from reusing the same reset token.
 */
function consumePasswordResetToken(string $token,string $newPassword): bool {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token) || strlen($newPassword)<12 || strlen($newPassword)>1024) return false;
    $hash=hash('sha256',$token);
    $db=db(); $db->begin_transaction();
    try {
        $stmt=$db->prepare('SELECT t.id,t.user_id FROM password_reset_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.consumed_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND u.active=1 FOR UPDATE');
        $stmt->bind_param('s',$hash); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc();
        if (!$row) { $db->rollback(); return false; }
        $id=(int)$row['id']; $userId=(int)$row['user_id'];
        $newHash=password_hash($newPassword,PASSWORD_DEFAULT);
        $stmt=$db->prepare('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=? AND active=1');
        $stmt->bind_param('si',$newHash,$userId); $stmt->execute();
        if ($stmt->affected_rows!==1) { $db->rollback(); return false; }
        $stmt=$db->prepare('UPDATE password_reset_tokens SET consumed_at=UTC_TIMESTAMP() WHERE id=?');
        $stmt->bind_param('i',$id); $stmt->execute();
        $db->commit(); return true;
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}
