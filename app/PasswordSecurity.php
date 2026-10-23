<?php
declare(strict_types=1);

/** PASSWORD_DEFAULT currently uses bcrypt; never silently truncate a password. */
function validNewPassword(string $password): bool {
    return strlen($password)<=72 && !str_contains($password,"\0")
        && preg_match_all('/./us',$password)>=12;
}

/** Record event names and internal IDs only, never passwords, tokens or addresses. */
function passwordAudit(string $event, ?int $actorId, ?int $subjectId): void {
    $stmt=db()->prepare('INSERT INTO password_security_events(event_name,actor_id,subject_id) VALUES(?,?,?)');
    $stmt->bind_param('sii',$event,$actorId,$subjectId);
    $stmt->execute();
}

function revokePasswordResetToken(string $token): void {
    $hash=hash('sha256',$token);
    $stmt=db()->prepare('DELETE FROM password_reset_tokens WHERE token_hash=?');
    $stmt->bind_param('s',$hash); $stmt->execute();
}
