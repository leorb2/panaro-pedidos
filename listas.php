<?php
// ============================================================
//  listas.php — Listas de precios para descargar
//  Distribuidor: distribuidor, punto de venta y público
//  Punto de venta: punto de venta y público
// ============================================================
require 'config.php';
require 'listas_lib.php';
auth_required();

$u            = current_user();
$lista_id     = (int)($u['lista_id'] ?? 3);
$lista_nombre = nombre_lista($lista_id);
$lista_color  = color_lista($lista_id);
$listas       = listas_permitidas($u);
$hoy          = date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Listas de precios — Panaro Car Care</title>
<link rel="stylesheet" href="style.css">
<style>
.page-layout{display:flex;flex-direction:column;height:100vh;overflow:hidden}
.header-fixo{flex-shrink:0;background:var(--negro);box-shadow:0 2px 12px rgba(0,0,0,.5);z-index:100}
.page-scroll{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch}
.logo{height:26px;width:auto;max-width:30%;object-fit:contain;flex-shrink:1}
@media(min-width:600px){.logo{height:40px;max-width:none}}
.site-header{background:var(--negro);display:flex;align-items:center;justify-content:space-between;padding:.55rem .8rem;gap:.4rem}

.listas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem}
.lista-card{background:var(--blanco);border-radius:10px;box-shadow:0 2px 16px rgba(0,0,0,.08);overflow:hidden;display:flex;flex-direction:column}
.lista-card .franja{background:var(--negro);border-bottom:3px solid #cc0000;padding:.9rem 1rem}
.lista-card .franja h3{font-family:'Bebas Neue',sans-serif;font-size:1.45rem;letter-spacing:.04em;color:#fff;line-height:1}
.lista-card .franja small{display:block;color:#aaa;font-size:.75rem;margin-top:.3rem}
.lista-card .cuerpo{padding:1rem;flex:1;display:flex;flex-direction:column;gap:.8rem}
.lista-card p{font-size:.85rem;color:var(--gris-4);line-height:1.45;margin:0}
.cols{display:flex;flex-wrap:wrap;gap:.35rem}
.cols span{font-size:.68rem;font-weight:700;letter-spacing:.04em;padding:.2rem .5rem;border-radius:4px;background:#990000;color:#fff}
.cols span.dest{background:#cc0000}
.acciones{display:flex;gap:.5rem;margin-top:auto}
.acciones a,.acciones button{flex:1;text-align:center;padding:.65rem .5rem;border-radius:7px;font-family:inherit;font-size:.85rem;font-weight:600;text-decoration:none;cursor:pointer;border:none;-webkit-tap-highlight-color:transparent;touch-action:manipulation}
.btn-ver{background:var(--rojo);color:#fff}
.btn-share{background:#25d366;color:#fff;display:none}
.btn-share.on{display:block}
.btn-desc{background:var(--gris-2);color:var(--negro)}
.nota{font-size:.78rem;color:var(--gris-4);margin-top:1.2rem;line-height:1.5}
</style>
</head>
<body>
<div class="page-layout">
<div class="header-fixo" id="sticky-header">
  <header class="site-header">
    <img src="assets/img/logo.png" alt="Panaro Car Care" class="logo">
    <div class="header-right" style="gap:.35rem">
      <span class="descuento-badge" style="background:<?= escape($lista_color) ?>;font-size:.7rem;max-width:85px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <?= escape($lista_nombre) ?>
      </span>
      <?php if (!empty($u['es_admin'])): ?>
      <a href="admin.php" class="btn-logout" style="border-color:#e30613;color:#e30613;white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Admin</a>
      <?php endif; ?>
      <a href="pedido.php" class="btn-logout" style="border-color:#444;color:#aaa;white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">+ Pedido</a>
      <a href="logout.php" class="btn-logout" style="white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Salir</a>
    </div>
  </header>
</div>

<div class="page-scroll">
<div class="main-wrap">
  <div class="page-nav">
    <a href="pedido.php">Nuevo pedido</a>
    <a href="historial.php">Mis pedidos</a>
    <a href="listas.php" class="activo">Listas de precios</a>
    <a href="perfil.php">Mi perfil</a>
  </div>

  <div class="pedido-header">
    <h2>Listas de precios</h2>
    <div style="font-size:.8rem;color:var(--gris-4)">Vigentes al <strong><?= $hoy ?></strong></div>
  </div>

  <div class="listas-grid">
    <?php foreach ($listas as $clave => $def): ?>
    <div class="lista-card">
      <div class="franja">
        <h3>Lista <?= escape($def['titulo']) ?></h3>
        <small>PDF · Vigente al <?= $hoy ?></small>
      </div>
      <div class="cuerpo">
        <p><?= escape($def['descripcion']) ?></p>
        <div class="cols">
          <?php foreach ($def['columnas'] as $c): ?>
            <span class="<?= !empty($c['destacada']) ? 'dest' : '' ?>"><?= escape($c['titulo']) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="acciones">
          <a class="btn-ver" href="lista_pdf.php?l=<?= $clave ?>" target="_blank">Ver PDF</a>
          <button type="button" class="btn-share" data-l="<?= $clave ?>"
                  data-nombre="<?= escape($def['archivo'] . ' ' . date('d-m-Y')) ?>.pdf">Compartir</button>
          <a class="btn-desc" href="lista_pdf.php?l=<?= $clave ?>&descargar=1">Descargar</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <p class="nota">
    Las listas se arman al momento con los precios vigentes del portal.
    <?php if (isset($listas['punto_venta']) && isset($listas['distribuidor'])): ?>
      La lista <strong>Punto de Venta</strong> y la de <strong>Precios al público</strong> no muestran tu precio de distribuidor: podés reenviarlas a tus clientes.
    <?php endif; ?>
    Desde el celular, <strong>Compartir</strong> manda el PDF directo por WhatsApp.
  </p>
</div>
</div>
</div>

<script>
// Compartir el PDF como archivo (WhatsApp, mail, etc.) en celulares que lo permiten
(function () {
  if (!navigator.canShare) return;
  var prueba;
  try { prueba = new File(['x'], 'x.pdf', { type: 'application/pdf' }); } catch (e) { return; }
  if (!navigator.canShare({ files: [prueba] })) return;

  document.querySelectorAll('.btn-share').forEach(function (btn) {
    btn.classList.add('on');
    btn.addEventListener('click', function () {
      var txt = btn.textContent;
      btn.textContent = 'Preparando…'; btn.disabled = true;
      fetch('lista_pdf.php?l=' + encodeURIComponent(btn.dataset.l), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error(); return r.blob(); })
        .then(function (b) {
          var f = new File([b], btn.dataset.nombre, { type: 'application/pdf' });
          return navigator.share({ files: [f], title: btn.dataset.nombre });
        })
        .catch(function () {})
        .then(function () { btn.textContent = txt; btn.disabled = false; });
    });
  });
  // en celulares que comparten, "Descargar" sobra
  document.querySelectorAll('.btn-desc').forEach(function (a) { a.style.display = 'none'; });
})();
</script>
</body>
</html>
