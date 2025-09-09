<?php
declare(strict_types=1);
session_start();

// Rimuovi tutte le variabili di sessione
$_SESSION = [];

// Cancella il cookie della sessione (se esiste)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Distruggi la sessione
session_destroy();

// Redirect alla login
header('Location: /trev/login.php');
exit;
