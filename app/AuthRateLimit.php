<?php
declare(strict_types=1);

/**
 * Database-backed, atomic fixed-window limiter. Never store raw email,
 * badge number or IP in the rate-limit table.
 */
function authRateKey(string $identifier): string {
    return hash('sha256',strtolower(trim($identifier)));
}
function authRateHit(string $action,string $identifier,int $maxAttempts=5,int $windowSeconds=900): bool {
    $key=authRateKey($identifier);
    $stmt=db()->prepare('INSERT INTO auth_rate_limits(action_name,identifier_hash,window_start,attempt_count) VALUES(?,?,UTC_TIMESTAMP(),1) ON DUPLICATE KEY UPDATE attempt_count=IF(window_start<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? SECOND),1,attempt_count+1),window_start=IF(window_start<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? SECOND),UTC_TIMESTAMP(),window_start)');
    $stmt->bind_param('ssii',$action,$key,$windowSeconds,$windowSeconds); $stmt->execute();
    $stmt=db()->prepare('SELECT attempt_count FROM auth_rate_limits WHERE action_name=? AND identifier_hash=?');
    $stmt->bind_param('ss',$action,$key); $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['attempt_count'] <= $maxAttempts;
}
function authRateClear(string $action,string $identifier): void {
    $key=authRateKey($identifier);
    $stmt=db()->prepare('DELETE FROM auth_rate_limits WHERE action_name=? AND identifier_hash=?');
    $stmt->bind_param('ss',$action,$key); $stmt->execute();
}
