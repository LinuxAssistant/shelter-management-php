<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($path === '/login' && $method === 'GET') {
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
    requireAuth(); view('Staff dashboard','<p>Welcome to Shelter Management.</p><p><a href="/incidents">View incident reports</a></p>');
} elseif ($path === '/incidents' && $method === 'GET') {
    requirePermission('incidents.view'); $rows=db()->query('SELECT id,category,severity,status,occurred_at FROM incidents ORDER BY occurred_at DESC LIMIT 100'); $html='<p><a href="/incidents/new">New incident</a></p><table><tr><th>ID</th><th>Category</th><th>Severity</th><th>Status</th><th>When</th></tr>';
    while($row=$rows->fetch_assoc()) $html.='<tr><td>'.(int)$row['id'].'</td><td>'.escape($row['category']).'</td><td>'.escape($row['severity']).'</td><td>'.escape($row['status']).'</td><td>'.escape($row['occurred_at']).'</td></tr>';
    view('Incident reports',$html.'</table>');
} elseif ($path === '/incidents/new' && $method === 'GET') {
    requirePermission('incidents.create'); view('Report incident','<form method="post" action="/incidents"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Category <select name="category"><option>Medical</option><option>Safety</option><option>Conflict</option><option>Property</option><option>Other</option></select></label><label>Severity <select name="severity"><option>Low</option><option>Medium</option><option>High</option><option>Critical</option></select></label><label>Location <input name="location" maxlength="150" required></label><label>Description <textarea name="description" maxlength="10000" required></textarea></label><button>Submit report</button></form>');
} elseif ($path === '/incidents' && $method === 'POST') {
    requirePermission('incidents.create'); verifyCsrf();
    $category=(string)($_POST['category'] ?? ''); $severity=(string)($_POST['severity'] ?? ''); $location=trim((string)($_POST['location'] ?? '')); $description=trim((string)($_POST['description'] ?? ''));
    if (!in_array($category,['Medical','Safety','Conflict','Property','Other'],true) || !in_array($severity,['Low','Medium','High','Critical'],true) || $location==='' || $description==='' || strlen($location)>150 || strlen($description)>10000) { http_response_code(422); exit('Invalid incident details'); }
    $stmt=db()->prepare('INSERT INTO incidents(category,severity,location,description,reported_by,occurred_at) VALUES(?,?,?,?,?,NOW())');
    $userId=(int)$_SESSION['user_id']; $stmt->bind_param('ssssi',$category,$severity,$location,$description,$userId); $stmt->execute();
    header('Location: /incidents',true,303); exit;
} else { http_response_code(404); view('Not found','<p>Page not found.</p>'); }
