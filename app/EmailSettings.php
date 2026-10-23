<?php
declare(strict_types=1);

function resetMailSettings(): array {
    $row=db()->query('SELECT configured,enabled,from_address FROM app_email_settings WHERE id=1')->fetch_assoc();
    if ($row && (int)$row['configured']===1) return ['enabled'=>(bool)$row['enabled'],'from'=>$row['from_address']];
    return ['enabled'=>getenv('PASSWORD_RESET_EMAIL_ENABLED')==='true','from'=>(string)(getenv('MAIL_FROM')?:'')];
}
function emailSettingsPage(string $method): void {
    requirePermission('admin.manage');
    header('Cache-Control: no-store');
    if (!in_array($method,['GET','POST'],true)) { header('Allow: GET, POST'); http_response_code(405); exit('Method not allowed'); }
    if ($method==='POST') {
        verifyCsrf();
        $from=$_POST['from_address']??'';
        $enabled=$_POST['enabled']??'0';
        if (!is_string($from) || !is_string($enabled) || !in_array($enabled,['0','1'],true)) { http_response_code(422); exit('Invalid email settings'); }
        $from=trim($from);
        if (strlen($from)>255 || strpbrk($from,"\r\n")!==false || ($from!=='' && !filter_var($from,FILTER_VALIDATE_EMAIL)) || ($enabled==='1' && $from==='')) { http_response_code(422); exit('Enter a valid sender address before enabling password-reset email'); }
        $value=(int)$enabled; $actor=(int)$_SESSION['user_id'];
        $database=db(); $database->begin_transaction();
        try {
            $stmt=$database->prepare('UPDATE app_email_settings SET configured=1,enabled=?,from_address=?,updated_by=?,updated_at=UTC_TIMESTAMP() WHERE id=1');
            $stmt->bind_param('isi',$value,$from,$actor); $stmt->execute();
            if (function_exists('workflowAudit')) workflowAudit('mail_settings_updated','email_settings',1);
            else passwordAudit('mail_settings_updated',$actor,null);
            $database->commit();
        } catch(Throwable $error) { $database->rollback(); http_response_code(500); exit('Unable to save email settings. No changes were saved.'); }
        $_SESSION['email_settings_saved']=true;
        header('Location: /admin/email',true,303); exit;
    }
    $settings=resetMailSettings();
    $saved=!empty($_SESSION['email_settings_saved']);
    unset($_SESSION['email_settings_saved']);
    $html=($saved?'<p role="status"><strong>Email settings saved.</strong></p>':'');
    $html.='<p>Password-reset email is currently <strong>'.($settings['enabled']?'enabled':'disabled').'</strong>.</p>';
    $html.='<p>Password-reset messages use the mail service configured on this server. Saving these settings does not verify delivery or configure an SMTP relay. No test message is sent automatically.</p><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Sender email address<input type="email" name="from_address" maxlength="255" value="'.escape($settings['from']).'" autocomplete="email"></label><label><input type="checkbox" name="enabled" value="1" '.($settings['enabled']?'checked':'').'> Enable password-reset email</label><button>Save email settings</button></form><p>The reset-link address comes from the server’s APP_URL setting and must use HTTPS. Keep email disabled until the server has a valid sending domain and working delivery configuration.</p>';
    view('Email settings',$html);
}
