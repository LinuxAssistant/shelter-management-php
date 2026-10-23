<?php
declare(strict_types=1);

function residentEditPage(string $method,int $id): void {
    requireResidentAccess(); header('Cache-Control: no-store');
    $stmt=db()->prepare('SELECT r.*,f.name AS facility_name FROM residents r JOIN facilities f ON f.id=r.facility_id WHERE r.id=? AND '.residentFacilityScope('r.facility_id'));
    $stmt->bind_param('i',$id); $stmt->execute(); $resident=$stmt->get_result()->fetch_assoc();
    if (!$resident) { http_response_code(404); exit('Resident not found'); }
    if ($method==='POST') {
        verifyCsrf(); $name=postedText('display_name',160); $facility=(int)$resident['facility_id'];
        workflowChange(function($database) use($id,$name,$facility) {
            $lock=$database->prepare('SELECT id FROM facilities WHERE id=? FOR UPDATE');
            $lock->bind_param('i',$facility); $lock->execute(); $lock->get_result()->fetch_row();
            $check=$database->prepare('SELECT id FROM residents WHERE facility_id=? AND id<>? AND LOWER(TRIM(display_name))=LOWER(?) LIMIT 1');
            $check->bind_param('iis',$facility,$id,$name); $check->execute();
            if ($check->get_result()->fetch_row() && ($_POST['distinct_person']??'')!=='1') throw new DomainException('A resident with that name exists. Check the existing record, then confirm if this is a different person.');
            $stmt=$database->prepare('UPDATE residents SET display_name=? WHERE id=?');
            $stmt->bind_param('si',$name,$id); $stmt->execute(); workflowAudit('resident_intake_updated','resident',$id);
        },'/admin/residents/'.$id.'/edit');
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $html='<p><a href="/admin/residents">Residents</a> · <a href="/admin/residents/'.$id.'/history">Stay history</a></p><p>'.escape($resident['reference_code'].' · '.$resident['facility_name']).'</p>'.workflowForm().'<label>Display name<input name="display_name" maxlength="160" required value="'.escape($resident['display_name']).'"></label><label><input type="checkbox" name="distinct_person" value="1"> I checked matching records; this is a different person with the same name.</label><button>Save intake details</button></form><p>Facility and reference cannot be changed here because stays and history depend on them. Record private case details in case management.</p>';
    view('Update resident intake',$html);
}
