<?php
/**
 * PQM — Universal Excel Upload Modal
 * Usage: <?php
 *   $upload_module  = 'weaving';          // module key sent to upload_handler.php
 *   $upload_label   = 'Weaving';          // human label
 *   $upload_sample  = 'ID | Date | ...';  // sample headers hint
 *   require __DIR__ . '/../includes/upload_modal.php';
 * ?>
 *
 * The file also enqueues the JS needed for the upload interaction.
 * It must be included BEFORE </body>.
 */

$_um_module = $upload_module  ?? 'unknown';
$_um_label  = $upload_label   ?? 'Module';
$_um_sample = $upload_sample  ?? '';
$_um_id     = 'uploadModal_' . $_um_module;

// Only render upload UI for admins
if (!defined('IS_ADMIN') || !IS_ADMIN) return;
?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     UPLOAD TRIGGER BUTTON  (insert where needed in the page)
     Call: echo pqm_upload_btn($upload_module) — or just include this file
     which already outputs a standard button via upload_trigger_btn below.
     ════════════════════════════════════════════════════════════════════════ -->

<!-- ── Bootstrap Modal ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="<?= $_um_id ?>" tabindex="-1" aria-labelledby="<?= $_um_id ?>Label" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="background:#0f172a;border:1px solid rgba(59,130,246,.35);border-radius:14px;">

      <!-- Header -->
      <div class="modal-header" style="border-bottom:1px solid rgba(59,130,246,.2);padding:1.2rem 1.5rem;">
        <div>
          <h5 class="modal-title fw-bold mb-0" id="<?= $_um_id ?>Label" style="color:#e2e8f0;font-size:1.05rem;">
            <i class="bi bi-file-earmark-excel me-2" style="color:#22c55e"></i>
            Import Excel Data — <?= htmlspecialchars($_um_label) ?>
          </h5>
          <small style="color:#64748b;">Upload an .xlsx, .xls or .csv file to add records</small>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <!-- Body -->
      <div class="modal-body" style="padding:1.5rem;">

        <!-- Drop Zone -->
        <div class="pqm-dropzone" id="dropzone_<?= $_um_module ?>"
             onclick="document.getElementById('fileInput_<?= $_um_module ?>').click()"
             ondragover="event.preventDefault();this.classList.add('dz-hover')"
             ondragleave="this.classList.remove('dz-hover')"
             ondrop="pqmHandleDrop(event,'<?= $_um_module ?>')">
          <i class="bi bi-cloud-upload" style="font-size:2.2rem;color:#3b82f6;display:block;margin-bottom:.5rem;"></i>
          <div style="color:#94a3b8;font-size:.95rem;font-weight:600;">Drag &amp; drop your Excel file here</div>
          <div style="color:#475569;font-size:.8rem;margin-top:.25rem;">or click to browse</div>
          <div style="margin-top:.75rem;">
            <span class="badge" style="background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.3);font-size:.7rem;padding:.3em .7em;">.xlsx</span>
            <span class="badge ms-1" style="background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.3);font-size:.7rem;padding:.3em .7em;">.xls</span>
            <span class="badge ms-1" style="background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.3);font-size:.7rem;padding:.3em .7em;">.csv</span>
          </div>
        </div>

        <!-- Hidden file input -->
        <input type="file" id="fileInput_<?= $_um_module ?>"
               accept=".xlsx,.xls,.csv"
               style="display:none"
               onchange="pqmFileSelected(this,'<?= $_um_module ?>')">

        <!-- Selected file info -->
        <div id="fileInfo_<?= $_um_module ?>" class="mt-3" style="display:none;">
          <div class="d-flex align-items-center gap-2 p-2"
               style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:8px;">
            <i class="bi bi-file-earmark-spreadsheet" style="color:#3b82f6;font-size:1.2rem;"></i>
            <div>
              <div id="fileName_<?= $_um_module ?>" style="color:#e2e8f0;font-size:.85rem;font-weight:600;"></div>
              <div id="fileSize_<?= $_um_module ?>" style="color:#64748b;font-size:.75rem;"></div>
            </div>
            <button class="btn btn-sm ms-auto" style="background:transparent;border:none;color:#ef4444;"
                    onclick="pqmClearFile('<?= $_um_module ?>')">
              <i class="bi bi-x-circle"></i>
            </button>
          </div>
        </div>

        <!-- Progress bar -->
        <div id="progress_<?= $_um_module ?>" class="mt-3" style="display:none;">
          <div class="d-flex justify-content-between mb-1">
            <small style="color:#94a3b8;">Uploading &amp; processing…</small>
            <small id="progressPct_<?= $_um_module ?>" style="color:#3b82f6;">0%</small>
          </div>
          <div class="progress" style="height:6px;background:rgba(59,130,246,.15);border-radius:999px;">
            <div id="progressBar_<?= $_um_module ?>" class="progress-bar"
                 role="progressbar" style="width:0%;background:linear-gradient(90deg,#3b82f6,#60a5fa);border-radius:999px;transition:width .3s;">
            </div>
          </div>
        </div>

        <!-- Result box -->
        <div id="result_<?= $_um_module ?>" class="mt-3" style="display:none;"></div>

        <!-- Sample headers hint -->
        <?php if ($_um_sample): ?>
        <div class="mt-3 p-2" style="background:rgba(15,23,42,.5);border:1px solid rgba(71,85,105,.3);border-radius:8px;">
          <div style="color:#475569;font-size:.7rem;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.05em;">Expected column headers (row 1)</div>
          <code style="color:#94a3b8;font-size:.72rem;word-break:break-all;"><?= htmlspecialchars($_um_sample) ?></code>
        </div>
        <?php endif; ?>

      </div><!-- /modal-body -->

      <!-- Footer -->
      <div class="modal-footer" style="border-top:1px solid rgba(59,130,246,.15);padding:1rem 1.5rem;">
        <button type="button" class="btn btn-sm"
                style="background:transparent;border:1px solid rgba(100,116,139,.3);color:#94a3b8;"
                data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm fw-semibold" id="uploadBtn_<?= $_um_module ?>"
                onclick="pqmDoUpload('<?= $_um_module ?>')"
                style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;border:none;padding:.4rem 1.1rem;border-radius:6px;">
          <i class="bi bi-upload me-1"></i> Upload &amp; Import
        </button>
      </div>

    </div><!-- /modal-content -->
  </div>
</div>

<!-- ── Shared CSS (injected once) ───────────────────────────────────────────── -->
<?php if (!defined('PQM_UPLOAD_CSS_DONE')): define('PQM_UPLOAD_CSS_DONE', 1); ?>
<style>
.pqm-dropzone{
    border:2px dashed rgba(59,130,246,.35);
    border-radius:12px;
    background:rgba(30,58,95,.12);
    padding:2rem 1rem;
    text-align:center;
    cursor:pointer;
    transition:border-color .2s,background .2s;
}
.pqm-dropzone:hover,.pqm-dropzone.dz-hover{
    border-color:#3b82f6;
    background:rgba(59,130,246,.08);
}
.pqm-upload-result-ok{
    background:rgba(34,197,94,.08);
    border:1px solid rgba(34,197,94,.25);
    border-radius:10px;
    padding:1rem 1.2rem;
    color:#86efac;
}
.pqm-upload-result-err{
    background:rgba(239,68,68,.08);
    border:1px solid rgba(239,68,68,.25);
    border-radius:10px;
    padding:1rem 1.2rem;
    color:#fca5a5;
}
.pqm-upload-trigger-btn{
    display:inline-flex;align-items:center;gap:.4rem;
    background:linear-gradient(135deg,#14532d,#166534);
    color:#86efac;
    border:1px solid rgba(34,197,94,.3);
    border-radius:8px;
    padding:.4rem 1rem;
    font-size:.82rem;
    font-weight:600;
    cursor:pointer;
    transition:all .2s;
    text-decoration:none;
}
.pqm-upload-trigger-btn:hover{
    background:linear-gradient(135deg,#166534,#15803d);
    color:#bbf7d0;
    border-color:rgba(34,197,94,.5);
}
</style>
<?php endif; ?>

<!-- ── Shared JS (injected once) ────────────────────────────────────────────── -->
<?php if (!defined('PQM_UPLOAD_JS_DONE')): define('PQM_UPLOAD_JS_DONE', 1); ?>
<script>
/* ── PQM Upload helpers ───────────────────────────────────────────── */
const _pqmFiles = {};

function pqmHandleDrop(e, mod) {
    e.preventDefault();
    document.getElementById('dropzone_' + mod).classList.remove('dz-hover');
    const f = e.dataTransfer.files[0];
    if (f) pqmSetFile(mod, f);
}
function pqmFileSelected(input, mod) {
    if (input.files[0]) pqmSetFile(mod, input.files[0]);
}
function pqmSetFile(mod, file) {
    const ext = file.name.split('.').pop().toLowerCase();
    if (!['xlsx','xls','csv'].includes(ext)) {
        pqmShowResult(mod, false,
            '⚠️ WRONG FORMAT — Only .xlsx, .xls or .csv files are accepted. ' +
            'You uploaded: .' + ext);
        return;
    }
    _pqmFiles[mod] = file;
    document.getElementById('fileName_' + mod).textContent = file.name;
    document.getElementById('fileSize_' + mod).textContent =
        (file.size / 1024).toFixed(1) + ' KB';
    document.getElementById('fileInfo_' + mod).style.display = '';
    document.getElementById('result_'   + mod).style.display = 'none';
    document.getElementById('progress_' + mod).style.display = 'none';
}
function pqmClearFile(mod) {
    delete _pqmFiles[mod];
    document.getElementById('fileInput_' + mod).value = '';
    document.getElementById('fileInfo_'  + mod).style.display = 'none';
    document.getElementById('result_'    + mod).style.display = 'none';
}
function pqmDoUpload(mod) {
    const file = _pqmFiles[mod];
    if (!file) { alert('Please select a file first.'); return; }

    const btn = document.getElementById('uploadBtn_' + mod);
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing…';

    const fd = new FormData();
    fd.append('module', mod);
    fd.append('excel_file', file);

    const prog    = document.getElementById('progress_'    + mod);
    const progBar = document.getElementById('progressBar_' + mod);
    const progPct = document.getElementById('progressPct_' + mod);
    prog.style.display = '';
    progBar.style.width = '0%';

    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', e => {
        if (e.lengthComputable) {
            const pct = Math.round(e.loaded / e.total * 80); // cap at 80 while server processes
            progBar.style.width = pct + '%';
            progPct.textContent = pct + '%';
        }
    });
    xhr.addEventListener('load', () => {
        progBar.style.width = '100%';
        progPct.textContent = '100%';
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-upload me-1"></i> Upload &amp; Import';
        try {
            const res = JSON.parse(xhr.responseText);
            if (res.success) {
                pqmShowResult(mod, true,
                    '✅ ' + res.message +
                    (res.errors && res.errors.length
                        ? '<br><small style="color:#fbbf24">Row errors: ' + res.errors.join('<br>') + '</small>'
                        : ''),
                    true);
                setTimeout(() => location.reload(), 1500);
            } else {
                // Detect wrong format errors
                const isFormat = (res.message || '').toUpperCase().includes('WRONG FORMAT') ||
                                 (res.message || '').toUpperCase().includes('MISSING') ||
                                 (res.message || '').toUpperCase().includes('NO MATCHING');
                pqmShowResult(mod, false,
                    (isFormat ? '❌ WRONG FORMAT<br>' : '❌ Error: ') +
                    (res.message || 'Unknown error'));
            }
        } catch(err) {
            pqmShowResult(mod, false, '❌ Server returned unexpected response. Check server logs.');
        }
        setTimeout(() => { prog.style.display = 'none'; }, 800);
    });
    xhr.addEventListener('error', () => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-upload me-1"></i> Upload &amp; Import';
        pqmShowResult(mod, false, '❌ Network error — could not reach the server.');
        prog.style.display = 'none';
    });

    xhr.open('POST', (window._pqmBasePath || '') + 'includes/upload_handler.php');
    xhr.send(fd);
}
function pqmShowResult(mod, success, html, reload) {
    const el = document.getElementById('result_' + mod);
    el.style.display = '';
    el.className = success ? 'pqm-upload-result-ok mt-3' : 'pqm-upload-result-err mt-3';
    el.innerHTML = '<div style="font-size:.87rem;">' + html + '</div>' +
        (success && reload
            ? '<div class="mt-2" style="color:#86efac;font-size:.78rem;"><i class="bi bi-arrow-clockwise me-1"></i>Refreshing page…</div>'
            : '');
}
</script>
<?php endif; ?>