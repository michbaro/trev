<?php
declare(strict_types=1);
require_once __DIR__ . '/init.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* =========================
   HANDLER AJAX
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'message'=>'Token CSRF non valido.']); exit;
    }

    try {
        if ($_POST['action'] === 'quick_add_prodotto') {
            $nome  = trim((string)($_POST['nome'] ?? ''));
            $unita = trim((string)($_POST['unita'] ?? ''));
            if ($nome === '') throw new RuntimeException('Il nome prodotto è obbligatorio.');
            if (!in_array($unita, ['sacco','kg'], true)) throw new RuntimeException('Unità non valida.');
            $stmt = $pdo->prepare('INSERT INTO prodotto (nome, unita) VALUES (:n,:u)');
            $stmt->execute([':n'=>$nome, ':u'=>$unita]);
            echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId(), 'nome'=>$nome, 'unita'=>$unita]); exit;
        }

        if ($_POST['action'] === 'quick_add_fornitore') {
            $nome = trim((string)($_POST['nome'] ?? ''));
            if ($nome === '') throw new RuntimeException('Il nome fornitore è obbligatorio.');
            $stmt = $pdo->prepare('INSERT INTO fornitore (nome) VALUES (:n)');
            $stmt->execute([':n'=>$nome]);
            echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId(), 'nome'=>$nome]); exit;
        }

        if ($_POST['action'] === 'get_acquisto') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id<=0) throw new RuntimeException('ID non valido.');
            $stmt = $pdo->prepare('SELECT a.id, a.dataacquisto, a.quantita, a.scadenza,
                                          l.lotto AS lotto_nome,
                                          a.lotto_id,
                                          a.prodotto, a.fornitore,
                                          a.note, a.documento_scansionato
                                   FROM acquisto a
                                   JOIN lotto l ON l.id=a.lotto_id
                                   WHERE a.id=:id');
            $stmt->execute([':id'=>$id]);
            $row = $stmt->fetch();
            if (!$row) throw new RuntimeException('Acquisto non trovato.');
            $files = [];
            if (!empty($row['documento_scansionato'])) {
                $dec = json_decode($row['documento_scansionato'], true);
                if (is_array($dec)) $files = $dec;
            }
            echo json_encode(['ok'=>true,'data'=>$row,'files'=>$files]); exit;
        }

        if ($_POST['action'] === 'delete_attachment') {
            $id   = (int)($_POST['id'] ?? 0);
            $path = trim((string)($_POST['path'] ?? ''));
            if ($id<=0 || $path==='') throw new RuntimeException('Parametri mancanti.');
            $stmt = $pdo->prepare('SELECT documento_scansionato FROM acquisto WHERE id=:id');
            $stmt->execute([':id'=>$id]);
            $row = $stmt->fetch();
            $arr = [];
            if ($row && $row['documento_scansionato']) {
                $dec = json_decode($row['documento_scansionato'], true);
                if (is_array($dec)) $arr = $dec;
            }
            $arr = array_values(array_filter($arr, fn($p)=>$p !== $path));
            $stmt = $pdo->prepare('UPDATE acquisto SET documento_scansionato=:j WHERE id=:id');
            $stmt->execute([':j'=>json_encode($arr, JSON_UNESCAPED_SLASHES), ':id'=>$id]);
            $abs = __DIR__ . '/' . $path;
            if (is_file($abs)) @unlink($abs);
            echo json_encode(['ok'=>true,'files'=>$arr]); exit;
        }

        if ($_POST['action'] === 'save_acquisto') {
            $id         = isset($_POST['id']) && $_POST['id']!=='' ? (int)$_POST['id'] : null;
            $data       = trim((string)($_POST['dataacquisto'] ?? ''));
            $lottoName  = trim((string)($_POST['lotto'] ?? ''));
            $quantita   = (int)($_POST['quantita'] ?? 0);
            $scadenza   = trim((string)($_POST['scadenza'] ?? '')); // facoltativa
            $prodotto   = (int)($_POST['prodotto'] ?? 0);
            $fornitore  = (int)($_POST['fornitore'] ?? 0);
            $note       = trim((string)($_POST['note'] ?? ''));

            if ($data==='' || $lottoName==='' || $quantita<=0 || $prodotto<=0 || $fornitore<=0) {
                throw new RuntimeException('Compila tutti i campi obbligatori.');
            }
            $scadenzaParam = ($scadenza==='') ? null : $scadenza;

            // Risolvi/crea lotto.id da $lottoName
            $pdo->beginTransaction();
            try {
                $sel = $pdo->prepare('SELECT id FROM lotto WHERE lotto=:n LIMIT 1');
                $sel->execute([':n'=>$lottoName]);
                $lottoId = $sel->fetchColumn();
                if (!$lottoId) {
                    $ins = $pdo->prepare('INSERT INTO lotto (lotto) VALUES (:n)');
                    $ins->execute([':n'=>$lottoName]);
                    $lottoId = (int)$pdo->lastInsertId();
                }
                // INSERT / UPDATE acquisto
                if ($id === null) {
                    $stmt = $pdo->prepare('INSERT INTO acquisto (dataacquisto, lotto_id, quantita, scadenza, note, prodotto, fornitore)
                                           VALUES (:d,:l,:q,:s,:n,:p,:f)');
                    $stmt->execute([
                        ':d'=>$data, ':l'=>$lottoId, ':q'=>$quantita,
                        ':s'=>$scadenzaParam, ':n'=>$note, ':p'=>$prodotto, ':f'=>$fornitore
                    ]);
                    $id = (int)$pdo->lastInsertId();
                    $op = 'added';
                    $existingPaths = [];
                } else {
                    $stmt = $pdo->prepare('UPDATE acquisto
                                           SET dataacquisto=:d, lotto_id=:l, quantita=:q, scadenza=:s, note=:n, prodotto=:p, fornitore=:f
                                           WHERE id=:id');
                    $stmt->execute([
                        ':d'=>$data, ':l'=>$lottoId, ':q'=>$quantita,
                        ':s'=>$scadenzaParam, ':n'=>$note, ':p'=>$prodotto, ':f'=>$fornitore, ':id'=>$id
                    ]);
                    $op = 'updated';

                    $cur = $pdo->prepare('SELECT documento_scansionato FROM acquisto WHERE id=:id');
                    $cur->execute([':id'=>$id]);
                    $row = $cur->fetch();
                    $existingPaths = [];
                    if ($row && !empty($row['documento_scansionato'])) {
                        $decoded = json_decode($row['documento_scansionato'], true);
                        if (is_array($decoded)) $existingPaths = $decoded;
                    }
                }

                // Upload multiplo
                $paths = $existingPaths;
                if (!empty($_FILES['files']['name'][0])) {
                    $baseDir = __DIR__ . "/resources/$id/";
                    if (!is_dir($baseDir)) mkdir($baseDir, 0777, true);
                    foreach ($_FILES['files']['name'] as $idx=>$name) {
                        if ($_FILES['files']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                        $tmp = $_FILES['files']['tmp_name'][$idx];
                        $safe = preg_replace('/[^a-zA-Z0-9_\.\-]/','_', $name);
                        $safe = substr($safe, -180);
                        $destRel = "resources/$id/" . (uniqid('', true) . "_" . $safe);
                        $destAbs = __DIR__ . '/' . $destRel;
                        if (move_uploaded_file($tmp, $destAbs)) $paths[] = $destRel;
                    }
                    $stmt = $pdo->prepare('UPDATE acquisto SET documento_scansionato = :json WHERE id=:id');
                    $stmt->execute([':json'=>json_encode($paths, JSON_UNESCAPED_SLASHES), ':id'=>$id]);
                }

                $pdo->commit();
                echo json_encode(['ok'=>true,'op'=>$op]); exit;
            } catch (PDOException $ee) {
                $pdo->rollBack();
                if ($ee->getCode()==='23000') {
                    echo json_encode(['ok'=>false,'message'=>'Lotto duplicato o vincoli violati.']); exit;
                }
                throw $ee;
            }
        }

        if ($_POST['action'] === 'delete_acquisto') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id<=0) throw new RuntimeException('ID non valido.');
            $stmt = $pdo->prepare('DELETE FROM acquisto WHERE id=:id');
            $stmt->execute([':id'=>$id]);
            echo json_encode(['ok'=>true,'op'=>'deleted']); exit;
        }

        http_response_code(400);
        echo json_encode(['ok'=>false,'message'=>'Azione non riconosciuta.']); exit;

    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'message'=>'Errore database.']); exit;
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'message'=>$e->getMessage()]); exit;
    }
}

/* =========================
   QUERY LISTE
   ========================= */
function fmtDate(?string $iso): string {
    if (!$iso) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    return $dt ? $dt->format('d/m/Y') : htmlspecialchars($iso);
}
try {
    $acquisti  = $pdo->query('SELECT a.id, a.dataacquisto, a.quantita, a.scadenza,
                                     l.lotto AS lotto_nome,
                                     p.nome AS prodotto, f.nome AS fornitore,
                                     a.prodotto AS prodotto_id, a.fornitore AS fornitore_id
                              FROM acquisto a
                              JOIN lotto l     ON a.lotto_id=l.id
                              JOIN prodotto p  ON a.prodotto=p.id
                              JOIN fornitore f ON a.fornitore=f.id
                              ORDER BY a.dataacquisto DESC, a.id DESC')->fetchAll();
    $prodotti  = $pdo->query('SELECT id,nome,unita FROM prodotto ORDER BY nome')->fetchAll();
    $fornitori = $pdo->query('SELECT id,nome FROM fornitore ORDER BY nome')->fetchAll();
} catch (Throwable $e) {
    $acquisti=$prodotti=$fornitori=[];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Acquisti</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{
  --bg:#f6f7fb; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#e5e7eb;
  --accent:#2563eb; --accent-hover:#1d4ed8; --row-hover:#fbfdff;
  --success-bg:#ecfdf5; --success-border:#bbf7d0; --success-text:#065f46; --shadow:0 8px 24px rgba(15,23,42,.08);
  --edit:#10b981; --edit-weak:#eafaf0;
  --del:#ef4444;  --del-weak:#fef2f2;
  --info:#3b82f6; --info-weak:#eef5ff;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
body.modal-open{overflow:hidden}
.container{max-width:1600px;margin:24px auto;padding:0 16px}

/* header */
.header{display:flex;gap:10px;align-items:center;justify-content:space-between;margin:8px 0 16px}
.title{font-size:22px;font-weight:700}
.header-actions{display:flex;gap:10px;align-items:center}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;border:1px solid var(--border);background:#fff;font-weight:600;cursor:pointer;box-shadow:var(--shadow);transition:transform .05s,box-shadow .15s,border-color .15s}
.btn:hover{box-shadow:0 10px 28px rgba(15,23,42,.12);border-color:#cbd5e1}
.btn:active{transform:translateY(1px)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.btn.primary:hover{background:var(--accent-hover);border-color:var(--accent-hover)}
.btn.icon{width:42px;height:42px;justify-content:center;border-radius:999px}

/* card + tabella */
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);overflow:hidden}
.table-wrap{overflow-x:auto}
.table{width:100%;table-layout:auto;border-collapse:separate;border-spacing:0}
thead th{font-size:12px;color:var(--muted);font-weight:700;letter-spacing:.3px;padding:12px 14px;border-bottom:1px solid var(--border);text-align:center;user-select:none;cursor:pointer}
thead th.sortable .label{display:inline-flex;align-items:center;gap:6px}
thead th.sortable .label .arrow{font-size:11px;opacity:.7}
tbody td{padding:14px;border-top:1px solid var(--border);vertical-align:middle;text-align:center;white-space:nowrap}
tbody tr:hover{background:var(--row-hover)}
th.col-actions{cursor:default}
th.col-id, td.col-id{width:90px}
th.col-lotto{min-width:240px}
th.col-prod{min-width:260px}
th.col-forn{min-width:260px}
th.col-quant{width:140px}
th.col-actions{width:160px}
td.actions .actions-group{display:flex;gap:10px;justify-content:center}

/* ICON BUTTONS */
.icon-btn{
  width:38px;height:38px;border-radius:999px;
  background:#fff;border:1px solid var(--border);
  display:inline-grid;place-items:center;cursor:pointer;
  transition:box-shadow .15s,border-color .15s, background .15s, color .15s, transform .05s;
}
.icon-btn:hover{box-shadow:0 8px 20px rgba(15,23,42,.14);border-color:#cbd5e1}
.icon-btn.edit:hover{background:var(--edit-weak);color:var(--edit)}
.icon-btn.del:hover{background:var(--del-weak);color:var(--del)}
.icon-btn.info:hover{background:var(--info-weak);color:var(--info)}
.icon-btn:active{transform:translateY(1px)}

/* chips filtri */
.filters-bar{display:none;flex-wrap:wrap;gap:8px;padding:10px 14px;border-bottom:1px solid var(--border);background:#fcfdff}
.filter-chip{display:inline-flex;gap:6px;align-items:center;padding:6px 10px;border-radius:999px;background:#eef2ff;border:1px solid #e0e7ff;font-size:12px}
.filter-chip button{background:transparent;border:0;cursor:pointer;color:#475569}

/* modal overlay */
.modal-backdrop{position:fixed; inset:0; width:100vw; height:100vh; background:rgba(15,23,42,.45); display:none; z-index:9999;}
.modal-wrapper{position:absolute; inset:0; display:grid; place-items:center; padding:20px;}
.modal{width:100%; max-width:720px; background:#fff; border:1px solid var(--border); border-radius:16px; box-shadow:0 20px 50px rgba(15,23,42,.18); transform:translateY(8px); opacity:0; transition:opacity .15s ease, transform .15s ease;}
.modal.visible{transform:translateY(0); opacity:1}
.modal-header{padding:16px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between}
.modal-title{font-weight:800}
.modal-body{padding:16px 18px; display:grid; gap:12px}
.form-grid{display:grid; grid-template-columns:1fr 1fr; gap:12px}
.form-grid .full{grid-column:1 / -1}
.field label{display:block; margin-bottom:6px; color:var(--muted); font-size:12px; font-weight:700}
.field input[type="text"], .field input[type="date"], .field input[type="number"], .field select, .field textarea{ width:100%; background:#fff; border:1px solid var(--border); color:#0f172a; padding:10px 12px; border-radius:10px; outline:none}
.field textarea{min-height:100px; resize:vertical}
.field input:focus, .field select:focus, .field textarea:focus{border-color:#c7d2fe; box-shadow:0 0 0 4px #e0e7ff}
.modal-footer{padding:16px 18px; display:flex; gap:10px; justify-content:flex-end; border-top:1px solid var(--border)}

/* uploader */
.uploader{border:2px dashed var(--border);border-radius:12px;padding:18px;text-align:center;color:#64748b;cursor:pointer;transition:border .2s, background .2s}
.uploader.dragover{border-color:var(--accent);background:#f0f7ff}
.uploader input{display:none}
.files-list{display:flex; flex-wrap:wrap; gap:8px; margin-top:8px}
.file-chip{display:inline-flex; align-items:center; gap:8px; background:#f1f5f9;border:1px solid #e2e8f0;border-radius:999px;padding:6px 10px;font-size:12px}
.file-chip button{border:0;background:transparent;cursor:pointer;color:#334155}

/* mini modali quick add */
.mini-modal{border:1px solid var(--border); background:#fff; border-radius:14px; padding:14px; box-shadow:var(--shadow); width:100%; max-width:380px}
.mini-modal .field{margin-bottom:10px}
.mini-actions{display:flex; gap:10px; justify-content:flex-end}

/* modal filtri */
.filter-modal{border:1px solid var(--border); background:#fff; border-radius:14px; padding:16px; box-shadow:var(--shadow); width:100%; max-width:720px}
.filters-grid{display:grid; grid-template-columns:repeat(3, 1fr); gap:12px}
.filter-actions{display:flex; gap:8px; justify-content:flex-end; margin-top:8px}

/* help icon tooltip */
.help-wrap{display:inline-flex;align-items:center;gap:6px}
.help-icon{
  width:20px;height:20px;border-radius:999px;border:1px solid var(--border);
  display:inline-grid;place-items:center;cursor:pointer;font-size:12px;color:#475569;background:#fff;
  transition:box-shadow .15s,border-color .15s, background .15s, color .15s;
}
.help-icon:hover{background:#eef2ff;border-color:#c7d2fe;color:#1d4ed8}
.help-pop{position:absolute;z-index:20;background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow);padding:8px 10px;font-size:12px;color:#0f172a;display:none;max-width:300px}

.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(20px);
  background:var(--success-bg);color:var(--success-text);border:1px solid var(--success-border);
  border-radius:12px;padding:12px 16px;display:flex;align-items:center;gap:10px;
  opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;z-index:60;box-shadow:var(--shadow)}
.toast.visible{opacity:1;transform:translateX(-50%) translateY(0)}

.pager-outside{max-width:1600px;margin:8px auto 0;padding:0 16px}
.pager{display:flex;gap:6px;align-items:center;justify-content:flex-end;padding:6px 0;font-size:12px}
.pager .btn{padding:6px 8px;border-radius:8px}
.pager input[type="number"]{width:64px;border:1px solid var(--border);border-radius:8px;padding:6px 8px;font-size:12px}

@media (max-width:920px){ .filters-grid{grid-template-columns:1fr 1fr} .form-grid{grid-template-columns:1fr} }
@media (max-width:600px){ .filters-grid{grid-template-columns:1fr} }
</style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="container">
  <div class="header">
    <div class="title">Acquisti</div>
    <div class="header-actions">
      <button class="btn icon" id="btnFilters" title="Filtri"><i class="bi bi-funnel-fill"></i></button>
      <button class="btn primary" id="btnNew"><i class="bi bi-plus-circle-fill"></i> Nuovo Acquisto</button>
    </div>
  </div>

  <div class="card">
    <div class="filters-bar" id="filtersBar"></div>

    <div class="table-wrap">
      <table class="table" id="acqTable">
        <thead>
          <tr>
            <th class="col-id sortable" data-col="id"><span class="label"># <span class="arrow" data-arrow="id"></span></span></th>
            <th class="sortable" data-col="data"><span class="label">Data <span class="arrow" data-arrow="data"></span></span></th>
            <th class="col-lotto sortable" data-col="lotto"><span class="label">Lotto <span class="arrow" data-arrow="lotto"></span></span></th>
            <th class="col-quant sortable" data-col="quantita"><span class="label">Quantità <span class="arrow" data-arrow="quantita"></span></span></th>
            <th class="sortable" data-col="scadenza"><span class="label">Scadenza <span class="arrow" data-arrow="scadenza"></span></span></th>
            <th class="col-prod sortable" data-col="prodotto"><span class="label">Prodotto <span class="arrow" data-arrow="prodotto"></span></span></th>
            <th class="col-forn sortable" data-col="fornitore"><span class="label">Fornitore <span class="arrow" data-arrow="fornitore"></span></span></th>
            <th class="col-actions">Azioni</th>
          </tr>
        </thead>
        <tbody id="tbody">
          <?php $idx=0; foreach($acquisti as $a): $idx++; ?>
            <tr
              data-index="<?= $idx ?>"
              data-id="<?= (int)$a['id'] ?>"
              data-data="<?= htmlspecialchars($a['dataacquisto'], ENT_QUOTES) ?>"
              data-lotto="<?= htmlspecialchars(mb_strtolower($a['lotto_nome']), ENT_QUOTES) ?>"
              data-quantita="<?= (int)$a['quantita'] ?>"
              data-scadenza="<?= htmlspecialchars($a['scadenza'], ENT_QUOTES) ?>"
              data-prodotto="<?= htmlspecialchars(mb_strtolower($a['prodotto']), ENT_QUOTES) ?>"
              data-fornitore="<?= htmlspecialchars(mb_strtolower($a['fornitore']), ENT_QUOTES) ?>"
              data-prodottoid="<?= (int)$a['prodotto_id'] ?>"
              data-fornitoreid="<?= (int)$a['fornitore_id'] ?>"
            >
              <td class="col-id"><?= (int)$a['id'] ?></td>
              <td><?= fmtDate($a['dataacquisto']) ?></td>
              <td><?= htmlspecialchars($a['lotto_nome']) ?></td>
              <td><?= (int)$a['quantita'] ?></td>
              <td><?= fmtDate($a['scadenza']) ?></td>
              <td><?= htmlspecialchars($a['prodotto']) ?></td>
              <td><?= htmlspecialchars($a['fornitore']) ?></td>
              <td class="actions">
                <div class="actions-group">
                  <button class="icon-btn edit btn-edit" title="Modifica"><i class="bi bi-pencil-fill"></i></button>
                  <button class="icon-btn del btn-del" title="Elimina"><i class="bi bi-trash3-fill"></i></button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    </div>
  </div>

  <!-- PAGER FUORI DALLA TABELLA -->
  <div class="pager-outside">
    <div class="pager" id="pager">
      <button class="btn icon info" id="firstPage" title="Prima"><i class="bi bi-chevron-double-left"></i></button>
      <button class="btn icon info" id="prevPage"  title="Precedente"><i class="bi bi-chevron-left"></i></button>
      <span>Pagina</span>
      <input type="number" id="pageInput" min="1" value="1" autocomplete="off">
      <span id="pageTotal">/ 1</span>
      <button class="btn icon info" id="nextPage"  title="Successiva"><i class="bi bi-chevron-right"></i></button>
      <button class="btn icon info" id="lastPage"  title="Ultima"><i class="bi bi-chevron-double-right"></i></button>
    </div>
  </div>
</div>

<!-- Modal principale -->
<div class="modal-backdrop" id="modalBackdrop" aria-hidden="true" style="display:none">
  <div class="modal-wrapper">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
      <div class="modal-header">
        <div class="modal-title" id="modalTitle">Nuovo Acquisto</div>
        <button class="icon-btn info" id="btnCloseModal" aria-label="Chiudi"><i class="bi bi-x-circle-fill"></i></button>
      </div>
      <form id="acqForm" class="modal-body" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_acquisto">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="id" id="acqId">

        <div class="form-grid">
          <div class="field">
            <label for="dataAcq">Data acquisto *</label>
            <input type="date" id="dataAcq" name="dataacquisto" required>
          </div>
          <div class="field">
            <label for="scadenza">Data scadenza</label>
            <input type="date" id="scadenza" name="scadenza">
          </div>

          <div class="field">
            <label for="lotto">Lotto * 
              <span class="help-wrap">
                <span class="help-icon" id="lottoHelp">?</span>
              </span>
            </label>
            <input type="text" id="lotto" name="lotto" maxlength="100" required placeholder="Inserisci esattamente il nome del lotto">
            <div class="help-pop" id="lottoPop">Riporta <strong>esattamente</strong> il nome del lotto, senza modificare spazi, maiuscole o simboli. Se il lotto esiste verrà riutilizzato; altrimenti sarà creato.</div>
          </div>
          <div class="field">
            <label for="fornitore">Fornitore *</label>
            <select id="fornitore" name="fornitore" required>
              <option value="">— Seleziona —</option>
              <?php foreach($fornitori as $f): ?>
                <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['nome']) ?></option>
              <?php endforeach; ?>
              <option value="__new_forn__">+ Aggiungi nuovo fornitore…</option>
            </select>
          </div>

          <div class="field">
            <label for="prodotto">Prodotto *</label>
            <select id="prodotto" name="prodotto" required>
              <option value="">— Seleziona —</option>
              <?php foreach($prodotti as $p): ?>
                <option value="<?= (int)$p['id'] ?>">
                  <?= htmlspecialchars($p['nome']) ?> (<?= $p['unita']==='kg'?'kg':'sacco' ?>)
                </option>
              <?php endforeach; ?>
              <option value="__new_prod__">+ Aggiungi nuovo prodotto…</option>
            </select>
          </div>
          <div class="field">
            <label for="quantita">Quantità *</label>
            <input type="number" id="quantita" name="quantita" min="1" required>
          </div>

          <!-- NOTE -->
          <div class="field full">
            <label for="note">Note</label>
            <textarea id="note" name="note" placeholder="Aggiungi eventuali dettagli sull’acquisto (es. condizioni, trasporto, ecc.)"></textarea>
          </div>

          <!-- Uploader -->
          <div class="field full">
            <label>Documenti/Allegati (trascina o clicca)</label>
            <div class="uploader" id="uploader">
              <input type="file" name="files[]" id="filesInput" multiple>
              <div><i class="bi bi-cloud-arrow-up-fill"></i> Trascina qui i file o <u>clicca</u> per selezionare</div>
            </div>
            <div class="files-list" id="filesList"></div>
            <div class="files-list" id="existingFiles"></div>
          </div>
        </div>
      </form>
      <div class="modal-footer">
        <button class="btn" id="btnCancel"><i class="bi bi-x-circle-fill"></i> Annulla</button>
        <button class="btn primary" id="btnSave"><i class="bi bi-check-circle-fill"></i> Salva</button>
      </div>
    </div>
  </div>
</div>

<!-- Mini modali quick add -->
<div class="modal-backdrop" id="miniProdBackdrop" style="display:none">
  <div class="modal-wrapper">
    <div class="mini-modal">
      <div class="field"><label>Nome prodotto *</label><input type="text" id="miniProdNome" placeholder="Es. Mangime Premium"></div>
      <div class="field"><label>Unità *</label><select id="miniProdUnita"><option value="sacco">sacco</option><option value="kg">kg</option></select></div>
      <div class="mini-actions"><button class="btn" id="miniProdCancel"><i class="bi bi-x-circle-fill"></i> Annulla</button><button class="btn primary" id="miniProdSave"><i class="bi bi-check-circle-fill"></i> Salva</button></div>
    </div>
  </div>
</div>
<div class="modal-backdrop" id="miniFornBackdrop" style="display:none">
  <div class="modal-wrapper">
    <div class="mini-modal">
      <div class="field"><label>Nome fornitore *</label><input type="text" id="miniFornNome" placeholder="Es. Mangimi Rossi S.r.l."></div>
      <div class="mini-actions"><button class="btn" id="miniFornCancel"><i class="bi bi-x-circle-fill"></i> Annulla</button><button class="btn primary" id="miniFornSave"><i class="bi bi-check-circle-fill"></i> Salva</button></div>
    </div>
  </div>
</div>

<!-- Modal filtri -->
<div class="modal-backdrop" id="filterBackdrop" style="display:none">
  <div class="modal-wrapper">
    <div class="filter-modal">
      <div class="modal-header" style="padding:0 0 8px 0; border:0; display:flex; justify-content:space-between; align-items:center">
        <div class="modal-title">Filtri avanzati</div>
        <button class="icon-btn info" id="btnFilterClose" aria-label="Chiudi filtri"><i class="bi bi-x-circle-fill"></i></button>
      </div>
      <div class="filters-grid">
        <div class="field"><label>ID</label><input type="number" id="flt_id" placeholder="es. 12"></div>
        <div class="field"><label>Data</label><input type="date" id="flt_data"></div>
        <div class="field"><label>Lotto</label><input type="text" id="flt_lotto" placeholder="Ricerca testo"></div>
        <div class="field"><label>Scadenza</label><input type="date" id="flt_scadenza"></div>
        <div class="field"><label>Prodotto</label><input type="text" id="flt_prodotto" placeholder="Ricerca testo"></div>
        <div class="field"><label>Fornitore</label><input type="text" id="flt_fornitore" placeholder="Ricerca testo"></div>
      </div>
      <div class="filter-actions">
        <button class="btn" id="btnFilterReset"><i class="bi bi-x-circle"></i> Pulisci</button>
        <button class="btn primary" id="btnFilterApply"><i class="bi bi-check-circle"></i> Applica</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"><i class="bi bi-check-circle-fill"></i><span id="toastMsg">Operazione completata</span></div>

<script>
(function(){
  if (window.__ACQUISTI_INIT__) return;
  window.__ACQUISTI_INIT__ = true;

  function qs(sel){ return document.querySelector(sel); }
  function qsa(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); }
  function todayISO(){ const d=new Date(); const m=('0'+(d.getMonth()+1)).slice(-2), day=('0'+d.getDate()).slice(-2); return d.getFullYear()+'-'+m+'-'+day; }
  function clamp(v,min,max){ return Math.max(min, Math.min(max, v)); }

  var tbody   = qs('#tbody');

  // Modal principale
  var modalBackdrop = qs('#modalBackdrop');
  var modal         = modalBackdrop ? modalBackdrop.querySelector('.modal') : null;
  var acqForm       = qs('#acqForm');
  var acqId         = qs('#acqId');
  var inputData     = qs('#dataAcq');
  var inputScad     = qs('#scadenza');
  var inputLotto    = qs('#lotto');
  var inputQta      = qs('#quantita');
  var inputNote     = qs('#note');
  var selProd       = qs('#prodotto');
  var selForn       = qs('#fornitore');
  var filesInput    = qs('#filesInput');
  var filesList     = qs('#filesList');
  var existingFiles = qs('#existingFiles');
  var uploader      = qs('#uploader');

  function openModal(mode, data){
    if (!modalBackdrop || !modal) return;
    qs('#modalTitle').textContent = mode==='edit' ? 'Modifica Acquisto' : 'Nuovo Acquisto';
    existingFiles.innerHTML=''; filesList.innerHTML=''; if (filesInput) filesInput.value='';

    if (mode==='edit' && data){
      acqId.value = data.id || '';
      inputData.value = data.dataacq || todayISO();
      inputScad.value = data.scadenza || '';
      inputLotto.value= (data.lotto || '').toUpperCase() || '';
      inputQta.value  = data.quantita || '';
      inputNote.value = '';

      var fd = new FormData();
      fd.append('action','get_acquisto');
      fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
      fd.append('id', data.id);
      fetch(location.href, {method:'POST', body:fd, credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(j){
          if(j.ok){
            if (j.data && j.data.prodotto)  selProd.value  = String(j.data.prodotto);
            if (j.data && j.data.fornitore) selForn.value = String(j.data.fornitore);
            if (j.data && typeof j.data.note === 'string') inputNote.value = j.data.note;
            if (j.data && j.data.lotto_nome) inputLotto.value = j.data.lotto_nome;
            renderExistingFiles(j.files || []);
          }
        });
    } else {
      acqId.value = '';
      inputData.value = todayISO();
      inputScad.value = '';
      inputLotto.value= '';
      inputQta.value  = '';
      inputNote.value = '';
      if (selProd) selProd.value='';
      if (selForn) selForn.value='';
    }

    document.body.classList.add('modal-open');
    modalBackdrop.style.display='block';
    requestAnimationFrame(function(){ modal.classList.add('visible'); });
    inputData && inputData.focus();
  }
  function closeModal(){
    if (!modalBackdrop || !modal) return;
    modal.classList.remove('visible');
    setTimeout(function(){ modalBackdrop.style.display='none'; document.body.classList.remove('modal-open'); },150);
  }

  document.addEventListener('click', function(e){
    var t = e.target;
    if (t.closest('#btnNew')) { openModal('new'); }
    if (t.closest('#btnCloseModal')) { closeModal(); }
    if (t.closest('#btnCancel')) { closeModal(); }
  });
  if (modalBackdrop){
    modalBackdrop.addEventListener('click', function(e){
      if (e.target === modalBackdrop) closeModal();
    });
  }
  window.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && modalBackdrop && modalBackdrop.style.display==='block') closeModal();
  });

  // Tooltip (?) lotto
  (function(){
    var help = qs('#lottoHelp'), pop = qs('#lottoPop'), field = qs('#lotto');
    function open(){ if(pop){ const r=field.getBoundingClientRect(); pop.style.left=(r.right-300)+'px'; pop.style.top=(r.top+window.scrollY-8)+'px'; pop.style.display='block'; } }
    function close(){ if(pop) pop.style.display='none'; }
    if (help){ help.addEventListener('mouseenter', open); help.addEventListener('mouseleave', close); help.addEventListener('click', function(){ if(pop) pop.style.display = (pop.style.display==='block'?'none':'block'); }); }
    if (document){ document.addEventListener('click', function(e){ if(pop && !e.target.closest('#lottoHelp') && !e.target.closest('#lottoPop')) close(); }); }
  })();

  // Uploader
  function renderFiles(){
    if (!filesList || !filesInput) return;
    filesList.innerHTML='';
    var fl = filesInput.files || [];
    for (var i=0;i<fl.length;i++){
      var chip = document.createElement('span');
      chip.className='file-chip';
      chip.innerHTML = '<i class="bi bi-file-earmark"></i> '+ fl[i].name +
        ' <button type="button" data-i="'+i+'" title="Rimuovi"><i class="bi bi-x-circle"></i></button>';
      filesList.appendChild(chip);
    }
    qsa('#filesList button[data-i]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var idx = +btn.getAttribute('data-i');
        var dt = new DataTransfer();
        var fl2 = filesInput.files;
        for (var j=0;j<fl2.length;j++){ if (j!==idx) dt.items.add(fl2[j]); }
        filesInput.files = dt.files;
        renderFiles();
      });
    });
  }
  if (uploader){
    uploader.addEventListener('click', function(){ filesInput && filesInput.click(); });
    uploader.addEventListener('dragover', function(e){ e.preventDefault(); uploader.classList.add('dragover'); });
    uploader.addEventListener('dragleave', function(){ uploader.classList.remove('dragover'); });
    uploader.addEventListener('drop', function(e){ e.preventDefault(); uploader.classList.remove('dragover'); if (filesInput){ filesInput.files=e.dataTransfer.files; renderFiles(); } });
  }
  if (filesInput){ filesInput.addEventListener('change', renderFiles); }

  function renderExistingFiles(paths){
    if (!existingFiles) return;
    existingFiles.innerHTML='';
    (paths||[]).forEach(function(p){
      var chip = document.createElement('span');
      chip.className='file-chip';
      var name = (p.split('/').pop() || p);
      chip.innerHTML = '<a href="'+p+'" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text"></i> '+name+'</a> ' +
                       '<button type="button" data-path="'+p+'" title="Elimina"><i class="bi bi-x-circle"></i></button>';
      existingFiles.appendChild(chip);
    });
    qsa('#existingFiles button[data-path]').forEach(function(btn){
      btn.addEventListener('click', function(){
        if(!confirm('Eliminare questo allegato?')) return;
        var fd=new FormData();
        fd.append('action','delete_attachment');
        fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
        fd.append('id', acqId.value || '0');
        fd.append('path', btn.getAttribute('data-path'));
        fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'})
          .then(function(r){return r.json();})
          .then(function(j){ if(j.ok){ renderExistingFiles(j.files || []); } else alert(j.message||'Errore'); });
      });
    });
  }

  // Quick Add prodotto/fornitore
  var miniProdBackdrop=qs('#miniProdBackdrop'), miniProdNome=qs('#miniProdNome'), miniProdUnita=qs('#miniProdUnita');
  var miniFornBackdrop=qs('#miniFornBackdrop'), miniFornNome=qs('#miniFornNome');

  document.addEventListener('change', function(e){
    if (e.target.id==='prodotto' && e.target.value==='__new_prod__'){ selProd.value=''; if (miniProdBackdrop) { miniProdBackdrop.style.display='block'; miniProdNome && miniProdNome.focus(); } }
    if (e.target.id==='fornitore' && e.target.value==='__new_forn__'){ selForn.value=''; if (miniFornBackdrop){ miniFornBackdrop.style.display='block'; miniFornNome && miniFornNome.focus(); } }
  });

  document.addEventListener('click', function(e){
    var t=e.target;
    if (t.closest('#miniProdCancel')) { if (miniProdBackdrop) miniProdBackdrop.style.display='none'; }
    if (t.closest('#miniFornCancel')) { if (miniFornBackdrop) miniFornBackdrop.style.display='none'; }

    if (t.closest('#miniProdSave')) {
      var nome = (miniProdNome && miniProdNome.value.trim()) || '';
      var unita = (miniProdUnita && miniProdUnita.value) || 'sacco';
      if (!nome){ miniProdNome && miniProdNome.focus(); return; }
      var fd=new FormData();
      fd.append('action','quick_add_prodotto');
      fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
      fd.append('nome', nome); fd.append('unita', unita);
      fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(j){
          if (j.ok){
            var opt = new Option(j.nome + (j.unita==='kg'?' (kg)':' (sacco)'), j.id, true, true);
            selProd.add(opt);
            if (miniProdBackdrop) miniProdBackdrop.style.display='none';
          } else alert(j.message||'Errore');
        });
    }

    if (t.closest('#miniFornSave')) {
      var nomeF = (miniFornNome && miniFornNome.value.trim()) || '';
      if (!nomeF){ miniFornNome && miniFornNome.focus(); return; }
      var fd2=new FormData();
      fd2.append('action','quick_add_fornitore');
      fd2.append('csrf_token', <?= json_encode($csrfToken) ?>);
      fd2.append('nome', nomeF);
      fetch(location.href,{method:'POST',body:fd2,credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(j){
          if (j.ok){
            var opt = new Option(j.nome, j.id, true, true);
            selForn.add(opt);
            if (miniFornBackdrop) miniFornBackdrop.style.display='none';
          } else alert(j.message||'Errore');
        });
    }
  });

  if (miniProdBackdrop){
    miniProdBackdrop.addEventListener('click', function(e){ if (e.target===miniProdBackdrop) miniProdBackdrop.style.display='none'; });
  }
  if (miniFornBackdrop){
    miniFornBackdrop.addEventListener('click', function(e){ if (e.target===miniFornBackdrop) miniFornBackdrop.style.display='none'; });
  }

  // Toast
  var toast=qs('#toast'), toastMsg=qs('#toastMsg');
  function showToast(message){
    if (!toast || !toastMsg) return;
    toastMsg.textContent=message||'Operazione completata';
    toast.classList.add('visible');
    setTimeout(function(){ toast.classList.remove('visible'); }, 2000);
  }
  (function(){
    var p=new URLSearchParams(location.search);
    if(p.get('success')==='1'){
      var op=p.get('op'); var msg='Operazione completata';
      if(op==='added') msg='Acquisto aggiunto';
      if(op==='updated') msg='Acquisto aggiornato';
      if(op==='deleted') msg='Acquisto eliminato';
      setTimeout(function(){
        showToast(msg);
        var u=new URL(location.href); u.searchParams.delete('success'); u.searchParams.delete('op'); history.replaceState({},'',u);
      },60);
    }
  })();

  // Edit/Delete/dblclick
  if (tbody){
    tbody.addEventListener('click', function(e){
      var tr = e.target.closest('tr'); if(!tr) return;
      if (e.target.closest('.btn-edit')) {
        openModal('edit',{
          id:tr.dataset.id, dataacq:tr.dataset.data,
          lotto:tr.dataset.lotto, quantita:tr.dataset.quantita,
          scadenza:tr.dataset.scadenza
        });
      }
      if (e.target.closest('.btn-del')) {
        if(!confirm('Confermi l\'eliminazione dell\'acquisto lotto "'+(tr.dataset.lotto || '')+'"?')) return;
        var fd=new FormData();
        fd.append('action','delete_acquisto');
        fd.append('id', tr.dataset.id);
        fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
        fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'})
          .then(function(r){return r.json();})
          .then(function(j){
            if(j.ok){
              var url=new URL(location.href); url.searchParams.set('success','1'); url.searchParams.set('op', j.op||'deleted'); location.href=url;
            } else alert(j.message||'Errore');
          });
      }
    });
    tbody.addEventListener('dblclick', function(e){
      var tr = e.target.closest('tr'); if(!tr) return;
      openModal('edit',{
        id:tr.dataset.id, dataacq:tr.dataset.data,
        lotto:tr.dataset.lotto, quantita:tr.dataset.quantita,
        scadenza:tr.dataset.scadenza
      });
    });
  }

  // Salva
  document.addEventListener('click', function(e){
    if (!e.target.closest('#btnSave')) return;
    e.preventDefault();
    if (!inputData.value){ inputData.focus(); return; }
    if (!inputLotto.value.trim()){ inputLotto.focus(); return; }
    if (!selForn.value){ selForn.focus(); return; }
    if (!selProd.value){ selProd.focus(); return; }
    if (!inputQta.value || +inputQta.value<=0){ inputQta.focus(); return; }
    var fd=new FormData(acqForm);
    fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){
        if(j.ok){
          var url=new URL(location.href); url.searchParams.set('success','1'); url.searchParams.set('op', j.op||'added'); location.href=url;
        } else alert(j.message||'Errore');
      });
  });

  // Filtri
  var filterBackdrop = qs('#filterBackdrop');
  var btnFilters = qs('#btnFilters'), btnFilterClose = qs('#btnFilterClose');
  var btnFilterApply = qs('#btnFilterApply'), btnFilterReset = qs('#btnFilterReset');
  var filtersBar = qs('#filtersBar');
  var filters = { id:'', data:'', lotto:'', scadenza:'', prodotto:'', fornitore:'' };
  var inputs = { id:qs('#flt_id'), data:qs('#flt_data'), lotto:qs('#flt_lotto'), scadenza:qs('#flt_scadenza'), prodotto:qs('#flt_prodotto'), fornitore:qs('#flt_fornitore') };

  function openFilters(){
    if (!filterBackdrop) return;
    Object.keys(inputs).forEach(function(k){ if(inputs[k]) inputs[k].value = filters[k] || ''; });
    document.body.classList.add('modal-open');
    filterBackdrop.style.display='block';
  }
  function closeFilters(){
    if (!filterBackdrop) return;
    filterBackdrop.style.display='none';
    document.body.classList.remove('modal-open');
  }

  if (btnFilters) btnFilters.addEventListener('click', openFilters);
  if (btnFilterClose) btnFilterClose.addEventListener('click', closeFilters);
  if (filterBackdrop){
    filterBackdrop.addEventListener('click', function(e){ if(e.target===filterBackdrop) closeFilters(); });
  }
  window.addEventListener('keydown', function(e){ if(e.key==='Escape' && filterBackdrop && filterBackdrop.style.display==='block') closeFilters(); });

  function applyFiltersFromInputs(){
    filters.id        = inputs.id && inputs.id.value.trim() || '';
    filters.data      = inputs.data && inputs.data.value.trim() || '';
    filters.lotto     = inputs.lotto && inputs.lotto.value.trim().toLowerCase() || '';
    filters.scadenza  = inputs.scadenza && inputs.scadenza.value.trim() || '';
    filters.prodotto  = inputs.prodotto && inputs.prodotto.value.trim().toLowerCase() || '';
    filters.fornitore = inputs.fornitore && inputs.fornitore.value.trim().toLowerCase() || '';
  }

  function rowMatches(tr){
    if (filters.id && String(tr.dataset.id)!==String(filters.id)) return false;
    if (filters.data && tr.dataset.data !== filters.data) return false;
    if (filters.scadenza && tr.dataset.scadenza !== filters.scadenza) return false;
    if (filters.lotto && !(tr.dataset.lotto||'').includes(filters.lotto)) return false;
    if (filters.prodotto && !(tr.dataset.prodotto||'').includes(filters.prodotto)) return false;
    if (filters.fornitore && !(tr.dataset.fornitore||'').includes(filters.fornitore)) return false;
    return true;
  }

  function renderFilterChips(){
    if (!filtersBar) return;
    var parts=[];
    if (filters.id) parts.push(['id','ID: '+filters.id]);
    if (filters.data){ var a=filters.data.split('-'); parts.push(['data','Data: '+a[2]+'/'+a[1]+'/'+a[0]]); }
    if (filters.lotto) parts.push(['lotto','Lotto: '+filters.lotto]);
    if (filters.scadenza){ var b=filters.scadenza.split('-'); parts.push(['scadenza','Scadenza: '+b[2]+'/'+b[1]+'/'+b[0]]); }
    if (filters.prodotto) parts.push(['prodotto','Prodotto: '+filters.prodotto]);
    if (filters.fornitore) parts.push(['fornitore','Fornitore: '+filters.fornitore]);
    if (!parts.length){ filtersBar.style.display='none'; filtersBar.innerHTML=''; return; }
    filtersBar.style.display='flex';
    filtersBar.innerHTML = parts.map(function(p){ return '<span class="filter-chip"><strong>'+p[1]+'</strong> <button data-k="'+p[0]+'" title="Rimuovi"><i class="bi bi-x-circle"></i></button></span>'; }).join('');
    qsa('.filter-chip button[data-k]').forEach(function(btn){
      btn.addEventListener('click', function(){ filters[btn.getAttribute('data-k')] = ''; currentPage=1; refresh(); });
    });
  }

  if (btnFilterApply){
    btnFilterApply.addEventListener('click', function(){
      applyFiltersFromInputs(); currentPage=1; refresh(); closeFilters();
    });
  }
  if (btnFilterReset){
    btnFilterReset.addEventListener('click', function(){
      Object.keys(filters).forEach(function(k){ filters[k]=''; });
      currentPage=1; refresh(); closeFilters();
    });
  }

  // Ordinamento
  var sortCol = null, sortDir = null;
  qsa('thead th.sortable').forEach(function(th){
    th.addEventListener('click', function(){
      var col = th.getAttribute('data-col');
      if (sortCol !== col){ sortCol = col; sortDir = 'asc'; }
      else if (sortDir === 'asc'){ sortDir = 'desc'; }
      else if (sortDir === 'desc'){ sortCol = null; sortDir = null; }
      else { sortDir = 'asc'; }
      qsa('[data-arrow]').forEach(function(a){ a.textContent=''; });
      if (sortCol && sortDir){
        var arrow = qs('[data-arrow="'+sortCol+'"]');
        if (arrow) arrow.textContent = (sortDir==='asc' ? '▲' : '▼');
      }
      currentPage=1; refresh();
    });
  });

  // Paginazione
  var PAGE_SIZE = 15;
  var pageInput = qs('#pageInput'), pageTotalSpan = qs('#pageTotal');
  var btnFirst = qs('#firstPage'), btnPrev = qs('#prevPage'), btnNext = qs('#nextPage'), btnLast = qs('#lastPage');
  var currentPage = 1, totalPages = 1;

  function goToInputPage(){
    var v = parseInt(pageInput.value,10); if (isNaN(v)) v=1;
    currentPage = clamp(v, 1, totalPages); refresh();
  }
  if (btnFirst) btnFirst.addEventListener('click', function(){ currentPage=1; refresh(); });
  if (btnPrev)  btnPrev .addEventListener('click', function(){ currentPage=clamp(currentPage-1,1,totalPages); refresh(); });
  if (btnNext)  btnNext .addEventListener('click', function(){ currentPage=clamp(currentPage+1,1,totalPages); refresh(); });
  if (btnLast)  btnLast .addEventListener('click', function(){ currentPage=totalPages; refresh(); });
  if (pageInput){
    pageInput.addEventListener('change', goToInputPage);
    pageInput.addEventListener('input', function(){
      var v = parseInt(pageInput.value,10); if (isNaN(v)) v=1;
      v = clamp(v, 1, Math.max(1,totalPages)); pageInput.value = String(v);
    });
    pageInput.addEventListener('keyup', function(e){ if (e.key==='Enter') goToInputPage(); });
  }

  function compareRows(a,b,col,dir){
    if (!col || !dir) return (parseInt(a.dataset.index,10) - parseInt(b.dataset.index,10));
    var av=a.dataset[col]||'', bv=b.dataset[col]||'';
    if (col==='id' || col==='quantita'){
      av=parseFloat(av||'0'); bv=parseFloat(bv||'0');
      return dir==='asc' ? av-bv : bv-av;
    }
    if (col==='data' || col==='scadenza'){
      if (av<bv) return dir==='asc' ? -1 : 1;
      if (av>bv) return dir==='asc' ?  1 : -1;
      return 0;
    }
    var cmp = String(av).localeCompare(String(bv));
    return dir==='asc' ? cmp : -cmp;
  }

  function rowMatches(tr){
    if (filters.id && String(tr.dataset.id)!==String(filters.id)) return false;
    if (filters.data && tr.dataset.data !== filters.data) return false;
    if (filters.scadenza && tr.dataset.scadenza !== filters.scadenza) return false;
    if (filters.lotto && !(tr.dataset.lotto||'').includes(filters.lotto)) return false;
    if (filters.prodotto && !(tr.dataset.prodotto||'').includes(filters.prodotto)) return false;
    if (filters.fornitore && !(tr.dataset.fornitore||'').includes(filters.fornitore)) return false;
    return true;
  }

  function refresh(){
    var allRows = qsa('#tbody tr');
    var sortedAll = allRows.slice().sort(function(a,b){ return compareRows(a,b,sortCol,sortDir); });
    var tb = qs('#tbody'); sortedAll.forEach(function(tr){ tb.appendChild(tr); });

    var filtered = sortedAll.filter(function(tr){ return rowMatches(tr); });
    allRows.forEach(function(tr){ tr.style.display='none'; });

    var maxPage = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    if (filtered.length === 0) { currentPage = 1; }
    else if ((currentPage - 1) * PAGE_SIZE >= filtered.length) { currentPage = 1; }
    totalPages = maxPage;

    var start = (currentPage-1)*PAGE_SIZE;
    filtered.slice(start, start+PAGE_SIZE).forEach(function(tr){ tr.style.display=''; });

    if (pageInput){ pageInput.max = String(totalPages); pageInput.value = String(currentPage); }
    if (pageTotalSpan) pageTotalSpan.textContent = '/ ' + totalPages;
    if (btnPrev)  btnPrev.disabled  = currentPage<=1;
    if (btnFirst) btnFirst.disabled = currentPage<=1;
    if (btnNext)  btnNext.disabled  = currentPage>=totalPages;
    if (btnLast)  btnLast.disabled  = currentPage>=totalPages;

    renderFilterChips();

    var emptyMsg = qs('#noRowsMsg');
    if (!emptyMsg){
      emptyMsg = document.createElement('div');
      emptyMsg.id = 'noRowsMsg';
      emptyMsg.style.cssText = 'display:none;padding:16px;text-align:center;color:#64748b';
      emptyMsg.textContent = 'Nessun risultato.';
      var wrap = qs('.table-wrap'); if (wrap) wrap.appendChild(emptyMsg);
    }
    emptyMsg.style.display = filtered.length ? 'none' : 'block';
  }

  function renderFilterChips(){
    var filtersBar = qs('#filtersBar'); if(!filtersBar) return;
    var parts=[];
    if (filters.id) parts.push(['id','ID: '+filters.id]);
    if (filters.data){ var a=filters.data.split('-'); parts.push(['data','Data: '+a[2]+'/'+a[1]+'/'+a[0]]); }
    if (filters.lotto) parts.push(['lotto','Lotto: '+filters.lotto]);
    if (filters.scadenza){ var b=filters.scadenza.split('-'); parts.push(['scadenza','Scadenza: '+b[2]+'/'+b[1]+'/'+b[0]]); }
    if (filters.prodotto) parts.push(['prodotto','Prodotto: '+filters.prodotto]);
    if (filters.fornitore) parts.push(['fornitore','Fornitore: '+filters.fornitore]);
    if (!parts.length){ filtersBar.style.display='none'; filtersBar.innerHTML=''; return; }
    filtersBar.style.display='flex';
    filtersBar.innerHTML = parts.map(function(p){ return '<span class="filter-chip"><strong>'+p[1]+'</strong> <button data-k="'+p[0]+'" title="Rimuovi"><i class="bi bi-x-circle"></i></button></span>'; }).join('');
    qsa('.filter-chip button[data-k]').forEach(function(btn){
      btn.addEventListener('click', function(){ filters[btn.getAttribute('data-k')] = ''; currentPage=1; refresh(); });
    });
  }

  // Iniziale
  refresh();
})();
</script>
</body>
</html>
