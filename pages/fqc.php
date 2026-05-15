<?php
$page_title = 'FQC';
$base_path  = '../';
require_once __DIR__ . '/../includes/db.php';

$conn->query("CREATE TABLE IF NOT EXISTS `fqc` (
    `Id` int(6) AUTO_INCREMENT PRIMARY KEY,
    `Date` varchar(20) DEFAULT NULL,
    `Shift` varchar(5) DEFAULT NULL,
    `Machine_No` varchar(20) DEFAULT NULL,
    `Batch_Code` varchar(30) DEFAULT NULL,
    `Job_Order` varchar(20) DEFAULT NULL,
    `Product_Type` varchar(50) DEFAULT NULL,
    `Inspected_Qty` int(8) DEFAULT NULL,
    `Passed_Qty` int(8) DEFAULT NULL,
    `Failed_Qty` int(8) DEFAULT NULL,
    `Defect_Type` varchar(100) DEFAULT NULL,
    `Inspector` varchar(50) DEFAULT NULL,
    `Remarks` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$f_month   = $_GET['month']   ?? '';
$f_machine = $_GET['machine'] ?? '';
$f_shift   = $_GET['shift']   ?? '';
$f_product = $_GET['product'] ?? '';

$where=['1=1'];$params=[];$types='';
if($f_month)  {$where[]='`Date` LIKE ?';      $params[]=$f_month.'%'; $types.='s';}
if($f_machine){$where[]='`Machine_No`=?';     $params[]=$f_machine;   $types.='s';}
if($f_shift)  {$where[]='`Shift`=?';          $params[]=$f_shift;     $types.='s';}
if($f_product){$where[]='`Product_Type`=?';   $params[]=$f_product;   $types.='s';}
$wsql=implode(' AND ',$where);

function fqcqry($conn,$sql,$t='',$p=[]){
    $s=$conn->prepare($sql);
    if(!$s)die('<pre style="color:red">'.htmlspecialchars($conn->error).'</pre>');
    if($t&&$p)$s->bind_param($t,...$p);
    $s->execute();return $s->get_result();
}

$kpi=fqcqry($conn,"SELECT COUNT(*) recs,
    SUM(Inspected_Qty) tot_inspected,
    SUM(Passed_Qty) tot_passed,
    SUM(Failed_Qty) tot_failed,
    COUNT(DISTINCT Machine_No) machines,
    COUNT(DISTINCT Inspector) inspectors
    FROM fqc WHERE $wsql",$types,$params)->fetch_assoc();

$has_data=($kpi['recs']??0)>0;

$pass_rate = ($kpi['tot_inspected']??0) > 0
    ? round(($kpi['tot_passed']/$kpi['tot_inspected'])*100,1) : 0;

$c_prod_labels=$c_prod_pass=$c_prod_fail='[]';
$c_defect_labels=$c_defect_vals='[]';
if($has_data){
    $r=fqcqry($conn,"SELECT Product_Type k,SUM(Passed_Qty) v1,SUM(Failed_Qty) v2
        FROM fqc WHERE $wsql GROUP BY Product_Type ORDER BY v1 DESC LIMIT 10",$types,$params);
    $pl=$pp=$pf=[];
    while($row=$r->fetch_assoc()){$pl[]=$row['k'];$pp[]=(int)$row['v1'];$pf[]=(int)$row['v2'];}
    $c_prod_labels=json_encode($pl);$c_prod_pass=json_encode($pp);$c_prod_fail=json_encode($pf);

    $r2=fqcqry($conn,"SELECT Defect_Type k,COUNT(*) v1
        FROM fqc WHERE $wsql AND Defect_Type IS NOT NULL AND Defect_Type!=''
        GROUP BY Defect_Type ORDER BY v1 DESC LIMIT 8",$types,$params);
    $dl=$dv=[];
    while($row=$r2->fetch_assoc()){$dl[]=$row['k'];$dv[]=(int)$row['v1'];}
    $c_defect_labels=json_encode($dl);$c_defect_vals=json_encode($dv);
}

$opt_months_raw = $conn->query("SELECT DISTINCT LEFT(`Date`,7) AS ym FROM fqc WHERE `Date` IS NOT NULL AND `Date` != '' AND `Date` REGEXP '^[0-9]{4}-[0-9]{2}' ORDER BY ym");
$opt_months = [];
while ($om = $opt_months_raw->fetch_assoc()) {
    $ym = $om['ym'];
    if (strlen($ym) === 7) {
        list($y,$m) = explode('-', $ym);
        $opt_months[$ym] = date('F Y', mktime(0,0,0,(int)$m,1,(int)$y));
    }
}

$opt_machines=$conn->query("SELECT DISTINCT Machine_No v FROM fqc ORDER BY Machine_No");
$opt_shifts  =$conn->query("SELECT DISTINCT Shift v FROM fqc ORDER BY Shift");
$opt_products=$conn->query("SELECT DISTINCT Product_Type v FROM fqc ORDER BY Product_Type");
$rows=fqcqry($conn,"SELECT * FROM fqc WHERE $wsql ORDER BY Id DESC LIMIT 500",$types,$params);

$upload_module='fqc';$upload_label='FQC';
$upload_sample='Id | Date | Shift | Machine_No | Batch_Code | Job_Order | Product_Type | Inspected_Qty | Passed_Qty | Failed_Qty | Defect_Type | Inspector | Remarks';

require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/navbar.php';
?>
<script>window._pqmBasePath='<?=$base_path?>';</script>
<div class="app-layout">
<?php require_once __DIR__.'/../includes/sidebar.php';?>
<div class="main-content">

  <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
      <div class="page-heading"><i class="bi bi-clipboard2-check me-2" style="color:#3b82f6"></i>FQC Module</div>
      <div class="page-subheading mt-1">Final Quality Control — inspection &amp; pass/fail analytics</div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="badge process-badge" style="background:#1e3a5f;border:1px solid #3b82f6;color:#93c5fd"><i class="bi bi-database me-1"></i>fqc</span>
      <a href="#" class="pqm-upload-trigger-btn" data-bs-toggle="modal" data-bs-target="#uploadModal_fqc">
        <i class="bi bi-file-earmark-excel"></i> Add Data (Excel)
      </a>
    </div>
  </div>

  <!-- Filters -->
  <form method="GET" class="pqm-card mb-4">
    <div class="row g-2 align-items-end">
      <div class="col-sm-2"><label class="filter-label">Month</label>
        <select name="month" class="form-select form-select-sm pqm-input">
          <option value="">All Months</option>
          <?php foreach ($opt_months as $mv => $ml): ?>
          <option value="<?=htmlspecialchars($mv)?>" <?=$f_month===$mv?'selected':''?>><?=htmlspecialchars($ml)?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-sm-3"><label class="filter-label">Machine</label>
        <select name="machine" class="form-select form-select-sm pqm-input"><option value="">All</option>
          <?php while($o=$opt_machines->fetch_assoc()):?><option value="<?=htmlspecialchars($o['v'])?>" <?=$f_machine===$o['v']?'selected':''?>><?=htmlspecialchars($o['v'])?></option><?php endwhile;?>
        </select></div>
      <div class="col-sm-2"><label class="filter-label">Shift</label>
        <select name="shift" class="form-select form-select-sm pqm-input"><option value="">All</option>
          <?php while($o=$opt_shifts->fetch_assoc()):?><option value="<?=htmlspecialchars($o['v'])?>" <?=$f_shift===$o['v']?'selected':''?>><?=htmlspecialchars($o['v'])?></option><?php endwhile;?>
        </select></div>
      <div class="col-sm-3"><label class="filter-label">Product Type</label>
        <select name="product" class="form-select form-select-sm pqm-input"><option value="">All</option>
          <?php while($o=$opt_products->fetch_assoc()):?><option value="<?=htmlspecialchars($o['v'])?>" <?=$f_product===$o['v']?'selected':''?>><?=htmlspecialchars($o['v'])?></option><?php endwhile;?>
        </select></div>
      <div class="col-sm-auto">
        <button type="submit" class="btn btn-sm pqm-btn-primary">Apply</button>
        <a href="fqc.php" class="btn btn-sm pqm-btn-ghost ms-1">Reset</a>
      </div>
    </div>
  </form>

  <?php if(!$has_data):?>
  <div class="pqm-card">
    <div class="d-flex flex-column align-items-center justify-content-center py-5"
         style="border:1px dashed rgba(59,130,246,.3);border-radius:10px;background:rgba(30,58,95,.12);">
      <i class="bi bi-clipboard2-check" style="font-size:3rem;color:#3b82f6;opacity:.4"></i>
      <div class="mt-3 fw-semibold" style="color:#94a3b8;font-size:1rem">No FQC Data Yet</div>
      <div class="mt-1" style="color:#64748b;font-size:.85rem">Upload an Excel file to populate inspection results.</div>
      <a href="#" class="pqm-upload-trigger-btn mt-3" data-bs-toggle="modal" data-bs-target="#uploadModal_fqc">
        <i class="bi bi-file-earmark-excel"></i> Upload Excel File
      </a>
    </div>
  </div>
  <?php else:?>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <?php $kpis=[
      ['Records',number_format($kpi['recs']),'bi-list-ol','#3b82f6'],
      ['Inspected',number_format($kpi['tot_inspected']),'bi-search','#94a3b8'],
      ['Passed',number_format($kpi['tot_passed']),'bi-check-circle','#22c55e'],
      ['Failed',number_format($kpi['tot_failed']),'bi-x-circle','#ef4444'],
      ['Pass Rate',$pass_rate.'%','bi-percent','#f59e0b'],
      ['Inspectors',$kpi['inspectors'],'bi-person-badge','#a78bfa'],
    ];
    foreach($kpis as [$lbl,$val,$ico,$clr]):?>
    <div class="col-6 col-lg-4 col-xl-2">
      <div class="pqm-card kpi-card h-100">
        <div class="d-flex align-items-start justify-content-between">
          <div><div class="kpi-value"><?=$val?></div><div class="kpi-label"><?=$lbl?></div></div>
          <i class="bi <?=$ico?>" style="font-size:1.4rem;color:<?=$clr?>;opacity:.7"></i>
        </div>
      </div>
    </div>
    <?php endforeach;?>
  </div>

  <!-- Charts -->
  <div class="row g-4 mb-4">
    <div class="col-lg-8"><div class="pqm-card h-100">
      <div class="chart-title mb-3">Pass / Fail by Product Type</div>
      <canvas id="chartProduct" height="90"></canvas>
    </div></div>
    <div class="col-lg-4"><div class="pqm-card h-100">
      <div class="chart-title mb-3">Top Defect Types</div>
      <canvas id="chartDefect" height="200"></canvas>
    </div></div>
  </div>

  <!-- Table -->
  <div class="pqm-card">
    <div class="chart-title mb-3">FQC Records
      <span class="badge ms-2" style="background:rgba(59,130,246,.15);color:#93c5fd;font-size:.75rem;"><?=number_format($kpi['recs'])?> rows</span>
    </div>
    <div class="table-responsive">
      <table class="table pqm-table">
        <thead><tr>
          <th>ID</th><th>Date</th><th>Shift</th><th>Machine</th><th>Batch Code</th>
          <th>Job Order</th><th>Product Type</th><th>Inspected</th><th>Passed</th>
          <th>Failed</th><th>Defect Type</th><th>Inspector</th><th>Remarks</th>
        </tr></thead>
        <tbody>
        <?php while($r=$rows->fetch_assoc()):?>
        <tr>
          <td><?=htmlspecialchars($r['Id'])?></td><td><?=htmlspecialchars($r['Date'])?></td>
          <td><?=htmlspecialchars($r['Shift'])?></td><td><?=htmlspecialchars($r['Machine_No'])?></td>
          <td><?=htmlspecialchars($r['Batch_Code'])?></td><td><?=htmlspecialchars($r['Job_Order'])?></td>
          <td><?=htmlspecialchars($r['Product_Type'])?></td>
          <td><?=number_format((int)$r['Inspected_Qty'])?></td>
          <td style="color:#86efac"><?=number_format((int)$r['Passed_Qty'])?></td>
          <td style="color:#fca5a5"><?=number_format((int)$r['Failed_Qty'])?></td>
          <td><?=htmlspecialchars($r['Defect_Type'])?></td><td><?=htmlspecialchars($r['Inspector'])?></td>
          <td><?=htmlspecialchars($r['Remarks'])?></td>
        </tr>
        <?php endwhile;?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif;?>

</div></div>

<?php require_once __DIR__.'/../includes/upload_modal.php';?>

<?php if($has_data):?>
<script>
const CD={color:'#94a3b8',grid:'rgba(148,163,184,.08)'};
new Chart(document.getElementById('chartProduct'),{type:'bar',data:{labels:<?=$c_prod_labels?>,datasets:[
  {label:'Passed',data:<?=$c_prod_pass?>,backgroundColor:'rgba(34,197,94,.7)',borderRadius:4},
  {label:'Failed',data:<?=$c_prod_fail?>,backgroundColor:'rgba(239,68,68,.6)',borderRadius:4}
]},options:{responsive:true,plugins:{legend:{labels:{color:CD.color}}},scales:{x:{ticks:{color:CD.color},grid:{color:CD.grid}},y:{ticks:{color:CD.color},grid:{color:CD.grid}}}}});
new Chart(document.getElementById('chartDefect'),{type:'bar',indexAxis:'y',data:{labels:<?=$c_defect_labels?>,datasets:[{label:'Count',data:<?=$c_defect_vals?>,backgroundColor:'rgba(239,68,68,.65)',borderRadius:4}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:CD.color},grid:{color:CD.grid}},y:{ticks:{color:CD.color,font:{size:10}},grid:{color:CD.grid}}}}});
</script>
<?php endif;?>
<?php require_once __DIR__.'/../includes/footer.php';?>
