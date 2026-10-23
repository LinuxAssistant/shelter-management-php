<?php
declare(strict_types=1);
function daytimeExceptionsPage(string $method): void {
    requireModule('operations');
    if ($method==='POST') {
        verifyCsrf();
        $facility=filter_var($_POST['facility_id']??null,FILTER_VALIDATE_INT);
        $residentRaw=trim((string)($_POST['resident_id']??''));
        $resident=$residentRaw===''?null:filter_var($residentRaw,FILTER_VALIDATE_INT);
        $reason=(string)($_POST['reason']??'');
        $notes=trim((string)($_POST['notes']??''));
        $start=(string)($_POST['starts_at']??''); $end=(string)($_POST['ends_at']??'');
        $a=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$start);
        $b=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$end);
        $error=null;
        if (!$facility || $facility<1) $error='Select a valid facility.';
        elseif ($residentRaw!=='' && (!$resident || $resident<1)) $error='Select a valid resident or All residents.';
        elseif (!in_array($reason,['cold_weather','rain','severe_weather','case_manager','group_meeting','other'],true)) $error='Select a valid reason.';
        elseif (strlen($notes)>500) $error='Notes must be 500 characters or fewer.';
        elseif (!$a || $a->format('Y-m-d\\TH:i')!==$start) $error='Enter a valid start date and time.';
        elseif (!$b || $b->format('Y-m-d\\TH:i')!==$end) $error='Enter a valid end date and time.';
        elseif ($a >= $b) $error='The end date and time must be after the start.';
        if ($error!==null) {
            http_response_code(422);
            view('Unable to record daytime access','<p>'.escape($error).'</p><p><a href="/admin/daytime-exceptions">Return to daytime exceptions</a></p>');
            return;
        }
        $facilityCheck=db()->prepare('SELECT f.id FROM facilities f WHERE f.id=? AND f.active=1 AND '.moduleFacilityScope('operations').'');
        $facilityCheck->bind_param('i',$facility); $facilityCheck->execute();
        if (!$facilityCheck->get_result()->fetch_row()) {
            http_response_code(422); exit('The selected facility is not active.');
        }
        if ($resident!==null) {
            $check=db()->prepare('SELECT id FROM residents WHERE id=? AND facility_id=?');
            $check->bind_param('ii',$resident,$facility); $check->execute();
            if (!$check->get_result()->fetch_row()) { http_response_code(422); exit('Resident is not in this facility'); }
        }
        $staff=(int)($_SESSION['user_id']??0);
        if (!$staff) { http_response_code(403); exit('Not authenticated'); }
        $from=$a->format('Y-m-d H:i:s'); $to=$b->format('Y-m-d H:i:s');
        $stmt=db()->prepare('INSERT INTO daytime_access_exceptions(facility_id,resident_id,reason,starts_at,ends_at,authorized_by,notes) VALUES(?,?,?,?,?,?,?)');
        $stmt->bind_param('iisssis',$facility,$resident,$reason,$from,$to,$staff,$notes);
        auditedExecute($stmt,'daytime_exception_created','daytime_exception'); header('Location: /admin/daytime-exceptions',true,303); exit;
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $html='<p>Record authorized daytime access. Leave resident blank for a facility-wide weather exception. This is a log, not an automatic door-access control.</p><table><tr><th>Facility</th><th>Resident</th><th>Reason</th><th>From</th><th>Until</th><th>Authorized by</th></tr>';
    $rows=db()->query('SELECT e.*,f.name AS facility_name,r.display_name AS resident_name FROM daytime_access_exceptions e JOIN facilities f ON f.id=e.facility_id LEFT JOIN residents r ON r.id=e.resident_id WHERE '.moduleFacilityScope('operations').' ORDER BY e.starts_at DESC LIMIT 100');
    while ($r=$rows->fetch_assoc()) $html.='<tr><td>'.escape($r['facility_name']).'</td><td>'.escape($r['resident_name']??'All residents').'</td><td>'.escape($r['reason']).'</td><td>'.escape($r['starts_at']).'</td><td>'.escape($r['ends_at']).'</td><td>#'.(int)$r['authorized_by'].'</td></tr>';
    $html.='</table><h2>Authorize daytime access</h2><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Facility<select name="facility_id" required>';
    $facilities=db()->query('SELECT f.id,f.name FROM facilities f WHERE f.active=1 AND '.moduleFacilityScope('operations').' ORDER BY f.name');
    while ($f=$facilities->fetch_assoc()) $html.='<option value="'.(int)$f['id'].'">'.escape($f['name']).'</option>';
    $html.='</select></label><label>Resident (blank means facility-wide)<select name="resident_id" id="exception-resident"><option value="">All residents</option>';
    $residents=db()->query('SELECT r.id,r.facility_id,r.display_name,r.reference_code,f.name AS facility_name FROM residents r JOIN facilities f ON f.id=r.facility_id WHERE f.active=1 AND '.moduleFacilityScope('operations').' ORDER BY f.name,r.display_name');
    while ($r=$residents->fetch_assoc()) $html.='<option value="'.(int)$r['id'].'" data-facility="'.(int)$r['facility_id'].'">'.escape($r['facility_name'].' — '.$r['display_name'].' ('.$r['reference_code'].')').'</option>';
    $html.='</select></label><label>Reason<select name="reason">';
    foreach (['cold_weather','rain','severe_weather','case_manager','group_meeting','other'] as $reason) $html.='<option value="'.$reason.'">'.escape(str_replace('_',' ',ucwords($reason,'_'))).'</option>';
    $html.='</select></label><label>Starts<input type="datetime-local" name="starts_at" required></label><label>Ends<input type="datetime-local" name="ends_at" required></label><label>Notes<input name="notes" maxlength="500"></label><button>Record exception</button></form>';
    $html.='<script src="/assets/daytime-exceptions.js" defer></script>';
    view('Daytime access exceptions',$html);
}
