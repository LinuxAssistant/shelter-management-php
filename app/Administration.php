<?php
declare(strict_types=1);
function lockAdministrativeChanges(): void {
    $lock=db()->query("SELECT id FROM permissions WHERE name='admin.manage' FOR UPDATE");
    $lock->fetch_row();
    if (!can('admin.manage')) throw new DomainException('Administrator access changed. Sign in again.');
}
function employeeRolesPage(string $method,int $employee): void {
    requirePermission('admin.manage'); header('Cache-Control: no-store');
    $stmt=db()->prepare('SELECT id,email FROM users WHERE id=? AND active=1');
    $stmt->bind_param('i',$employee); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
    if (!$user) { http_response_code(404); exit('Active employee not found'); }
    if ($method==='POST') {
        verifyCsrf();
        if ($employee===(int)$_SESSION['user_id']) { http_response_code(422); exit('Another administrator must change your roles'); }
        $values=$_POST['role_ids']??[];
        if (!is_array($values) || count($values)>50) { http_response_code(422); exit('Invalid roles'); }
        $ids=[];
        foreach($values as $value) {
            $id=filter_var($value,FILTER_VALIDATE_INT);
            if (!$id || $id<1 || in_array($id,$ids,true)) { http_response_code(422); exit('Invalid roles'); }
            $ids[]=$id;
        }
        workflowChange(function($database) use($employee,$ids) {
            lockAdministrativeChanges();
            $stmt=$database->prepare('SELECT id FROM users WHERE id=? AND active=1 FOR UPDATE');
            $stmt->bind_param('i',$employee); $stmt->execute();
            if (!$stmt->get_result()->fetch_row()) throw new DomainException('Active employee not found');
            if ($ids && $database->query('SELECT id FROM roles WHERE id IN('.implode(',',$ids).')')->num_rows!==count($ids)) throw new DomainException('Unknown role');
            $stmt=$database->prepare('DELETE FROM user_roles WHERE user_id=?'); $stmt->bind_param('i',$employee); $stmt->execute();
            $stmt=$database->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)');
            foreach($ids as $role) { $stmt->bind_param('ii',$employee,$role); $stmt->execute(); }
            workflowAudit('employee_roles_updated','user',$employee);
        },'/admin/employees/'.$employee.'/roles');
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $stmt=db()->prepare('SELECT r.id,r.name,(ur.user_id IS NOT NULL) AS assigned FROM roles r LEFT JOIN user_roles ur ON ur.role_id=r.id AND ur.user_id=? ORDER BY r.name');
    $stmt->bind_param('i',$employee); $stmt->execute();
    $html='<p>Roles for '.escape($user['email']).'. Administrator grants global access. Other incident roles also require incident facility assignments. Module and resident assignments are managed separately.</p>'.workflowForm();
    foreach($stmt->get_result() as $role) $html.='<label><input type="checkbox" name="role_ids[]" value="'.(int)$role['id'].'" '.($role['assigned']?'checked':'').'>'.escape($role['name']).'</label>';
    view('Employee roles',$html.'<button>Save roles</button></form>');
}
function incidentStatusPage(string $method,int $id): void {
    requireIncidentAccess('incidents.review');
    if ($method!=='POST') { http_response_code(405); exit('Method not allowed'); }
    verifyCsrf(); $status=postedText('status',30);
    if (!in_array($status,['Open','Under review','Resolved'],true)) { http_response_code(422); exit('Invalid incident status'); }
    workflowChange(function($database) use($id,$status) {
        $stmt=$database->prepare('SELECT i.id FROM incidents i WHERE i.id=? AND '.incidentFacilityScope('i.facility_id').' FOR UPDATE');
        $stmt->bind_param('i',$id); $stmt->execute();
        if (!$stmt->get_result()->fetch_row()) throw new DomainException('Incident not found');
        $stmt=$database->prepare('UPDATE incidents SET status=? WHERE id=?');
        $stmt->bind_param('si',$status,$id); $stmt->execute(); workflowAudit('incident_status_updated','incident',$id);
    },'/incidents/'.$id);
}
