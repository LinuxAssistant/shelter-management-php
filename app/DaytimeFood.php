<?php
declare(strict_types=1);
function daytimeFoodPage(string $method): void {
    requireModule('operations');
    if ($method==='POST') {
        verifyCsrf();
        $facility=filter_var($_POST['facility_id']??null,FILTER_VALIDATE_INT);
        $exceptionRaw=trim((string)($_POST['exception_id']??''));
        $exception=$exceptionRaw===''?null:filter_var($exceptionRaw,FILTER_VALIDATE_INT);
        $description=trim((string)($_POST['food_description']??''));
        $source=(string)($_POST['source']??'donated');
        $portions=(string)($_POST['portions_served']??'');
        $notes=trim((string)($_POST['notes']??''));
        $date=(string)($_POST['served_at']??'');
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$date);
        if (!$facility || ($exceptionRaw!=='' && (!$exception || $exception<1)) ||
            $description==='' || strlen($description)>255 || strlen($notes)>500 ||
            !in_array($source,['donated','purchased','mixed'],true) ||
            !ctype_digit($portions) || (float)$portions>2147483647 ||
            !$dt || $dt->format('Y-m-d\\TH:i')!==$date) {
            http_response_code(422); exit('Invalid daytime food record');
        }
        checkModuleFacility('operations',(int)$facility);
        if ($exception!==null) {
            $check=db()->prepare('SELECT id FROM daytime_access_exceptions WHERE id=? AND facility_id=?');
            $check->bind_param('ii',$exception,$facility); $check->execute();
            if (!$check->get_result()->fetch_row()) { http_response_code(422); exit('Exception does not belong to this facility'); }
        }
        $staff=(int)($_SESSION['user_id']??0);
        if (!$staff) { http_response_code(403); exit('Not authenticated'); }
        $when=$dt->format('Y-m-d H:i:s'); $count=(int)$portions;
        $stmt=db()->prepare('INSERT INTO daytime_food_distributions(facility_id,exception_id,served_at,food_description,source,portions_served,notes,recorded_by) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->bind_param('iisssisi',$facility,$exception,$when,$description,$source,$count,$notes,$staff);
        auditedExecute($stmt,'daytime_food_recorded','daytime_food'); header('Location: /admin/daytime-food',true,303); exit;
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $html='<p>Record food offered to residents during daytime stays. These distributions are separate from scheduled breakfast, lunch and dinner counts.</p><table><tr><th>Facility</th><th>Time</th><th>Food</th><th>Source</th><th>Portions</th><th>Exception</th></tr>';
    $rows=db()->query('SELECT d.*,f.name AS facility_name FROM daytime_food_distributions d JOIN facilities f ON f.id=d.facility_id WHERE '.moduleFacilityScope('operations').' ORDER BY d.served_at DESC LIMIT 100');
    while ($r=$rows->fetch_assoc()) $html.='<tr><td>'.escape($r['facility_name']).'</td><td>'.escape($r['served_at']).'</td><td>'.escape($r['food_description']).'</td><td>'.escape($r['source']).'</td><td>'.(int)$r['portions_served'].'</td><td>'.($r['exception_id']===null?'—':'#'.(int)$r['exception_id']).'</td></tr>';
    $html.='</table><h2>Record daytime food</h2><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Facility<select name="facility_id" required>';
    $facilities=db()->query('SELECT f.id,f.name FROM facilities f WHERE f.active=1 AND '.moduleFacilityScope('operations').' ORDER BY f.name');
    while ($f=$facilities->fetch_assoc()) $html.='<option value="'.(int)$f['id'].'">'.escape($f['name']).'</option>';
    $html.='</select></label><label>Related daytime exception<select name="exception_id"><option value="">None</option>';
    $exceptions=db()->query('SELECT e.id,e.facility_id,e.reason,f.name AS facility_name,e.starts_at FROM daytime_access_exceptions e JOIN facilities f ON f.id=e.facility_id WHERE '.moduleFacilityScope('operations').' ORDER BY e.starts_at DESC LIMIT 100');
    while ($e=$exceptions->fetch_assoc()) $html.='<option value="'.(int)$e['id'].'" data-facility="'.(int)$e['facility_id'].'">'.escape($e['facility_name'].' — '.$e['reason'].' — '.$e['starts_at']).' (#'.(int)$e['id'].')</option>';
    $html.='</select></label><label>Food provided<input name="food_description" maxlength="255" required></label><label>Source<select name="source"><option value="donated">Donated</option><option value="purchased">Purchased</option><option value="mixed">Mixed</option></select></label><label>Date and time<input type="datetime-local" name="served_at" required></label><label>Portions served<input type="number" name="portions_served" min="0" value="0" required></label><label>Notes<input name="notes" maxlength="500"></label><button>Record food</button></form>';
    $html.='<script>const foodFacility=document.querySelector("select[name=facility_id]"),foodException=document.querySelector("select[name=exception_id]");function filterFoodExceptions(){for(const option of foodException.options){if(!option.value)continue;option.hidden=option.dataset.facility!==foodFacility.value;option.disabled=option.hidden;}if(foodException.selectedOptions[0]?.disabled)foodException.value="";}foodFacility.addEventListener("change",filterFoodExceptions);filterFoodExceptions();</script>';
    view('Daytime food distributions',$html);
}
