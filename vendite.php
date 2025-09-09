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
        // Aggiungi rapido cliente
        if ($_POST['action'] === 'quick_add_cliente') {
            $nome = trim((string)($_POST['nome'] ?? ''));
            if ($nome === '') throw new RuntimeException('Il nome cliente è obbligatorio.');
            $stmt = $pdo->prepare('INSERT INTO cliente (nome) VALUES (:n)');
            $stmt->execute([':n'=>$nome]);
            echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId(), 'nome'=>$nome]); exit;
        }

        // Lotti per prodotto: DISTINCT lotti che compaiono negli acquisti del prodotto scelto
        if ($_POST['action'] === 'list_lotti_by_prodotto') {
            $prodId = (int)($_POST['prodotto'] ?? 0);
            if ($prodId <= 0) throw new RuntimeException('Prodotto non valido.');
            $stmt = $pdo->prepare('SELECT DISTINCT l.id, l.lotto
                                   FROM acquisto a
                                   JOIN lotto l ON l.id = a.lotto_id
                                   WHERE a.prodotto = :p
                                   ORDER BY l.lotto ASC');
            $stmt->execute([':p'=>$prodId]);
            $rows = $stmt->fetchAll();
            echo json_encode(['ok'=>true,'lotti'=>$rows]); exit;
        }

        // Dettagli vendita per edit
        if ($_POST['action'] === 'get_vendita') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id<=0) throw new RuntimeException('ID non valido.');
            // prendo vendita + cliente, e provo a inferire il prodotto dal lotto
            $stmt = $pdo->prepare('SELECT v.id, v.datavendita, v.cliente, v.lotto, v.quantita,
                                          (SELECT a.prodotto
                                           FROM acquisto a
                                           JOIN lotto l ON l.id=a.lotto_id
                                           WHERE l.lotto = v.lotto
                                           ORDER BY a.id DESC LIMIT 1) AS prodotto_id
                                   FROM vendita v
                                   WHERE v.id=:id');
            $stmt->execute([':id'=>$id]);
            $row = $stmt->fetch();
            if (!$row) throw new RuntimeException('Vendita non trovata.');
            echo json_encode(['ok'=>true,'data'=>$row]); exit;
        }

        // Salvataggio vendita (insert/update) con aggiornamento giacenza
        if ($_POST['action'] === 'save_vendita') {
            $id        = isset($_POST['id']) && $_POST['id']!=='' ? (int)$_POST['id'] : null;
            $data      = trim((string)($_POST['datavendita'] ?? ''));
            $cliente   = (int)($_POST['cliente'] ?? 0);
            $prodotto  = (int)($_POST['prodotto'] ?? 0); // usato per giacenza e per lotti dipendenti
            $lottoId   = (int)($_POST['lotto_id'] ?? 0); // select dei lotti è sull'id lotto
            $quantita  = (int)($_POST['quantita'] ?? 0);

            if ($data==='' || $cliente<=0 || $prodotto<=0 || $lottoId<=0 || $quantita<=0) {
                throw new RuntimeException('Compila tutti i campi obbligatori.');
            }

            // Risolvi nome lotto (stringa) da salvare nella tabella vendita
            $lottoSel = $pdo->prepare('SELECT lotto FROM lotto WHERE id=:id');
            $lottoSel->execute([':id'=>$lottoId]);
            $lottoNome = $lottoSel->fetchColumn();
            if (!$lottoNome) throw new RuntimeException('Lotto non valido.');

            $pdo->beginTransaction();
            try {
                if ($id === null) {
                    // Controllo stock sufficiente
                    $g = (int)$pdo->query('SELECT giacenza FROM prodotto WHERE id='.(int)$prodotto.' LIMIT 1')->fetchColumn();
                    if ($g < $quantita) throw new RuntimeException('Giacenza insufficiente per il prodotto selezionato.');

                    $ins = $pdo->prepare('INSERT INTO vendita (datavendita, cliente, lotto, quantita)
                                           VALUES (:d,:c,:l,:q)');
                    $ins->execute([':d'=>$data, ':c'=>$cliente, ':l'=>$lottoNome, ':q'=>$quantita]);
                    $id = (int)$pdo->lastInsertId();

                    // Aggiorna giacenza (-)
                    $upd = $pdo->prepare('UPDATE prodotto SET giacenza = giacenza - :q WHERE id=:p');
                    $upd->execute([':q'=>$quantita, ':p'=>$prodotto]);

                    $op = 'added';
                } else {
                    // Carica vendita corrente per calcolare delta su giacenze
                    $cur = $pdo->prepare('SELECT datavendita, cliente, lotto, quantita FROM vendita WHERE id=:id');
                    $cur->execute([':id'=>$id]);
                    $old = $cur->fetch();
                    if (!$old) throw new RuntimeException('Vendita inesistente.');

                    // Prodotto precedente dedotto dal lotto precedente
                    $oldProd = $pdo->prepare('SELECT a.prodotto FROM acquisto a JOIN lotto l ON l.id=a.lotto_id WHERE l.lotto=:lotto ORDER BY a.id DESC LIMIT 1');
                    $oldProd->execute([':lotto'=>$old['lotto']]);
                    $oldProdId = (int)$oldProd->fetchColumn();

                    // Revert vecchia giacenza + applica nuova
                    if ($oldProdId>0) {
                        $pdo->prepare('UPDATE prodotto SET giacenza = giacenza + :q WHERE id=:p')
                            ->execute([':q'=>(int)$old['quantita'], ':p'=>$oldProdId]);
                    }
                    // Controlla stock nuovo
                    $g2 = (int)$pdo->query('SELECT giacenza FROM prodotto WHERE id='.(int)$prodotto.' LIMIT 1')->fetchColumn();
                    if ($g2 < $quantita) throw new RuntimeException('Giacenza insufficiente per il prodotto selezionato.');

                    // Applica nuova
                    $pdo->prepare('UPDATE prodotto SET giacenza = giacenza - :q WHERE id=:p')
                        ->execute([':q'=>$quantita, ':p'=>$prodotto]);

                    // Aggiorna riga vendita
                    $upd = $pdo->prepare('UPDATE vendita SET datavendita=:d, cliente=:c, lotto=:l, quantita=:q WHERE id=:id');
                    $upd->execute([':d'=>$data, ':c'=>$cliente, ':l'=>$lottoNome, ':q'=>$quantita, ':id'=>$id]);
                    $op = 'updated';
                }

                $pdo->commit();
                echo json_encode(['ok'=>true,'op'=>$op]); exit;
            } catch (PDOException $ee) {
                $pdo->rollBack();
                throw $ee;
            }
        }

        // Elimina vendita (e ripristina giacenza)
        if ($_POST['action'] === 'delete_vendita') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id<=0) throw new RuntimeException('ID non valido.');

            $pdo->beginTransaction();
            try {
                $cur = $pdo->prepare('SELECT lotto, quantita FROM vendita WHERE id=:id');
                $cur->execute([':id'=>$id]);
                $row = $cur->fetch();
                if ($row) {
                    // Prodotto dedotto dal lotto
                    $oldProd = $pdo->prepare('SELECT a.prodotto FROM acquisto a JOIN lotto l ON l.id=a.lotto_id WHERE l.lotto=:lotto ORDER BY a.id DESC LIMIT 1');
                    $oldProd->execute([':lotto'=>$row['lotto']]);
                    $oldProdId = (int)$oldProd->fetchColumn();
                    if ($oldProdId>0) {
                        $pdo->prepare('UPDATE prodotto SET giacenza = giacenza + :q WHERE id=:p')
                            ->execute([':q'=>(int)$row['quantita'], ':p'=>$oldProdId]);
                    }
                }
                $del = $pdo->prepare('DELETE FROM vendita WHERE id=:id');
                $del->execute([':id'=>$id]);
                $pdo->commit();
                echo json_encode(['ok'=>true,'op'=>'deleted']); exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
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
   QUERY LISTE (tabella vendite)
   ========================= */
function fmtDate(?string $iso): string {
    if (!$iso) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    return $dt ? $dt->format('d/m/Y') : htmlspecialchars($iso);
}
try {
    // Elenco vendite con dati cliente + prodotto (inferito)
    $vendite = $pdo->query('SELECT v.id, v.datavendita, v.lotto, v.quantita,
                                   c.nome AS cliente,
                                   (SELECT p.nome FROM prodotto p WHERE p.id = (
                                       SELECT a.prodotto FROM acquisto a JOIN lotto l ON l.id=a.lotto_id WHERE l.lotto=v.lotto ORDER BY a.id DESC LIMIT 1
                                   )) AS prodotto,
                                   (SELECT a.prodotto FROM acquisto a JOIN lotto l ON l.id=a.lotto_id WHERE l.lotto=v.lotto ORDER BY a.id DESC LIMIT 1) AS prodotto_id
                            FROM vendita v
                            LEFT JOIN cliente c ON c.id=v.cliente
                            ORDER BY v.datavendita DESC, v.id DESC')->fetchAll();
    $prodotti = $pdo->query('SELECT id,nome,unita,giacenza FROM prodotto ORDER BY nome')->fetchAll();
    $clienti  = $pdo->query('SELECT id,nome FROM cliente ORDER BY nome')->fetchAll();
} catch (Throwable $e) {
    $vendite=$prodotti=$clienti=[];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vendite</title>
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

.header{display:flex;gap:10px;align-items:center;justify-content:space-between;margin:8px 0 16px}
.title{font-size:22px;font-weight:700}
.header-actions{display:flex;gap:10px;align-items:center}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;border:1px solid var(--border);background:#fff;font-weight:600;cursor:pointer;box-shadow:var(--shadow);transition:transform .05s,box-shadow .15s,border-color .15s}
.btn:hover{box-shadow:0 10px 28px rgba(15,23,42,.12);border-color:#cbd5e1}
.btn:active{transform:translateY(1px)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.btn.primary:hover{background:var(--accent-hover);border-color:var(--accent-hover)}
.btn.icon{width:42px;height:42px;justify-content:center;border-radius:999px}

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
th.col-cliente{min-width:220px}
th.col-quant{width:140px}
th.col-actions{width:160px}
td.actions .actions-group{display:flex;gap:10px;justify-content:center}

.icon-btn{width:38px;height:38px;border-radius:999px;background:#fff;border:1px solid var(--border);display:inline-grid;place-items:center;cursor:pointer;transition:box-shadow .15s,border-color .15s, background .15s, color .15s, transform .05s}
.icon-btn:hover{box-shadow:0 8px 20px rgba(15,23,42,.14);border-color:#cbd5e1}
.icon-btn.edit:hover{background:var(--edit-weak);color:var(--edit)}
.icon-btn.del:hover{background:var(--del-weak);color:var(--del)}
.icon-btn.info:hover{background:var(--info-weak);color:var(--info)}
.icon-btn:active{transform:translateY(1px)}

.filters-bar{display:none;flex-wrap:wrap;gap:8px;padding:10px 14px;border-bottom:1px solid var(--border);background:#fcfdff}
.filter-chip{display:inline-flex;gap:6px;align-items:center;padding:6px 10px;border-radius:999px;background:#eef2ff;border:1px solid #e0e7ff;font-size:12px}
.filter-chip button{background:transparent;border:0;cursor:pointer;color:#475569}

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
.field input:focus, .field select:focus{border-color:#c7d2fe; box-shadow:0 0 0 4px #e0e7ff}
.modal-footer{padding:16px 18px; display:flex; gap:10px; justify-content:flex-end; border-top:1px solid var(--border)}

/* Mini modale cliente */
.mini-modal{border:1px solid var(--border); background:#fff; border-radius:14px; padding:14px; box-shadow:var(--shadow); width:100%; max-width:380px}
.mini-modal .field{margin-bottom:10px}
.mini-actions{display:flex; gap:10px; justify-content:flex-end}

/* Modal filtri */
.filter-modal{border:1px solid var(--border); background:#fff; border-radius:14px; padding:16px; box-shadow:var(--shadow); width:100%; max-width:720px}
.filters-grid{display:grid; grid-template-columns:repeat(3, 1fr); gap:12px}
.filter-actions{display:flex; gap:8px; justify-content:flex-end; margin-top:8px}

.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(20px);background:var(--success-bg);color:var(--success-text);border:1px solid var(--success-border);border-radius:12px;padding:12px 16px;display:flex;align-items:center;gap:10px;opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;z-index:60;box-shadow:var(--shadow)}
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
    <div class="title">Vendite</div>
    <div class="header-actions">
      <button class="btn icon" id="btnFilters" title="Filtri"><i class="bi bi-funnel-fill"></i></button>
      <button class="btn primary" id="btnNew"><i class="bi bi-plus-circle-fill"></i> Nuova Vendita</button>
    </div>
  </div>

  <div class="card">
    <div class="filters-bar" id="filtersBar"></div>

    <div class="table-wrap">
      <table class="table" id="venTable">
        <thead>
          <tr>
            <th class="col-id sortable" data-col="id"><span class="label"># <span class="arrow" data-arrow="id"></span></span></th>
            <th class="sortable" data-col="data"><span class="label">Data <span class="arrow" data-arrow="data"></span></span></th>
            <th class="col-quant sortable" data-col="quantita"><span class="label">Quantità <span class="arrow" data-arrow="quantita"></span></span></th>
            <th class="col-prod sortable" data-col="prodotto"><span class="label">Prodotto <span class="arrow" data-arrow="prodotto"></span></span></th>
            <th class="col-lotto sortable" data-col="lotto"><span class="label">Lotto <span class="arrow" data-arrow="lotto"></span></span></th>
            <th class="col-cliente sortable" data-col="cliente"><span class="label">Cliente <span class="arrow" data-arrow="cliente"></span></span></th>
            <th class="col-actions">Azioni</th>
          </tr>
        </thead>
        <tbody id="tbody">
          <?php $idx=0; foreach($vendite as $v): $idx++; ?>
            <tr
              data-index="<?= $idx ?>"
              data-id="<?= (int)$v['id'] ?>"
              data-data="<?= htmlspecialchars($v['datavendita'], ENT_QUOTES) ?>"
              data-quantita="<?= (int)$v['quantita'] ?>"
              data-prodotto="<?= htmlspecialchars(mb_strtolower($v['prodotto']??''), ENT_QUOTES) ?>"
              data-lotto="<?= htmlspecialchars(mb_strtolower($v['lotto']), ENT_QUOTES) ?>"
              data-cliente="<?= htmlspecialchars(mb_strtolower($v['cliente']??''), ENT_QUOTES) ?>"
            >
              <td class="col-id"><?= (int)$v['id'] ?></td>
              <td><?= fmtDate($v['datavendita']) ?></td>
              <td><?= (int)$v['quantita'] ?></td>
              <td><?= htmlspecialchars($v['prodotto'] ?? '-') ?></td>
              <td><?= htmlspecialchars($v['lotto']) ?></td>
              <td><?= htmlspecialchars($v['cliente'] ?? '-') ?></td>
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

<!-- Modal principale Vendita -->
<div class="modal-backdrop" id="modalBackdrop" aria-hidden="true" style="display:none">
  <div class="modal-wrapper">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
      <div class="modal-header">
        <div class="modal-title" id="modalTitle">Nuova Vendita</div>
        <button class="icon-btn info" id="btnCloseModal" aria-label="Chiudi"><i class="bi bi-x-circle-fill"></i></button>
      </div>
      <form id="venForm" class="modal-body">
        <input type="hidden" name="action" value="save_vendita">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="id" id="venId">

        <div class="form-grid">
          <div class="field">
            <label for="dataVen">Data vendita *</label>
            <input type="date" id="dataVen" name="datavendita" required>
          </div>

          <div class="field">
            <label for="cliente">Cliente *</label>
            <select id="cliente" name="cliente" required>
              <option value="">— Seleziona —</option>
              <?php foreach($clienti as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
              <?php endforeach; ?>
              <option value="__new_cli__">+ Aggiungi nuovo cliente…</option>
            </select>
          </div>

          <div class="field">
            <label for="prodotto">Prodotto *</label>
            <select id="prodotto" name="prodotto" required>
              <option value="">— Seleziona —</option>
              <?php foreach($prodotti as $p): ?>
                <option value="<?= (int)$p['id'] ?>" data-giacenza="<?= (int)$p['giacenza'] ?>">
                  <?= htmlspecialchars($p['nome']) ?> (<?= $p['unita']==='kg'?'kg':'sacco' ?>) — Giacenza: <?= (int)$p['giacenza'] ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="lotto">Lotto *</label>
            <select id="lotto" name="lotto_id" required disabled>
              <option value="">— Seleziona prodotto prima —</option>
            </select>
          </div>

          <div class="field">
            <label for="quantita">Quantità *</label>
            <input type="number" id="quantita" name="quantita" min="1" required>
          </div>

          <div class="field">
            <label>Giacenza attuale</label>
            <input type="text" id="giacenzaView" disabled placeholder="—" aria-disabled="true">
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

<!-- Mini modal: aggiungi cliente -->
<div class="modal-backdrop" id="miniCliBackdrop" style="display:none">
  <div class="modal-wrapper">
    <div class="mini-modal">
      <div class="field"><label>Nome cliente *</label><input type="text" id="miniCliNome" placeholder="Es. Mario Rossi"></div>
      <div class="mini-actions"><button class="btn" id="miniCliCancel"><i class="bi bi-x-circle-fill"></i> Annulla</button><button class="btn primary" id="miniCliSave"><i class="bi bi-check-circle-fill"></i> Salva</button></div>
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
        <div class="field"><label>Quantità</label><input type="number" id="flt_quantita" min="1"></div>
        <div class="field"><label>Prodotto</label><input type="text" id="flt_prodotto" placeholder="Ricerca testo"></div>
        <div class="field"><label>Lotto</label><input type="text" id="flt_lotto" placeholder="Ricerca testo"></div>
        <div class="field"><label>Cliente</label><input type="text" id="flt_cliente" placeholder="Ricerca testo"></div>
      </div>
      <div class="filter-actions">
        <button class="btn" id="btnFilterReset"><i class="bi bi-x-circle"></i> Pulisci</button>
        <button class="btn primary" id="btnFilterApply"><i class="bi bi-check-circle"></i> Applica</button>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"><i class="bi bi-check-circle-fill"></i><span id="toastMsg">Operazione completata</span></div>

<script>
(function(){
  if (window.__VENDITE_INIT__) return; window.__VENDITE_INIT__ = true;
  function qs(sel){ return document.querySelector(sel); }
  function qsa(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); }
  function todayISO(){ const d=new Date(); const m=('0'+(d.getMonth()+1)).slice(-2), day=('0'+d.getDate()).slice(-2); return d.getFullYear()+'-'+m+'-'+day; }
  function clamp(v,min,max){ return Math.max(min, Math.min(max, v)); }

  var tbody = qs('#tbody');

  // Modale principale
  var modalBackdrop = qs('#modalBackdrop');
  var modal = modalBackdrop ? modalBackdrop.querySelector('.modal') : null;
  var venForm = qs('#venForm');
  var venId = qs('#venId');
  var inputData = qs('#dataVen');
  var selCliente = qs('#cliente');
  var selProd = qs('#prodotto');
  var selLotto = qs('#lotto');
  var inputQta = qs('#quantita');
  var giacView = qs('#giacenzaView');

  function openModal(mode, data){
    if (!modalBackdrop || !modal) return;
    qs('#modalTitle').textContent = mode==='edit' ? 'Modifica Vendita' : 'Nuova Vendita';

    if (mode==='edit' && data){
      venId.value = data.id || '';
      inputData.value = data.data || todayISO();
      inputQta.value = data.quantita || '';

      // Precarica dati server (cliente, prodotto inferito, lotto)
      var fd = new FormData();
      fd.append('action','get_vendita');
      fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
      fd.append('id', data.id);
      fetch(location.href, {method:'POST', body:fd, credentials:'same-origin'})
        .then(r=>r.json())
        .then(function(j){
          if (!j.ok) return alert(j.message||'Errore');
          if (j.data){
            if (j.data.cliente) selCliente.value = String(j.data.cliente);
            var prodId = j.data.prodotto_id ? String(j.data.prodotto_id) : '';
            if (prodId){
              selProd.value = prodId; refreshGiacenza();
              // carica lotti del prodotto poi seleziona il lotto
              loadLottiForProduct(prodId).then(function(){
                // trova nel select il valore con testo j.data.lotto
                var opt = Array.from(selLotto.options).find(o=>o.textContent===j.data.lotto);
                if (opt) selLotto.value = opt.value;
              });
            } else {
              // nessun prodotto inferito => reset lotti
              selProd.value = '';
              selLotto.innerHTML = '<option value="">— Seleziona prodotto prima —</option>';
              selLotto.disabled = true;
              giacView.value = '';
            }
          }
        });
    } else {
      venId.value = '';
      inputData.value = todayISO();
      inputQta.value = '';
      selCliente.value = '';
      selProd.value = '';
      selLotto.innerHTML = '<option value="">— Seleziona prodotto prima —</option>';
      selLotto.disabled = true;
      giacView.value = '';
    }

    document.body.classList.add('modal-open');
    modalBackdrop.style.display='block';
    requestAnimationFrame(function(){ modal.classList.add('visible'); });
    inputData && inputData.focus();
  }
  function closeModal(){ if (!modalBackdrop || !modal) return; modal.classList.remove('visible'); setTimeout(function(){ modalBackdrop.style.display='none'; document.body.classList.remove('modal-open'); },150); }

  document.addEventListener('click', function(e){
    var t=e.target;
    if (t.closest('#btnNew')) openModal('new');
    if (t.closest('#btnCloseModal')) closeModal();
    if (t.closest('#btnCancel')) closeModal();
  });
  if (modalBackdrop){ modalBackdrop.addEventListener('click', function(e){ if (e.target===modalBackdrop) closeModal(); }); }

  // ESC chiude TUTTE le modali (principale, mini, filtri)
  window.addEventListener('keydown', function(e){
    if (e.key==='Escape') {
      ['modalBackdrop','miniCliBackdrop','filterBackdrop'].forEach(function(id){
        var el = document.getElementById(id); if (el && el.style.display==='block') el.click();
      });
      // chiude anche se visibile
      if (modalBackdrop && modalBackdrop.style.display==='block') closeModal();
      if (filterBackdrop && filterBackdrop.style.display==='block') closeFilters();
      if (miniCliBackdrop && miniCliBackdrop.style.display==='block') miniCliBackdrop.style.display='none';
    }
  });

  // Gestione dipendenza Prodotto -> Lotti
  function refreshGiacenza(){
    var opt = selProd.options[selProd.selectedIndex];
    if (opt && opt.dataset.giacenza!=null) giacView.value = opt.dataset.giacenza;
    else giacView.value = '';
  }
  function loadLottiForProduct(prodId){
    selLotto.disabled = true; selLotto.innerHTML = '<option value="">Caricamento…</option>';
    var fd = new FormData();
    fd.append('action','list_lotti_by_prodotto');
    fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
    fd.append('prodotto', prodId);
    return fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'})
      .then(r=>r.json())
      .then(function(j){
        if (!j.ok) throw new Error(j.message||'Errore');
        var opts = ['<option value="">— Seleziona —</option>'];
        (j.lotti||[]).forEach(function(l){ opts.push('<option value="'+l.id+'">'+l.lotto+'</option>'); });
        selLotto.innerHTML = opts.join('');
        selLotto.disabled = false;
      })
      .catch(function(err){ selLotto.innerHTML = '<option value="">Nessun lotto disponibile</option>'; selLotto.disabled=true; });
  }
  selProd && selProd.addEventListener('change', function(){
    refreshGiacenza();
    if (selProd.value){ loadLottiForProduct(selProd.value); }
    else { selLotto.innerHTML = '<option value="">— Seleziona prodotto prima —</option>'; selLotto.disabled=true; giacView.value=''; }
  });

  // Mini modale cliente (quick add)
  var miniCliBackdrop = qs('#miniCliBackdrop');
  var miniCliNome = qs('#miniCliNome');
  document.addEventListener('change', function(e){
    if (e.target.id==='cliente' && e.target.value==='__new_cli__'){
      selCliente.value=''; if (miniCliBackdrop){ miniCliBackdrop.style.display='block'; miniCliNome && miniCliNome.focus(); }
    }
  });
  document.addEventListener('click', function(e){
    var t=e.target;
    if (t.closest('#miniCliCancel')){ if (miniCliBackdrop) miniCliBackdrop.style.display='none'; }
    if (t.closest('#miniCliSave')){
      var nome=(miniCliNome && miniCliNome.value.trim())||''; if (!nome){ miniCliNome && miniCliNome.focus(); return; }
      var fd=new FormData(); fd.append('action','quick_add_cliente'); fd.append('csrf_token', <?= json_encode($csrfToken) ?>); fd.append('nome', nome);
      fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'})
        .then(r=>r.json()).then(function(j){
          if (j.ok){ var opt=new Option(j.nome, j.id, true, true); selCliente.add(opt); if (miniCliBackdrop) miniCliBackdrop.style.display='none'; }
          else alert(j.message||'Errore');
        });
    }
  });
  if (miniCliBackdrop){ miniCliBackdrop.addEventListener('click', function(e){ if (e.target===miniCliBackdrop) miniCliBackdrop.style.display='none'; }); }

  // Toast
  var toast=qs('#toast'), toastMsg=qs('#toastMsg');
  function showToast(message){ if(!toast||!toastMsg)return; toastMsg.textContent=message||'Operazione completata'; toast.classList.add('visible'); setTimeout(function(){ toast.classList.remove('visible'); },2000); }
  (function(){ var p=new URLSearchParams(location.search); if(p.get('success')==='1'){ var op=p.get('op'); var msg='Operazione completata'; if(op==='added') msg='Vendita aggiunta'; if(op==='updated') msg='Vendita aggiornata'; if(op==='deleted') msg='Vendita eliminata'; setTimeout(function(){ showToast(msg); var u=new URL(location.href); u.searchParams.delete('success'); u.searchParams.delete('op'); history.replaceState({},'',u); },60);} })();

  // Edit/Delete/doppio click
  if (tbody){
    tbody.addEventListener('click', function(e){
      var tr=e.target.closest('tr'); if(!tr) return;
      if (e.target.closest('.btn-edit')) {
        openModal('edit', { id:tr.dataset.id, data:tr.dataset.data, quantita:tr.dataset.quantita });
      }
      if (e.target.closest('.btn-del')) {
        if(!confirm('Confermi l\'eliminazione della vendita lotto "'+(tr.dataset.lotto||'')+'"?')) return;
        var fd=new FormData(); fd.append('action','delete_vendita'); fd.append('csrf_token', <?= json_encode($csrfToken) ?>); fd.append('id', tr.dataset.id);
        fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'})
          .then(r=>r.json()).then(function(j){ if(j.ok){ var url=new URL(location.href); url.searchParams.set('success','1'); url.searchParams.set('op', j.op||'deleted'); location.href=url; } else alert(j.message||'Errore'); });
      }
    });
    tbody.addEventListener('dblclick', function(e){ var tr=e.target.closest('tr'); if(!tr) return; openModal('edit', { id:tr.dataset.id, data:tr.dataset.data, quantita:tr.dataset.quantita }); });
  }

  // Salva vendita
  document.addEventListener('click', function(e){
    if (!e.target.closest('#btnSave')) return; e.preventDefault();
    if (!inputData.value){ inputData.focus(); return; }
    if (!selCliente.value){ selCliente.focus(); return; }
    if (!selProd.value){ selProd.focus(); return; }
    if (!selLotto.value){ selLotto.focus(); return; }
    if (!inputQta.value || +inputQta.value<=0){ inputQta.focus(); return; }
    var fd=new FormData(venForm);
    fetch(location.href,{method:'POST',body:fd,credentials:'same-origin'})
      .then(r=>r.json())
      .then(function(j){ if(j.ok){ var url=new URL(location.href); url.searchParams.set('success','1'); url.searchParams.set('op', j.op||'added'); location.href=url; } else alert(j.message||'Errore'); });
  });

  // Filtri
  var filterBackdrop = qs('#filterBackdrop');
  var btnFilters = qs('#btnFilters'), btnFilterClose = qs('#btnFilterClose');
  var btnFilterApply = qs('#btnFilterApply'), btnFilterReset = qs('#btnFilterReset');
  var filtersBar = qs('#filtersBar');
  var filters = { id:'', data:'', quantita:'', prodotto:'', lotto:'', cliente:'' };
  var inputs = { id:qs('#flt_id'), data:qs('#flt_data'), quantita:qs('#flt_quantita'), prodotto:qs('#flt_prodotto'), lotto:qs('#flt_lotto'), cliente:qs('#flt_cliente') };

  function openFilters(){ if(!filterBackdrop) return; Object.keys(inputs).forEach(function(k){ if(inputs[k]) inputs[k].value = filters[k] || ''; }); document.body.classList.add('modal-open'); filterBackdrop.style.display='block'; }
  function closeFilters(){ if(!filterBackdrop) return; filterBackdrop.style.display='none'; document.body.classList.remove('modal-open'); }
  if (btnFilters) btnFilters.addEventListener('click', openFilters);
  if (btnFilterClose) btnFilterClose.addEventListener('click', closeFilters);
  if (filterBackdrop){ filterBackdrop.addEventListener('click', function(e){ if(e.target===filterBackdrop) closeFilters(); }); }

  function applyFiltersFromInputs(){
    filters.id        = inputs.id && inputs.id.value.trim() || '';
    filters.data      = inputs.data && inputs.data.value.trim() || '';
    filters.quantita  = inputs.quantita && inputs.quantita.value.trim() || '';
    filters.prodotto  = inputs.prodotto && inputs.prodotto.value.trim().toLowerCase() || '';
    filters.lotto     = inputs.lotto && inputs.lotto.value.trim().toLowerCase() || '';
    filters.cliente   = inputs.cliente && inputs.cliente.value.trim().toLowerCase() || '';
  }

  function rowMatches(tr){
    if (filters.id && String(tr.dataset.id)!==String(filters.id)) return false;
    if (filters.data && tr.dataset.data !== filters.data) return false;
    if (filters.quantita && String(tr.dataset.quantita)!==String(filters.quantita)) return false;
    if (filters.prodotto && !(tr.dataset.prodotto||'').includes(filters.prodotto)) return false;
    if (filters.lotto && !(tr.dataset.lotto||'').includes(filters.lotto)) return false;
    if (filters.cliente && !(tr.dataset.cliente||'').includes(filters.cliente)) return false;
    return true;
  }

  function renderFilterChips(){
    if (!filtersBar) return;
    var parts=[]; if (filters.id) parts.push(['id','ID: '+filters.id]);
    if (filters.data){ var a=filters.data.split('-'); parts.push(['data','Data: '+a[2]+'/'+a[1]+'/'+a[0]]); }
    if (filters.quantita) parts.push(['quantita','Quantità: '+filters.quantita]);
    if (filters.prodotto) parts.push(['prodotto','Prodotto: '+filters.prodotto]);
    if (filters.lotto) parts.push(['lotto','Lotto: '+filters.lotto]);
    if (filters.cliente) parts.push(['cliente','Cliente: '+filters.cliente]);
    if (!parts.length){ filtersBar.style.display='none'; filtersBar.innerHTML=''; return; }
    filtersBar.style.display='flex';
    filtersBar.innerHTML = parts.map(function(p){ return '<span class="filter-chip"><strong>'+p[1]+'</strong> <button data-k="'+p[0]+'" title="Rimuovi"><i class="bi bi-x-circle"></i></button></span>'; }).join('');
    qsa('.filter-chip button[data-k]').forEach(function(btn){ btn.addEventListener('click', function(){ filters[btn.getAttribute('data-k')] = ''; currentPage=1; refresh(); }); });
  }

  if (btnFilterApply){ btnFilterApply.addEventListener('click', function(){ applyFiltersFromInputs(); currentPage=1; refresh(); closeFilters(); }); }
  if (btnFilterReset){ btnFilterReset.addEventListener('click', function(){ Object.keys(filters).forEach(function(k){ filters[k]=''; }); currentPage=1; refresh(); closeFilters(); }); }

  // Ordinamento
  var sortCol = null, sortDir = null;
  qsa('thead th.sortable').forEach(function(th){ th.addEventListener('click', function(){ var col=th.getAttribute('data-col'); if (sortCol!==col){ sortCol=col; sortDir='asc'; } else if (sortDir==='asc'){ sortDir='desc'; } else if (sortDir==='desc'){ sortCol=null; sortDir=null; } else { sortDir='asc'; } qsa('[data-arrow]').forEach(function(a){ a.textContent=''; }); if (sortCol && sortDir){ var arrow=qs('[data-arrow="'+sortCol+'"]'); if (arrow) arrow.textContent = (sortDir==='asc'?'▲':'▼'); } currentPage=1; refresh(); }); });

  // Paginazione
  var PAGE_SIZE = 15;
  var pageInput = qs('#pageInput'), pageTotalSpan=qs('#pageTotal');
  var btnFirst=qs('#firstPage'), btnPrev=qs('#prevPage'), btnNext=qs('#nextPage'), btnLast=qs('#lastPage');
  var currentPage=1, totalPages=1;
  function goToInputPage(){ var v=parseInt(pageInput.value,10); if(isNaN(v)) v=1; currentPage = clamp(v,1,totalPages); refresh(); }
  if (btnFirst) btnFirst.addEventListener('click', function(){ currentPage=1; refresh(); });
  if (btnPrev)  btnPrev .addEventListener('click', function(){ currentPage=clamp(currentPage-1,1,totalPages); refresh(); });
  if (btnNext)  btnNext .addEventListener('click', function(){ currentPage=clamp(currentPage+1,1,totalPages); refresh(); });
  if (btnLast)  btnLast.addEventListener('click', function(){ currentPage=totalPages; refresh(); });
  if (pageInput){ pageInput.addEventListener('change', goToInputPage); pageInput.addEventListener('input', function(){ var v=parseInt(pageInput.value,10); if(isNaN(v)) v=1; v=clamp(v,1,Math.max(1,totalPages)); pageInput.value=String(v); }); pageInput.addEventListener('keyup', function(e){ if(e.key==='Enter') goToInputPage(); }); }

  function compareRows(a,b,col,dir){
    if (!col || !dir) return (parseInt(a.dataset.index,10) - parseInt(b.dataset.index,10));
    var av=a.dataset[col]||'', bv=b.dataset[col]||'';
    if (col==='id' || col==='quantita'){ av=parseFloat(av||'0'); bv=parseFloat(bv||'0'); return dir==='asc' ? av-bv : bv-av; }
    if (col==='data'){ if (av<bv) return dir==='asc'?-1:1; if (av>bv) return dir==='asc'?1:-1; return 0; }
    var cmp=String(av).localeCompare(String(bv)); return dir==='asc'?cmp:-cmp;
  }

  function refresh(){
    var allRows = qsa('#tbody tr');
    var sortedAll = allRows.slice().sort(function(a,b){ return compareRows(a,b,sortCol,sortDir); });
    var tb = qs('#tbody'); sortedAll.forEach(function(tr){ tb.appendChild(tr); });

    var filtered = sortedAll.filter(function(tr){ return rowMatches(tr); });
    allRows.forEach(function(tr){ tr.style.display='none'; });

    var maxPage = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    if (filtered.length===0){ currentPage=1; }
    else if ((currentPage-1)*PAGE_SIZE >= filtered.length){ currentPage=1; }
    totalPages = maxPage;

    var start=(currentPage-1)*PAGE_SIZE; filtered.slice(start, start+PAGE_SIZE).forEach(function(tr){ tr.style.display=''; });

    if (pageInput){ pageInput.max=String(totalPages); pageInput.value=String(currentPage); }
    if (pageTotalSpan) pageTotalSpan.textContent = '/ '+totalPages;
    if (btnPrev)  btnPrev.disabled  = currentPage<=1;
    if (btnFirst) btnFirst.disabled = currentPage<=1;
    if (btnNext)  btnNext.disabled  = currentPage>=totalPages;
    if (btnLast)  btnLast.disabled  = currentPage>=totalPages;

    renderFilterChips();

    var emptyMsg = qs('#noRowsMsg');
    if (!emptyMsg){ emptyMsg=document.createElement('div'); emptyMsg.id='noRowsMsg'; emptyMsg.style.cssText='display:none;padding:16px;text-align:center;color:#64748b'; emptyMsg.textContent='Nessun risultato.'; var wrap=qs('.table-wrap'); if(wrap) wrap.appendChild(emptyMsg); }
    emptyMsg.style.display = filtered.length ? 'none':'block';
  }

  // Iniziale
  refresh();
})();
</script>
</body>
</html>
