<?php
declare(strict_types=1);

function publicSitePage(string $method,string $section='home'): void {
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $settings=db()->query('SELECT * FROM public_site_settings WHERE id=1')->fetch_assoc();
    if (!$settings) { http_response_code(503); exit('Site information unavailable'); }
    $title=$section==='home'?$settings['site_name']:ucfirst($section);
    $content=$section==='services'?$settings['services']:($section==='contact'?$settings['contact_text']:$settings['introduction']);
    view($title,'<nav><a href="/welcome">Welcome</a> · <a href="/services">Services</a> · <a href="/contact">Contact</a> · <a href="/login">Staff sign in</a></nav><p>'.nl2br(escape($content)).'</p>');
}
function publicSettingsPage(string $method): void {
    requirePermission('admin.manage'); header('Cache-Control: no-store');
    if ($method==='POST') {
        verifyCsrf(); $name=postedText('site_name',160); $intro=postedText('introduction',10000); $services=postedText('services',10000); $contact=postedText('contact_text',5000);
        workflowChange(function($database) use($name,$intro,$services,$contact) {
            $actor=(int)$_SESSION['user_id'];
            $stmt=$database->prepare('UPDATE public_site_settings SET site_name=?,introduction=?,services=?,contact_text=?,updated_by=? WHERE id=1');
            $stmt->bind_param('ssssi',$name,$intro,$services,$contact,$actor); $stmt->execute(); workflowAudit('public_site_updated','public_site',1);
        },'/admin/public-site');
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $settings=db()->query('SELECT * FROM public_site_settings WHERE id=1')->fetch_assoc();
    $html='<p>Only publish public contact details and service information. Do not include resident or private staff information.</p>'.workflowForm();
    foreach(['site_name'=>'Shelter name','introduction'=>'Introduction','services'=>'Services','contact_text'=>'Public contact information'] as $key=>$label) $html.='<label>'.escape($label).'<textarea name="'.$key.'" maxlength="'.($key==='site_name'?160:($key==='contact_text'?5000:10000)).'" required>'.escape($settings[$key]).'</textarea></label>';
    view('Public website settings',$html.'<button>Save public information</button></form><p><a href="/welcome">View public site</a></p>');
}
function reportsPage(string $method): void {
    requireAuth(); header('Cache-Control: no-store');
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $html='<p>Current totals are limited to facilities and modules you can access. Financial and meal totals cover the last 30 calendar days. No case-note or incident descriptions appear in these reports.</p>';
    $allowed=false;
    if (canManageResidents()) {
        $allowed=true; $html.='<h2>Current occupancy</h2><table><tr><th>Facility</th><th>Active beds</th><th>Occupied beds</th><th>Available beds</th></tr>';
        $rows=db()->query('SELECT f.name,COUNT(b.id) AS beds,SUM(s.id IS NOT NULL) AS occupied FROM facilities f LEFT JOIN rooms rm ON rm.facility_id=f.id AND rm.active=1 LEFT JOIN beds b ON b.room_id=rm.id AND b.active=1 LEFT JOIN stays s ON s.bed_id=b.id AND s.checked_out_at IS NULL WHERE f.active=1 AND '.residentFacilityScope('f.id').' GROUP BY f.id,f.name ORDER BY f.name');
        foreach($rows as $row) $html.='<tr><td>'.escape($row['name']).'</td><td>'.(int)$row['beds'].'</td><td>'.(int)$row['occupied'].'</td><td>'.max(0,(int)$row['beds']-(int)$row['occupied']).'</td></tr>';
        $html.='</table>';
    }
    if (canUseModule('finance')) {
        $allowed=true; $html.='<p><a href="/reports/donations.csv">Export donations CSV</a> · <a href="/reports/expenses.csv">Export expenses CSV</a></p><h2>Cash donations and expenses</h2><table><tr><th>Facility</th><th>Cash received</th><th>Expenses</th><th>Net</th></tr>';
        $rows=db()->query("SELECT f.name,(SELECT COALESCE(SUM(d.amount),0) FROM donations d WHERE d.facility_id=f.id AND d.kind='cash' AND d.received_at>=CURRENT_DATE-INTERVAL 29 DAY AND d.received_at<CURRENT_DATE+INTERVAL 1 DAY) AS received,(SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE e.facility_id=f.id AND e.spent_at>=CURRENT_DATE-INTERVAL 29 DAY AND e.spent_at<CURRENT_DATE+INTERVAL 1 DAY) AS spent FROM facilities f WHERE f.active=1 AND ".moduleFacilityScope('finance')." ORDER BY f.name");
        foreach($rows as $row) $html.='<tr><td>'.escape($row['name']).'</td><td>'.escape($row['received']).'</td><td>'.escape($row['spent']).'</td><td>'.escape(number_format((float)$row['received']-(float)$row['spent'],2,'.','')).'</td></tr>';
        $html.='</table>';
    }
    if (canUseModule('kitchen')) {
        $allowed=true; $html.='<h2>Meals served (30 days)</h2><table><tr><th>Facility</th><th>Audience</th><th>Meals</th></tr>';
        $rows=db()->query('SELECT f.name,t.audience,SUM(t.meals_served) AS meals FROM kitchen_meals t JOIN facilities f ON f.id=t.facility_id WHERE t.served_on BETWEEN CURRENT_DATE-INTERVAL 29 DAY AND CURRENT_DATE AND '.moduleFacilityScope('kitchen').' GROUP BY f.id,f.name,t.audience ORDER BY f.name,t.audience');
        foreach($rows as $row) $html.='<tr><td>'.escape($row['name']).'</td><td>'.escape($row['audience']).'</td><td>'.(int)$row['meals'].'</td></tr>';
        $html.='</table>';
    }
    if (canUseModule('volunteers')) {
        $allowed=true; $html.='<h2>Approved volunteer hours (30 days)</h2><table><tr><th>Facility</th><th>Hours</th></tr>';
        $rows=db()->query("SELECT f.name,SUM(TIMESTAMPDIFF(MINUTE,s.starts_at,s.ends_at))/60 AS hours FROM volunteer_shifts s JOIN volunteers v ON v.id=s.volunteer_id JOIN facilities f ON f.id=v.facility_id JOIN volunteer_shift_details d ON d.shift_id=s.id AND d.status='completed' WHERE s.starts_at>=CURRENT_DATE-INTERVAL 29 DAY AND s.ends_at<CURRENT_DATE+INTERVAL 1 DAY AND ".moduleFacilityScope('volunteers')." GROUP BY f.id,f.name ORDER BY f.name");
        foreach($rows as $row) $html.='<tr><td>'.escape($row['name']).'</td><td>'.escape(number_format((float)$row['hours'],2)).'</td></tr>';
        $html.='</table>';
    }
    if (!$allowed) { http_response_code(403); exit('Forbidden'); }
    view('Reports',$html);
}
function auditPage(string $method): void {
    requirePermission('admin.manage'); header('Cache-Control: no-store');
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $html='<p>Recent administrative activity. Events contain internal IDs rather than resident notes or credentials.</p><table><tr><th>Time</th><th>Actor</th><th>Event</th><th>Record</th></tr>';
    foreach(db()->query('SELECT occurred_at,actor_user_id,event_name,entity_type,entity_id FROM audit_events ORDER BY id DESC LIMIT 200') as $row) $html.='<tr><td>'.escape($row['occurred_at']).'</td><td>#'.(int)$row['actor_user_id'].'</td><td>'.escape($row['event_name']).'</td><td>'.escape(($row['entity_type']??'').' #'.($row['entity_id']??'')).'</td></tr>';
    view('Audit trail',$html.'</table>');
}
function staffDashboard(): void {
    requireAuth(); header('Cache-Control: no-store');
    $html='<p>Welcome to Shelter Management.</p><div class="dashboard">';
    $groups=[];
    if (canManageResidents()) $groups['Residents']=['/admin/residents'=>'Resident intake and records','/admin/occupancy'=>'Check-in and check-out','/reports'=>'Occupancy reports'];
    if (canManageIncidents()) $groups['Safety']=['/incidents'=>'Incident reports'];
    if (canUseModule('casework')) $groups['Case management']=['/cases'=>'Private notes and appointments'];
    if (canUseModule('operations')) $groups['Daily operations']=['/admin/chores'=>'Chores','/admin/schedules'=>'Facility schedules','/admin/programs'=>'Programs and meetings','/admin/daytime-exceptions'=>'Daytime access','/admin/daytime-food'=>'Daytime food'];
    if (canUseModule('kitchen')) $groups['Kitchen']=['/inventory'=>'Stock and movements','/admin/platform/kitchen-meals'=>'Meals','/admin/platform/kitchen-temperatures'=>'Temperature logs','/admin/platform/kitchen-waste'=>'Waste','/reports'=>'Meal reports'];
    if (canUseModule('finance')) $groups['Donations and finances']=['/admin/platform/donations'=>'Donations','/admin/platform/expenses'=>'Expenses','/reports'=>'Financial reports'];
    if (canUseModule('volunteers')) $groups['Volunteers']=['/admin/platform/volunteers'=>'Volunteer records','/volunteer-shifts'=>'Shifts and approved hours','/reports'=>'Volunteer reports'];
    if (can('admin.manage')) $groups['Administration']=['/admin/employees'=>'Employees and access','/admin/platform/facilities'=>'Facilities','/admin/platform/rooms'=>'Rooms','/admin/platform/beds'=>'Beds','/admin/email'=>'Email settings','/admin/public-site'=>'Public website','/admin/audit'=>'Audit trail'];
    foreach($groups as $title=>$links) { $html.='<section><h2>'.escape($title).'</h2><ul>'; foreach($links as $url=>$label) $html.='<li><a href="'.$url.'">'.escape($label).'</a></li>'; $html.='</ul></section>'; }
    if (!$groups) $html.='<p>No facility access has been assigned. Contact your administrator.</p>';
    view('Staff dashboard',$html.'</div>');
}
