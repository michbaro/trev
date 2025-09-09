<?php
declare(strict_types=1);
require_once __DIR__ . '/init.php';
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Trev · Beta</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root{
    --bg:#f8fafc; --card:#ffffff; --text:#0f172a; --muted:#64748b;
    --accent:#2563eb; --accent2:#7c3aed;
    --shadow:0 10px 30px rgba(15,23,42,.10), 0 20px 60px rgba(2,6,23,.06);
    --shadow-lg:0 24px 80px rgba(2,6,23,.18);
  }
  *{box-sizing:border-box}
  body{
    margin:0; min-height:100vh; display:grid; place-items:center;
    font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
    color:var(--text); background:linear-gradient(180deg,#f9fbff, #eef2ff 40%, #f8fafc 100%);
  }
  .card{
    background:var(--card); border-radius:20px; box-shadow:var(--shadow);
    padding:40px 32px; text-align:center; max-width:900px; width:100%;
  }
  .logo{width:120px; height:auto; margin:0 auto 18px; display:block;}
  h1{margin:0 0 8px; font-size:28px; font-weight:800; background:linear-gradient(90deg,var(--accent), var(--accent2)); -webkit-background-clip:text; background-clip:text; color:transparent}
  p.lead{margin:0 0 30px; font-size:16px; color:var(--muted)}

  .links{
    display:flex; flex-wrap:wrap; justify-content:center; gap:16px;
  }
  .link{
    display:inline-flex; align-items:center; gap:8px;
    padding:12px 18px; border-radius:14px;
    background:#fff; border:1px solid #e5e7eb; color:#0f172a;
    text-decoration:none; font-weight:600; box-shadow:var(--shadow);
    transition:transform .08s ease, box-shadow .18s ease;
  }
  .link:hover{ box-shadow:var(--shadow-lg); transform:translateY(-1px) }
  .link i{font-size:18px; color:var(--accent);}
</style>
</head>
<body>
  <div class="card">
    <img src="logo-biosound.png" alt="Biosound" class="logo">
    <h1>Questa è una beta sviluppata da Biosound</h1>
    <p class="lead">Ambiente di test per Acquisti, Vendite e Anagrafiche</p>

    <div class="links">
      <a class="link" href="acquisti.php"><i class="bi bi-box-seam"></i> Acquisti</a>
      <a class="link" href="vendite.php"><i class="bi bi-receipt"></i> Vendite</a>
      <a class="link" href="prodotti.php"><i class="bi bi-basket"></i> Prodotti</a>
      <a class="link" href="fornitori.php"><i class="bi bi-truck"></i> Fornitori</a>
      <a class="link" href="clienti.php"><i class="bi bi-people"></i> Clienti</a>
    </div>
  </div>
</body>
</html>
