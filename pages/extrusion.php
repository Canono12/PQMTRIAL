<?php
$page_title = 'Extrusion';
$base_path  = '../';
require_once __DIR__ . '/../includes/db.php';

// Auto-create table
$conn->query("CREATE TABLE IF NOT EXISTS `extrusion` (
    `Id` int(6) AUTO_INCREMENT PRIMARY KEY,
    `Date` varchar(20) DEFAULT NULL,
    `Shift` varchar(5) DEFAULT NULL,
    `Machine_No` varchar(20) DEFAULT NULL,
    `Line` varchar(10) DEFAULT NULL,
    `Output_Weight_kg` decimal(10,3) DEFAULT NULL,
    `Waste_kg` decimal(10,3) DEFAULT NULL,
    `Tape_Denier` varchar(20) DEFAULT NULL,
    `Tape_Width_mm` decimal(6,2) DEFAULT NULL,
    `Batch_Code` varchar(30) DEFAULT NULL,
    `Remarks` varchar(200) DEFAULT NULL,
    `Encoded_By` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Filters
$f_date    = $_GET['date']    ?? '';
$f_machine = $_GET['machine'] ?? '';
$f_shift   = $_GET['shift']   ?? '';

$where = ['1=1']; $params = []; $types = '';
if ($f_date)    { $where[] = '`Date` = ?';       $params[] = $f_date;    $types .= 's'; }
if ($f_machine) { $where[] = '`Machine_No` = ?'; $params[] = $f_machine; $types .= 's'; }
if ($f_shift)   { $where[] = '`Shift` = ?';      $params[] = $f_shift;   $types .= 's'; }
$wsql = implode(' AND ', $where);

function exqry($conn,$sql,$t='',$p=[]){
    $s=$conn->prepare($sql);
    if(!$s)die('<pre style="color:red">'.htmlspecialchars($conn->error).'</pre>');
    if($t&&$p)$s->bind_param($t,...$p);
    $s->execute();return $s->get_result();
}

$kpi = exqry($conn,"SELECT COUNT(*) AS recs,
        SUM(Output_Weight_kg) AS tot_output,
        SUM(Waste_kg) AS tot_waste,
        COUNT(DISTINCT Machine_No) AS machines,
        COUNT(DISTINCT Shift) AS shifts
    FROM extrusion WHERE $wsql",$types,$params)->fetch_assoc();

$has_data = ($kpi['recs']??0) > 0;

$c_machine_labels=$c_machine_output=$c_machine_waste=$c_shift_labels=$c_shift_output='[]';
if ($has_data) {
    $r=exqry($conn,"SELECT Machine_No k,SUM(Output_Weight_kg) v1,SUM(Waste_kg) v2
        FROM extrusion WHERE $wsql GROUP BY Machine_No ORDER BY v1 DESC LIMIT 15",$types,$params);
    $ml=$mo=$mw=[];
    while($row=$r->fetch_assoc()){$ml[]=$row['k'];$mo[]=(float)$row['v1'];$mw[]=(float)$row['v2'];}
    $c_machine_labels=json_encode($ml);$c_machine_output=json_encode($mo);$c_machine_waste=json_encode($mw);

    $r2=exqry($conn,"SELECT Shift k,SUM(Output_Weight_kg) v1
        FROM extrusion WHERE $wsql GROUP BY Shift ORDER BY Shift",$types,$params);
    $sl=$so=[];
    while($row=$r2->fetch_assoc()){$sl[]='Shift '.$row['k'];$so[]=(float)$row['v1'];}
    $c_shift_labels=json_encode($sl);$c_shift_output=json_encode($so);
}

$opt_machines=$conn->query("SELECT DISTINCT Machine_No v FROM extrusion ORDER BY Machine_No");
$opt_shifts  =$conn->query("SELECT DISTINCT Shift v FROM extrusion ORDER BY Shift");
$rows=exqry($conn,"SELECT * FROM extrusion WHERE $wsql ORDER BY Id DESC LIMIT 500",$types,$params);

// Upload modal vars
$upload_module='extrusion';$upload_label='Extrusion';
$upload_sample='Id | Date | Shift | Machine_No | Line | Output_Weight_kg | Waste_kg | Tape_Denier | Tape_Width_mm | Batch_Code | Remarks | Encoded_By';

require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/navbar.php';
?>
<script>window._pqmBasePath='<?=$base_path?>';</script>
<div class="app-layout">
<?php require_once __DIR__.'/../includes/sidebar.php';?>
<div class="main-content">

  <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
      <div class="page-heading"><i class="bi bi-fire me-2" style="color:#3b82f6"></i>Extrusion Module</div>
      <div class="page-subheading mt-1">Output &amp; waste analytics for the Extrusion process</div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="badge process-badge" style="background:#1e3a5f;border:1px solid #3b82f6;color:#93c5fd"><i class="bi bi-database me-1"></i>extrusion</span>
      <a href="#" class="pqm-upload-trigger-btn" data-bs-toggle="modal" data-bs-target="#uploadModal_extrusion">
        <i class="bi bi-file-earmark-excel"></i> Add Data (Excel)
      </a>
    </div>
  </div>

  <!-- Filters -->
  <form method="GET" class="pqm-card mb-4">
    <div class="row g-2 align-items-end">
      <div class="col-sm-3"><label class="filter-label">Date</label>
        <input type="date" name="date" value="<?=htmlspecialchars($f_date)?>" class="form-control form-control-sm pqm-input"></div>
      <div class="col-sm-3"><label class="filter-label">Machine</label>
        <select name="machine" class="form-select form-select-sm pqm-input">
          <option value="">All Machines</option>
          <?php while($o=$opt_machines->fetch_assoc()):?>
          <option value="<?=htmlspecialchars($o['v'])?>" <?=$f_machine===$o['v']?'selected':''?>><?=htmlspecialchars($o['v'])?></option>
          <?php endwhile;?>
        </select></div>
      <div class="col-sm-2"><label class="filter-label">Shift</label>
        <select name="shift" class="form-select form-select-sm pqm-input">
          <option value="">All Shifts</option>
          <?php while($o=$opt_shifts->fetch_assoc()):?>
          <option value="<?=htmlspecialchars($o['v'])?>" <?=$f_shift===$o['v']?'selected':''?>><?=htmlspecialchars($o['v'])?></option>
          <?php endwhile;?>
        </select></div>
      <div class="col-sm-auto">
        <button type="submit" class="btn btn-sm pqm-btn-primary">Apply</button>
        <a href="extrusion.php" class="btn btn-sm pqm-btn-ghost ms-1">Reset</a>
      </div>
    </div>
  </form>

  <?php if (!$has_data): ?>
  <div class="pqm-card">
    <div class="d-flex flex-column align-items-center justify-content-center py-5"
         style="border:1px dashed rgba(59,130,246,.3);border-radius:10px;background:rgba(30,58,95,.12);">
      <i class="bi bi-fire" style="font-size:3rem;color:#3b82f6;opacity:.4"></i>
      <div class="mt-3 fw-semibold" style="color:#94a3b8;font-size:1rem">No Extrusion Data Yet</div>
      <div class="mt-1" style="color:#64748b;font-size:.85rem">Upload an Excel file to populate charts and records.</div>
      <a href="#" class="pqm-upload-trigger-btn mt-3" data-bs-toggle="modal" data-bs-target="#uploadModal_extrusion">
        <i class="bi bi-file-earmark-excel"></i> Upload Excel File
      </a>
    </div>
  </div>
  <?php else: ?>

  <!-- KPI Cards -->
  <div class="row g-3 mb-4">
    <?php $kpis=[
      ['Records',number_format($kpi['recs']),'bi-list-ol','#3b82f6'],
      ['Total Output (kg)',number_format((float)$kpi['tot_output'],1),'bi-graph-up-arrow','#22c55e'],
      ['Total Waste (kg)',number_format((float)$kpi['tot_waste'],1),'bi-exclamation-triangle','#f59e0b'],
      ['Machines',$kpi['machines'],'bi-cpu','#a78bfa'],
      ['Shifts',$kpi['shifts'],'bi-clock','#38bdf8'],
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
      <div class="chart-title mb-3">Output &amp; Waste by Machine</div>
      <canvas id="chartMachine" height="90"></canvas>
    </div></div>
    <div class="col-lg-4"><div class="pqm-card h-100">
      <div class="chart-title mb-3">Output by Shift</div>
      <canvas id="chartShift" height="200"></canvas>
    </div></div>
  </div>

  <!-- Table -->
  <div class="pqm-card">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <div class="chart-title mb-0">Extrusion Records
        <span class="badge ms-2" style="background:rgba(59,130,246,.15);color:#93c5fd;font-size:.75rem;"><?=number_format($kpi['recs'])?> rows</span>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table pqm-table">
        <thead><tr>
          <th>ID</th><th>Date</th><th>Shift</th><th>Machine</th><th>Line</th>
          <th>Output (kg)</th><th>Waste (kg)</th><th>Denier</th><th>Width (mm)</th>
          <th>Batch Code</th><th>Encoded By</th><th>Remarks</th>
        </tr></thead>
        <tbody>
        <?php while($r=$rows->fetch_assoc()):?>
        <tr>
          <td><?=htmlspecialchars($r['Id'])?></td><td><?=htmlspecialchars($r['Date'])?></td>
          <td><?=htmlspecialchars($r['Shift'])?></td><td><?=htmlspecialchars($r['Machine_No'])?></td>
          <td><?=htmlspecialchars($r['Line'])?></td><td><?=htmlspecialchars($r['Output_Weight_kg'])?></td>
          <td><?=htmlspecialchars($r['Waste_kg'])?></td><td><?=htmlspecialchars($r['Tape_Denier'])?></td>
          <td><?=htmlspecialchars($r['Tape_Width_mm'])?></td><td><?=htmlspecialchars($r['Batch_Code'])?></td>
          <td><?=htmlspecialchars($r['Encoded_By'])?></td><td><?=htmlspecialchars($r['Remarks'])?></td>
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
new Chart(document.getElementById('chartMachine'),{type:'bar',data:{labels:<?=$c_machine_labels?>,datasets:[
  {label:'Output (kg)',data:<?=$c_machine_output?>,backgroundColor:'rgba(59,130,246,.7)',borderRadius:4},
  {label:'Waste (kg)', data:<?=$c_machine_waste?>, backgroundColor:'rgba(245,158,11,.6)',borderRadius:4}
]},options:{responsive:true,plugins:{legend:{labels:{color:CD.color}}},scales:{x:{ticks:{color:CD.color},grid:{color:CD.grid}},y:{ticks:{color:CD.color},grid:{color:CD.grid}}}}});
new Chart(document.getElementById('chartShift'),{type:'doughnut',data:{labels:<?=$c_shift_labels?>,datasets:[{data:<?=$c_shift_output?>,backgroundColor:['rgba(59,130,246,.8)','rgba(34,197,94,.8)','rgba(245,158,11,.8)','rgba(167,139,250,.8)'],borderWidth:0}]},options:{responsive:true,plugins:{legend:{labels:{color:CD.color}}}}});
</script>
<?php endif;?>
<?php require_once __DIR__.'/../includes/footer.php';?>
