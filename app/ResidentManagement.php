<?php
declare(strict_types=1);

/** Resident intake and transactional bed occupancy; use fictional test data. */
function residentManagement(string $method): void {
    requireResidentAccess();
    header('Cache-Control: no-store');
    $residentScope=residentFacilityScope('r.facility_id');
    $facilityScope=residentFacilityScope('f.id');
    $roomScope=residentFacilityScope('rm.facility_id');
    if ($method==='POST') {
        verifyCsrf();
        $facility=filter_var($_POST['facility_id']??null,FILTER_VALIDATE_INT);
        $name=trim((string)($_POST['display_name']??''));
        if (!$facility || $facility<1 || $name==='' || strlen($name)>160) {
            http_response_code(422); exit('Invalid resident information');
        }
        $check=db()->prepare('SELECT f.id FROM facilities f WHERE f.id=? AND f.active=1 AND '.$facilityScope);
        $check->bind_param('i',$facility); $check->execute();
        if (!$check->get_result()->fetch_row()) { http_response_code(422); exit('Facility is not active'); }
        // Generate a collision-resistant temporary value, then derive a stable
        // reference from the database-assigned resident ID.
        $database=db();
        $database->begin_transaction();
        try {
            $lock=$database->prepare('SELECT id FROM facilities WHERE id=? FOR UPDATE');
            $lock->bind_param('i',$facility); $lock->execute(); $lock->get_result()->fetch_row();
            $duplicates=$database->prepare('SELECT id FROM residents WHERE facility_id=? AND LOWER(TRIM(display_name))=LOWER(?) LIMIT 1');
            $duplicates->bind_param('is',$facility,$name); $duplicates->execute();
            if ($duplicates->get_result()->fetch_row() && ($_POST['distinct_person']??'')!=='1') {
                $database->rollback(); http_response_code(409);
                view('Check existing resident','<p>A resident with this name already exists. Search the resident records before creating another. If this is a different person, confirm that on the registration form.</p><a href="/admin/residents">Return to residents</a>'); return;
            }
            $code='TMP-'.bin2hex(random_bytes(16));
            $stmt=$database->prepare('INSERT INTO residents(facility_id,reference_code,display_name) VALUES(?,?,?)');
            $stmt->bind_param('iss',$facility,$code,$name);
            $stmt->execute();
            $residentId=$database->insert_id;
            $code='RES-'.str_pad((string)$residentId,6,'0',STR_PAD_LEFT);
            $stmt=$database->prepare('UPDATE residents SET reference_code=? WHERE id=?');
            $stmt->bind_param('si',$code,$residentId);
            $stmt->execute();
            $database->commit();
        } catch (Throwable $e) {
            $database->rollback();
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode()===1062) {
                http_response_code(409);
                exit('A reference code conflict occurred. Please contact an administrator.');
            }
            throw $e;
        }
        header('Location: /admin/residents',true,303); exit;
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $query=$_GET['q']??'';
    if (!is_string($query) || strlen($query)>100) { http_response_code(422); exit('Invalid search'); }
    $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($query)).'%';
    $html='<form method="get"><label>Find an existing resident by name or reference<input name="q" maxlength="100" value="'.escape($query).'"></label><button>Search resident records</button></form><p>Use fictional residents for testing. <a href="/admin/occupancy">Manage check-in and check-out</a></p><table><tr><th>Facility</th><th>Reference</th><th>Resident</th><th>Status</th><th>Current bed</th></tr>';
    $stmt=db()->prepare("SELECT r.*,f.name AS facility_name,CASE WHEN rm.facility_id=r.facility_id THEN b.code END AS bed_code,b.position,CASE WHEN rm.facility_id=r.facility_id THEN rm.name END AS room_name FROM residents r JOIN facilities f ON f.id=r.facility_id LEFT JOIN stays s ON s.resident_id=r.id AND s.checked_out_at IS NULL LEFT JOIN beds b ON b.id=s.bed_id LEFT JOIN rooms rm ON rm.id=b.room_id WHERE ".$residentScope." AND (r.display_name LIKE ? ESCAPE '!' OR r.reference_code LIKE ? ESCAPE '!') ORDER BY r.id DESC LIMIT 100");
    $stmt->bind_param('ss',$like,$like); $stmt->execute(); $rows=$stmt->get_result();
    while ($r=$rows->fetch_assoc()) $html.='<tr><td>'.escape($r['facility_name']).'</td><td>'.escape($r['reference_code']).'</td><td>'.'<a href="/admin/residents/'.(int)$r['id'].'/history">'.escape($r['display_name']).'</a> · <a href="/admin/residents/'.(int)$r['id'].'/edit">Update intake</a></td><td>'.($r['bed_code']===null?'Checked out':'Currently staying').'</td><td>'.($r['bed_code']===null?'Not checked in':escape($r['room_name'].' / '.$r['bed_code'].' ('.$r['position'].')')).'</td></tr>';
    $html.='</table><h2>Register resident</h2><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Facility<select name="facility_id" required><option value="">Select facility</option>';
    $facilities=db()->query('SELECT f.id,f.name FROM facilities f WHERE f.active=1 AND '.$facilityScope.' ORDER BY f.name');
    while ($f=$facilities->fetch_assoc()) $html.='<option value="'.(int)$f['id'].'">'.escape($f['name']).'</option>';
    $html.='</select></label><p>The reference code is generated automatically when you register the resident.</p><label>Display name<input name="display_name" maxlength="160" required></label><label><input type="checkbox" name="distinct_person" value="1"> I checked existing records; this is a different person with the same name.</label><button>Register resident</button></form>';
    view('Residents',$html);
}

function occupancyManagement(string $method): void {
    requireResidentAccess();
    header('Cache-Control: no-store');
    $residentScope=residentFacilityScope('r.facility_id');
    $facilityScope=residentFacilityScope('f.id');
    $roomScope=residentFacilityScope('rm.facility_id');
    if ($method==='POST') {
        verifyCsrf();
        $action=(string)($_POST['action']??'');
        $db=db();
        if ($action==='checkin') {
            $resident=filter_var($_POST['resident_id']??null,FILTER_VALIDATE_INT);
            $bed=filter_var($_POST['bed_id']??null,FILTER_VALIDATE_INT);
            if (!$resident || !$bed || $resident<1 || $bed<1) { http_response_code(422); exit('Select a resident and bed'); }
            $db->begin_transaction();
            try {
                // Lock both resources so concurrent requests cannot double-book.
                $stmt=$db->prepare('SELECT r.facility_id FROM residents r WHERE r.id=? AND '.$residentScope.' FOR UPDATE');
                $stmt->bind_param('i',$resident); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc();
                $stmt=$db->prepare('SELECT rm.facility_id,b.active AS bed_active,rm.active AS room_active,f.active AS facility_active FROM beds b JOIN rooms rm ON rm.id=b.room_id JOIN facilities f ON f.id=rm.facility_id WHERE b.id=? AND '.$roomScope.' FOR UPDATE');
                $stmt->bind_param('i',$bed); $stmt->execute(); $b=$stmt->get_result()->fetch_assoc();
                if (!$r || !$b || $r['facility_id']!=$b['facility_id'] || !$b['bed_active'] || !$b['room_active'] || !$b['facility_active']) {
                    throw new DomainException('Resident and active bed must belong to the same active facility.');
                }
                $stmt=$db->prepare('SELECT id FROM stays WHERE resident_id=? AND checked_out_at IS NULL LIMIT 1');
                $stmt->bind_param('i',$resident); $stmt->execute();
                if ($stmt->get_result()->fetch_row()) throw new DomainException('Resident is already checked in.');
                $stmt=$db->prepare('SELECT id FROM stays WHERE bed_id=? AND checked_out_at IS NULL LIMIT 1');
                $stmt->bind_param('i',$bed); $stmt->execute();
                if ($stmt->get_result()->fetch_row()) throw new DomainException('Bed is already occupied.');
                $stmt=$db->prepare('INSERT INTO stays(resident_id,bed_id,checked_in_at) VALUES(?,?,NOW())');
                $stmt->bind_param('ii',$resident,$bed); $stmt->execute();
                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                if ($e instanceof mysqli_sql_exception && in_array((int)$e->getCode(),[1062,1205,1213],true)) {
                    http_response_code(409);
                    view('Availability changed','<p>Availability changed during check-in. Refresh occupancy and try again.</p><a href="/admin/occupancy">Return to occupancy</a>');
                    return;
                }
                if ($e instanceof DomainException) { http_response_code(422); view('Unable to check in','<p>'.escape($e->getMessage()).'</p><a href="/admin/occupancy">Return to occupancy</a>'); return; }
                throw $e;
            }
        } elseif ($action==='checkout') {
            $stay=filter_var($_POST['stay_id']??null,FILTER_VALIDATE_INT);
            if (!$stay || $stay<1) { http_response_code(422); exit('Invalid stay'); }
            $departureType=(string)($_POST['departure_type']??'');
            $reason=trim((string)($_POST['departure_reason']??''));
            if (!in_array($departureType,['voluntary','staff_exit'],true) || strlen($reason)>500 || ($departureType==='staff_exit' && $reason==='')) {
                http_response_code(422); exit('Select a valid departure type. Staff-directed exits require a reason (maximum 500 characters).');
            }
            $staffId=(int)$_SESSION['user_id'];
            $db->begin_transaction();
            try {
                $stmt=$db->prepare('SELECT s.id FROM stays s JOIN residents r ON r.id=s.resident_id JOIN beds b ON b.id=s.bed_id JOIN rooms rm ON rm.id=b.room_id WHERE s.id=? AND s.checked_out_at IS NULL AND '.$residentScope.' AND '.$roomScope.' FOR UPDATE');
                $stmt->bind_param('i',$stay); $stmt->execute();
                if (!$stmt->get_result()->fetch_row()) throw new DomainException('Stay is already closed or does not exist.');
                $stmt=$db->prepare('UPDATE stays SET checked_out_at=NOW(),departure_type=?,departure_reason=?,departed_by=? WHERE id=? AND checked_out_at IS NULL');
                $stmt->bind_param('ssii',$departureType,$reason,$staffId,$stay); $stmt->execute();
                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                if ($e instanceof DomainException) { http_response_code(422); exit($e->getMessage()); }
                throw $e;
            }
        } else { http_response_code(422); exit('Invalid occupancy action'); }
        header('Location: /admin/occupancy',true,303); exit;
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $html='<p><a href="/admin/residents">Register residents</a></p><h2>Current occupancy</h2><table><tr><th>Facility</th><th>Resident</th><th>Room</th><th>Bed</th><th>Checked in</th><th>Action</th></tr>';
    $rows=db()->query('SELECT s.id,s.checked_in_at,r.display_name,f.name AS facility_name,rm.name AS room_name,b.code,b.position FROM stays s JOIN residents r ON r.id=s.resident_id JOIN beds b ON b.id=s.bed_id JOIN rooms rm ON rm.id=b.room_id JOIN facilities f ON f.id=rm.facility_id WHERE s.checked_out_at IS NULL AND '.$residentScope.' AND '.$facilityScope.' ORDER BY f.name,rm.name,b.code,b.position');
    while ($r=$rows->fetch_assoc()) {
        $html.='<tr><td>'.escape($r['facility_name']).'</td><td>'.escape($r['display_name']).'</td><td>'.escape($r['room_name']).'</td><td>'.escape($r['code'].' ('.$r['position'].')').'</td><td>'.escape($r['checked_in_at']).'</td><td><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="action" value="checkout"><input type="hidden" name="stay_id" value="'.(int)$r['id'].'"><label>Departure type<select name="departure_type" required><option value="voluntary">Voluntary departure</option><option value="staff_exit">Staff-directed exit</option></select></label><label>Reason (required for staff exit)<input name="departure_reason" maxlength="500"></label><button>Close stay</button></form></td></tr>';
    }
    $html.='</table><h2>Check in resident</h2><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="action" value="checkin"><label>Search by resident name or reference code<input type="search" id="resident-search" placeholder="Search residents"></label><label>Resident<select name="resident_id" id="occupancy-resident" required><option value="">Search to select a resident</option>';
    $html.='</select></label><p id="resident-search-status" role="status" aria-live="polite">Enter at least two characters.</p><label>Available bed<select name="bed_id" id="occupancy-bed" required><option value="">Select bed</option>';
    $beds=db()->query('SELECT b.id,rm.facility_id,b.code,b.position,rm.name AS room_name,f.name AS facility_name FROM beds b JOIN rooms rm ON rm.id=b.room_id JOIN facilities f ON f.id=rm.facility_id WHERE b.active=1 AND rm.active=1 AND f.active=1 AND '.$roomScope.' AND NOT EXISTS(SELECT 1 FROM stays s WHERE s.bed_id=b.id AND s.checked_out_at IS NULL) ORDER BY f.name,rm.name,b.code,b.position');
    while ($b=$beds->fetch_assoc()) $html.='<option value="'.(int)$b['id'].'" data-facility="'.(int)$b['facility_id'].'">'.escape($b['facility_name'].' — '.$b['room_name'].' / '.$b['code'].' ('.$b['position'].')').'</option>';
    $html.='</select></label><button>Check in</button></form><script src="/assets/resident-search.js" defer></script>';
    view('Bed occupancy',$html);
}
