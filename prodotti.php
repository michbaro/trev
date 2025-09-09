<?php
declare(strict_types=1);
require_once __DIR__ . '/init.php';

/* ===== DEBUG VISIBILE (puoi mettere a false in prod) ===== */
const APP_DEBUG = true;
if (APP_DEBUG) { error_reporting(E_ALL); ini_set('display_errors','1'); }

/* ===== CSRF ===== */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

/* ===== Helper error JSON ===== */
function jerr(string $msg, string $type='Error', array $debug=[]): void {
  http_response_code(200);
  echo json_encode(['ok'=>false,'type'=>$type,'message'=>$msg,'debug'=>$debug], JSON_UNESCAPED_SLASHES);
  exit;
}

/* =========================================================
   AJAX
========================================================= */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
  header('Content-Type: application/json; charset=utf-8');
  if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) jerr('Token CSRF non valido.','CSRF');

  try {
    switch ($_POST['action']) {

      case 'save_prod': {
        $id    = isset($_POST['id']) && $_POST['id']!=='' ? (int)$_POST['id'] : null;
        $nome  = trim((string)($_POST['nome'] ?? ''));
        $unita = trim((string)($_POST['unita'] ?? ''));
        $giac  = $_POST['giacenza'] ?? null; // solo in modifica

        if ($nome==='') jerr('Il nome non può essere vuoto.','Validation');
        if (!in_array($unita,['sacco','kg'],true)) jerr('Unità non valida.','Validation');
        if ($giac !== null) {
          if (!is_numeric($giac) || (int)$giac < 0) jerr('Giacenza non valida (>=0).','Validation');
          $giac = (int)$giac;
        }

        if ($id===null) {
          $st=$pdo->prepare('INSERT INTO prodotto (nome,unita) VALUES (:n,:u)');
          $st->execute([':n'=>$nome,':u'=>$unita]);
          echo json_encode(['ok'=>true,'op'=>'added']); exit;
        } else {
          if ($giac===null) {
            $st=$pdo->prepare('UPDATE prodotto SET nome=:n, unita=:u WHERE id=:id');
            $st->execute([':n'=>$nome,':u'=>$unita,':id'=>$id]);
          } else {
            $st=$pdo->prepare('UPDATE prodotto SET nome=:n, unita=:u, giacenza=:g WHERE id=:id');
            $st->execute([':n'=>$nome,':u'=>$unita,':g'=>$giac,':id'=>$id]);
          }
          echo json_encode(['ok'=>true,'op'=>'updated']); exit;
        }
      }

      case 'delete_prod': {
        $id=(int)($_POST['id'] ?? 0);
        if ($id<=0) jerr('ID non valido.','Validation');
        $st=$pdo->prepare('DELETE FROM prodotto WHERE id=:id');
        $st->execute([':id'=>$id]);
        echo json_encode(['ok'=>true,'op'=>'deleted']); exit;
      }

      case 'acquisti_by_prod': {
        $pid=(int)($_POST['id'] ?? 0);
        if ($pid<=0) jerr('ID non valido.','Validation');

        // *** SCHEMA NUOVO: a.lotto_id + tabella lotto ***
        $sql = "
          SELECT a.id, a.dataacquisto, a.scadenza, a.quantita,
                 l.id AS lotto_id, l.lotto AS lotto_nome,
                 f.nome AS fornitore
          FROM acquisto a
          JOIN lotto      l ON l.id = a.lotto_id
          JOIN fornitore  f ON f.id = a.fornitore
          WHERE a.prodotto = :pid
          ORDER BY a.dataacquisto DESC, a.id DESC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':pid'=>$pid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok'=>true,'rows'=>$rows,'debug'=>APP_DEBUG?['sql'=>$sql,'pid'=>$pid]:null]); exit;
      }

    }

    jerr('Azione non riconosciuta.','BadRequest');

  } catch (PDOException $e) {
    jerr('PDOException: '.$e->getMessage(),'PDO',['code'=>$e->getCode()]);
  } catch (Throwable $e) {
    jerr('Exception: '.$e->getMessage(),'Exception');
  }
}

/* =========================================================
   LISTA PRODOTTI (giacenza visualizzata: salvata>0 ? salvata : somma acquisti)
========================================================= */
try {
  $prodotti = $pdo->query("
    SELECT p.id, p.nome, p.unita,
           COALESCE(NULLIF(p.giacenza,0), SUM(a.quantita)) AS giacenza_eff,
           COALESCE(SUM(a.quantita),0) AS giac_calc,
           COALESCE(p.giacenza,0)      AS giac_saved
    FROM prodotto p
    LEFT JOIN acquisto a ON a.prodotto = p.id
    GROUP BY p.id, p.nome, p.unita, p.giacenza
    ORDER BY p.nome ASC
  ")->fetchAll();
} catch (Throwable $e) {
  $prodotti = [];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prodotti</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{
  --bg:#f6f7fb;--card:#fff;--text:#0f172a;--muted:#64748b;--border:#e5e7eb;
  --accent:#2563eb;--accent-hover:#1d4ed8;--row-hover:#fbfdff;--shadow:0 8px 24px rgba(15,23,42,.08);
  --edit:#10b981;--edit-weak:#eafaf0;--del:#ef4444;--del-weak:#fef2f2;--info:#3b82f6;--info-weak:#eef5ff;
  --badge-kg:#e8f6ee;--badge-kg-border:#bde4cb;--badge-sacco:#f7eee6;--badge-sacco-border:#ebd5c1;
}
*{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
.container{max-width:1200px;margin:24px auto;padding:0 16px}
.header{display:flex;align-items:center;justify-content:space-between;margin:8px 0 16px;gap:10px}
.title{font-size:22px;font-weight:700}
.header-actions{display:flex;gap:10px;align-items:center}
.search{position:relative}
.search input{width:300px;max-width:60vw;background:#fff;border:1px solid var(--border);border-radius:10px;padding:10px 12px 10px 36px;outline:none}
.search input:focus{border-color:#c7d2fe;box-shadow:0 0 0 4px #e0e7ff}
.search .icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:16px;color:var(--muted)}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;border:1px solid var(--border);background:#fff;font-weight:600;cursor:pointer;box-shadow:var(--shadow)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.btn.icon{width:42px;height:42px;border-radius:999px;justify-content:center}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);overflow:hidden}
.table{width:100%;border-collapse:separate;border-spacing:0}
thead th{font-size:12px;color:var(--muted);font-weight:700;padding:12px 14px;border-bottom:1px solid var(--border);text-align:center;user-select:none;cursor:pointer}
thead th.sortable .label{display:inline-flex;align-items:center;gap:6px}
thead th .arrow{font-size:11px;opacity:.7}
tbody td{padding:14px;border-top:1px solid var(--border);text-align:center}
tbody tr:hover{background:var(--row-hover)}
th.col-nome{min-width:360px;text-align:left} td.col-nome{text-align:left}
th.col-actions{width:210px;cursor:default}
.actions-group{display:flex;gap:10px;justify-content:center}
.badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-weight:700;font-size:12px;border:1px solid var(--border)}
.badge.kg{background:var(--badge-kg);border-color:var(--badge-kg-border)}
.badge.sacco{background:var(--badge-sacco);border-color:var(--badge-sacco-border)}
.icon-btn{width:38px;height:38px;border-radius:999px;background:#fff;border:1px solid var(--border);display:grid;place-items:center}
.icon-btn:hover{box-shadow:0 8px 20px rgba(15,23,42,.14);border-color:#cbd5e1}
.icon-btn.edit:hover{background:var(--edit-weak);color:var(--edit)}
.icon-btn.view:hover{background:var(--info-weak);color:var(--info)}
.icon-btn.del:hover{background:var(--del-weak);color:var(--del)}
/* modal */
.modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;z-index:9999}
.modal-wrapper{position:absolute;inset:0;display:grid;place-items:center;padding:20px}
.modal{width:100%;max-width:520px;background:#fff;border:1px solid var(--border);border-radius:16px;box-shadow:0 20px 50px rgba(15,23,42,.18);transform:translateY(8px);opacity:0;transition:.15s}
.modal.visible{transform:translateY(0);opacity:1}
.modal-header{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-title{font-weight:800}
.modal-body{padding:16px 18px;display:grid;gap:12px}
.field label{display:block;margin-bottom:6px;color:#64748b;font-size:12px;font-weight:700}
.field input,.field select{width:100%;background:#fff;border:1px solid var(--border);padding:10px 12px;border-radius:10px}
.modal-footer{padding:16px 18px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end}
.modal-wide{width:100%;max-width:900px}
.subtable{width:100%;border-collapse:separate;border-spacing:0;margin-top:4px}
.subtable thead th{font-size:12px;color:#64748b;padding:10px;border-bottom:1px solid var(--border);text-align:center}
.subtable tbody td{padding:12px;border-top:1px solid var(--border);text-align:center;white-space:nowrap}
.error-box{background:#fff3cd;border:1px solid #ffe08a;color:#7a5d00;border-radius:10px;padding:8px 12px;margin-top:10px;font-size:13px}
.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(20px);background:#ecfdf5;color:#065f46;border:1px solid #bbf7d0;border-radius:12px;padding:12px 16px;display:flex;align-items:center;gap:10px;opacity:0;pointer-events:none;transition:.2s;z-index:60;box-shadow:var(--shadow)}
.toast.visible{opacity:1;transform:translateX(-50%) translateY(0)}
.pager{display:flex;gap:6px;align-items:center;justify-content:flex-end;padding:6px 0;font-size:12px}
.pager .btn{padding:6px 8px;border-radius:8px}
.pager input[type="number"]{width:64px;border:1px solid var(--border);border-radius:8px;padding:6px 8px}
</style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>

<div class="container">
  <div class="header">
    <div class="title">Prodotti</div>
    <div class="header-actions">
      <div class="search">
        <i class="bi bi-search icon"></i>
        <input id="searchInput" type="text" placeholder="Cerca per nome...">
      </div>
      <button class="btn primary" id="btnNew"><i class="bi bi-plus-circle-fill"></i> Nuovo Prodotto</button>
    </div>
  </div>

  <div class="card">
    <table class="table" id="prodTable">
      <thead>
        <tr>
          <th class="col-nome sortable" data-col="nome"><span class="label">Nome <span class="arrow" data-arrow="nome"></span></span></th>
          <th class="col-unita sortable" data-col="unita"><span class="label">Unità <span class="arrow" data-arrow="unita"></span></span></th>
          <th class="col-giac sortable" data-col="giacenza"><span class="label">Giacenza <span class="arrow" data-arrow="giacenza"></span></span></th>
          <th class="col-actions">Azioni</th>
        </tr>
      </thead>
      <tbody id="tbody">
        <?php $i=0; foreach($prodotti as $p): $i++; ?>
        <tr
          data-index="<?= $i ?>"
          data-id="<?= (int)$p['id'] ?>"
          data-nome="<?= htmlspecialchars(mb_strtolower($p['nome']),ENT_QUOTES) ?>"
          data-unita="<?= htmlspecialchars($p['unita'],ENT_QUOTES) ?>"
          data-giacenza="<?= (int)$p['giacenza_eff'] ?>"
          data-giaccalc="<?= (int)$p['giac_calc'] ?>"
          data-giacsaved="<?= (int)$p['giac_saved'] ?>"
        >
          <td class="col-nome"><?= htmlspecialchars($p['nome']) ?></td>
          <td><?php $cls=$p['unita']==='kg'?'kg':'sacco'; ?><span class="badge <?= $cls ?>"><?= $cls ?></span></td>
          <td><?= (int)$p['giacenza_eff'] ?></td>
          <td class="actions">
            <div class="actions-group">
              <button class="icon-btn view btn-view" title="Vedi acquisti"><i class="bi bi-eye-fill"></i></button>
              <button class="icon-btn edit btn-edit" title="Modifica"><i class="bi bi-pencil-fill"></i></button>
              <button class="icon-btn del btn-del" title="Elimina"><i class="bi bi-trash3-fill"></i></button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if(empty($prodotti)): ?>
      <div style="padding:16px; text-align:center; color:#64748b">Nessun prodotto presente.</div>
    <?php endif; ?>
  </div>

  <div class="pager">
    <button class="btn icon" id="firstPage" title="Prima"><i class="bi bi-chevron-double-left"></i></button>
    <button class="btn icon" id="prevPage"  title="Precedente"><i class="bi bi-chevron-left"></i></button>
    <span>Pagina</span>
    <input type="number" id="pageInput" min="1" value="1" autocomplete="off">
    <span id="pageTotal">/ 1</span>
    <button class="btn icon" id="nextPage"  title="Successiva"><i class="bi bi-chevron-right"></i></button>
    <button class="btn icon" id="lastPage"  title="Ultima"><i class="bi bi-chevron-double-right"></i></button>
  </div>
</div>

<!-- Modal Add/Edit -->
<div class="modal-backdrop" id="modalBackdrop" style="display:none">
  <div class="modal-wrapper">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
      <div class="modal-header">
        <div class="modal-title" id="modalTitle">Nuovo Prodotto</div>
        <button class="icon-btn" id="btnCloseModal" aria-label="Chiudi"><i class="bi bi-x-circle-fill"></i></button>
      </div>
      <form id="prodForm" class="modal-body">
        <input type="hidden" name="action" value="save_prod">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="id" id="prodId">
        <div class="field">
          <label for="prodNome">Nome *</label>
          <input type="text" id="prodNome" name="nome" maxlength="255" required placeholder="Es. Mangime Premium">
        </div>
        <div class="field">
          <label for="prodUnita">Unità *</label>
          <select id="prodUnita" name="unita" required>
            <option value="sacco">sacco</option>
            <option value="kg">kg</option>
          </select>
        </div>
        <!-- SOLO MODIFICA -->
        <div class="field" id="wrapGiacProd" style="display:none">
          <label for="prodGiac">Giacenza (calcolata — modificabile)</label>
          <input type="number" min="0" id="prodGiac" name="giacenza" value="0">
        </div>
      </form>
      <div class="modal-footer">
        <button class="btn" id="btnCancel"><i class="bi bi-x-circle-fill"></i> Annulla</button>
        <button class="btn primary" id="btnSave"><i class="bi bi-check-circle-fill"></i> Salva</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Acquisti del prodotto -->
<div class="modal-backdrop" id="viewBackdrop" style="display:none">
  <div class="modal-wrapper">
    <div class="modal modal-wide">
      <div class="modal-header">
        <div class="modal-title" id="viewTitle">Acquisti del prodotto</div>
        <button class="icon-btn" id="btnViewClose" aria-label="Chiudi"><i class="bi bi-x-circle-fill"></i></button>
      </div>
      <div class="modal-body">
        <div id="viewInfo" style="color:#64748b"></div>
        <div id="viewError" class="error-box" style="display:none"></div>
        <table class="subtable" id="subTable">
          <thead>
            <tr><th>Lotto</th><th>Data</th><th>Scadenza</th><th>Quantità</th><th>Fornitore</th></tr>
          </thead>
          <tbody id="subBody"></tbody>
        </table>
      </div>
      <div class="modal-footer">
        <button class="btn" id="btnViewClose2"><i class="bi bi-x-circle-fill"></i> Chiudi</button>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"><i class="bi bi-check-circle-fill"></i><span id="toastMsg">Operazione completata</span></div>

<script>
(function(){
  if (window.__PROD_INIT__) return; window.__PROD_INIT__ = true;
  const qs=s=>document.querySelector(s), qsa=s=>Array.from(document.querySelectorAll(s));
  const clamp=(v,min,max)=>Math.max(min,Math.min(max,v));
  const tbody=qs('#tbody');

  // Ricerca
  const searchInput=qs('#searchInput');
  searchInput.addEventListener('input',()=>{ const q=(searchInput.value||'').toLowerCase().trim();
    qsa('#tbody tr').forEach(tr=> tr.style.display = (tr.dataset.nome||'').includes(q)?'':'none');
    currentPage=1; refresh();
  });

  // Modal add/edit
  const modalBackdrop=qs('#modalBackdrop'), modal=modalBackdrop.querySelector('.modal');
  const modalTitle=qs('#modalTitle'), btnNew=qs('#btnNew'), btnCloseModal=qs('#btnCloseModal'), btnCancel=qs('#btnCancel'), btnSave=qs('#btnSave');
  const form=qs('#prodForm'), prodId=qs('#prodId'), prodNome=qs('#prodNome'), prodUnita=qs('#prodUnita'), wrapGiac=qs('#wrapGiacProd'), prodGiac=qs('#prodGiac');

  function openModal(mode,row){
    modalTitle.textContent = mode==='edit' ? 'Modifica Prodotto' : 'Nuovo Prodotto';
    if (mode==='edit' && row){
      prodId.value=row.dataset.id;
      prodNome.value=row.querySelector('.col-nome').textContent.trim();
      prodUnita.value=row.dataset.unita;
      prodGiac.value=row.dataset.giaccalc || '0';
      wrapGiac.style.display='block';
    } else {
      prodId.value=''; prodNome.value=''; prodUnita.value='sacco'; prodGiac.value='0'; wrapGiac.style.display='none';
    }
    document.body.classList.add('modal-open'); modalBackdrop.style.display='block'; requestAnimationFrame(()=> modal.classList.add('visible')); prodNome.focus();
  }
  function closeModal(){ modal.classList.remove('visible'); setTimeout(()=>{ modalBackdrop.style.display='none'; document.body.classList.remove('modal-open'); },150); }
  btnNew.addEventListener('click',()=>openModal('new'));
  btnCloseModal.addEventListener('click',closeModal); btnCancel.addEventListener('click',closeModal);
  modalBackdrop.addEventListener('click',e=>{ if(e.target===modalBackdrop) closeModal(); });
  window.addEventListener('keydown',e=>{ if(e.key==='Escape' && modalBackdrop.style.display==='block') closeModal(); });

  // Modale "Vedi acquisti"
  const viewBackdrop=qs('#viewBackdrop'), viewModal=viewBackdrop.querySelector('.modal');
  const viewTitle=qs('#viewTitle'), viewInfo=qs('#viewInfo'), viewError=qs('#viewError'), subBody=qs('#subBody');
  const btnViewClose=qs('#btnViewClose'), btnViewClose2=qs('#btnViewClose2');

  function openView(tr){
    const id=tr.dataset.id, nome=tr.querySelector('.col-nome').textContent.trim(), giac=tr.dataset.giaccalc || tr.dataset.giacenza || '0';
    viewTitle.textContent='Acquisti — '+nome; viewInfo.textContent='Giacenza totale: '+giac;
    viewError.style.display='none'; viewError.textContent=''; subBody.innerHTML='<tr><td colspan="5" style="color:#64748b">Caricamento...</td></tr>';

    const fd=new FormData(); fd.append('action','acquisti_by_prod'); fd.append('csrf_token', <?= json_encode($csrfToken) ?>); fd.append('id',id);
    fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'})
      .then(async r => { let j=null; try{ j=await r.json(); }catch(e){ j=null; }
        if(!j || j.ok===false){
          console.error('acquisti_by_prod ERROR', j);
          subBody.innerHTML='';
          viewError.style.display='block';
          viewError.innerHTML = j ? `<strong>${j.type||'Errore'}</strong>: ${j.message||'Errore'}<br><small>${j.debug?JSON.stringify(j.debug):''}</small>` : 'Risposta non valida dal server.';
          return;
        }
        console.debug('acquisti_by_prod DEBUG', j.debug||{});
        if(!j.rows || !j.rows.length){ subBody.innerHTML='<tr><td colspan="5" style="color:#64748b">Nessun acquisto trovato.</td></tr>'; return; }
        subBody.innerHTML = j.rows.map(r=>{
          const data=r.dataacquisto ? r.dataacquisto.split('-').reverse().join('/') : '';
          const scad=r.scadenza ? r.scadenza.split('-').reverse().join('/') : '';
          const link = r.lotto_id ? ('lotto.php?id='+encodeURIComponent(r.lotto_id)) : null;
          const lottoHtml = link ? `<a href="${link}" target="_blank" title="Apri lotto">${r.lotto_nome}</a> <a href="${link}" target="_blank" title="Vedi lotto" style="margin-left:6px"><i class="bi bi-eye-fill"></i></a>` : `<span>${r.lotto_nome ?? '-'}</span>`;
          return `<tr><td>${lottoHtml}</td><td>${data}</td><td>${scad}</td><td>${r.quantita}</td><td>${r.fornitore}</td></tr>`;
        }).join('');
      })
      .catch(err=>{ console.error('fetch error',err); subBody.innerHTML=''; viewError.style.display='block'; viewError.textContent='Errore di rete: '+(err?.message||String(err)); });

    document.body.classList.add('modal-open'); viewBackdrop.style.display='block'; requestAnimationFrame(()=> viewModal.classList.add('visible'));
  }
  function closeView(){ viewModal.classList.remove('visible'); setTimeout(()=>{ viewBackdrop.style.display='none'; document.body.classList.remove('modal-open'); },150); }
  btnViewClose.addEventListener('click',closeView); btnViewClose2.addEventListener('click',closeView);
  viewBackdrop.addEventListener('click',e=>{ if(e.target===viewBackdrop) closeView(); });
  window.addEventListener('keydown',e=>{ if(e.key==='Escape' && viewBackdrop.style.display==='block') closeView(); });

  // Toast
  const toast=qs('#toast'), toastMsg=qs('#toastMsg');
  function showToast(m){ toastMsg.textContent=m||'Operazione completata'; toast.classList.add('visible'); setTimeout(()=>toast.classList.remove('visible'),2000); }
  (function(){ const p=new URLSearchParams(location.search); if(p.get('success')==='1'){ const op=p.get('op'); let msg='Operazione completata'; if(op==='added') msg='Prodotto aggiunto'; if(op==='updated') msg='Prodotto aggiornato'; if(op==='deleted') msg='Prodotto eliminato'; setTimeout(()=>{ showToast(msg); const u=new URL(location.href); u.searchParams.delete('success'); u.searchParams.delete('op'); history.replaceState({},'',u); },60);} })();

  // Deleghe
  tbody.addEventListener('click',e=>{
    const tr=e.target.closest('tr'); if(!tr) return;
    if(e.target.closest('.btn-edit')) openModal('edit',tr);
    if(e.target.closest('.btn-view')) openView(tr);
    if(e.target.closest('.btn-del')){
      if(!confirm('Confermi l\'eliminazione di "'+tr.querySelector('.col-nome').textContent.trim()+'"?')) return;
      const fd=new FormData(); fd.append('action','delete_prod'); fd.append('id',tr.dataset.id); fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
      fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(j=>{
        if(j.ok){ const url=new URL(location.href); url.searchParams.set('success','1'); url.searchParams.set('op', j.op||'deleted'); location.href=url; }
        else alert((j.type?j.type+': ':'')+(j.message||'Errore'));
      }).catch(err=>alert('Errore di rete: '+err.message));
    }
  });

  // Salva
  btnSave.addEventListener('click',e=>{
    e.preventDefault();
    if(!prodNome.value.trim()){ prodNome.focus(); return; }
    if(!['sacco','kg'].includes(prodUnita.value)){ alert('Unità non valida.'); return; }
    const fd=new FormData(form);
    fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(j=>{
      if(j.ok){ const url=new URL(location.href); url.searchParams.set('success','1'); url.searchParams.set('op', j.op||'added'); location.href=url; }
      else alert((j.type?j.type+': ':'')+(j.message||'Errore'));
    }).catch(err=>alert('Errore di rete: '+err.message));
  });

  // Ordinamento + paginazione
  let sortCol=null, sortDir=null;
  qsa('thead th.sortable').forEach(th=>{
    th.addEventListener('click',()=>{
      const col=th.dataset.col;
      if(sortCol!==col){ sortCol=col; sortDir='asc'; }
      else if(sortDir==='asc'){ sortDir='desc'; }
      else if(sortDir==='desc'){ sortCol=null; sortDir=null; }
      else { sortDir='asc'; }
      qsa('[data-arrow]').forEach(a=>a.textContent=''); if(sortCol&&sortDir){ const a=qs('[data-arrow="'+sortCol+'"]'); if(a) a.textContent=(sortDir==='asc'?'▲':'▼'); }
      currentPage=1; refresh();
    });
  });
  function cmp(a,b,col,dir){
    if(!col||!dir) return (parseInt(a.dataset.index,10)-parseInt(b.dataset.index,10));
    let av=a.dataset[col]||'', bv=b.dataset[col]||'';
    if(col==='giacenza'){ av=parseFloat(av||'0'); bv=parseFloat(bv||'0'); return dir==='asc'? av-bv : bv-av; }
    return dir==='asc'? String(av).localeCompare(String(bv)) : String(bv).localeCompare(String(av));
  }
  const PAGE_SIZE=15; let currentPage=1,totalPages=1;
  const pageInput=qs('#pageInput'), pageTotal=qs('#pageTotal');
  const btnFirst=qs('#firstPage'), btnPrev=qs('#prevPage'), btnNext=qs('#nextPage'), btnLast=qs('#lastPage');
  function visibleRows(){ return qsa('#tbody tr').filter(tr=>tr.style.display!=='none'); }
  function goToPageInput(){ let v=parseInt(pageInput.value,10); if(isNaN(v)) v=1; currentPage=clamp(v,1,totalPages); refresh(); }
  btnFirst.addEventListener('click',()=>{currentPage=1;refresh();}); btnPrev.addEventListener('click',()=>{currentPage=clamp(currentPage-1,1,totalPages);refresh();});
  btnNext.addEventListener('click',()=>{currentPage=clamp(currentPage+1,1,totalPages);refresh();}); btnLast.addEventListener('click',()=>{currentPage=totalPages;refresh();});
  pageInput.addEventListener('change',goToPageInput); pageInput.addEventListener('keyup',e=>{ if(e.key==='Enter') goToPageInput(); });

  function refresh(){
    const all=visibleRows(); const sorted=all.slice().sort((a,b)=>cmp(a,b,sortCol,sortDir)); const tb=qs('#tbody'); sorted.forEach(tr=>tb.appendChild(tr));
    const filtered=sorted; const maxPage=Math.max(1, Math.ceil(filtered.length/PAGE_SIZE));
    if(filtered.length===0) currentPage=1; else if((currentPage-1)*PAGE_SIZE>=filtered.length) currentPage=1;
    totalPages=maxPage; qsa('#tbody tr').forEach(tr=>tr.style.visibility='hidden');
    const start=(currentPage-1)*PAGE_SIZE; filtered.slice(start,start+PAGE_SIZE).forEach(tr=>tr.style.visibility='visible');
    pageInput.max=String(totalPages); pageInput.value=String(currentPage); pageTotal.textContent='/ '+totalPages;
    btnFirst.disabled=btnPrev.disabled=currentPage<=1; btnNext.disabled=btnLast.disabled=currentPage>=totalPages;
  }
  refresh();
})();
</script>
</body>
</html>
