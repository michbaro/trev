<?php
// init.php — include PRIMA di qualunque output in tutte le pagine protette
session_start();

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

// 3) Calcolo dinamico della “base URL”
//    Se stai servendo l’app in http://host/qualcosa/... prende “/qualcosa”
//    altrimenti prende la stringa vuota.
$script = $_SERVER['SCRIPT_NAME'];            // es. "/biosound/log/login.php"
$parts  = explode('/', trim($script, '/'));   // ["biosound","log","login.php"]
$base   = isset($parts[0]) ? '/'.$parts[0] : '';

// 4) Redirect if not authenticated
$current    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// definisci login/logout/home relativi a $base
$loginURL   = $base . '/login.php';
$logoutURL  = $base . '/logout.php';
$homeURL    = $base . '/index.php';

if (empty($_SESSION['username'])) {
    // se NON sono su login o logout, vado a login
    if ($current !== $loginURL && $current !== $logoutURL) {
        header('Location: ' . $loginURL);
        exit;
    }
} else {
    // se SONO già loggato, ma sono su login o logout, sposto alla home
    if ($current === $loginURL || $current === $logoutURL) {
        header('Location: ' . $homeURL);
        exit;
    }
}