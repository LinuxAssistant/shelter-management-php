<?php
declare(strict_types=1);
function searchResidentsApi(string $method): void {
    header('Cache-Control: no-store');
    requireResidentAccess();
    $scope=residentFacilityScope('r.facility_id');
    header('Content-Type: application/json; charset=utf-8');
    if ($method !== 'GET') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); return; }
    if (isset($_GET['q']) && !is_string($_GET['q'])) { http_response_code(422); echo json_encode(['error'=>'Invalid search query']); return; }
    $q=trim($_GET['q']??'');
    if (mb_strlen($q)<2 || mb_strlen($q)>100) { echo '[]'; return; }
    $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';
    $stmt=db()->prepare("SELECT r.id,r.facility_id,r.reference_code,r.display_name,f.name AS facility_name FROM residents r JOIN facilities f ON f.id=r.facility_id WHERE f.active=1 AND ".$scope." AND (r.reference_code LIKE ? ESCAPE '!' OR r.display_name LIKE ? ESCAPE '!') AND NOT EXISTS (SELECT 1 FROM stays s WHERE s.resident_id=r.id AND s.checked_out_at IS NULL) ORDER BY r.display_name,r.id LIMIT 20");
    $stmt->bind_param('ss',$pattern,$pattern);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC),JSON_INVALID_UTF8_SUBSTITUTE);
}
