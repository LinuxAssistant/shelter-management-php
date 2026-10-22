<?php
declare(strict_types=1);

/** Assign an unscoped historical report once; never move an already scoped report. */
function assignIncidentFacility(string $method,int $incidentId): void {
    requirePermission('admin.manage');
    header('Cache-Control: no-store');
    if ($method!=='POST') { header('Allow: POST'); http_response_code(405); exit('Method not allowed'); }
    verifyCsrf();
    $facility=filter_var($_POST['facility_id']??null,FILTER_VALIDATE_INT);
    if (!$facility || $facility<1) { http_response_code(422); exit('Select an active facility'); }
    $database=db(); $database->begin_transaction();
    try {
        $stmt=$database->prepare('SELECT facility_id FROM incidents WHERE id=? FOR UPDATE');
        $stmt->bind_param('i',$incidentId); $stmt->execute(); $incident=$stmt->get_result()->fetch_assoc();
        if (!$incident) { $database->rollback(); http_response_code(404); exit('Incident not found'); }
        if ($incident['facility_id']!==null) { $database->rollback(); http_response_code(409); exit('This report already has a facility. Refresh the report.'); }
        $stmt=$database->prepare('SELECT id FROM facilities WHERE id=? AND active=1 FOR UPDATE');
        $stmt->bind_param('i',$facility); $stmt->execute();
        if (!$stmt->get_result()->fetch_row()) { $database->rollback(); http_response_code(422); exit('Select an active facility'); }
        $stmt=$database->prepare('UPDATE incidents SET facility_id=? WHERE id=? AND facility_id IS NULL');
        $stmt->bind_param('ii',$facility,$incidentId); $stmt->execute();
        $actor=(int)$_SESSION['user_id']; $event='incident_facility_assigned'; $entity='incident';
        $stmt=$database->prepare('INSERT INTO audit_events(actor_user_id,event_name,entity_type,entity_id) VALUES(?,?,?,?)');
        $stmt->bind_param('issi',$actor,$event,$entity,$incidentId); $stmt->execute();
        $database->commit();
    } catch (Throwable $e) { $database->rollback(); throw $e; }
    header('Location: /incidents/'.$incidentId,true,303); exit;
}
