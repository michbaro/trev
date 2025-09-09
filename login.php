<?php
/**
 * LOGIN "TREV"
 *
 * Tabella consigliata (MariaDB/MySQL):
 *
 * CREATE TABLE `utente` (
 *   `id` INT NOT NULL AUTO_INCREMENT,
 *   `username` VARCHAR(100) NOT NULL UNIQUE,
 *   `password` VARCHAR(255) NOT NULL, -- contiene l'hash (PASSWORD_HASH)
 *   `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *   PRIMARY KEY (`id`),
 *   KEY `idx_username` (`username`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
 *
 * Esempio per creare un utente (da PHP una tantum):
 *   $hash = password_hash('ScegliUnaPasswordForte!', PASSWORD_DEFAULT);
 *   INSERT INTO utente (username, password) VALUES ('admin', '$hash');
 */

declare(strict_types=1);
require_once __DIR__ . '/init.php';

// Già loggato? porta alla home (cambia destinazione se preferisci)
if (!empty($_SESSION['user'])) {
    header('Location: /trev/acquisti.php');
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica CSRF
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        $err = 'Sessione scaduta. Ricarica la pagina.';
    } else {
        $user = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');

        if ($user === '' || $pass === '') {
            $err = 'Inserisci username e password.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT id, username, password FROM utente WHERE username = :u LIMIT 1');
                $stmt->execute([':u' => $user]);
                $row = $stmt->fetch();

                // Confronto costante + password_verify
                if ($row && hash_equals($row['username'], $user) && password_verify($pass, $row['password'])) {
                    // (opzionale) rigenera session id
                    session_regenerate_id(true);
                    $_SESSION['user'] = [
                        'id' => (int)$row['id'],
                        'username' => $row['username']
                    ];
                    // redirect post-login
                    $to = isset($_GET['r']) && preg_match('~^/[a-zA-Z0-9_\-/\.]*$~', $_GET['r']) ? $_GET['r'] : '/trev/index.php';
                    header('Location: ' . $to);
                    exit;
                }
                // errore generico
                $err = 'Credenziali non valide.';
            } catch (Throwable $e) {
                $err = 'Errore di connessione. Riprova.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accedi · Trev</title>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{
  --bg: #0f172a;           /* slate-900 */
  --card: #0b1223;         /* dark card */
  --soft: #111a30;         /* softer card */
  --border: #1f2a44;       /* border */
  --text: #e2e8f0;         /* slate-200 */
  --muted:#94a3b8;         /* slate-400 */
  --accent:#22d3ee;        /* cyan-400 */
  --accent-2:#38bdf8;      /* sky-400 */
  --danger:#f87171;        /* red-400 */
  --shadow: 0 15px 50px rgba(2,6,23,.45);
}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0; font-family:Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color:var(--text); background:
  radial-gradient(1200px 800px at 80% -10%, rgba(34,211,238,.18), transparent 40%),
  radial-gradient(900px 600px at -10% 60%, rgba(56,189,248,.14), transparent 40%),
  linear-gradient(180deg, #0b1020 0%, #0f172a 100%);
}
.container{min-height:100%; display:grid; place-items:center; padding:24px}
.card{width:100%; max-width:420px; background:linear-gradient(180deg, var(--card), var(--soft)); border:1px solid var(--border); border-radius:18px; box-shadow:var(--shadow); overflow:hidden}
.card-header{padding:22px 22px 10px; display:flex; gap:12px; align-items:center}
.logo-wrap{width:40px;height:40px;border-radius:10px; background:rgba(34,211,238,.12); display:grid;place-items:center; border:1px solid rgba(34,211,238,.28)}
.logo-wrap img{width:26px;height:26px; display:block}
.title{font-weight:800; font-size:20px}
.subtitle{color:var(--muted); font-size:12px; margin-top:2px}
.card-body{padding:22px}
.field{margin-bottom:12px}
.field label{display:block; font-size:12px; color:var(--muted); font-weight:700; margin-bottom:6px}
.input{width:100%; padding:12px 12px; border-radius:12px; background:#0a1325; border:1px solid var(--border); color:var(--text); outline:none; transition:border .15s, box-shadow .15s}
.input:focus{border-color:var(--accent); box-shadow:0 0 0 4px rgba(34,211,238,.12)}
.actions{display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:8px}
.btn{display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:12px 14px; border-radius:12px; font-weight:700; cursor:pointer; border:1px solid var(--border); background:#0a1325; color:var(--text); transition:transform .05s, box-shadow .15s, border .15s}
.btn:hover{box-shadow:0 10px 30px rgba(2,6,23,.35); border-color:#2b3a5e}
.btn.primary{background:linear-gradient(135deg, var(--accent), var(--accent-2)); color:#0b1020; border:0}
.btn.primary:active{transform:translateY(1px)}
.helper{font-size:12px; color:var(--muted)}
.error{display:flex; gap:8px; align-items:flex-start; padding:10px 12px; background:rgba(248,113,113,.1); border:1px solid rgba(248,113,113,.25); color:#fecaca; border-radius:12px; margin:0 22px 14px}
.footer{padding:16px 22px; display:flex; justify-content:center; gap:10px; font-size:12px; color:var(--muted); border-top:1px solid var(--border)}
.small{font-size:12px; color:var(--muted)}
.toggle{display:flex; align-items:center; gap:8px; cursor:pointer; user-select:none}
.checkbox{appearance:none; width:18px; height:18px; border-radius:6px; border:1px solid var(--border); background:#0a1325; display:grid; place-items:center}
.checkbox:checked{background:linear-gradient(135deg, var(--accent), var(--accent-2)); border:0}
.checkbox:checked::after{content:'\2713'; color:#0b1020; font-size:12px; font-weight:800}
</style>
</head>
<body>
<div class="container">
  <div class="card" role="dialog" aria-labelledby="loginTitle" aria-modal="true">
    <div class="card-header">
      <div class="logo-wrap"><img src="/trev/logo-trev.png" alt="Trev"></div>
      <div>
        <div class="title" id="loginTitle">Accedi a Trev</div>
        <div class="subtitle">Gestione acquisti e vendite</div>
      </div>
    </div>

    <?php if ($err): ?>
      <div class="error"><i class="bi bi-exclamation-triangle-fill"></i><div><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div></div>
    <?php endif; ?>

    <form class="card-body" method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="field">
        <label for="username">Username</label>
        <input class="input" id="username" name="username" type="text" autocomplete="username" placeholder="es. admin" required>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <input class="input" id="password" name="password" type="password" autocomplete="current-password" placeholder="La tua password" required>
      </div>

      <div class="actions">
        <label class="toggle"><input type="checkbox" class="checkbox" id="remember" disabled> <span class="helper">Ricordami (coming soon)</span></label>
        <button class="btn primary" type="submit"><i class="bi bi-box-arrow-in-right"></i> Entra</button>
      </div>
    </form>

    <div class="footer">
      <span class="small">© <?= date('Y') ?> · Sviluppato da Biosound</span>
    </div>
  </div>
</div>

<script>
  // Focus automatico
  (function(){ try{ document.getElementById('username')?.focus(); }catch(e){} })();
  // Enter su password => submit
  (function(){
    var pwd = document.getElementById('password');
    if(pwd){ pwd.addEventListener('keyup', function(e){ if(e.key==='Enter'){ pwd.form && pwd.form.submit(); } }); }
  })();
</script>
</body>
</html>
