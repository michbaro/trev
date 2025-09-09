<?php
declare(strict_types=1);
require_once __DIR__ . '/init.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'message'=>'Token CSRF non valido.']); exit;
    }

    try {
        if ($_POST['action'] === 'save_forn') {
            $id   = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
            $nome = trim((string)($_POST['nome'] ?? ''));

            if ($nome === '') throw new RuntimeException('Il nome non può essere vuoto.');

            if ($id === null) {
                $stmt = $pdo->prepare('INSERT INTO fornitore (nome) VALUES (:n)');
                $stmt->execute([':n' => $nome]);
                echo json_encode(['ok'=>true,'op'=>'added']); exit;
            } else {
                $stmt = $pdo->prepare('UPDATE fornitore SET nome = :n WHERE id = :id');
                $stmt->execute([':n' => $nome, ':id' => $id]);
                echo json_encode(['ok'=>true,'op'=>'updated']); exit;
            }
        }

        if ($_POST['action'] === 'delete_forn') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ID non valido.');

            $stmt = $pdo->prepare('DELETE FROM fornitore WHERE id = :id');
            $stmt->execute([':id' => $id]);

            echo json_encode(['ok'=>true,'op'=>'deleted']); exit;
        }

        http_response_code(400);
        echo json_encode(['ok'=>false,'message'=>'Azione non riconosciuta.']); exit;

    } catch (PDOException $e) {
        // FK su acquisto.fornitore → fornitore.id
        echo json_encode([
            'ok'=>false,
            'message' => $e->getCode()==='23000'
                ? 'Impossibile eliminare: fornitore collegato ad altri record.'
                : 'Errore database.'
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'message'=>$e->getMessage()]); exit;
    }
}

try {
    $fornitori = $pdo->query('SELECT id, nome FROM fornitore ORDER BY nome ASC')->fetchAll();
} catch (Throwable $e) {
    $fornitori = [];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fornitori</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{
  --bg:#f6f7fb; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#e5e7eb;
  --accent:#2563eb; --accent-hover:#1d4ed8; --row-hover:#fbfdff;
  --success-bg:#ecfdf5; --success-border:#bbf7d0; --success-text:#065f46; --shadow:0 8px 24px rgba(15,23,42,.08);
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
body.modal-open{overflow:hidden}
.container{max-width:1100px;margin:24px auto;padding:0 16px}

/* header */
.header{display:flex;gap:12px;align-items:center;justify-content:space-between;margin:8px 0 16px}
.title{font-size:22px;font-weight:700;text-align:left}
.actions{display:flex;gap:10px;align-items:center}
.search{position:relative}
.search input{width:300px;max-width:60vw;background:#fff;border:1px solid var(--border);border-radius:10px;padding:10px 12px 10px 36px;outline:none;transition:border .15s,box-shadow .15s}
.search input:focus{border-color:#c7d2fe;box-shadow:0 0 0 4px #e0e7ff}
.search .icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:16px;color:var(--muted)}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;border:1px solid var(--border);background:#fff;font-weight:600;cursor:pointer;box-shadow:var(--shadow);transition:transform .05s,box-shadow .15s,border-color .15s}
.btn:hover{box-shadow:0 10px 28px rgba(15,23,42,.12);border-color:#cbd5e1}
.btn:active{transform:translateY(1px)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff;box-shadow:none}
.btn.primary:hover{background:var(--accent-hover);border-color:var(--accent-hover)}

/* card + tabella */
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);overflow:hidden}
.table{width:100%;border-collapse:separate;border-spacing:0}
thead th{font-size:12px;color:var(--muted);font-weight:700;letter-spacing:.3px;padding:12px 14px;border-bottom:1px solid var(--border)}
tbody td{padding:14px;border-top:1px solid var(--border);vertical-align:middle}
tbody tr:hover{background:var(--row-hover)} /* hover leggero */

/* colonne */
.col-nome{width:80%; text-align:left;}
/* Azioni: header e bottoni allineati a destra, pulsanti spostati verso destra come in prodotti.php */
.col-actions{width:20%;}
th.col-actions{padding-right:50px; text-align:right;}
td.actions{padding-right:30px;}
.actions-group{
  display:flex; gap:10px; justify-content:flex-end;
  transform: translateX(90px) !important; /* stessa traslazione che usi in prodotti.php */
}

.icon-btn{background:#fff;border:1px solid var(--border);padding:8px 10px;border-radius:10px;cursor:pointer}
.icon-btn:hover{border-color:#cbd5e1;background:#f8fafc}
.icon-btn.edit:hover{background:#eafaf0;border-color:#bbf7d0;color:#047857}
.icon-btn.del:hover{background:#fef2f2;border-color:#fecaca;color:#b91c1c}

/* modal (centrata, overlay alto) */
.modal-backdrop{position:fixed; inset:0; width:100vw; height:100vh; background:rgba(15,23,42,.45); display:none; z-index:9999;}
.modal-wrapper{position:absolute; inset:0; display:grid; place-items:center; padding:20px;}
.modal{width:100%; max-width:480px; background:#fff; border:1px solid var(--border); border-radius:16px; box-shadow:0 20px 50px rgba(15,23,42,.18); transform:translateY(8px); opacity:0; transition:opacity .15s ease, transform .15s ease;}
.modal.visible{transform:translateY(0); opacity:1}
.modal-header{padding:16px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between}
.modal-title{font-weight:800}
.modal-body{padding:16px 18px; display:grid; gap:12px}
.field label{display:block; margin-bottom:6px; color:var(--muted); font-size:12px; font-weight:700}
.field input[type="text"]{width:100%; background:#fff; border:1px solid var(--border); color:var(--text); padding:10px 12px; border-radius:10px; outline:none}
.field input:focus{border-color:#c7d2fe; box-shadow:0 0 0 4px #e0e7ff}
.modal-footer{padding:16px 18px; display:flex; gap:10px; justify-content:flex-end; border-top:1px solid var(--border)}

/* toast */
.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(20px);
  background:var(--success-bg);color:var(--success-text);border:1px solid var(--success-border);
  border-radius:12px;padding:12px 16px;display:flex;align-items:center;gap:10px;
  opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;z-index:60;box-shadow:var(--shadow)}
.toast.visible{opacity:1;transform:translateX(-50%) translateY(0)}

.nav-spacer{height:12px}
@media (max-width:600px){
  .header{flex-direction:column;align-items:stretch}
  .actions{justify-content:space-between}
  .search input{width:100%}
}
</style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="nav-spacer"></div>

<div class="container">
  <div class="header">
    <div class="title">Fornitori</div>
    <div class="actions">
      <div class="search">
        <i class="bi bi-search icon"></i>
        <input id="searchInput" type="text" placeholder="Cerca per nome...">
      </div>
      <button class="btn primary" id="btnNew"><i class="bi bi-plus-lg"></i> Nuovo Fornitore</button>
    </div>
  </div>

  <div class="card">
    <table class="table" id="fornTable">
      <thead>
        <tr>
          <th class="col-nome">Nome</th>
          <th class="col-actions">Azioni</th>
        </tr>
      </thead>
      <tbody id="tbody">
      <?php foreach ($fornitori as $f): ?>
        <tr data-id="<?= (int)$f['id'] ?>" data-nome="<?= htmlspecialchars($f['nome'], ENT_QUOTES) ?>">
          <td class="col-nome"><?= htmlspecialchars($f['nome']) ?></td>
          <td class="actions">
            <div class="actions-group">
              <button class="icon-btn btn-edit edit" title="Modifica"><i class="bi bi-pencil"></i></button>
              <button class="icon-btn btn-del del" title="Elimina"><i class="bi bi-trash"></i></button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($fornitori)): ?>
      <div style="padding:16px"><span class="muted">Nessun fornitore presente.</span></div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal -->
<div class="modal-backdrop" id="modalBackdrop" aria-hidden="true">
  <div class="modal-wrapper">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
      <div class="modal-header">
        <div class="modal-title" id="modalTitle">Nuovo Fornitore</div>
        <button class="icon-btn" id="btnCloseModal" aria-label="Chiudi"><i class="bi bi-x-lg"></i></button>
      </div>
      <form id="fornForm" class="modal-body">
        <input type="hidden" name="action" value="save_forn">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="id" id="fornId">
        <div class="field">
          <label for="fornNome">Nome *</label>
          <input type="text" id="fornNome" name="nome" maxlength="255" required placeholder="Es. Mangimi Rossi S.r.l.">
        </div>
      </form>
      <div class="modal-footer">
        <button class="btn" id="btnCancel">Annulla</button>
        <button class="btn primary" id="btnSave">Salva</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"><i class="bi bi-check-circle"></i><span id="toastMsg">Operazione completata</span></div>

<script>
(function(){
  // Ricerca live
  const searchInput=document.getElementById('searchInput');
  const tbody=document.getElementById('tbody');
  searchInput.addEventListener('input',()=>{
    const q=(searchInput.value||'').toLowerCase().trim();
    [...tbody.querySelectorAll('tr')].forEach(tr=>{
      const n=(tr.dataset.nome||'').toLowerCase();
      tr.style.display = n.includes(q)?'':'none';
    });
  });

  // Modal
  const modalBackdrop=document.getElementById('modalBackdrop');
  const modal=document.querySelector('.modal');
  const btnNew=document.getElementById('btnNew');
  const btnCloseModal=document.getElementById('btnCloseModal');
  const btnCancel=document.getElementById('btnCancel');
  const btnSave=document.getElementById('btnSave');
  const form=document.getElementById('fornForm');
  const fornId=document.getElementById('fornId');
  const fornNome=document.getElementById('fornNome');
  const modalTitle=document.getElementById('modalTitle');

  function openModal(mode,data){
    modalTitle.textContent = mode==='edit'?'Modifica Fornitore':'Nuovo Fornitore';
    if(mode==='edit'&&data){ fornId.value=data.id; fornNome.value=data.nome; }
    else{ fornId.value=''; fornNome.value=''; }
    document.body.classList.add('modal-open');
    modalBackdrop.style.display='block';
    requestAnimationFrame(()=> modal.classList.add('visible'));
    fornNome.focus();
  }
  function closeModal(){
    modal.classList.remove('visible');
    setTimeout(()=>{ modalBackdrop.style.display='none'; document.body.classList.remove('modal-open'); },150);
  }
  btnNew.addEventListener('click',()=>openModal('new'));
  btnCloseModal.addEventListener('click',closeModal);
  btnCancel.addEventListener('click',closeModal);
  modalBackdrop.addEventListener('click',(e)=>{ if(e.target.id==='modalBackdrop') closeModal(); });
  window.addEventListener('keydown',(e)=>{ if(e.key==='Escape') closeModal(); });

  // Toast da ?success=1
  const toast=document.getElementById('toast'); const toastMsg=document.getElementById('toastMsg');
  function showToast(message){ toastMsg.textContent=message||'Operazione completata'; toast.classList.add('visible'); setTimeout(()=>toast.classList.remove('visible'),2000); }
  (function(){
    const p=new URLSearchParams(location.search);
    if(p.get('success')==='1'){
      const op=p.get('op'); let msg='Operazione completata';
      if(op==='added') msg='Fornitore aggiunto';
      if(op==='updated') msg='Fornitore aggiornato';
      if(op==='deleted') msg='Fornitore eliminato';
      setTimeout(()=>{ showToast(msg); const u=new URL(location.href); u.searchParams.delete('success'); u.searchParams.delete('op'); history.replaceState({},'',u); },60);
    }
  })();

  // Edit/Delete click
  tbody.addEventListener('click',(e)=>{
    const tr=e.target.closest('tr'); if(!tr) return;
    if(e.target.closest('.btn-edit')) openModal('edit',{id:tr.dataset.id,nome:tr.dataset.nome});
    if(e.target.closest('.btn-del')){
      if(!confirm('Confermi l\'eliminazione di "'+tr.dataset.nome+'"?')) return;
      const fd=new FormData(); fd.append('action','delete_forn'); fd.append('id',tr.dataset.id);
      fd.append('csrf_token',document.querySelector('input[name="csrf_token"]').value);
      fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(j=>{
        if(j.ok){ const url=new URL(location.href); url.searchParams.set('success','1'); url.searchParams.set('op', j.op||'deleted'); location.href=url; }
        else alert(j.message||'Errore');
      }).catch(()=>alert('Errore di rete'));
    }
  });

  // Salva
  btnSave.addEventListener('click',(e)=>{
    e.preventDefault();
    if(!fornNome.value.trim()){ fornNome.focus(); return; }
    const fd=new FormData(form);
    fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(j=>{
      if(j.ok){ const url=new URL(location.href); url.searchParams.set('success','1'); url.searchParams.set('op', j.op||'added'); location.href=url; }
      else alert(j.message||'Errore');
    }).catch(()=>alert('Errore di rete'));
  });
})();
</script>
</body>
</html>
