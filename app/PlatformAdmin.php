<?php
declare(strict_types=1);

/** Administration-only CRUD for nonclinical facility resources. */
function platformResources(): array {
    return [
        'facilities'=>['table'=>'facilities','fields'=>['name'=>'text','timezone'=>'timezone','address'=>'text']],
        'rooms'=>['table'=>'rooms','fields'=>['facility_id'=>'number','name'=>'text']],
        'beds'=>['table'=>'beds','fields'=>['room_id'=>'number','code'=>'text','bed_type'=>'select:single,bunk','position'=>'select:single,lower,upper']],
        'volunteers'=>['table'=>'volunteers','fields'=>['facility_id'=>'number','display_name'=>'text']],
        'donations'=>['table'=>'donations','fields'=>['facility_id'=>'number','donor_label'=>'text','kind'=>'select:cash,in_kind','amount'=>'optional-decimal','description'=>'optional-text','received_at'=>'datetime-local','item_category'=>'optional-select:bedding,clothing,toiletries,food,household,misc','quantity'=>'optional-decimal','unit'=>'optional-text','estimated_value'=>'optional-decimal']],
        'kitchen-inventory'=>['table'=>'kitchen_inventory','fields'=>['facility_id'=>'number','item_name'=>'text','quantity'=>'decimal','unit'=>'text','storage_location'=>'text','expires_on'=>'optional-date']],
        'kitchen-meals'=>['table'=>'kitchen_meals','fields'=>['facility_id'=>'number','served_on'=>'date','meal_type'=>'select:breakfast,lunch,dinner,snack','audience'=>'select:resident,staff','description'=>'text','meals_served'=>'number-zero']],
        'kitchen-temperatures'=>['table'=>'kitchen_temperature_logs','fields'=>['facility_id'=>'number','equipment_name'=>'text','temperature_f'=>'signed-decimal','recorded_at'=>'datetime-local']],
        'kitchen-waste'=>['table'=>'kitchen_waste','fields'=>['facility_id'=>'number','item_name'=>'text','quantity'=>'decimal','unit'=>'text','reason'=>'text','recorded_at'=>'datetime-local']],
        'expenses'=>['table'=>'expenses','fields'=>['facility_id'=>'number','description'=>'text','amount'=>'decimal','spent_at'=>'datetime-local']]
    ];
}
function platformAdmin(string $resource,string $method): void {
    $module=str_starts_with($resource,'kitchen-')?'kitchen':(in_array($resource,['donations','expenses'],true)?'finance':($resource==='volunteers'?'volunteers':null));
    if ($module!==null) requireModule($module); else requirePermission('admin.manage');
    header('Cache-Control: no-store');
    $config=platformResources()[$resource]??null;
    if (!$config) { http_response_code(404); return; }
    $table=$config['table']; $fields=$config['fields'];
    if ($method==='POST') {
        verifyCsrf(); $values=[];
        foreach ($fields as $name=>$type) {
            $value=trim((string)($_POST[$name]??''));
            $optional=str_starts_with($type,'optional-');
            $baseType=$optional?substr($type,9):$type;
            if ((!$optional && $value==='') || strlen($value)>255) { http_response_code(422); exit('Missing or invalid field'); }
            if ($baseType==='number' && (!ctype_digit($value) || (int)$value<1)) { http_response_code(422); exit('Invalid reference'); }
            if ($baseType==='number-zero' && (!ctype_digit($value) || (float)$value>2147483647)) { http_response_code(422); exit('Invalid count'); }
            if ($baseType==='signed-decimal' && (!is_numeric($value) || !is_finite((float)$value))) { http_response_code(422); exit('Invalid temperature'); }
            if ($baseType==='timezone' && !in_array($value,DateTimeZone::listIdentifiers(),true)) { http_response_code(422); exit('Invalid timezone'); }
            if ($baseType==='date' && $value!=='' && (!DateTimeImmutable::createFromFormat('!Y-m-d',$value) || DateTimeImmutable::createFromFormat('!Y-m-d',$value)->format('Y-m-d')!==$value)) { http_response_code(422); exit('Invalid date'); }
            if ($baseType==='decimal' && $value!=='' && (!is_numeric($value) || !is_finite((float)$value) || (float)$value<0 || (float)$value>9999999999.99)) { http_response_code(422); exit('Invalid amount'); }
            if (str_starts_with($baseType,'select:') && $value!=='' && !in_array($value,explode(',',substr($baseType,7)),true)) { http_response_code(422); exit('Invalid choice'); }
            if ($type==='datetime-local') {
                $date=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$value);
                if (!$date || $date->format('Y-m-d\\TH:i')!==$value) { http_response_code(422); exit('Invalid date'); }
                $value=$date->format('Y-m-d H:i:s');
            }
            $values[]=$optional && $value===''?null:$value;
        }
        if (isset($fields['facility_id'])) {
            $facility=(int)$values[array_search('facility_id',array_keys($fields),true)];
            if ($module!==null) checkModuleFacility($module,$facility);
            else {
                $active=db()->prepare('SELECT id FROM facilities WHERE id=? AND active=1');
                $active->bind_param('i',$facility); $active->execute();
                if (!$active->get_result()->fetch_row()) { http_response_code(422); exit('Select an active facility'); }
            }
        }
        if ($resource==='donations') {
            $kind=$values[2];
            if ($kind==='cash' && $values[3]===null) { http_response_code(422); exit('Cash donations require an amount.'); }
            if ($kind==='in_kind' && ($values[6]===null || $values[7]===null || (float)$values[7]<=0 || $values[8]===null)) { http_response_code(422); exit('In-kind donations require category, positive quantity and unit.'); }
        }
        if ($resource==='beds') {
            $bedType=$values[2]; $position=$values[3];
            if (($bedType==='single' && $position!=='single') || ($bedType==='bunk' && !in_array($position,['lower','upper'],true))) {
                http_response_code(422); exit('Choose Single position for a single bed, or Lower/Upper for a bunk.');
            }
            $check=db()->prepare('SELECT bed_type FROM beds WHERE room_id=? AND code=? LIMIT 1');
            $check->bind_param('ss',$values[0],$values[1]); $check->execute();
            $existing=$check->get_result()->fetch_assoc();
            if ($existing && ($existing['bed_type']!==$bedType || $bedType==='single')) {
                http_response_code(422); exit('This bed code is already used by a different bed type.');
            }
        }
        if ($resource==='facilities' && isset($_POST['edit_facility_id'])) {
            $id=filter_var($_POST['edit_facility_id'],FILTER_VALIDATE_INT);
            if (!$id || $id<1) { http_response_code(422); exit('Invalid facility'); }
            $stmt=db()->prepare('UPDATE facilities SET name=?,timezone=?,address=? WHERE id=?');
            $stmt->bind_param('sssi',$values[0],$values[1],$values[2],$id);
            auditedExecute($stmt,'facility_updated','facility',$id);
            header('Location: /admin/platform/facilities',true,303); exit;
        }
        $columns=implode(',',array_keys($fields));
        $placeholders=implode(',',array_fill(0,count($values),'?'));
        $stmt=db()->prepare("INSERT INTO `$table` ($columns) VALUES ($placeholders)");
        $types=str_repeat('s',count($values));
        $stmt->bind_param($types,...$values);
        $database=db(); $database->begin_transaction();
        try { $stmt->execute(); $created=$database->insert_id; workflowAudit('resource_created',$resource,(int)$created); $database->commit(); }
        catch (mysqli_sql_exception $e) {
            $database->rollback();
            if (in_array((int)$e->getCode(),[1452,1062],true)) {
                http_response_code(422);
                view('Unable to save','<p>The selected related record does not exist, or this entry already exists. <a href="/admin/platform/'.escape($resource).'">Return to the form</a>.</p>');
                return;
            }
            error_log('Platform resource save failed'); http_response_code(500); exit('Unable to save. No changes were saved.');
        }
        header('Location: /admin/platform/'.$resource,true,303); exit;
    }
    if ($method!=='GET') { http_response_code(405); return; }
    $html='<p><a href="/">Dashboard</a></p><table><thead><tr><th>ID</th>';
    foreach (array_keys($fields) as $field) $html.='<th>'.escape($field==='room_id'?'Room':ucwords(str_replace('_',' ',$field))).'</th>';
    if ($resource==='donations') $html.='<th>Acknowledgment</th>';
    $html.='</tr></thead><tbody>';
    $rows=db()->query($resource==='beds'
        ? 'SELECT b.*,r.name AS room_name,f.name AS facility_name FROM beds b JOIN rooms r ON r.id=b.room_id JOIN facilities f ON f.id=r.facility_id ORDER BY b.id DESC LIMIT 100'
        : ($module!==null ? "SELECT t.* FROM `$table` t WHERE ".moduleFacilityScope($module,'t.facility_id')." ORDER BY t.id DESC LIMIT 100" : "SELECT * FROM `$table` ORDER BY id DESC LIMIT 100"));
    while ($row=$rows->fetch_assoc()) {
        $html.='<tr><td>'.(int)$row['id'].'</td>';
        foreach (array_keys($fields) as $field) {
            $display=$field==='room_id' && $resource==='beds'
                ? $row['facility_name'].' — '.$row['room_name'].' (#'.$row['room_id'].')'
                : (string)($row[$field]??'');
            $html.='<td>'.escape((string)$display).'</td>';
        }
        if ($resource==='donations') $html.='<td><a href="/donations/'.(int)$row['id'].'/receipt">Acknowledgment and reconciliation</a></td>';
        $html.='</tr>';
    }
    $html.='</tbody></table>';
    if ($resource==='facilities') {
        $html.='<h2>Edit facility</h2><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'"><label>Facility<select name="edit_facility_id" id="edit-facility" required>';
        $facilities=db()->query('SELECT id,name,timezone,address FROM facilities ORDER BY name,id');
        $facilityOptions=[];
        while ($f=$facilities->fetch_assoc()) {
            $facilityOptions[]=$f;
            $html.='<option value="'.(int)$f['id'].'">'.escape($f['name']).' (#'.(int)$f['id'].')</option>';
        }
        $html.='</select></label><label>Name<input name="name" id="facility-name" maxlength="160" required></label><label>Timezone<select name="timezone" id="facility-timezone" required>';
        foreach (DateTimeZone::listIdentifiers() as $zone) $html.='<option value="'.escape($zone).'">'.escape($zone).'</option>';
        $html.='</select></label><label>Address<input name="address" id="facility-address" maxlength="255" required></label><button>Save changes</button></form>';
        $html.='<script>const facilityData='.json_encode($facilityOptions,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';const facilitySelect=document.getElementById("edit-facility");function loadFacility(){const f=facilityData.find(x=>String(x.id)===facilitySelect.value);if(!f)return;document.getElementById("facility-name").value=f.name;document.getElementById("facility-timezone").value=f.timezone;document.getElementById("facility-address").value=f.address;}facilitySelect.addEventListener("change",loadFacility);loadFacility();</script>';
    }
    $html.='<h2>Add '.escape(ucfirst($resource)).'</h2><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'">';
    foreach ($fields as $field=>$type) {
        $label=escape($field==='room_id'?'Room':ucwords(str_replace('_',' ',$field)));
        $optional=str_starts_with($type,'optional-');
        $baseType=$optional?substr($type,9):$type;
        $required=$optional?'':' required';
        if ($baseType==='timezone') {
            $html.='<label>'.$label.'<select name="'.escape($field).'" required>';
            foreach (DateTimeZone::listIdentifiers() as $zone) $html.='<option value="'.escape($zone).'"'.($zone==='America/Detroit'?' selected':'').'>'.escape($zone).'</option>';
            $html.='</select></label>';
        } elseif (str_starts_with($baseType,'select:')) {
            $html.='<label>'.$label.'<select name="'.escape($field).'"'.$required.'>';
            if ($optional) $html.='<option value="">Not applicable</option>';
            foreach (explode(',',substr($baseType,7)) as $choice) $html.='<option value="'.escape($choice).'">'.escape($choice).'</option>';
            $html.='</select></label>';
        } elseif (in_array($field,['facility_id','room_id'],true)) {
            $relatedTable=$field==='facility_id'?'facilities':'rooms';
            $relatedRows=db()->query($field==='room_id'
                ? 'SELECT r.id, r.name, f.name AS facility_name FROM rooms r JOIN facilities f ON f.id=r.facility_id WHERE r.active=1 AND f.active=1 ORDER BY f.name,r.name,r.id'
                : ($module!==null ? 'SELECT f.id,f.name FROM facilities f WHERE f.active=1 AND '.moduleFacilityScope($module).' ORDER BY f.name,f.id' : 'SELECT id,name FROM facilities WHERE active=1 ORDER BY name,id'));
            $html.='<label>'.$label.'<select name="'.escape($field).'" required><option value="">Select '.escape($relatedTable).'</option>';
            while ($related=$relatedRows->fetch_assoc()) {
                $display=isset($related['facility_name'])?$related['facility_name'].' — '.$related['name']:$related['name'];
                $html.='<option value="'.(int)$related['id'].'">'.escape($display).' (#'.(int)$related['id'].')</option>';
            }
            $html.='</select></label>';
        } else {
            $inputType=in_array($baseType,['decimal','signed-decimal','number-zero'],true)?'number':$baseType;
            $step=$baseType==='decimal'?' step="0.01" min="0"':($baseType==='signed-decimal'?' step="0.01"':($baseType==='number-zero'?' min="0" step="1"':($baseType==='number'?' min="1"':'')));
            $html.='<label>'.$label.'<input type="'.$inputType.'" name="'.escape($field).'" maxlength="255"'.$step.$required.'></label>';
        }
    }
    view(ucfirst($resource),$html.'<button>Add record</button></form>');
}
