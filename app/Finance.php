<?php
declare(strict_types=1);
function donationReceipt(string $method,int $id): void {
    requireModule('finance');
    $stmt=db()->prepare('SELECT t.*,f.name AS facility_name,d.reference AS reconciliation_reference,d.reconciled_at FROM donations t JOIN facilities f ON f.id=t.facility_id LEFT JOIN donation_reconciliations d ON d.donation_id=t.id WHERE t.id=? AND '.moduleFacilityScope('finance','t.facility_id'));
    $stmt->bind_param('i',$id); $stmt->execute(); $donation=$stmt->get_result()->fetch_assoc();
    if (!$donation) { http_response_code(404); exit('Donation not found'); }
    if ($method==='POST') {
        verifyCsrf(); $reference=postedText('reference',120);
        workflowChange(function($database) use($id,$reference) {
            $stmt=$database->prepare('SELECT t.id FROM donations t WHERE t.id=? AND '.moduleFacilityScope('finance','t.facility_id').' FOR UPDATE');
            $stmt->bind_param('i',$id); $stmt->execute();
            if (!$stmt->get_result()->fetch_row()) throw new DomainException('Donation not found');
            $stmt=$database->prepare('SELECT donation_id FROM donation_reconciliations WHERE donation_id=?');
            $stmt->bind_param('i',$id); $stmt->execute();
            if ($stmt->get_result()->fetch_row()) throw new DomainException('Donation is already reconciled');
            $actor=(int)$_SESSION['user_id'];
            $stmt=$database->prepare('INSERT INTO donation_reconciliations(donation_id,reference,reconciled_by) VALUES(?,?,?)');
            $stmt->bind_param('isi',$id,$reference,$actor); $stmt->execute(); workflowAudit('donation_reconciled','donation',$id);
        },'/donations/'.$id.'/receipt');
    }
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $html='<p><a href="/admin/platform/donations">Donations</a></p><h2>Donation acknowledgment DON-'.str_pad((string)$id,6,'0',STR_PAD_LEFT).'</h2><dl><dt>Facility</dt><dd>'.escape($donation['facility_name']).'</dd><dt>Received from</dt><dd>'.escape($donation['donor_label']).'</dd><dt>Date</dt><dd>'.escape($donation['received_at']).'</dd><dt>Type</dt><dd>'.escape($donation['kind']).'</dd><dt>Amount</dt><dd>'.escape($donation['amount']??'Not applicable').'</dd><dt>Description</dt><dd>'.escape($donation['description']??'').'</dd></dl><p>This acknowledges receipt. Tax treatment depends on your organization and jurisdiction.</p>';
    if ($donation['reconciliation_reference']!==null) $html.='<p>Reconciled: '.escape($donation['reconciliation_reference'].' · '.$donation['reconciled_at']).'</p>';
    else $html.='<h2>Reconcile donation</h2>'.workflowForm().workflowField('Internal reconciliation reference','reference','text',120).'<button>Mark reconciled</button></form>';
    view('Donation acknowledgment',$html);
}
function financeExport(string $method,string $kind): void {
    requireModule('finance');
    if ($method!=='GET') { http_response_code(405); exit('Method not allowed'); }
    $configs=['donations'=>['id','donor_label','kind','amount','description','received_at'],'expenses'=>['id','description','amount','spent_at']];
    if (!isset($configs[$kind])) { http_response_code(404); exit('Not found'); }
    $columns=$configs[$kind];
    $rows=db()->query('SELECT '.implode(',',array_map(fn($c)=>'t.'.$c,$columns)).' FROM '.$kind.' t WHERE '.moduleFacilityScope('finance','t.facility_id').' ORDER BY t.id DESC LIMIT 5000');
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="'.$kind.'.csv"'); header('X-Content-Type-Options: nosniff');
    $file=fopen('php://output','w');
    fputcsv($file,$columns,',','"','');
    foreach($rows as $row) {
        $safe=[];
        foreach($row as $value) {
            $value=(string)($value??'');
            if (preg_match('/^[\\s]*[=+@-]/u',$value) || preg_match('/^[\\t\\r\\n]/',$value)) $value="'".$value;
            $safe[]=$value;
        }
        fputcsv($file,$safe,',','"','');
    }
    fclose($file);
}
