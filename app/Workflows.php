<?php
declare(strict_types=1);

function postedText(string $key,int $max,bool $required=true): string {
    $raw=$_POST[$key]??'';
    if (!is_string($raw)) { http_response_code(422); exit('Invalid field'); }
    $value=trim($raw);
    if (($required && $value==='') || strlen($value)>$max) { http_response_code(422); exit('Missing or invalid field'); }
    return $value;
}
function postedId(string $key): int {
    $value=filter_var($_POST[$key]??null,FILTER_VALIDATE_INT);
    if (!$value || $value<1) { http_response_code(422); exit('Invalid selection'); }
    return (int)$value;
}
function postedTime(string $key): string {
    $value=postedText($key,16);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value);
    if (!$date || $date->format('Y-m-d\TH:i')!==$value) { http_response_code(422); exit('Invalid date and time'); }
    return $date->format('Y-m-d H:i:s');
}
function workflowAudit(string $event,string $entity,int $id): void {
    $actor=(int)$_SESSION['user_id'];
    $stmt=db()->prepare('INSERT INTO audit_events(actor_user_id,event_name,entity_type,entity_id) VALUES(?,?,?,?)');
    $stmt->bind_param('issi',$actor,$event,$entity,$id); $stmt->execute();
}
function auditedExecute(mysqli_stmt $statement,string $event,string $entity,int $id=0): void {
    $database=db(); $database->begin_transaction();
    try {
        $statement->execute();
        $record=$id?:$database->insert_id;
        workflowAudit($event,$entity,(int)$record);
        $database->commit();
    } catch(Throwable $e) {
        $database->rollback();
        error_log('Audited shelter update failed: '.get_class($e));
        http_response_code(500); exit('Unable to save. No changes were saved.');
    }
}
function workflowChange(callable $action,string $redirect): void {
    $database=db(); $database->begin_transaction();
    try { $action($database); $database->commit(); }
    catch (Throwable $e) {
        $database->rollback();
        if ($e instanceof DomainException) { http_response_code(422); exit($e->getMessage()); }
        error_log('Shelter workflow failed: '.get_class($e));
        http_response_code(500); exit('Unable to save. No changes were saved.');
    }
    header('Location: '.$redirect,true,303); exit;
}
function workflowForm(string $action=''): string {
    return '<form method="post" action="'.escape($action).'"><input type="hidden" name="_csrf" value="'.csrf().'">';
}
function workflowField(string $label,string $name,string $type='text',int $max=160,bool $required=true): string {
    return '<label>'.escape($label).'<input type="'.escape($type).'" name="'.escape($name).'" maxlength="'.$max.'"'.($required?' required':'').'></label>';
}
function caseworkPage(string $method,?int $residentId=null): void {
    requireModule('casework');
    if ($residentId===null) {
        if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
        $html='<p>Case notes and appointments are private to staff assigned case access at the facility.</p><table><tr><th>Facility</th><th>Reference</th><th>Resident</th></tr>';
        $rows=db()->query('SELECT r.id,r.reference_code,r.display_name,f.name FROM residents r JOIN facilities f ON f.id=r.facility_id WHERE '.moduleFacilityScope('casework','r.facility_id').' ORDER BY r.id DESC LIMIT 200');
        foreach($rows as $row) $html.='<tr><td>'.escape($row['name']).'</td><td>'.escape($row['reference_code']).'</td><td><a href="/cases/'.(int)$row['id'].'">'.escape($row['display_name']).'</a></td></tr>';
        view('Case management',$html.'</table>'); return;
    }
    $stmt=db()->prepare('SELECT r.* FROM residents r WHERE r.id=? AND '.moduleFacilityScope('casework','r.facility_id'));
    $stmt->bind_param('i',$residentId); $stmt->execute(); $resident=$stmt->get_result()->fetch_assoc();
    if (!$resident) { http_response_code(404); exit('Resident not found'); }
    if ($method==='POST') {
        verifyCsrf(); $action=postedText('action',30); $actor=(int)$_SESSION['user_id'];
        if ($action==='note') {
            $body=postedText('body',10000);
            workflowChange(function($database) use($residentId,$actor,$body) {
                $stmt=$database->prepare('INSERT INTO case_notes(resident_id,author_id,body) VALUES(?,?,?)');
                $stmt->bind_param('iis',$residentId,$actor,$body); $stmt->execute(); workflowAudit('case_note_added','case_note',$database->insert_id);
            },'/cases/'.$residentId);
        } elseif ($action==='appointment') {
            $facility=(int)$resident['facility_id'];
            $title=postedText('title',160); $location=postedText('location',160,false);
            $start=postedTime('starts_at'); $end=postedTime('ends_at');
            if ($start>=$end) { http_response_code(422); exit('End must follow start'); }
            workflowChange(function($database) use($residentId,$actor,$title,$location,$start,$end,$facility) {
                $stmt=$database->prepare('INSERT INTO resident_appointments(facility_id,resident_id,appointment_type,title,location,starts_at,ends_at,created_by) VALUES(?,?,\'other\',?,?,?,?,?)');
                $stmt->bind_param('iissssi',$facility,$residentId,$title,$location,$start,$end,$actor); $stmt->execute(); workflowAudit('appointment_created','appointment',$database->insert_id);
            },'/cases/'.$residentId);
        } elseif ($action==='appointment_status') {
            $id=postedId('appointment_id'); $status=postedText('status',20);
            if (!in_array($status,['scheduled','completed','cancelled'],true)) { http_response_code(422); exit('Invalid status'); }
            workflowChange(function($database) use($id,$residentId,$status) {
                $stmt=$database->prepare('SELECT id FROM resident_appointments WHERE id=? AND resident_id=? FOR UPDATE');
                $stmt->bind_param('ii',$id,$residentId); $stmt->execute();
                if (!$stmt->get_result()->fetch_row()) throw new DomainException('Appointment not found');
                $stmt=$database->prepare('UPDATE resident_appointments SET status=? WHERE id=? AND resident_id=?');
                $stmt->bind_param('sii',$status,$id,$residentId); $stmt->execute(); workflowAudit('appointment_status_changed','appointment',$id);
            },'/cases/'.$residentId);
        } elseif ($action==='profile') {
            $contact=postedText('emergency_contact',500,false); $consent=postedText('consent_note',1000,false);
            $raw=postedText('caseworker_id',20,false); $worker=$raw===''?null:filter_var($raw,FILTER_VALIDATE_INT);
            if ($raw!=='' && (!$worker || $worker<1)) { http_response_code(422); exit('Invalid case worker'); }
            $facility=(int)$resident['facility_id'];
            workflowChange(function($database) use($residentId,$actor,$contact,$consent,$worker,$facility) {
                if ($worker!==null) {
                    $stmt=$database->prepare("SELECT u.id FROM users u WHERE u.id=? AND u.active=1 AND (EXISTS(SELECT 1 FROM staff_facility_permissions m WHERE m.user_id=u.id AND m.facility_id=? AND m.module='casework') OR EXISTS(SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=u.id AND p.name='admin.manage'))");
                    $stmt->bind_param('ii',$worker,$facility); $stmt->execute();
                    if (!$stmt->get_result()->fetch_row()) throw new DomainException('Select an active case worker assigned to this facility');
                }
                $stmt=$database->prepare('INSERT INTO resident_case_profiles(resident_id,caseworker_id,emergency_contact,consent_note,updated_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE caseworker_id=VALUES(caseworker_id),emergency_contact=VALUES(emergency_contact),consent_note=VALUES(consent_note),updated_by=VALUES(updated_by)');
                $stmt->bind_param('iissi',$residentId,$worker,$contact,$consent,$actor); $stmt->execute(); workflowAudit('case_profile_updated','resident',$residentId);
            },'/cases/'.$residentId);
        } elseif ($action==='referral') {
            $service=postedText('service',160); $provider=postedText('provider',160); $notes=postedText('notes',1000,false);
            workflowChange(function($database) use($residentId,$actor,$service,$provider,$notes) {
                $stmt=$database->prepare('INSERT INTO case_referrals(resident_id,service,provider,notes,created_by) VALUES(?,?,?,?,?)');
                $stmt->bind_param('isssi',$residentId,$service,$provider,$notes,$actor); $stmt->execute(); workflowAudit('referral_created','case_referral',$database->insert_id);
            },'/cases/'.$residentId);
        } elseif ($action==='referral_status') {
            $id=postedId('referral_id'); $status=postedText('status',20);
            if (!in_array($status,['pending','in_progress','completed','cancelled'],true)) { http_response_code(422); exit('Invalid status'); }
            workflowChange(function($database) use($id,$residentId,$status) {
                $stmt=$database->prepare('SELECT id FROM case_referrals WHERE id=? AND resident_id=? FOR UPDATE');
                $stmt->bind_param('ii',$id,$residentId); $stmt->execute();
                if (!$stmt->get_result()->fetch_row()) throw new DomainException('Referral not found');
                $stmt=$database->prepare('UPDATE case_referrals SET status=? WHERE id=? AND resident_id=?');
                $stmt->bind_param('sii',$status,$id,$residentId); $stmt->execute(); workflowAudit('referral_status_updated','case_referral',$id);
            },'/cases/'.$residentId);
        } else { http_response_code(422); exit('Invalid action'); }
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $stmt=db()->prepare('SELECT * FROM resident_case_profiles WHERE resident_id=?');
    $stmt->bind_param('i',$residentId); $stmt->execute(); $profile=$stmt->get_result()->fetch_assoc()?:[];
    $html='<p><a href="/cases">Cases</a></p><p>'.escape($resident['display_name'].' · '.$resident['reference_code']).'</p><h2>Case assignment and intake support</h2><p>Record only necessary information with appropriate consent. Emergency contacts and case details stay private to case staff.</p>'.workflowForm().'<input type="hidden" name="action" value="profile"><label>Case worker<select name="caseworker_id"><option value="">Unassigned</option>';
    $facility=(int)$resident['facility_id'];
    $stmt=db()->prepare("SELECT u.id,u.first_name,u.last_name FROM users u WHERE u.active=1 AND (EXISTS(SELECT 1 FROM staff_facility_permissions m WHERE m.user_id=u.id AND m.facility_id=? AND m.module='casework') OR EXISTS(SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=u.id AND p.name='admin.manage')) ORDER BY u.first_name,u.id");
    $stmt->bind_param('i',$facility); $stmt->execute();
    foreach($stmt->get_result() as $worker) $html.='<option value="'.(int)$worker['id'].'" '.(($profile['caseworker_id']??null)==$worker['id']?'selected':'').'>'.escape(trim(($worker['first_name']??'').' '.($worker['last_name']??''))?:'Employee #'.$worker['id']).'</option>';
    $html.='</select></label><label>Emergency contact (optional)<textarea name="emergency_contact" maxlength="500">'.escape($profile['emergency_contact']??'').'</textarea></label><label>Consent and intake support note (optional)<textarea name="consent_note" maxlength="1000">'.escape($profile['consent_note']??'').'</textarea></label><button>Save case profile</button></form><h2>Services and referrals</h2>';
    $stmt=db()->prepare('SELECT * FROM case_referrals WHERE resident_id=? ORDER BY id DESC LIMIT 100');
    $stmt->bind_param('i',$residentId); $stmt->execute();
    foreach($stmt->get_result() as $referral) {
        $html.='<article><h3>'.escape($referral['service']).'</h3><p>'.escape($referral['provider'].' · '.$referral['status']).'</p><p>'.nl2br(escape($referral['notes'])).'</p>'.workflowForm().'<input type="hidden" name="action" value="referral_status"><input type="hidden" name="referral_id" value="'.(int)$referral['id'].'"><label>Status<select name="status">';
        foreach(['pending','in_progress','completed','cancelled'] as $status) $html.='<option'.($status===$referral['status']?' selected':'').'>'.$status.'</option>';
        $html.='</select></label><button>Update referral</button></form></article>';
    }
    $html.=workflowForm().'<input type="hidden" name="action" value="referral">'.workflowField('Service','service').workflowField('Provider','provider').'<label>Referral notes<textarea name="notes" maxlength="1000"></textarea></label><button>Add referral</button></form><h2>Case notes</h2>';
    $stmt=db()->prepare('SELECT n.body,n.created_at,u.first_name,u.last_name FROM case_notes n JOIN users u ON u.id=n.author_id WHERE n.resident_id=? ORDER BY n.created_at DESC,n.id DESC LIMIT 100');
    $stmt->bind_param('i',$residentId); $stmt->execute();
    foreach($stmt->get_result() as $row) $html.='<article><p>'.nl2br(escape($row['body'])).'</p><small>'.escape(trim(($row['first_name']??'').' '.($row['last_name']??''))).' · '.escape($row['created_at']).'</small></article>';
    $html.=workflowForm().'<input type="hidden" name="action" value="note"><label>Case note<textarea name="body" maxlength="10000" required></textarea></label><button>Add private note</button></form><h2>Appointments</h2>';
    $stmt=db()->prepare('SELECT * FROM resident_appointments WHERE resident_id=? ORDER BY starts_at DESC LIMIT 100');
    $stmt->bind_param('i',$residentId); $stmt->execute();
    foreach($stmt->get_result() as $row) {
        $html.='<article><h3>'.escape($row['title']).'</h3><p>'.escape($row['starts_at'].' – '.$row['ends_at'].' · '.$row['location'].' · '.$row['status']).'</p>'.workflowForm().'<input type="hidden" name="action" value="appointment_status"><input type="hidden" name="appointment_id" value="'.(int)$row['id'].'"><label>Status<select name="status">';
        foreach(['scheduled','completed','cancelled'] as $status) $html.='<option'.($row['status']===$status?' selected':'').'>'.$status.'</option>';
        $html.='</select></label><button>Update appointment</button></form></article>';
    }
    $html.='<h2>New appointment</h2>'.workflowForm().'<input type="hidden" name="action" value="appointment">'.workflowField('Appointment','title').workflowField('Location','location','text',160,false).workflowField('Start','starts_at','datetime-local').workflowField('End','ends_at','datetime-local').'<button>Schedule appointment</button></form>';
    view('Private case management',$html);
}
function volunteerShiftsPage(string $method): void {
    requireModule('volunteers');
    if ($method==='POST') {
        verifyCsrf(); $action=postedText('action',30);
        if ($action==='create') {
            $volunteer=postedId('volunteer_id'); $start=postedTime('starts_at'); $end=postedTime('ends_at');
            if ($start>=$end) { http_response_code(422); exit('End must follow start'); }
            workflowChange(function($database) use($volunteer,$start,$end) {
                $stmt=$database->prepare('SELECT v.id FROM volunteers v WHERE v.id=? AND v.active=1 AND '.moduleFacilityScope('volunteers','v.facility_id').' FOR UPDATE');
                $stmt->bind_param('i',$volunteer); $stmt->execute();
                if (!$stmt->get_result()->fetch_row()) throw new DomainException('Select an accessible active volunteer');
                $stmt=$database->prepare("SELECT s.id FROM volunteer_shifts s LEFT JOIN volunteer_shift_details d ON d.shift_id=s.id WHERE s.volunteer_id=? AND s.starts_at<? AND s.ends_at>? AND COALESCE(d.status,'scheduled')<>'cancelled' LIMIT 1");
                $stmt->bind_param('iss',$volunteer,$end,$start); $stmt->execute();
                if ($stmt->get_result()->fetch_row()) throw new DomainException('This volunteer already has an overlapping shift');
                $stmt=$database->prepare('INSERT INTO volunteer_shifts(volunteer_id,starts_at,ends_at) VALUES(?,?,?)');
                $stmt->bind_param('iss',$volunteer,$start,$end); $stmt->execute(); $id=$database->insert_id;
                $stmt=$database->prepare('INSERT INTO volunteer_shift_details(shift_id) VALUES(?)'); $stmt->bind_param('i',$id); $stmt->execute();
                workflowAudit('volunteer_shift_created','volunteer_shift',$id);
            },'/volunteer-shifts');
        } elseif ($action==='status') {
            $id=postedId('shift_id'); $status=postedText('status',20); $actor=(int)$_SESSION['user_id'];
            if (!in_array($status,['scheduled','completed','cancelled'],true)) { http_response_code(422); exit('Invalid status'); }
            workflowChange(function($database) use($id,$status,$actor) {
                $stmt=$database->prepare('SELECT s.id FROM volunteer_shifts s JOIN volunteers v ON v.id=s.volunteer_id WHERE s.id=? AND '.moduleFacilityScope('volunteers','v.facility_id').' FOR UPDATE');
                $stmt->bind_param('i',$id); $stmt->execute();
                if (!$stmt->get_result()->fetch_row()) throw new DomainException('Shift not found');
                $stmt=$database->prepare('INSERT INTO volunteer_shift_details(shift_id,status,approved_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),approved_by=VALUES(approved_by)');
                $stmt->bind_param('isi',$id,$status,$actor); $stmt->execute(); workflowAudit('volunteer_shift_status_changed','volunteer_shift',$id);
            },'/volunteer-shifts');
        } else { http_response_code(422); exit('Invalid action'); }
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $html='<p><a href="/admin/platform/volunteers">Volunteer records</a></p><table><tr><th>Volunteer</th><th>Start</th><th>End</th><th>Hours</th><th>Status</th><th>Update</th></tr>';
    $rows=db()->query("SELECT s.*,v.display_name,COALESCE(d.status,'scheduled') AS status,TIMESTAMPDIFF(MINUTE,s.starts_at,s.ends_at)/60 AS hours FROM volunteer_shifts s JOIN volunteers v ON v.id=s.volunteer_id LEFT JOIN volunteer_shift_details d ON d.shift_id=s.id WHERE ".moduleFacilityScope('volunteers','v.facility_id')." ORDER BY s.starts_at DESC LIMIT 100");
    foreach($rows as $row) {
        $html.='<tr><td>'.escape($row['display_name']).'</td><td>'.escape($row['starts_at']).'</td><td>'.escape($row['ends_at']).'</td><td>'.escape(number_format((float)$row['hours'],2)).'</td><td>'.escape($row['status']).'</td><td>'.workflowForm().'<input type="hidden" name="action" value="status"><input type="hidden" name="shift_id" value="'.(int)$row['id'].'"><label>Status<select name="status">';
        foreach(['scheduled','completed','cancelled'] as $status) $html.='<option'.($status===$row['status']?' selected':'').'>'.$status.'</option>';
        $html.='</select></label><button>Save shift</button></form></td></tr>';
    }
    $html.='</table><h2>Schedule a shift</h2>'.workflowForm().'<input type="hidden" name="action" value="create"><label>Volunteer<select name="volunteer_id" required>';
    foreach(db()->query('SELECT v.id,v.display_name FROM volunteers v WHERE v.active=1 AND '.moduleFacilityScope('volunteers','v.facility_id').' ORDER BY v.display_name') as $row) $html.='<option value="'.(int)$row['id'].'">'.escape($row['display_name']).'</option>';
    view('Volunteer shifts',$html.'</select></label>'.workflowField('Start','starts_at','datetime-local').workflowField('End','ends_at','datetime-local').'<button>Schedule shift</button></form>');
}
function inventoryPage(string $method): void {
    requireModule('kitchen');
    if ($method==='POST') {
        verifyCsrf(); $id=postedId('inventory_id'); $reason=postedText('reason',255); $change=postedText('quantity_change',20);
        if (!preg_match('/^-?[0-9]{1,9}(\.[0-9]{1,2})?$/D',$change) || (float)$change==0) { http_response_code(422); exit('Enter a nonzero quantity with at most two decimal places'); }
        workflowChange(function($database) use($id,$reason,$change) {
            $stmt=$database->prepare('SELECT t.id FROM kitchen_inventory t WHERE t.id=? AND '.moduleFacilityScope('kitchen','t.facility_id').' FOR UPDATE');
            $stmt->bind_param('i',$id); $stmt->execute();
            if (!$stmt->get_result()->fetch_row()) throw new DomainException('Inventory item not found');
            $stmt=$database->prepare('UPDATE kitchen_inventory SET quantity=quantity+? WHERE id=? AND quantity+? BETWEEN 0 AND 9999999999.99');
            $stmt->bind_param('sis',$change,$id,$change); $stmt->execute();
            if (!$stmt->affected_rows) throw new DomainException('Adjustment would make stock negative or exceed its limit');
            $actor=(int)$_SESSION['user_id'];
            $stmt=$database->prepare('INSERT INTO inventory_movements(inventory_id,quantity_change,reason,recorded_by) VALUES(?,?,?,?)');
            $stmt->bind_param('issi',$id,$change,$reason,$actor); $stmt->execute(); workflowAudit('inventory_adjusted','inventory',$id);
        },'/inventory');
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $html='<p><a href="/admin/platform/kitchen-inventory">Add inventory items</a></p><table><tr><th>Item</th><th>Stock</th><th>Storage</th><th>Expires</th></tr>';
    $options='';
    foreach(db()->query('SELECT t.* FROM kitchen_inventory t WHERE '.moduleFacilityScope('kitchen','t.facility_id').' ORDER BY t.expires_on IS NULL,t.expires_on,t.item_name LIMIT 200') as $row) {
        $html.='<tr><td>'.escape($row['item_name']).'</td><td>'.escape($row['quantity'].' '.$row['unit']).'</td><td>'.escape($row['storage_location']).'</td><td>'.escape($row['expires_on']??'—').'</td></tr>';
        $options.='<option value="'.(int)$row['id'].'">'.escape($row['item_name'].' ('.$row['unit'].')').'</option>';
    }
    $html.='</table><h2>Receive or use stock</h2>'.workflowForm().'<label>Item<select name="inventory_id" required>'.$options.'</select></label>'.workflowField('Quantity change (negative to use stock)','quantity_change','text',20).workflowField('Reason','reason','text',255).'<button>Record stock movement</button></form><h2>Recent stock movements</h2><table><tr><th>Item</th><th>Change</th><th>Reason</th><th>Recorded</th></tr>';
    foreach(db()->query('SELECT m.*,t.item_name FROM inventory_movements m JOIN kitchen_inventory t ON t.id=m.inventory_id WHERE '.moduleFacilityScope('kitchen','t.facility_id').' ORDER BY m.id DESC LIMIT 100') as $row) $html.='<tr><td>'.escape($row['item_name']).'</td><td>'.escape($row['quantity_change']).'</td><td>'.escape($row['reason']).'</td><td>'.escape($row['created_at']).'</td></tr>';
    view('Inventory and stock movements',$html.'</table>');
}
