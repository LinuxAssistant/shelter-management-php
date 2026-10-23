<?php
declare(strict_types=1);

function facilityModules(): array {
    return ['operations'=>'Daily operations','kitchen'=>'Kitchen','finance'=>'Donations and expenses','volunteers'=>'Volunteers','casework'=>'Private case management'];
}
function moduleFacilityScope(string $module,string $column='f.id'): string {
    if (!isset(facilityModules()[$module]) || !in_array($column,['f.id','r.facility_id','c.facility_id','t.facility_id','v.facility_id'],true)) throw new LogicException('Invalid module scope');
    if (can('admin.manage')) return '1=1';
    $user=(int)($_SESSION['user_id']??0);
    return "EXISTS(SELECT 1 FROM staff_facility_permissions mp JOIN facilities mf ON mf.id=mp.facility_id AND mf.active=1 WHERE mp.user_id=".$user." AND mp.module='".$module."' AND mp.facility_id=".$column.")";
}
function canUseModule(string $module): bool {
    if (empty($_SESSION['user_id'])) return false;
    return (bool)db()->query('SELECT f.id FROM facilities f WHERE f.active=1 AND '.moduleFacilityScope($module).' LIMIT 1')->fetch_row() || can('admin.manage');
}
function requireModule(string $module): void {
    requireAuth(); header('Cache-Control: no-store');
    if (!canUseModule($module)) { http_response_code(403); exit('Forbidden'); }
}
function checkModuleFacility(string $module,int $facility): void {
    $stmt=db()->prepare('SELECT f.id FROM facilities f WHERE f.id=? AND f.active=1 AND '.moduleFacilityScope($module));
    $stmt->bind_param('i',$facility); $stmt->execute();
    if (!$stmt->get_result()->fetch_row()) { http_response_code(422); exit('Select an accessible active facility'); }
}
function moduleFacilityOptions(string $module): string {
    $rows=db()->query('SELECT f.id,f.name FROM facilities f WHERE f.active=1 AND '.moduleFacilityScope($module).' ORDER BY f.name,f.id');
    $html='<label>Facility<select name="facility_id" required><option value="">Select facility</option>';
    while ($f=$rows->fetch_assoc()) $html.='<option value="'.(int)$f['id'].'">'.escape($f['name']).'</option>';
    return $html.'</select></label>';
}
function moduleAssignments(string $method,int $employee): void {
    requirePermission('admin.manage'); header('Cache-Control: no-store');
    if (!in_array($method,['GET','POST'],true)) { http_response_code(405); exit('Method not allowed'); }
    $stmt=db()->prepare('SELECT id,email FROM users WHERE id=? AND active=1');
    $stmt->bind_param('i',$employee); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
    if (!$user) { http_response_code(404); exit('Active employee not found'); }
    if ($method==='POST') {
        verifyCsrf(); $input=$_POST['grants']??[];
        if (!is_array($input) || count($input)>1000) { http_response_code(422); exit('Invalid selections'); }
        $grants=[];
        foreach ($input as $value) {
            if (!is_string($value) || !preg_match('/^([1-9][0-9]*):(operations|kitchen|finance|volunteers|casework)$/D',$value,$m) || isset($grants[$value])) { http_response_code(422); exit('Invalid selections'); }
            $grants[$value]=[(int)$m[1],$m[2]];
        }
        $database=db(); $database->begin_transaction();
        try {
            $stmt=$database->prepare('SELECT id FROM users WHERE id=? AND active=1 FOR UPDATE');
            $stmt->bind_param('i',$employee); $stmt->execute();
            if (!$stmt->get_result()->fetch_row()) { $database->rollback(); http_response_code(404); exit('Active employee not found'); }
            foreach ($grants as [$facility,$module]) {
                $stmt=$database->prepare('SELECT id FROM facilities WHERE id=? AND active=1 FOR UPDATE');
                $stmt->bind_param('i',$facility); $stmt->execute();
                if (!$stmt->get_result()->fetch_row()) { $database->rollback(); http_response_code(422); exit('Select active facilities'); }
            }
            $stmt=$database->prepare('DELETE FROM staff_facility_permissions WHERE user_id=?');
            $stmt->bind_param('i',$employee); $stmt->execute();
            $stmt=$database->prepare('INSERT INTO staff_facility_permissions(user_id,facility_id,module) VALUES(?,?,?)');
            foreach ($grants as [$facility,$module]) { $stmt->bind_param('iis',$employee,$facility,$module); $stmt->execute(); }
            $actor=(int)$_SESSION['user_id'];
            $stmt=$database->prepare("INSERT INTO audit_events(actor_user_id,event_name,entity_type,entity_id) VALUES(?,'facility_module_access_updated','user',?)");
            $stmt->bind_param('ii',$actor,$employee); $stmt->execute(); $database->commit();
        } catch(Throwable $e) { $database->rollback(); http_response_code(500); exit('Unable to save access. No changes were saved.'); }
        header('Location: /admin/employees/'.$employee.'/module-facilities',true,303); exit;
    }
    $stmt=db()->prepare('SELECT facility_id,module FROM staff_facility_permissions WHERE user_id=?');
    $stmt->bind_param('i',$employee); $stmt->execute(); $assigned=[];
    foreach ($stmt->get_result() as $grant) $assigned[$grant['facility_id'].':'.$grant['module']]=true;
    $html='<p>Access for '.escape($user['email']).'. Grants apply only to the selected module and facility. Operations includes operational resident names; case management is separately restricted. Resident and incident access have separate assignments. Global administrators retain all access.</p><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'">';
    foreach(db()->query('SELECT id,name FROM facilities WHERE active=1 ORDER BY name,id') as $facility) {
        $html.='<fieldset><legend>'.escape($facility['name']).'</legend>';
        foreach(facilityModules() as $module=>$label) {
            $key=$facility['id'].':'.$module;
            $html.='<label><input type="checkbox" name="grants[]" value="'.escape($key).'" '.(isset($assigned[$key])?'checked':'').'>'.escape($label).'</label>';
        }
        $html.='</fieldset>';
    }
    view('Facility module access',$html.'<button>Save module access</button></form>');
}
