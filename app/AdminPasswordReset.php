<?php
declare(strict_types=1);

function adminResetPassword(int $employeeId): void {
    requirePermission('admin.manage'); verifyCsrf();
    $stmt=db()->prepare('SELECT id,email,active FROM users WHERE id=?');
    $stmt->bind_param('i',$employeeId); $stmt->execute(); $employee=$stmt->get_result()->fetch_assoc();
    if (!$employee || !(int)$employee['active']) { http_response_code(404); exit('Active employee not found'); }
    if (!authRateHit('admin-reset-user',(string)$employeeId,3,3600)) {
        http_response_code(429); header('Retry-After: 3600'); exit('Too many reset requests');
    }
    if (getenv('PASSWORD_RESET_EMAIL_ENABLED')!=='true') {
        http_response_code(503); view('Email unavailable','<p>Password reset email is not configured.</p>'); return;
    }
    $token=issuePasswordResetToken($employeeId);
    if (!sendPasswordResetEmail((string)$employee['email'],$token)) {
        http_response_code(503); view('Delivery unavailable','<p>Unable to send the password reset email. Check mail configuration.</p>'); return;
    }
    view('Reset requested','<p>A password reset link has been sent to the employee email address.</p>');
}
