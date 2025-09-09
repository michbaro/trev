<?php
// init.php — include PRIMA di qualunque output in tutte le pagine
session_start();

// 1) Security headers (HSTS solo se HTTPS attivo)
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), fullscreen=(self)');
header('X-XSS-Protection: 1; mode=block');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

// 2) Connessione PDO
define('DB_HOST','127.0.0.1');
define('DB_NAME','mangime');
define('DB_USER','biosound_user');
define('DB_PASS','4zV3kV#vyq@fmKP');
define('DB_CHARSET','utf8mb4');

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
try { $pdo = new PDO($dsn, DB_USER, DB_PASS, $options); }
catch (PDOException $e) { http_response_code(500); exit('Errore di connessione al database.'); }

// 3) Base URL dinamica (primo segmento del path)
$script = $_SERVER['SCRIPT_NAME'] ?? '/';
$parts  = explode('/', trim($script, '/'));
$base   = isset($parts[0]) && $parts[0] !== '' ? '/'.$parts[0] : '';

// 4) Routing auth
$current     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$loginURL    = $base . '/login.php';
$logoutURL   = $base . '/logout.php';
$registerURL = $base . '/register.php';
$homeURL     = $base . '/acquisti.php'; // <- scegli la tua home

// PUBLIC: login, register, (opzionale) asset statici
$isPublic = in_array($current, [$loginURL, $registerURL], true)
         || preg_match('~\.(?:css|js|png|jpg|jpeg|gif|svg|ico|webp|woff2?)$~i', $current);

// stato login coerente con login.php (che salva $_SESSION['user'])
$isLogged = !empty($_SESSION['user']) && is_array($_SESSION['user']);

// NORMALIZZA per retrocompatibilità (se altrove leggi username)
if ($isLogged && empty($_SESSION['username'])) {
  $_SESSION['username'] = $_SESSION['user']['username'] ?? null;
}

// REDIRECT RULES
if (!$isLogged && !$isPublic && $current !== $logoutURL) {
  // utente non loggato: qualsiasi pagina privata -> login
  header('Location: ' . $loginURL);
  exit;
}
if ($isLogged && ($current === $loginURL || $current === $registerURL)) {
  // utente già loggato: niente login/register -> home
  header('Location: ' . $homeURL);
  exit;
}
