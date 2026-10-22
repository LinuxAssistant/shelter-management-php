<?php
declare(strict_types=1);

function employeePage(): void {
    requirePermission('admin.manage');
    $users=db()->query('SELECT id,first_name,last_name,job_title,email,badge_number,active FROM users ORDER BY id DESC LIMIT 250');
    $html='<p><a href="/admin/employees/new">Add employee</a></p><table><tr><th>Name</th><th>Title</th><th>Email</th><th>Badge</th><th>Status</th></tr>';
    while($u=$users->fetch_assoc()) {
        $html.='<tr><td><a href="/admin/employees/'.(int)$u['id'].'">'.escape(trim(($u['first_name']??'').' '.($u['last_name']??''))).'</a></td><td>'.escape($u['job_title']??'').'</td><td>'.escape($u['email']).'</td><td>'.escape($u['badge_number']).'</td><td>'.($u['active']?'Active':'Inactive').'</td></tr>';
    }
    view('Employees',$html.'</table>');
}
function employeeForm(?array $employee): void {
    requirePermission('admin.manage');
    $new=$employee===null;
    $id=$new?null:(int)$employee['id'];
    $action=$new?'/admin/employees':'/admin/employees/'.$id;
    $html='<form method="post" action="'.$action.'"><input type="hidden" name="_csrf" value="'.csrf().'">';
    foreach (['first_name'=>'First name','last_name'=>'Last name','job_title'=>'Job title','email'=>'Email','badge_number'=>'Badge number'] as $key=>$label) {
        $type=$key==='email'?'email':'text';
        $html.='<label>'.escape($label).'<input type="'.$type.'" name="'.$key.'" maxlength="255" value="'.escape((string)($employee[$key]??'')).'" required></label>';
    }
    if ($new) $html.='<label>Initial password (minimum 12 characters)<input type="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password"></label>';
    else $html.='<label><input type="checkbox" name="active" value="1" '.($employee['active']?'checked':'').'> Active account</label>';
    $html.='<button>'.($new?'Create employee':'Save changes').'</button></form>';
    if (!$new && $employee['active']) {
        $html.='<h2>Password recovery</h2><p>Send a reset link to the saved employee email address. Save any email changes first.</p><form method="post" action="/admin/employees/'.$id.'/reset-password"><input type="hidden" name="_csrf" value="'.csrf().'"><button>Send password reset email</button></form>';
    }
    view($new?'Add employee':'Edit employee',$html);
}
function employeeSave(?int $id): void {
    requirePermission('admin.manage'); verifyCsrf();
    $data=[];
    foreach(['first_name','last_name','job_title','email','badge_number'] as $key) {
        $data[$key]=trim((string)($_POST[$key]??''));
        if ($data[$key]==='' || strlen($data[$key])>($key==='badge_number'?64:($key==='job_title'?120:($key==='email'?255:100)))) { http_response_code(422); exit('Invalid employee details'); }
    }
    if (!filter_var($data['email'],FILTER_VALIDATE_EMAIL)) { http_response_code(422); exit('Invalid email'); }
    $first=$data['first_name']; $last=$data['last_name']; $title=$data['job_title']; $email=$data['email']; $badge=$data['badge_number'];
    if ($id===null) {
        $password=(string)($_POST['password']??'');
        if (!validNewPassword($password)) { http_response_code(422); exit('Password must contain at least 12 characters and fit within 72 bytes'); }
        $hash=password_hash($password,PASSWORD_DEFAULT);
        $stmt=db()->prepare('INSERT INTO users(first_name,last_name,job_title,email,badge_number,password_hash) VALUES(?,?,?,?,?,?)');
        $stmt->bind_param('ssssss',$first,$last,$title,$email,$badge,$hash); $stmt->execute();
    } else {
        $database=db(); $database->begin_transaction();
        try {
            $stmt=$database->prepare('SELECT id,active,email FROM users WHERE id=? FOR UPDATE');
            $stmt->bind_param('i',$id); $stmt->execute(); $old=$stmt->get_result()->fetch_assoc();
            if (!$old) { $database->rollback(); http_response_code(404); exit('Employee not found'); }
            $active=isset($_POST['active'])?1:0;
            if ($id===(int)$_SESSION['user_id'] && !$active) { $database->rollback(); http_response_code(422); exit('You cannot deactivate your own account'); }
            $invalidate=(int)$old['active']!==$active || $old['email']!==$email;
            $versionIncrement=$invalidate?1:0;
            $stmt=$database->prepare('UPDATE users SET first_name=?,last_name=?,job_title=?,email=?,badge_number=?,active=?,session_version=session_version+? WHERE id=?');
            $stmt->bind_param('sssssiii',$first,$last,$title,$email,$badge,$active,$versionIncrement,$id); $stmt->execute();
            if ($invalidate) {
                $stmt=$database->prepare('DELETE FROM password_reset_tokens WHERE user_id=?');
                $stmt->bind_param('i',$id); $stmt->execute();
                passwordAudit('account_access_changed',(int)$_SESSION['user_id'],$id);
            }
            $database->commit();
        } catch (Throwable $e) { $database->rollback(); throw $e; }
    }
    header('Location: /admin/employees',true,303); exit;
}
