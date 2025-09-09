<?php
// test_db.php — verifica connessione al database

// Usa le stesse credenziali di init.php
$host    = '127.0.0.1';   // prova anche 'localhost' se non va
$db      = 'mangime';
$user    = 'biosound_user';
$pass    = '4zV3kV#vyq@fmKP';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    echo "<h3 style='color:green'>✅ Connessione riuscita al database <b>$db</b></h3>";

    // prova query semplice
    $res = $pdo->query("SHOW TABLES")->fetchAll();
    echo "<pre>"; print_r($res); echo "</pre>";

} catch (PDOException $e) {
    echo "<h3 style='color:red'>❌ Errore connessione:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "</pre>";
}
