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
        'kitchen-meals'=>['table'=>'kitchen_meals','fields'=>['facility_id'=>'number','served_on'=>'date','meal_type'=>'select:breakfast,lunch,dinner,snack','description'=>'text','meals_served'=>'number-zero']],
        'kitchen-temperatures'=>['table'=>'kitchen_temperature_logs','fields'=>['facility_id'=>'number','equipment_name'=>'text','temperature_f'=>'signed-decimal','recorded_at'=>'datetime-local']],
        'kitchen-waste'=>['table'=>'kitchen_waste','fields'=>['facility_id'=>'number','item_name'=>'text','quantity'=>'decimal','unit'=>'text','reason'=>'text','recorded_at'=>'datetime-local']],
        'expenses'=>['table'=>'expenses','fields'=>['facility_id'=>'number','description'=>'text','amount'=>'decimal','spent_at'=>'datetime-local']]
    ];
}
function platformAdmin(string $resource,string $method): void {
    requirePermission('admin.manage');
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
            if ($baseType==='decimal' && $value!=='' && (!is_numeric($value) || (float)$value<0)) { http_response_code(422); exit('Invalid amount'); }
            if (str_starts_with($baseType,'select:') && $value!=='' && !in_array($value,explode(',',substr($baseType,7)),true)) { http_response_code(422); exit('Invalid choice'); }
            if ($type==='datetime-local') {
                $date=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$value);
                if (!$date || $date->format('Y-m-d\\TH:i')!==$value) { http_response_code(422); exit('Invalid date'); }
                $value=$date->format('Y-m-d H:i:s');
            }
            $values[]=$optional && $value===''?null:$value;
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
        $columns=implode(',',array_keys($fields));
        $placeholders=implode(',',array_fill(0,count($values),'?'));
        $stmt=db()->prepare("INSERT INTO `$table` ($columns) VALUES ($placeholders)");
        $types=str_repeat('s',count($values));
        $stmt->bind_param($types,...$values);
        try { $stmt->execute(); }
        catch (mysqli_sql_exception $e) {
            if (in_array((int)$e->getCode(),[1452,1062],true)) {
                http_response_code(422);
                view('Unable to save','<p>The selected related record does not exist, or this entry already exists. <a href="/admin/platform/'.escape($resource).'">Return to the form</a>.</p>');
                return;
            }
            throw $e;
        }
        header('Location: /admin/platform/'.$resource,true,303); exit;
    }
    if ($method!=='GET') { http_response_code(405); return; }
    $html='<p><a href="/">Dashboard</a></p><table><thead><tr><th>ID</th>';
    foreach (array_keys($fields) as $field) $html.='<th>'.escape($field==='room_id'?'Room':ucwords(str_replace('_',' ',$field))).'</th>';
    $html.='</tr></thead><tbody>';
    $rows=db()->query($resource==='beds'
        ? 'SELECT b.*,r.name AS room_name,f.name AS facility_name FROM beds b JOIN rooms r ON r.id=b.room_id JOIN facilities f ON f.id=r.facility_id ORDER BY b.id DESC LIMIT 100'
        : "SELECT * FROM `$table` ORDER BY id DESC LIMIT 100");
    while ($row=$rows->fetch_assoc()) {
        $html.='<tr><td>'.(int)$row['id'].'</td>';
        foreach (array_keys($fields) as $field) {
            $display=$field==='room_id' && $resource==='beds'
                ? $row['facility_name'].' — '.$row['room_name'].' (#'.$row['room_id'].')'
                : (string)($row[$field]??'');
            $html.='<td>'.escape((string)$display).'</td>';
        }
        $html.='</tr>';
    }
    $html.='</tbody></table><h2>Add '.escape(ucfirst($resource)).'</h2><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'">';
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
                : 'SELECT id, name FROM facilities WHERE active=1 ORDER BY name,id');
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
