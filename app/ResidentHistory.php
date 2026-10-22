<?php
declare(strict_types=1);

/** Read-only resident admission and departure history. */
function residentHistoryPage(string $method, int $residentId): void {
    requireResidentAccess();
    header('Cache-Control: no-store');
    $scope=residentFacilityScope('r.facility_id');
    $roomScope=residentFacilityScope('rm.facility_id');
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $stmt=db()->prepare('SELECT r.id,r.reference_code,r.display_name,f.name AS facility_name FROM residents r JOIN facilities f ON f.id=r.facility_id WHERE r.id=? AND '.$scope);
    $stmt->bind_param('i',$residentId);
    $stmt->execute();
    $resident=$stmt->get_result()->fetch_assoc();
    if (!$resident) { http_response_code(404); view('Resident not found','<p>No resident found.</p>'); return; }
    $html='<p><a href="/admin/residents">Residents</a> · <a href="/admin/occupancy">Bed occupancy</a></p>';
    $html.='<p><strong>Resident:</strong> '.escape($resident['display_name']).' · <strong>Reference:</strong> '.escape($resident['reference_code']).' · <strong>Facility:</strong> '.escape($resident['facility_name']).'</p>';
    $html.='<h2>Admission and departure history</h2><table><tr><th>Checked in</th><th>Checked out</th><th>Room / bed</th><th>Departure</th><th>Reason</th><th>Processed by</th></tr>';
    $stmt=db()->prepare('SELECT s.checked_in_at,s.checked_out_at,s.departure_type,s.departure_reason,rm.name AS room_name,b.code AS bed_code,b.position,u.email AS employee_email FROM stays s JOIN beds b ON b.id=s.bed_id JOIN rooms rm ON rm.id=b.room_id LEFT JOIN users u ON u.id=s.departed_by WHERE s.resident_id=? AND '.$roomScope.' ORDER BY s.checked_in_at DESC,s.id DESC LIMIT 200');
    $stmt->bind_param('i',$residentId);
    $stmt->execute();
    $rows=$stmt->get_result();
    $count=0;
    while($stay=$rows->fetch_assoc()) {
        $count++;
        $departure=$stay['checked_out_at']===null?'Currently checked in':match($stay['departure_type']){'voluntary'=>'Voluntary departure','staff_exit'=>'Staff-directed exit',default=>'Unspecified (older record)'};
        $html.='<tr><td>'.escape($stay['checked_in_at']).'</td><td>'.escape($stay['checked_out_at']??'—').'</td><td>'.escape($stay['room_name'].' / '.$stay['bed_code'].' ('.$stay['position'].')').'</td><td>'.escape($departure).'</td><td>'.escape($stay['departure_reason']?:'—').'</td><td>'.escape($stay['employee_email']??'—').'</td></tr>';
    }
    $html.='</table>';
    if(!$count)$html.='<p>No stays recorded.</p>';
    if($count===200)$html.='<p>Showing the 200 most recent stays.</p>';
    view('Resident history',$html);
}
