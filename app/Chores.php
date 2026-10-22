<?php
declare(strict_types=1);

function choresPage(string $method): void {
    requireModule('operations');
    if ($method==='POST') {
        verifyCsrf();
        $id=filter_var($_POST['chore_id']??null,FILTER_VALIDATE_INT);
        if ($id) {
            $status=(string)($_POST['status']??'');
            if (!in_array($status,['pending','in_progress','completed','cancelled'],true)) { http_response_code(422); exit('Invalid status'); }
            $stmt=db()->prepare("UPDATE chores c SET status=?, completed_at=IF(?='completed',NOW(),NULL) WHERE id=? AND ".moduleFacilityScope('operations','c.facility_id'));
            $stmt->bind_param('ssi',$status,$status,$id); $stmt->execute();
            if (!$stmt->affected_rows) {
                $check=db()->prepare('SELECT c.id FROM chores c WHERE c.id=? AND '.moduleFacilityScope('operations','c.facility_id'));
                $check->bind_param('i',$id); $check->execute();
                if (!$check->get_result()->fetch_row()) { http_response_code(404); exit('Chore not found'); }
            }
        } else {
            $facility=filter_var($_POST['facility_id']??null,FILTER_VALIDATE_INT);
            $title=trim((string)($_POST['title']??''));
            $area=trim((string)($_POST['area']??''));
            $description=trim((string)($_POST['description']??''));
            $due=(string)($_POST['due_at']??'');
            $recurrence=(string)($_POST['recurrence']??'none');
            $type=(string)($_POST['assignee_type']??'unassigned');
            $assignee=trim((string)($_POST['assignee_id']??''));
            $dt=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$due);
            if (!$facility || $title==='' || strlen($title)>160 || strlen($area)>100 || strlen($description)>10000 ||
                !$dt || $dt->format('Y-m-d\\TH:i')!==$due || !in_array($recurrence,['none','daily','weekly'],true) ||
                !in_array($type,['unassigned','resident'],true) ||
                ($type==='unassigned' && $assignee!=='') || ($type!=='unassigned' && (!ctype_digit($assignee) || (int)$assignee<1))) {
                http_response_code(422); exit('Invalid chore details');
            }
            checkModuleFacility('operations',(int)$facility);
            $assigneeId=$type==='unassigned'?null:(int)$assignee;
            if ($assigneeId!==null) {
                $check=db()->prepare('SELECT id FROM residents WHERE id=? AND facility_id=?');
                $check->bind_param('ii',$assigneeId,$facility);
                $check->execute();
                if (!$check->get_result()->fetch_row()) { http_response_code(422); exit('Assignee not found for this facility'); }
            }
            $when=$dt->format('Y-m-d H:i:s');
            $stmt=db()->prepare('INSERT INTO chores(facility_id,title,description,area,assignee_type,assignee_id,due_at,recurrence) VALUES(?,?,?,?,?,?,?,?)');
            $stmt->bind_param('issssiss',$facility,$title,$description,$area,$type,$assigneeId,$when,$recurrence);
            $stmt->execute();
        }
        header('Location: /admin/chores',true,303); exit;
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $html='<p><a href="/">Dashboard</a></p><table><tr><th>Facility</th><th>Chore</th><th>Area</th><th>Assigned to</th><th>Due</th><th>Repeat</th><th>Status</th><th>Update</th></tr>';
    $rows=db()->query('SELECT c.*,f.name AS facility_name FROM chores c JOIN facilities f ON f.id=c.facility_id WHERE '.moduleFacilityScope('operations').' ORDER BY c.due_at ASC LIMIT 100');
    while ($r=$rows->fetch_assoc()) {
        $html.='<tr><td>'.escape($r['facility_name']).'</td><td>'.escape($r['title']).'</td><td>'.escape($r['area']).'</td><td>'.escape($r['assignee_type']).($r['assignee_id']!==null?' #'.(int)$r['assignee_id']:'').'</td><td>'.escape($r['due_at']).'</td><td>'.escape($r['recurrence']).'</td><td>'.escape($r['status']).'</td><td><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="chore_id" value="'.(int)$r['id'].'"><select name="status">';
        foreach (['pending','in_progress','completed','cancelled'] as $status) $html.='<option value="'.$status.'"'.($r['status']===$status?' selected':'').'>'.escape($status).'</option>';
        $html.='</select><button>Save</button></form></td></tr>';
    }
    $html.='</table><h2>New chore</h2><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Facility<select name="facility_id" required>';
    $facilities=db()->query('SELECT f.id,f.name FROM facilities f WHERE f.active=1 AND '.moduleFacilityScope('operations').' ORDER BY f.name');
    while ($f=$facilities->fetch_assoc()) $html.='<option value="'.(int)$f['id'].'">'.escape($f['name']).'</option>';
    $html.='</select></label><label>Chore<input name="title" maxlength="160" required></label><label>Area<input name="area" maxlength="100"></label><label>Instructions<textarea name="description" maxlength="10000"></textarea></label><label>Due<input type="datetime-local" name="due_at" required></label><label>Repeat<select name="recurrence"><option value="none">None</option><option value="daily">Daily</option><option value="weekly">Weekly</option></select></label><label>Assigned to<select name="assignee_type"><option value="unassigned">Unassigned</option><option value="resident">Resident</option></select></label><label>Resident<select name="assignee_id" id="chore-resident"><option value="">Unassigned</option>';
    $residents=db()->query('SELECT r.id,r.facility_id,r.display_name,r.reference_code,f.name AS facility_name FROM residents r JOIN facilities f ON f.id=r.facility_id WHERE f.active=1 AND '.moduleFacilityScope('operations').' ORDER BY f.name,r.display_name,r.id');
    while ($resident=$residents->fetch_assoc()) $html.='<option value="'.(int)$resident['id'].'" data-facility="'.(int)$resident['facility_id'].'">'.escape($resident['facility_name'].' — '.$resident['display_name'].' ('.$resident['reference_code'].')').'</option>';
    $html.='</select></label><button>Create chore</button></form><script>
    const choreFacility=document.querySelector("form select[name=facility_id]");
    const choreResident=document.getElementById("chore-resident");
    const choreAssigneeType=document.querySelector("form select[name=assignee_type]");
    function filterChoreResidents() {
        for (const option of choreResident.options) {
            if (!option.value) continue;
            option.hidden=option.dataset.facility!==choreFacility.value;
            option.disabled=option.hidden;
        }
        if (choreResident.selectedOptions[0]?.disabled) choreResident.value="";
    }
    choreFacility.addEventListener("change",filterChoreResidents);
    choreAssigneeType.addEventListener("change",()=>{choreResident.disabled=choreAssigneeType.value!=="resident";if(choreResident.disabled)choreResident.value="";});
    filterChoreResidents();choreResident.disabled=true;
    </script>';
    view('Chores',$html);
}
