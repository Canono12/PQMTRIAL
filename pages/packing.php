<?php
$page_title = 'Packing';
$base_path  = '../';
require_once __DIR__ . '/../includes/db.php';

$conn->query("CREATE TABLE IF NOT EXISTS `packing` (
    `Id` int(6) AUTO_INCREMENT PRIMARY KEY,
    `Date` varchar(20) DEFAULT NULL,
    `Shift` varchar(5) DEFAULT NULL,
    `Machine_No` varchar(20) DEFAULT NULL,
    `Batch_Code` varchar(30) DEFAULT NULL,
    `Job_Order` varchar(20) DEFAULT NULL,
    `Customer` varchar(50) DEFAULT NULL,
    `Product_Type` varchar(100) DEFAULT NULL,
    `Packed_Qty` int(8) DEFAULT NULL,
    `Packed_Weight_kg` decimal(10,3) DEFAULT NULL,
    `Defective_Qty` int(6) DEFAULT NULL,
    `Dispatch_Date` varchar(20) DEFAULT NULL,
    `Remarks` varchar(200) DEFAULT NULL,
    `Encoded_By` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$f_month    = $_GET['month']    ?? '';
$f_machine  = $_GET['machine']  ?? '';
$f_shift    = $_GET['shift']    ?? '';
$f_customer = $_GET['customer'] ?? '';

$where=['1=1'];$params=[];$types='';
if($f_month)   {$where[]='`Date` LIKE ?';     $params[]=$f_month.'%'; $types.='s';}
if($f_machine) {$where[]='`Machine_No`=?';    $params[]=$f_machine;   $types.='s';}
if($f_shift)   {$where[]='`Shift`=?';         $params[]=$f_shift;     $types.='s';}
if($f_customer){$where[]='`Customer`=?';      $params[]=$f_customer;  $types.='s';}
$wsql=implode(' AND ',$where);

function pkqry($conn,$sql,$t='',$p=[]){
    $s=$conn->prepare($sql);
    if(!$s)die('<pre style="color:red">'.htmlspecialchars($conn->error).'</pre>');
    if($t&&$p)$s->bind_param($t,...$p);
    $s->execute();return $s->get_result();
}

$kpi=pkqry($conn,"SELECT COUNT(*) recs,
    SUM(Packed_Qty) tot_packed,
    SUM(Packed_Weight_kg) tot_weight,
    SUM(Defective_Qty) tot_defective,
    COUNT(DISTINCT Machine_No) machines,
    COUNT(DISTINCT Customer) customers
    FROM packing WHERE $wsql",$types,$params)->fetch_assoc();

$has_data=($kpi['recs']??0)>0;

$c_cust_labels=$c_cust_qty=$c_cust_weight='[]';
$c_prod_labels=$c_prod_qty='[]';
if($has_data){
    $r=pkqry($conn,"SELECT Customer k,SUM(Packed_Qty) v1,SUM(Packed_Weight_kg) v2
        FROM packing WHERE $wsql GROUP BY Customer ORDER BY v1 DESC LIMIT 10",$types,$params);
    $cl=$cq=$cw=[];
    while($row=$r->fetch_assoc()){$cl[]=$row['k'];$cq[]=(int)$row['v1'];$cw[]=(float)$row['v2'];}
    $c_cust_labels=json_encode($cl);$c_cust_qty=json_encode($cq);$c_cust_weight=json_encode($cw);

    $r2=pkqry($conn,"SELECT Product_Type k,SUM(Packed_Qty) v1
        FROM packing WHERE $wsql GROUP BY Product_Type ORDER BY v1 DESC LIMIT 8",$types,$params);
    $pl=$pq=[];
    while($row=$r2->fetch_assoc()){$pl[]=substr($row['k'],0,30);$pq[]=(int)$row['v1'];}
    $c_prod_labels=json_encode($pl);$c_prod_qty=json_encode($pq);
}

$opt_months_raw = $conn->query("SELECT DISTINCT LEFT(`Date`,7) AS ym FROM packing WHERE `Date` IS NOT NULL AND `Date` != '' AND `Date` REGEXP '^[0-9]{4}-[0-9]{2}' ORDER BY ym");
$opt_months = [];
while ($om = $opt_months_raw->fetch_assoc()) {
    $ym = $om['ym'];
    if (strlen($ym) === 7) {
        list($y,$m) = explode('-', $ym);
        $opt_months[$ym] = date('F Y', mktime(0,0,0,(int)$m,1,(int)$y));
    }
}

$opt_machines =$conn->query("SELECT DISTINCT Machine_No v FROM packing ORDER BY Machine_No");
$opt_shifts   =$conn->query("SELECT DISTINCT Shift v FROM packing ORDER BY Shift");
$opt_customers=$conn->query("SELECT DISTINCT Customer v FROM packing ORDER BY Customer");
$rows=pkqry($conn,"SELECT * FROM packing WHERE $wsql ORDER BY Id DESC LIMIT 500",$types,$params);

$upload_module='packing';$upload_label='Packing';
$upload_sample='Id | Date | Shift | Machine_No | Batch_Code | Job_Order | Customer | Product_Type | Packed_Qty | Packed_Weight_kg | Defective_Qty | Dispatch_Date | Remarks | Encoded_By';

require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/navbar.php';
?>
<script>window._pqmBasePath='<?=$base_path?>';</script>
<div class="app-layout">
<?php require_once __DIR__.'/../includes/sidebar.php';?>
<div class="main-content">

  <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
      <div class="page-heading"><i class="bi bi-box-seam me-2" style="color:#3b82f6"></i>Packing Module</div>
      <div class="page-subheading mt-1">Packing output &amp; dispatch analytics</div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="badge process-badge" style="background:#1e3a5f;border:1px solid #3b82f6;color:#93c5fd"><i class="bi bi-database me-1"></i>packing</span>
      <a href="#" class="pqm-upload-trigger-btn" data-bs-toggle="modal" data-bs-target="#uploadModal_packing">
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
      <div class="col-sm-3"><label class="filter-label">Customer</label>
        <select name="customer" class="form-select form-select-sm pqm-input"><option value="">All</option>
          <?php while($o=$opt_customers->fetch_assoc()):?><option value="<?=htmlspecialchars($o['v'])?>" <?=$f_customer===$o['v']?'selected':''?>><?=htmlspecialchars($o['v'])?></option><?php endwhile;?>
        </select></div>
      <div class="col-sm-auto">
        <button type="submit" class="btn btn-sm pqm-btn-primary">Apply</button>
        <a href="packing.php" class="btn btn-sm pqm-btn-ghost ms-1">Reset</a>
      </div>
    </div>
  </form>

  <?php if(!$has_data):?>
  <div class="pqm-card">
    <div class="d-flex flex-column align-items-center justify-content-center py-5"
         style="border:1px dashed rgba(59,130,246,.3);border-radius:10px;background:rgba(30,58,95,.12);">
      <i class="bi bi-box-seam" style="font-size:3rem;color:#3b82f6;opacity:.4"></i>
      <div class="mt-3 fw-semibold" style="color:#94a3b8;font-size:1rem">No Packing Data Yet</div>
      <div class="mt-1" style="color:#64748b;font-size:.85rem">Upload an Excel file to populate output counts and dispatch records.</div>
      <a href="#" class="pqm-upload-trigger-btn mt-3" data-bs-toggle="modal" data-bs-target="#uploadModal_packing">
        <i class="bi bi-file-earmark-excel"></i> Upload Excel File
      </a>
    </div>
  </div>
  <?php else:?>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <?php $kpis=[
      ['Records',number_format($kpi['recs']),'bi-list-ol','#3b82f6'],
      ['Total Packed',number_format($kpi['tot_packed']),'bi-boxes','#22c55e'],
      ['Weight (kg)',number_format((float)$kpi['tot_weight'],1),'bi-graph-up-arrow','#38bdf8'],
      ['Defective',number_format($kpi['tot_defective']),'bi-exclamation-circle','#ef4444'],
      ['Machines',$kpi['machines'],'bi-cpu','#a78bfa'],
      ['Customers',$kpi['customers'],'bi-people','#f59e0b'],
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
    <div class="col-lg-7"><div class="pqm-card h-100">
      <div class="chart-title mb-3">Packed Qty &amp; Weight by Customer</div>
      <canvas id="chartCust" height="100"></canvas>
    </div></div>
    <div class="col-lg-5"><div class="pqm-card h-100">
      <div class="chart-title mb-3">Top Products by Packed Qty</div>
      <canvas id="chartProd" height="200"></canvas>
    </div></div>
  </div>

  <!-- Table -->
  <div class="pqm-card">
    <div class="chart-title mb-3">Packing Records
      <span class="badge ms-2" style="background:rgba(59,130,246,.15);color:#93c5fd;font-size:.75rem;"><?=number_format($kpi['recs'])?> rows</span>
    </div>
    <div class="table-responsive">
      <table class="table pqm-table">
        <thead><tr>
          <th>ID</th><th>Date</th><th>Shift</th><th>Machine</th><th>Batch Code</th>
          <th>Job Order</th><th>Customer</th><th>Product</th><th>Packed Qty</th>
          <th>Weight (kg)</th><th>Defective</th><th>Dispatch Date</th><th>Encoded By</th><th>Remarks</th>
        </tr></thead>
        <tbody>
        <?php while($r=$rows->fetch_assoc()):?>
        <tr>
          <td><?=htmlspecialchars($r['Id'])?></td><td><?=htmlspecialchars($r['Date'])?></td>
          <td><?=htmlspecialchars($r['Shift'])?></td><td><?=htmlspecialchars($r['Machine_No'])?></td>
          <td><?=htmlspecialchars($r['Batch_Code'])?></td><td><?=htmlspecialchars($r['Job_Order'])?></td>
          <td><?=htmlspecialchars($r['Customer'])?></td><td><?=htmlspecialchars($r['Product_Type'])?></td>
          <td><?=number_format((int)$r['Packed_Qty'])?></td>
          <td><?=htmlspecialchars($r['Packed_Weight_kg'])?></td>
          <td style="<?=(int)$r['Defective_Qty']>0?'color:#fca5a5':''?>"><?=number_format((int)$r['Defective_Qty'])?></td>
          <td><?=htmlspecialchars($r['Dispatch_Date'])?></td>
          <td><?=htmlspecialchars($r['Encoded_By'])?></td>
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
new Chart(document.getElementById('chartCust'),{type:'bar',data:{labels:<?=$c_cust_labels?>,datasets:[
  {label:'Packed Qty',data:<?=$c_cust_qty?>,backgroundColor:'rgba(59,130,246,.7)',borderRadius:4},
  {label:'Weight (kg)',data:<?=$c_cust_weight?>,backgroundColor:'rgba(34,197,94,.6)',borderRadius:4,yAxisID:'y1'}
]},options:{responsive:true,plugins:{legend:{labels:{color:CD.color}}},scales:{
  x:{ticks:{color:CD.color},grid:{color:CD.grid}},
  y:{ticks:{color:CD.color},grid:{color:CD.grid}},
  y1:{position:'right',ticks:{color:CD.color},grid:{display:false}}
}}});
new Chart(document.getElementById('chartProd'),{type:'bar',indexAxis:'y',data:{labels:<?=$c_prod_labels?>,datasets:[{label:'Packed Qty',data:<?=$c_prod_qty?>,backgroundColor:'rgba(167,139,250,.7)',borderRadius:4}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:CD.color},grid:{color:CD.grid}},y:{ticks:{color:CD.color,font:{size:10}},grid:{color:CD.grid}}}}});
</script>
<?php endif;?>
<?php require_once __DIR__.'/../includes/footer.php';?>
