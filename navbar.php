<?php
// navbar.php — barra di navigazione comune responsive
?>
<style>
.navbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:10px 20px;
  background:#fff;
  box-shadow:0 2px 8px rgba(0,0,0,.1);
  font-family:system-ui,-apple-system,Segoe UI,Roboto;
  position:sticky; top:0; z-index:1000;
}
.navbar .logo img{
  height:36px;
}
.navbar .menu{
  display:flex; align-items:center; gap:24px;
}
.navbar a{
  text-decoration:none; color:#2e3a45; font-weight:500;
}
.navbar a:hover{ color:#66bb6a; }

/* Dropdown */
.dropdown{position:relative}
.dropdown-toggle{cursor:pointer}
.dropdown-menu{
  position:absolute; top:100%; left:50%; transform:translateX(-50%);
  background:#fff; border:1px solid #ddd; border-radius:8px;
  box-shadow:0 6px 20px rgba(0,0,0,.15);
  min-width:180px;
  display:none; flex-direction:column; padding:6px 0; z-index:2000;
}
.dropdown-menu a{padding:8px 14px; display:block; color:#2e3a45; font-weight:400;}
.dropdown-menu a:hover{background:#f5f5f5; color:#66bb6a}
.dropdown.open .dropdown-menu{display:flex}

/* Hamburger */
.hamburger{display:none; cursor:pointer; font-size:1.5rem; background:none; border:0; color:#2e3a45;}
@media(max-width:768px){
  .navbar .menu{display:none; position:absolute; top:60px; left:0; right:0;
    background:#fff; flex-direction:column; gap:0; border-top:1px solid #ddd; box-shadow:0 6px 20px rgba(0,0,0,.15);}
  .navbar .menu a,.navbar .dropdown-toggle{padding:12px 20px; width:100%; border-bottom:1px solid #eee;}
  .navbar .dropdown-menu{
    position:static; transform:none; border:0; box-shadow:none; min-width:100%; display:none;
  }
  .dropdown.open .dropdown-menu{display:flex}
  .hamburger{display:block}

  
}
</style>

<div class="navbar">
  <div class="logo">
    <a href="index.php"><img src="logo-trev.png" alt="Logo"></a>
  </div>
  <button class="hamburger" id="nav-toggle">☰</button>
  <div class="menu" id="nav-menu">
    <a href="acquisti.php">Acquisti</a>
    <a href="vendite.php">Vendite</a>
    <div class="dropdown" id="varie-dd">
      <div class="dropdown-toggle">Varie ▾</div>
      <div class="dropdown-menu">
        <a href="prodotti.php">Prodotti</a>
        <a href="fornitori.php">Fornitori</a>
        <a href="clienti.php">Clienti</a>
      </div>
    </div>
          <a href="logout.php"><strong>LOGOUT</strong></a>
  </div>
</div>

<script>
const dd=document.getElementById('varie-dd');
dd.querySelector('.dropdown-toggle').addEventListener('click',()=> dd.classList.toggle('open'));
document.addEventListener('click',e=>{ if(!dd.contains(e.target)) dd.classList.remove('open'); });

const navToggle=document.getElementById('nav-toggle');
const navMenu=document.getElementById('nav-menu');
navToggle.addEventListener('click',()=> navMenu.style.display=navMenu.style.display==='flex'?'none':'flex');
</script>

<?php include 'footer.php'?>
