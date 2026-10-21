<?php
declare(strict_types=1);
function shelterSchedulePage(string $method): void {
    requirePermission('admin.manage');
    if ($method==='POST') {
        verifyCsrf();
        $facility=filter_var($_POST['facility_id']??null,FILTER_VALIDATE_INT);
        $exit=(string)($_POST['daytime_exit']??'');
        $return=(string)($_POST['normal_return']??'');
        $policy=trim((string)($_POST['daytime_access_policy']??''));
        if (!$facility || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/',$exit) ||
            !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/',$return) || $exit >= $return ||
            strlen($policy)>255) { http_response_code(422); exit('Invalid schedule'); }
        $flags=[];
        foreach (['breakfast','lunch','dinner','snacks'] as $meal) $flags[]=isset($_POST[$meal.'_enabled'])?1:0;
        $stmt=db()->prepare('INSERT INTO facility_schedules(facility_id,breakfast_enabled,lunch_enabled,dinner_enabled,snacks_enabled,daytime_exit,normal_return,daytime_access_policy) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE breakfast_enabled=VALUES(breakfast_enabled),lunch_enabled=VALUES(lunch_enabled),dinner_enabled=VALUES(dinner_enabled),snacks_enabled=VALUES(snacks_enabled),daytime_exit=VALUES(daytime_exit),normal_return=VALUES(normal_return),daytime_access_policy=VALUES(daytime_access_policy)');
        $stmt->bind_param('iiiiisss',$facility,$flags[0],$flags[1],$flags[2],$flags[3],$exit,$return,$policy);
        $stmt->execute(); header('Location: /admin/schedules',true,303); exit;
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $html='<p>Configure meals and daytime access for each facility. Staff may authorize exceptions.</p>';
    $rows=db()->query('SELECT f.id,f.name,s.breakfast_enabled,s.lunch_enabled,s.dinner_enabled,s.snacks_enabled,s.daytime_exit,s.normal_return,s.daytime_access_policy FROM facilities f LEFT JOIN facility_schedules s ON s.facility_id=f.id WHERE f.active=1 ORDER BY f.name');
    while ($r=$rows->fetch_assoc()) {
        $html.='<h2>'.escape($r['name']).'</h2><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><input type="hidden" name="facility_id" value="'.(int)$r['id'].'">';
        foreach (['breakfast','lunch','dinner','snacks'] as $meal) {
            $enabled=$r[$meal.'_enabled']??($meal==='breakfast'||$meal==='dinner'?1:0);
            $html.='<label><input type="checkbox" name="'.$meal.'_enabled" value="1"'.($enabled?' checked':'').'> Serve '.escape(ucfirst($meal)).'</label>';
        }
        $html.='<label>Daytime exit<input type="time" name="daytime_exit" value="'.escape(substr($r['daytime_exit']??'09:00',0,5)).'" required></label><label>Normal return<input type="time" name="normal_return" value="'.escape(substr($r['normal_return']??'17:00',0,5)).'" required></label><label>Exceptions policy<input name="daytime_access_policy" maxlength="255" value="'.escape($r['daytime_access_policy']??'Scheduled appointments and programs; staff may authorize exceptions').'"></label><button>Save facility schedule</button></form>';
    }
    view('Facility schedules',$html);
}
function shelterProgramsPage(string $method): void {
    requirePermission('admin.manage');
    if ($method==='POST') {
        verifyCsrf();
        $facility=filter_var($_POST['facility_id']??null,FILTER_VALIDATE_INT);
        $provider=trim((string)($_POST['provider_name']??''));
        $title=trim((string)($_POST['title']??''));
        $topic=trim((string)($_POST['topic']??''));
        $location=trim((string)($_POST['location']??''));
        $start=(string)($_POST['starts_at']??''); $end=(string)($_POST['ends_at']??'');
        $a=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$start);
        $b=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$end);
        if (!$facility || $provider==='' || $title==='' || strlen($provider)>160 || strlen($title)>160 || strlen($topic)>255 || strlen($location)>160 ||
            !$a || !$b || $a->format('Y-m-d\\TH:i')!==$start || $b->format('Y-m-d\\TH:i')!==$end || $a >= $b) { http_response_code(422); exit('Invalid program'); }
        $from=$a->format('Y-m-d H:i:s'); $to=$b->format('Y-m-d H:i:s');
        $stmt=db()->prepare('INSERT INTO shelter_programs(facility_id,provider_name,title,topic,starts_at,ends_at,location) VALUES(?,?,?,?,?,?,?)');
        $stmt->bind_param('issssss',$facility,$provider,$title,$topic,$from,$to,$location);
        $stmt->execute(); header('Location: /admin/programs',true,303); exit;
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $html='<p>Schedule group meetings, workshops and visiting organizations.</p><table><tr><th>Facility</th><th>Provider</th><th>Session</th><th>Topic</th><th>Start</th><th>End</th></tr>';
    $rows=db()->query('SELECT p.*,f.name AS facility_name FROM shelter_programs p JOIN facilities f ON f.id=p.facility_id ORDER BY p.starts_at DESC LIMIT 100');
    while ($r=$rows->fetch_assoc()) $html.='<tr><td>'.escape($r['facility_name']).'</td><td>'.escape($r['provider_name']).'</td><td>'.escape($r['title']).'</td><td>'.escape($r['topic']).'</td><td>'.escape($r['starts_at']).'</td><td>'.escape($r['ends_at']).'</td></tr>';
    $html.='</table><h2>Schedule a program</h2><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Facility<select name="facility_id" required>';
    $facilities=db()->query('SELECT id,name FROM facilities WHERE active=1 ORDER BY name');
    while ($f=$facilities->fetch_assoc()) $html.='<option value="'.(int)$f['id'].'">'.escape($f['name']).'</option>';
    $html.='</select></label><label>Organization<input name="provider_name" maxlength="160" required></label><label>Session title<input name="title" maxlength="160" required></label><label>Topic<input name="topic" maxlength="255"></label><label>Location<input name="location" maxlength="160"></label><label>Starts<input type="datetime-local" name="starts_at" required></label><label>Ends<input type="datetime-local" name="ends_at" required></label><button>Schedule</button></form>';
    view('Programs and group meetings',$html);
}
