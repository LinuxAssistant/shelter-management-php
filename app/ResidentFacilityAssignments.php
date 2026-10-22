<?php
declare(strict_types=1);

function residentFacilityAssignments(string $method,int $employeeId): void {
    requirePermission('admin.manage');
    header('Cache-Control: no-store');
    if (!in_array($method,['GET','POST'],true)) {
        header('Allow: GET, POST'); http_response_code(405); exit('Method not allowed');
    }
    if ($method==='POST') {
        verifyCsrf();
        $input=$_POST['facility_ids']??[];
        if (!is_array($input) || count($input)>1000) { http_response_code(422); exit('Invalid facility selection'); }
        $ids=[];
        foreach ($input as $value) {
            $id=filter_var($value,FILTER_VALIDATE_INT);
            if (!$id || $id<1 || in_array($id,$ids,true)) { http_response_code(422); exit('Invalid facility selection'); }
            $ids[]=$id;
        }
        sort($ids);
        $database=db(); $database->begin_transaction();
        try {
            $stmt=$database->prepare('SELECT id FROM users WHERE id=? AND active=1 FOR UPDATE');
            $stmt->bind_param('i',$employeeId); $stmt->execute();
            if (!$stmt->get_result()->fetch_row()) { $database->rollback(); http_response_code(404); exit('Active employee not found'); }
            if ($ids) {
                $rows=$database->query('SELECT id FROM facilities WHERE active=1 AND id IN('.implode(',',$ids).') ORDER BY id FOR UPDATE');
                if ($rows->num_rows!==count($ids)) {
                    $database->rollback(); http_response_code(422); exit('Select active facilities only');
                }
            }
            $stmt=$database->prepare('DELETE FROM resident_facility_access WHERE user_id=?');
            $stmt->bind_param('i',$employeeId); $stmt->execute();
            $stmt=$database->prepare('INSERT INTO resident_facility_access(user_id,facility_id) VALUES(?,?)');
            foreach ($ids as $facility) { $stmt->bind_param('ii',$employeeId,$facility); $stmt->execute(); }
            $actor=(int)$_SESSION['user_id'];
            $event='resident_facility_access_updated'; $entity='user';
            $stmt=$database->prepare('INSERT INTO audit_events(actor_user_id,event_name,entity_type,entity_id) VALUES(?,?,?,?)');
            $stmt->bind_param('issi',$actor,$event,$entity,$employeeId); $stmt->execute();
            $database->commit();
        } catch (Throwable $e) { $database->rollback(); throw $e; }
        header('Location: /admin/employees/'.$employeeId.'/resident-facilities',true,303); exit;
    }
    $stmt=db()->prepare('SELECT id,first_name,last_name FROM users WHERE id=? AND active=1');
    $stmt->bind_param('i',$employeeId); $stmt->execute(); $employee=$stmt->get_result()->fetch_assoc();
    if (!$employee) { http_response_code(404); view('Employee not found','<p>No active employee found.</p>'); return; }
    $name=trim(($employee['first_name']??'').' '.($employee['last_name']??''))?:'Employee #'.$employeeId;
    $html='<p><a href="/admin/employees/'.$employeeId.'">Back to employee</a></p><p>Resident access for '.escape($name).'. Choose facilities where this employee can register residents, manage stays, and view history.</p><p>These assignments do not grant employee, financial, or other administrative access. Administrators already have access to every facility.</p><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'">';
    $stmt=db()->prepare('SELECT f.id,f.name,(a.user_id IS NOT NULL) AS assigned FROM facilities f LEFT JOIN resident_facility_access a ON a.facility_id=f.id AND a.user_id=? WHERE f.active=1 ORDER BY f.name');
    $stmt->bind_param('i',$employeeId); $stmt->execute(); $rows=$stmt->get_result();
    while ($facility=$rows->fetch_assoc()) {
        $html.='<label><input type="checkbox" name="facility_ids[]" value="'.(int)$facility['id'].'" '.($facility['assigned']?'checked':'').'> '.escape($facility['name']).'</label>';
    }
    $html.='<button>Save resident access</button></form>';
    view('Resident facility access',$html);
}
