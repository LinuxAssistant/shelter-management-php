<?php
declare(strict_types=1);

/** admin.manage remains the explicit global administration capability. */
function canManageResidents(): bool {
    if (empty($_SESSION['user_id'])) return false;
    if (can('admin.manage')) return true;
    $id=(int)$_SESSION['user_id'];
    $stmt=db()->prepare('SELECT 1 FROM resident_facility_access a JOIN facilities f ON f.id=a.facility_id WHERE a.user_id=? AND f.active=1 LIMIT 1');
    $stmt->bind_param('i',$id); $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}
function requireResidentAccess(): void {
    requireAuth();
    if (!canManageResidents()) { http_response_code(403); exit('Forbidden'); }
}

/** Only trusted source-code column expressions are accepted, never user input. */
function residentFacilityScope(string $column): string {
    if (!in_array($column,['f.id','r.facility_id','rm.facility_id'],true)) throw new LogicException('Invalid facility scope');
    if (can('admin.manage')) return '1=1';
    $id=(int)($_SESSION['user_id']??0);
    return 'EXISTS(SELECT 1 FROM resident_facility_access access_scope JOIN facilities access_facility ON access_facility.id=access_scope.facility_id AND access_facility.active=1 WHERE access_scope.user_id='.$id.' AND access_scope.facility_id='.$column.')';
}
