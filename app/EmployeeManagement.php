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
    if ($new) $html.='<label>Initial password (minimum 12 characters)<input type="password" name="password" minlength="12" required autocomplete="new-password"></label>';
    else $html.='<label><input type="checkbox" name="active" value="1" '.($employee['active']?'checked':'').'> Active account</label>';
    $html.='<button>'.($new?'Create employee':'Save changes').'</button></form>';
    if (!$new) $html.='<p><a href="/admin/employees/'.$id.'/resident-facilities">Manage resident facility access</a></p>';
    if (!$new) $html.='<p><a href="/admin/employees/'.$id.'/incident-facilities">Manage incident facility access</a></p>';
    if (!$new) $html.='<p><a href="/admin/employees/'.$id.'/module-facilities">Manage operations, kitchen, finance, volunteer and case access</a></p>';
    if (!$new) $html.='<p><a href="/admin/employees/'.$id.'/roles">Manage employee roles</a></p>';
    view($new?'Add employee':'Edit employee',$html);
}
function employeeSave(?int $id): void {
    requirePermission('admin.manage'); verifyCsrf();
    $data=[];
    foreach(['first_name'=>100,'last_name'=>100,'job_title'=>120,'email'=>255,'badge_number'=>64] as $key=>$max) $data[$key]=postedText($key,$max);
    if (!filter_var($data['email'],FILTER_VALIDATE_EMAIL)) { http_response_code(422); exit('Invalid email'); }
    $hash=null;
    if ($id===null) {
        $password=$_POST['password']??null;
        if (!is_string($password) || strlen($password)>72 || strlen($password)<12) { http_response_code(422); exit('Password must contain at least 12 characters'); }
        $hash=password_hash($password,PASSWORD_DEFAULT);
    }
    workflowChange(function($database) use($id,$data,$hash) {
        lockAdministrativeChanges();
        $first=$data['first_name']; $last=$data['last_name']; $title=$data['job_title']; $email=$data['email']; $badge=$data['badge_number'];
        if ($id===null) {
            $stmt=$database->prepare('INSERT INTO users(first_name,last_name,job_title,email,badge_number,password_hash) VALUES(?,?,?,?,?,?)');
            $stmt->bind_param('ssssss',$first,$last,$title,$email,$badge,$hash); $stmt->execute();
            workflowAudit('employee_created','user',$database->insert_id);
        } else {
            $stmt=$database->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $stmt->bind_param('i',$id); $stmt->execute();
            if (!$stmt->get_result()->fetch_row()) throw new DomainException('Employee not found');
            $active=isset($_POST['active'])?1:0;
            if ($id===(int)$_SESSION['user_id'] && !$active) throw new DomainException('You cannot deactivate your own account');
            $stmt=$database->prepare('UPDATE users SET first_name=?,last_name=?,job_title=?,email=?,badge_number=?,active=? WHERE id=?');
            $stmt->bind_param('sssssii',$first,$last,$title,$email,$badge,$active,$id); $stmt->execute();
            workflowAudit('employee_updated','user',$id);
        }
    },'/admin/employees');
}
