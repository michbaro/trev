<?php
declare(strict_types=1);
// 1) Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), fullscreen=(self)');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

// 2) Connessione PDO
define('DB_HOST',    '127.0.0.1'); // a
define('DB_NAME',    'mangime');
define('DB_USER',    'biosound_user');
define('DB_PASS',    '4zV3kV#vyq@fmKP');
define('DB_CHARSET', 'utf8mb4');

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    DB_HOST, DB_NAME, DB_CHARSET
);
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Errore di connessione al database.');
}
// Pagina DEV-ONLY per creare account, SENZA CSRF e SENZA sessioni.
// Step 1: inserisci passphrase esatta -> mostra form creazione utente
// Step 2: crea utente (username + password senza limiti)

$PASS_PHRASE = 'Michele_Domenico1';

$mode = 'pass';
$msg  = '';
$ok   = false;
$devToken = '';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function make_token(string $k): string { return hash('sha256', $k); }
function check_token(string $k, string $t): bool { return hash_equals(make_token($k), $t); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = $_POST['step'] ?? '';

    if ($step === 'pass') {
        $dev = (string)($_POST['dev_key'] ?? '');
        if (hash_equals($PASS_PHRASE, $dev)) {
            $mode = 'create';
            $devToken = make_token($PASS_PHRASE);
        } else {
            $msg = 'Passphrase errata.';
        }
    }

    if ($step === 'create') {
        // Autorizzazione: o token valido dal passo precedente, oppure ripresentare la passphrase
        $tokenOk = check_token($PASS_PHRASE, (string)($_POST['dev_token'] ?? ''))
                || hash_equals($PASS_PHRASE, (string)($_POST['dev_key'] ?? ''));
        if (!$tokenOk) {
            $msg = 'Accesso non autorizzato.';
            $mode = 'pass';
        } else {
            $mode = 'create';
            $devToken = make_token($PASS_PHRASE);
            $user = (string)($_POST['username'] ?? '');
            $pass = (string)($_POST['password'] ?? ''); // nessun limite
            if ($user === '' || $pass === '') {
                $msg = 'Compila tutti i campi.';
            } else {
                try {
                    $check = $pdo->prepare('SELECT 1 FROM utente WHERE username=:u LIMIT 1');
                    $check->execute([':u'=>$user]);
                    if ($check->fetchColumn()) {
                        $msg = 'Username già in uso.';
                    } else {
                        $hash = password_hash($pass, PASSWORD_DEFAULT);
                        $ins = $pdo->prepare('INSERT INTO utente (username, password) VALUES (:u,:p)');
                        $ins->execute([':u'=>$user, ':p'=>$hash]);
                        $ok = true; $msg = 'Utente creato.';
                    }
                } catch (Throwable $e) {
                    $msg = 'Errore database.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trev · Register (DEV)</title>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;800&display=swap" rel="stylesheet">
<style>
  :root{ --bg:#0b0f1a; --panel:#0e1424; --border:#1b2440; --text:#e5e7eb; --muted:#93a0bd; --ok:#22c55e; --err:#ef4444; }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{margin:0; background:linear-gradient(180deg,#0a0f1d 0%, #0b0f1a 100%); color:var(--text); font:14px/1.5 "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;}
  .wrap{min-height:100%; display:grid; place-items:center; padding:24px}
  .term{width:100%; max-width:720px; border:1px solid var(--border); border-radius:10px; overflow:hidden; background:linear-gradient(180deg, var(--panel), #0b1120); box-shadow:0 20px 60px rgba(2,6,23,.45)}
  .bar{display:flex; align-items:center; gap:10px; padding:10px 12px; border-bottom:1px solid var(--border); background:linear-gradient(180deg,#0e1528,#0b1224)}
  .dots{display:flex; gap:8px; margin-right:6px}
  .dot{width:10px;height:10px;border-radius:999px;background:#ef4444}
  .dot:nth-child(2){background:#f59e0b}
  .dot:nth-child(3){background:#10b981}
  .title{color:var(--muted); font-weight:600}
  .body{padding:18px 16px; display:grid; gap:12px}
  .line{color:#cbd5e1}
  .prompt{color:#38bdf8}
  .success{color:var(--ok)}
  .error{color:#fecaca}
  form{display:grid; gap:10px; background:#0a1325; border:1px dashed #1c2a4a; padding:14px; border-radius:10px}
  label{display:block; color:#93a0bd; font-size:12px; margin-bottom:4px}
  input[type=text], input[type=password]{ width:100%; padding:10px 12px; border-radius:8px; border:1px solid #1b2440; background:#09132a; color:#e5e7eb; outline:none }
  .row{display:grid; grid-template-columns:1fr 1fr; gap:10px}
  .actions{display:flex; gap:10px; justify-content:flex-end}
  .btn{ border:1px solid #1b2440; background:#e2e8f0; color:#0b1020; padding:10px 14px; border-radius:8px; font-weight:800; cursor:pointer }
  @media (max-width:680px){ .row{grid-template-columns:1fr} }
</style>
</head>
<body>
<div class="wrap">
  <div class="term" role="region" aria-label="Trev Dev Terminal">
    <div class="bar">
      <div class="dots"><span class="dot"></span><span class="dot"></span><span class="dot"></span></div>
      <div class="title">/trev/register.php — DEV</div>
    </div>
    <div class="body">
      <?php if ($msg): ?>
        <div class="line <?= $ok ? 'success' : 'error' ?>"><?= h($msg) ?></div>
      <?php endif; ?>

      <?php if ($mode === 'pass'): ?>
        <div class="line"><span class="prompt">trev@vps</span>$ richiesta passphrase</div>
        <form method="post" autocomplete="off">
          <input type="hidden" name="step" value="pass">
          <label for="dev_key">Passphrase</label>
          <input id="dev_key" name="dev_key" type="password" required>
          <div class="actions">
            <button class="btn" type="submit">CONTINUA</button>
          </div>
        </form>
      <?php else: ?>
        <div class="line"><span class="prompt">trev@vps</span>$ crea utente</div>
        <form method="post" autocomplete="off">
          <input type="hidden" name="step" value="create">
          <input type="hidden" name="dev_token" value="<?= h($devToken) ?>">
          <div class="row">
            <div>
              <label for="username">Username</label>
              <input id="username" name="username" type="text" required>
            </div>
            <div>
              <label for="password">Password</label>
              <input id="password" name="password" type="password" required>
            </div>
          </div>
          <div class="actions">
            <button class="btn" type="submit">CREATE</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
  (function(){
    var el = document.getElementById('dev_key') || document.getElementById('username');
    if(el) try{ el.focus(); }catch(e){}
  })();
</script>
</body>
</html>