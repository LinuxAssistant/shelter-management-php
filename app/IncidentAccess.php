<?php
declare(strict_types=1);

/** Incident permissions and facility assignments are independent of resident access. */
function canManageIncidents(string $permission='incidents.view'): bool {
    if (!can($permission)) return false;
    if (can('admin.manage')) return true;
    $id=(int)($_SESSION['user_id']??0);
    $stmt=db()->prepare('SELECT 1 FROM incident_facility_access a JOIN facilities f ON f.id=a.facility_id WHERE a.user_id=? AND f.active=1 LIMIT 1');
    $stmt->bind_param('i',$id); $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}
function requireIncidentAccess(string $permission): void {
    requirePermission($permission);
    header('Cache-Control: no-store');
    if (!canManageIncidents($permission)) { http_response_code(403); exit('Forbidden'); }
}
function incidentFacilityScope(string $column): string {
    if (!in_array($column,['i.facility_id','f.id'],true)) throw new LogicException('Invalid incident facility scope');
    if (can('admin.manage')) return '1=1';
    $id=(int)($_SESSION['user_id']??0);
    return 'EXISTS(SELECT 1 FROM incident_facility_access incident_access JOIN facilities incident_facility ON incident_facility.id=incident_access.facility_id AND incident_facility.active=1 WHERE incident_access.user_id='.$id.' AND incident_access.facility_id='.$column.')';
}
function incidentFacilityOptions(): string {
    $rows=db()->query('SELECT f.id,f.name FROM facilities f WHERE f.active=1 AND '.incidentFacilityScope('f.id').' ORDER BY f.name,f.id');
    $html='<label>Facility<select name="facility_id" required><option value="">Select facility</option>';
    while ($f=$rows->fetch_assoc()) $html.='<option value="'.(int)$f['id'].'">'.escape($f['name']).'</option>';
    return $html.'</select></label>';
}
