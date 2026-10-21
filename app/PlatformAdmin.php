<?php
declare(strict_types=1);

/** Administration-only CRUD for nonclinical facility resources. */
function platformResources(): array {
    return [
        'facilities'=>['table'=>'facilities','fields'=>['name'=>'text','timezone'=>'text','address'=>'text']],
        'rooms'=>['table'=>'rooms','fields'=>['facility_id'=>'number','name'=>'text']],
        'beds'=>['table'=>'beds','fields'=>['room_id'=>'number','code'=>'text','bed_type'=>'select:single,bunk','position'=>'select:single,lower,upper']],
        'volunteers'=>['table'=>'volunteers','fields'=>['facility_id'=>'number','display_name'=>'text']],
        'donations'=>['table'=>'donations','fields'=>['facility_id'=>'number','donor_label'=>'text','kind'=>'select:cash,in_kind','amount'=>'decimal','description'=>'text','received_at'=>'datetime-local']],
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
            if ($value==='' || strlen($value)>255) { http_response_code(422); exit('Missing or invalid field'); }
            if ($type==='number' && (!ctype_digit($value) || (int)$value<1)) { http_response_code(422); exit('Invalid reference'); }
            if ($type==='decimal' && (!is_numeric($value) || (float)$value<0)) { http_response_code(422); exit('Invalid amount'); }
            if (str_starts_with($type,'select:') && !in_array($value,explode(',',substr($type,7)),true)) { http_response_code(422); exit('Invalid choice'); }
            if ($type==='datetime-local') {
                $date=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$value);
                if (!$date || $date->format('Y-m-d\\TH:i')!==$value) { http_response_code(422); exit('Invalid date'); }
                $value=$date->format('Y-m-d H:i:s');
            }
            $values[]=$value;
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
    foreach (array_keys($fields) as $field) $html.='<th>'.escape(ucwords(str_replace('_',' ',$field))).'</th>';
    $html.='</tr></thead><tbody>';
    $rows=db()->query("SELECT * FROM `$table` ORDER BY id DESC LIMIT 100");
    while ($row=$rows->fetch_assoc()) {
        $html.='<tr><td>'.(int)$row['id'].'</td>';
        foreach (array_keys($fields) as $field) $html.='<td>'.escape((string)($row[$field]??'')).'</td>';
        $html.='</tr>';
    }
    $html.='</tbody></table><h2>Add '.escape(ucfirst($resource)).'</h2><form method="post"><input type="hidden" name="_csrf" value="'.csrf().'">';
    foreach ($fields as $field=>$type) {
        $label=escape(ucwords(str_replace('_',' ',$field)));
        if (str_starts_with($type,'select:')) {
            $html.='<label>'.$label.'<select name="'.escape($field).'">';
            foreach (explode(',',substr($type,7)) as $choice) $html.='<option value="'.escape($choice).'">'.escape($choice).'</option>';
            $html.='</select></label>';
        } elseif (in_array($field,['facility_id','room_id'],true)) {
            $relatedTable=$field==='facility_id'?'facilities':'rooms';
            $relatedLabel=$field==='facility_id'?'name':'name';
            $relatedRows=db()->query("SELECT id, name FROM `$relatedTable` WHERE active=1 ORDER BY name,id");
            $html.='<label>'.$label.'<select name="'.escape($field).'" required><option value="">Select '.escape($relatedTable).'</option>';
            while ($related=$relatedRows->fetch_assoc()) $html.='<option value="'.(int)$related['id'].'">'.escape($related['name']).' (#'.(int)$related['id'].')</option>';
            $html.='</select></label>';
        } else {
            $inputType=$type==='decimal'?'number':$type;
            $step=$type==='decimal'?' step="0.01" min="0"':($type==='number'?' min="1"':'');
            $html.='<label>'.$label.'<input type="'.$inputType.'" name="'.escape($field).'" maxlength="255"'.$step.' required></label>';
        }
    }
    view(ucfirst($resource),$html.'<button>Add record</button></form>');
}
