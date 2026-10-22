<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';
require dirname(__DIR__) . '/app/EmployeeManagement.php';
require dirname(__DIR__) . '/app/ResidentAccess.php';
require dirname(__DIR__) . '/app/FacilityModules.php';
require dirname(__DIR__) . '/app/Workflows.php';
require dirname(__DIR__) . '/app/SitePages.php';
require dirname(__DIR__) . '/app/Administration.php';
require dirname(__DIR__) . '/app/Finance.php';
require dirname(__DIR__) . '/app/IncidentAccess.php';
require dirname(__DIR__) . '/app/IncidentFacilityAttribution.php';
require dirname(__DIR__) . '/app/IncidentFacilityAssignments.php';
require dirname(__DIR__) . '/app/ResidentFacilityAssignments.php';
require dirname(__DIR__) . '/app/PlatformAdmin.php';
require dirname(__DIR__) . '/app/Chores.php';
require dirname(__DIR__) . '/app/ShelterSchedules.php';
require dirname(__DIR__) . '/app/DaytimeExceptions.php';
require dirname(__DIR__) . '/app/DaytimeFood.php';
require dirname(__DIR__) . '/app/ResidentManagement.php';
require dirname(__DIR__) . '/app/ResidentEdit.php';
require dirname(__DIR__) . '/app/ResidentHistory.php';
require dirname(__DIR__) . '/app/ResidentSearchApi.php';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (preg_match('~^/donations/([1-9][0-9]*)/receipt$~D',(string)$path,$receiptMatch)) {
    donationReceipt($method,(int)$receiptMatch[1]);
} elseif (preg_match('~^/reports/(donations|expenses)\.csv$~D',(string)$path,$exportMatch)) {
    financeExport($method,$exportMatch[1]);
} elseif (in_array($path,['/welcome','/services','/contact'],true)) {
    publicSitePage($method,$path==='/welcome'?'home':substr($path,1));
} elseif ($path === '/admin/public-site') {
    publicSettingsPage($method);
} elseif ($path === '/reports') {
    reportsPage($method);
} elseif ($path === '/admin/audit') {
    auditPage($method);
} elseif ($path === '/cases') {
    caseworkPage($method);
} elseif (preg_match('~^/cases/([1-9][0-9]*)$~D',(string)$path,$caseMatch)) {
    caseworkPage($method,(int)$caseMatch[1]);
} elseif ($path === '/volunteer-shifts') {
    volunteerShiftsPage($method);
} elseif ($path === '/inventory') {
    inventoryPage($method);
} elseif ($path === '/api/residents/search') {
    searchResidentsApi($method);
} elseif (preg_match('~^/admin/residents/([1-9][0-9]*)/history$~D',(string)$path,$residentHistoryMatch)) {
    residentHistoryPage($method,(int)$residentHistoryMatch[1]);
} elseif (preg_match('~^/admin/residents/([1-9][0-9]*)/edit$~D',(string)$path,$residentEditMatch)) {
    residentEditPage($method,(int)$residentEditMatch[1]);
} elseif ($path === '/admin/residents') {
    residentManagement($method);
} elseif ($path === '/admin/occupancy') {
    occupancyManagement($method);
} elseif ($path === '/admin/daytime-food') {
    daytimeFoodPage($method);
} elseif ($path === '/admin/daytime-exceptions') {
    daytimeExceptionsPage($method);
} elseif ($path === '/admin/schedules') {
    shelterSchedulePage($method);
} elseif ($path === '/admin/programs') {
    shelterProgramsPage($method);
} elseif ($path === '/admin/chores') {
    choresPage($method);
} elseif (preg_match('~^/admin/platform/(facilities|rooms|beds|volunteers|donations|expenses|kitchen-inventory|kitchen-meals|kitchen-temperatures|kitchen-waste)$~D',(string)$path,$platformMatch)) {
    platformAdmin($platformMatch[1],$method);
} elseif ($path === '/login' && $method === 'GET') {
    view('Employee login','<form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Email or badge number <input name="identifier" required autocomplete="username"></label><label>Password <input type="password" name="password" required autocomplete="current-password"></label><button>Sign in</button></form>');
} elseif ($path === '/login' && $method === 'POST') {
    verifyCsrf();
    $identifier=trim((string)($_POST['identifier'] ?? '')); $password=(string)($_POST['password'] ?? '');
    $stmt=db()->prepare('SELECT id,password_hash FROM users WHERE (email=? OR badge_number=?) AND active=1 LIMIT 1');
    $stmt->bind_param('ss',$identifier,$identifier); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
    if ($user && password_verify($password,$user['password_hash'])) { session_regenerate_id(true); $_SESSION['user_id']=(int)$user['id']; header('Location: /'); exit; }
    http_response_code(401); view('Login failed','<p>Invalid credentials.</p><a href="/login">Try again</a>');
} elseif ($path === '/logout' && $method === 'POST') {
    requireAuth(); verifyCsrf(); $_SESSION=[]; session_destroy(); header('Location: /login'); exit;
} elseif ($path === '/' && $method === 'GET') {
    if (empty($_SESSION['user_id'])) publicSitePage($method); else staffDashboard();
} elseif ($path === '/incidents' && $method === 'GET') {
    requireIncidentAccess('incidents.view'); $rows=db()->query('SELECT i.id,i.category,i.severity,i.status,i.occurred_at,f.name AS facility_name FROM incidents i LEFT JOIN facilities f ON f.id=i.facility_id WHERE '.incidentFacilityScope('i.facility_id').' ORDER BY i.occurred_at DESC LIMIT 100'); $html=(canManageIncidents('incidents.create')?'<p><a href="/incidents/new">New incident</a></p>':'').'<table><tr><th>ID</th><th>Facility</th><th>Category</th><th>Severity</th><th>Status</th><th>When</th></tr>';
    while($row=$rows->fetch_assoc()) $html.='<tr><td><a href="/incidents/'.(int)$row['id'].'">#'.(int)$row['id'].'</a></td><td>'.escape($row['facility_name']??'Unassigned (administrator only)').'</td><td>'.escape($row['category']).'</td><td>'.escape($row['severity']).'</td><td>'.escape($row['status']).'</td><td>'.escape($row['occurred_at']).'</td></tr>';
    view('Incident reports',$html.'</table>');
} elseif ($path === '/incidents/new' && $method === 'GET') {
    requireIncidentAccess('incidents.create'); view('Report incident','<form method="post" action="/incidents"><input type="hidden" name="_csrf" value="'.csrf().'">'.incidentFacilityOptions().'<label>Category <select name="category"><option>Medical</option><option>Safety</option><option>Conflict</option><option>Property</option><option>Other</option></select></label><label>Severity <select name="severity"><option>Low</option><option>Medium</option><option>High</option><option>Critical</option></select></label><label>Location <input name="location" maxlength="150" required></label><label>Description <textarea name="description" maxlength="10000" required></textarea></label><button>Submit report</button></form>');
} elseif ($path === '/incidents' && $method === 'POST') {
    requireIncidentAccess('incidents.create'); verifyCsrf();
    $category=(string)($_POST['category'] ?? ''); $severity=(string)($_POST['severity'] ?? ''); $location=trim((string)($_POST['location'] ?? '')); $description=trim((string)($_POST['description'] ?? ''));
    if (!in_array($category,['Medical','Safety','Conflict','Property','Other'],true) || !in_array($severity,['Low','Medium','High','Critical'],true) || $location==='' || $description==='' || strlen($location)>150 || strlen($description)>10000) { http_response_code(422); exit('Invalid incident details'); }
    $facility=filter_var($_POST['facility_id']??null,FILTER_VALIDATE_INT);
    if (!$facility || $facility<1) { http_response_code(422); exit('Select an active facility'); }
    $stmt=db()->prepare('SELECT f.id FROM facilities f WHERE f.id=? AND f.active=1 AND '.incidentFacilityScope('f.id'));
    $stmt->bind_param('i',$facility); $stmt->execute();
    if (!$stmt->get_result()->fetch_row()) { http_response_code(422); exit('Select an active facility'); }
    $stmt=db()->prepare('INSERT INTO incidents(category,severity,location,description,reported_by,facility_id,occurred_at) VALUES(?,?,?,?,?,?,NOW())');
    $userId=(int)$_SESSION['user_id']; $stmt->bind_param('ssssii',$category,$severity,$location,$description,$userId,$facility); $stmt->execute();
    header('Location: /incidents',true,303); exit;
} elseif (preg_match('~^/incidents/([1-9][0-9]*)/status$~D',(string)$path,$statusMatch)) {
    incidentStatusPage($method,(int)$statusMatch[1]);
} elseif (preg_match('~^/incidents/([1-9][0-9]*)/facility$~D',(string)$path,$incidentFacilityMatch)) {
    assignIncidentFacility($method,(int)$incidentFacilityMatch[1]);
} elseif (preg_match('~^/incidents/([1-9][0-9]*)$~D', (string)$path, $matches) && $method === 'GET') {
    requireIncidentAccess('incidents.view');
    $id=(int)$matches[1];
    $stmt=db()->prepare('SELECT i.*,u.email AS reporter,f.name AS facility_name FROM incidents i JOIN users u ON u.id=i.reported_by LEFT JOIN facilities f ON f.id=i.facility_id WHERE i.id=? AND '.incidentFacilityScope('i.facility_id'));
    $stmt->bind_param('i',$id); $stmt->execute(); $incident=$stmt->get_result()->fetch_assoc();
    if (!$incident) { http_response_code(404); exit('Incident not found'); }
    $html='<p><a href="/incidents">Back to incidents</a></p><p><strong>Facility:</strong> '.escape($incident['facility_name']??'Unassigned (administrator only)').'</p><p><strong>Category:</strong> '.escape($incident['category']).' | <strong>Severity:</strong> '.escape($incident['severity']).'</p><p><strong>Location:</strong> '.escape($incident['location']).'</p><p><strong>Reported by:</strong> '.escape($incident['reporter']).'</p><p>'.nl2br(escape($incident['description'])).'</p>';
    $stmt=db()->prepare('SELECT p.*,u.email AS added_by_email FROM incident_people p JOIN users u ON u.id=p.added_by WHERE p.incident_id=? ORDER BY p.id');
    $stmt->bind_param('i',$id); $stmt->execute(); $people=$stmt->get_result();
    $html.='<h2>People involved</h2><ul>';
    while ($person=$people->fetch_assoc()) $html.='<li>'.escape($person['display_name']).' — '.escape($person['person_type']).' ('.escape($person['involvement']).')</li>';
    $html.='</ul>';
    if (can('incidents.people')) $html.='<form method="post" action="/incidents/'.$id.'/people"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Name or identifier <input name="display_name" maxlength="150" required></label><label>Person type <select name="person_type"><option>Resident</option><option>Employee</option><option>Visitor</option><option>Unknown</option></select></label><label>Involvement <select name="involvement"><option>Witness</option><option>Affected</option><option>Reported</option><option>Other</option></select></label><button>Add person</button></form>';
    $stmt=db()->prepare('SELECT n.*,u.email AS author,u.first_name,u.last_name,u.job_title,u.badge_number FROM incident_notes n JOIN users u ON u.id=n.author_id WHERE n.incident_id=? ORDER BY n.created_at,n.id');
    $stmt->bind_param('i',$id); $stmt->execute(); $notes=$stmt->get_result();
    $html.='<h2>Employee notes</h2>';
    while ($note=$notes->fetch_assoc()) { $fullName=trim(($note['first_name'] ?? '').' '.($note['last_name'] ?? '')); $display=$fullName!==''?$fullName:$note['author']; $job=trim((string)($note['job_title'] ?? '')); $html.='<article><p>'.nl2br(escape($note['body'])).'</p><small>'.escape($display).($job!==''?' · '.escape($job):'').' · '.escape($note['created_at']).'</small></article><hr>'; }
    if (can('incidents.note')) $html.='<form method="post" action="/incidents/'.$id.'/notes"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Add a note <textarea name="body" maxlength="10000" required></textarea></label><button>Save note</button></form>';
    if ($incident['facility_id']===null && can('admin.manage')) {
        $html.='<h2>Assign historical report to a facility</h2><p>Verify the correct facility before saving. Staff assigned to that facility with incident permissions will be able to read this report, its notes, and people involved. This assignment cannot be changed here.</p><form method="post" action="/incidents/'.$id.'/facility"><input type="hidden" name="_csrf" value="'.csrf().'">'.incidentFacilityOptions().'<button>Assign facility and grant facility access</button></form>';
    }
    if (canManageIncidents('incidents.review')) {
        $html.='<h2>Review status</h2>'.workflowForm('/incidents/'.$id.'/status').'<label>Status<select name="status">';
        foreach(['Open','Under review','Resolved'] as $status) $html.='<option'.($status===$incident['status']?' selected':'').'>'.escape($status).'</option>';
        $html.='</select></label><button>Update incident status</button></form>';
    }
    view('Incident #'.$id,$html);
} elseif (preg_match('~^/incidents/([1-9][0-9]*)/(notes|people)$~D', (string)$path, $matches) && $method === 'POST') {
    $id=(int)$matches[1]; $kind=$matches[2];
    requireIncidentAccess($kind==='notes'?'incidents.note':'incidents.people'); verifyCsrf();
    $stmt=db()->prepare('SELECT i.id FROM incidents i WHERE i.id=? AND '.incidentFacilityScope('i.facility_id')); $stmt->bind_param('i',$id); $stmt->execute();
    if (!$stmt->get_result()->fetch_row()) { http_response_code(404); exit('Incident not found'); }
    $author=(int)$_SESSION['user_id'];
    if ($kind==='notes') {
        $body=trim((string)($_POST['body'] ?? ''));
        if ($body==='' || strlen($body)>10000) { http_response_code(422); exit('Invalid note'); }
        $stmt=db()->prepare('INSERT INTO incident_notes(incident_id,author_id,body) VALUES(?,?,?)');
        $stmt->bind_param('iis',$id,$author,$body);
    } else {
        $name=trim((string)($_POST['display_name'] ?? '')); $type=(string)($_POST['person_type'] ?? ''); $involvement=(string)($_POST['involvement'] ?? '');
        if ($name==='' || strlen($name)>150 || !in_array($type,['Resident','Employee','Visitor','Unknown'],true) || !in_array($involvement,['Witness','Affected','Reported','Other'],true)) { http_response_code(422); exit('Invalid person'); }
        $stmt=db()->prepare('INSERT INTO incident_people(incident_id,display_name,person_type,involvement,added_by) VALUES(?,?,?,?,?)');
        $stmt->bind_param('isssi',$id,$name,$type,$involvement,$author);
    }
    $stmt->execute(); header('Location: /incidents/'.$id,true,303); exit;
} elseif ($path === '/admin/employees' && $method === 'GET') {
    employeePage();
} elseif (preg_match('~^/admin/employees/([1-9][0-9]*)/resident-facilities$~D',(string)$path,$facilityAccessMatch)) {
    residentFacilityAssignments($method,(int)$facilityAccessMatch[1]);
} elseif (preg_match('~^/admin/employees/([1-9][0-9]*)/incident-facilities$~D',(string)$path,$incidentAccessMatch)) {
    incidentFacilityAssignments($method,(int)$incidentAccessMatch[1]);
} elseif (preg_match('~^/admin/employees/([1-9][0-9]*)/module-facilities$~D',(string)$path,$moduleMatch)) {
    moduleAssignments($method,(int)$moduleMatch[1]);
} elseif (preg_match('~^/admin/employees/([1-9][0-9]*)/roles$~D',(string)$path,$roleMatch)) {
    employeeRolesPage($method,(int)$roleMatch[1]);
} elseif ($path === '/admin/employees/new' && $method === 'GET') {
    employeeForm(null);
} elseif ($path === '/admin/employees' && $method === 'POST') {
    employeeSave(null);
} elseif (preg_match('~^/admin/employees/([1-9][0-9]*)$~D',(string)$path,$employeeMatches)) {
    requirePermission('admin.manage');
    $employeeId=(int)$employeeMatches[1];
    if ($method==='POST') employeeSave($employeeId);
    elseif ($method==='GET') {
        $stmt=db()->prepare('SELECT id,first_name,last_name,job_title,email,badge_number,active FROM users WHERE id=?');
        $stmt->bind_param('i',$employeeId); $stmt->execute(); $employee=$stmt->get_result()->fetch_assoc();
        if (!$employee) { http_response_code(404); exit('Employee not found'); }
        employeeForm($employee);
    } else { http_response_code(405); exit('Method not allowed'); }
} else { http_response_code(404); view('Not found','<p>Page not found.</p>'); }
