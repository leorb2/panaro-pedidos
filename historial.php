<?php
// ============================================================
//  historial.php — v3 fix: muestra total_con_dto
// ============================================================
require 'config.php';
auth_required();
$u = current_user();

$lista_id     = (int)($u['lista_id'] ?? 3);
$lista_nombre = nombre_lista($lista_id);
$lista_color  = color_lista($lista_id);

$ver_id    = isset($_GET['ver']) ? (int)$_GET['ver'] : null;
$detalle   = null;
$det_items = [];

if ($ver_id) {
    $st = db()->prepare('SELECT * FROM pedidos WHERE id=? AND usuario_id=? LIMIT 1');
    $st->execute([$ver_id, $u['id']]);
    $detalle = $st->fetch();
    if ($detalle) {
        $si = db()->prepare('SELECT * FROM pedido_items WHERE pedido_id=? ORDER BY id ASC');
        $si->execute([$ver_id]);
        $det_items = $si->fetchAll();
    }
}

$pedidos = db()->prepare(
    'SELECT * FROM pedidos WHERE usuario_id=? ORDER BY created_at DESC LIMIT 50'
);
$pedidos->execute([$u['id']]);
$pedidos = $pedidos->fetchAll();

function estado_label(string $e): string {
    return ['pendiente'=>'Pendiente','en_preparacion'=>'En preparación',
            'enviado'=>'Enviado','cancelado'=>'Cancelado'][$e] ?? $e;
}

function total_mostrar(array $p): float {
    return (float)(($p['total_con_dto'] ?? 0) > 0 ? $p['total_con_dto'] : $p['total']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Mis Pedidos — Panaro Car Care</title>
<link rel="stylesheet" href="style.css">
<style>
.page-layout{display:flex;flex-direction:column;height:100vh;overflow:hidden}
.header-fixo{flex-shrink:0;background:var(--negro);box-shadow:0 2px 12px rgba(0,0,0,.5);z-index:100}
.page-scroll{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch}
.logo{height:26px;width:auto;max-width:30%;object-fit:contain;flex-shrink:1}
@media(min-width:600px){.logo{height:40px;max-width:none}}
.site-header{background:var(--negro);display:flex;align-items:center;justify-content:space-between;padding:.55rem .8rem;gap:.4rem}

@media(max-width:640px){
  html,body{overflow-x:hidden;width:100%}
  .hist-tabla-wrap{display:none}
  .hist-cards{display:flex;flex-direction:column;gap:.6rem}
}
@media(min-width:641px){
  .hist-cards{display:none}
  .hist-tabla-wrap{display:block;overflow-x:auto}
}
.ped-card{background:var(--blanco);border-radius:10px;padding:.9rem 1rem;box-shadow:0 2px 10px rgba(0,0,0,.07);display:flex;justify-content:space-between;align-items:center;gap:.5rem}
.ped-card-left{flex:1;min-width:0}
.ped-card-num{font-weight:700;font-size:.95rem;color:var(--negro);margin-bottom:2px}
.ped-card-fecha{font-size:.75rem;color:var(--gris-4);margin-bottom:6px}
.ped-card-right{text-align:right;flex-shrink:0}
.ped-card-total{font-family:'Bebas Neue',sans-serif;font-size:1.4rem;color:var(--negro);line-height:1}
.ped-card-dto{font-size:.72rem;color:#22c55e;font-weight:600;margin-top:2px}
.ped-card-btn{display:inline-block;margin-top:6px;font-size:.75rem;color:var(--rojo);border:1px solid var(--rojo);padding:4px 12px;border-radius:5px;text-decoration:none;font-weight:600;white-space:nowrap}
</style>
</head>
<body>
<div class="page-layout">
<div class="header-fixo" id="sticky-header">
  <header class="site-header">
    <img src="assets/img/logo.png" alt="Panaro Car Care" class="logo">
    <div class="header-right" style="gap:.35rem">
      <span class="descuento-badge" style="background:<?= escape($lista_color) ?>;font-size:.7rem;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <?= escape($lista_nombre) ?>
      </span>
      <?php if (!empty($u['es_admin'])): ?>
      <a href="admin.php" class="btn-logout" style="border-color:#e30613;color:#e30613;white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Admin</a>
      <?php endif; ?>
      <a href="pedido.php" class="btn-logout" style="border-color:#444;color:#aaa;white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">+ Pedido</a>
      <a href="perfil.php" class="btn-logout" style="border-color:#444;color:#aaa;white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Perfil</a>
      <a href="logout.php" class="btn-logout" style="white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Salir</a>
    </div>
  </header>
</div>

<div class="page-scroll">
<div class="main-wrap">

  <div class="page-nav">
    <a href="pedido.php">Nuevo pedido</a>
    <a href="historial.php" class="activo">Mis pedidos</a>
    <a href="listas.php">Listas de precios</a>
    <a href="perfil.php">Mi perfil</a>
  </div>

  <div class="pedido-header"><h2>Mis Pedidos</h2></div>

  <?php if ($detalle): ?>
  <!-- ── Detalle de un pedido ──────────────────────────────── -->
  <div class="tabla-pedido" style="margin-bottom:1.5rem;padding:1.2rem">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
      <div>
        <h3 style="font-family:'Bebas Neue',sans-serif;font-size:1.5rem">Pedido #<?= $detalle['id'] ?></h3>
        <div style="font-size:.82rem;color:var(--gris-4)"><?= date('d/m/Y H:i', strtotime($detalle['created_at'])) ?></div>
      </div>
      <span class="estado-badge estado-<?= $detalle['estado'] ?>"><?= estado_label($detalle['estado']) ?></span>
    </div>

    <div style="overflow-x:auto">
      <table class="det-tabla" style="width:100%;min-width:280px">
        <thead>
          <tr>
            <th>Producto</th>
            <th style="text-align:center;white-space:nowrap">Cant.</th>
            <th style="text-align:right;white-space:nowrap">P. Unit.</th>
            <th style="text-align:right;white-space:nowrap">Subtotal</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($det_items as $it): ?>
          <tr>
            <td><?= escape($it['nombre']) ?></td>
            <td style="text-align:center"><?= $it['cantidad'] ?></td>
            <td style="text-align:right"><?= $it['precio_unit'] > 0 ? precio_fmt((float)$it['precio_unit']) : '<span style="color:#22c55e;font-weight:600">REGALO</span>' ?></td>
            <td style="text-align:right;font-weight:700"><?= $it['precio_unit'] > 0 ? precio_fmt((float)$it['subtotal']) : '<span style="color:#22c55e">$0</span>' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <?php if ((float)($detalle['monto_descuento'] ?? 0) > 0): ?>
          <tr>
            <td colspan="3" style="text-align:right;color:#22c55e;font-size:.88rem;border-top:1px solid var(--gris-2)">
              Descuento (<?= escape($detalle['codigo_descuento']) ?>)
            </td>
            <td style="color:#22c55e;font-weight:700;border-top:1px solid var(--gris-2)">
              − <?= precio_fmt((float)$detalle['monto_descuento']) ?>
            </td>
          </tr>
          <?php endif; ?>
          <tr>
            <td colspan="3" style="text-align:right;padding-right:.8rem">TOTAL</td>
            <td style="text-align:right"><?= precio_fmt(total_mostrar($detalle)) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <?php if ($detalle['envio_info']): ?>
      <p style="margin-top:.8rem;font-size:.85rem"><strong>Envío:</strong> <?= escape($detalle['envio_info']) ?></p>
    <?php endif; ?>
    <?php if ($detalle['notas']): ?>
      <p style="font-size:.85rem;margin-top:.4rem"><strong>Notas:</strong> <?= escape($detalle['notas']) ?></p>
    <?php endif; ?>

    <div style="margin-top:1rem">
      <a href="historial.php" class="btn-det">← Volver al historial</a>
    </div>
  </div>
  <?php endif; ?>

  <?php if (empty($pedidos)): ?>
    <div style="text-align:center;padding:3rem;color:var(--gris-4)">
      <div style="font-size:2.5rem;margin-bottom:.8rem">📦</div>
      <p>Todavía no tenés pedidos registrados.</p>
      <a href="pedido.php" style="color:var(--rojo);font-weight:600">Hacé tu primer pedido →</a>
    </div>

  <?php else: ?>

    <!-- MOBILE: tarjetas -->
    <div class="hist-cards">
      <?php foreach ($pedidos as $p):
        $t = total_mostrar($p);
        $dto = (float)($p['monto_descuento'] ?? 0);
      ?>
      <div class="ped-card">
        <div class="ped-card-left">
          <div class="ped-card-num">Pedido #<?= $p['id'] ?></div>
          <div class="ped-card-fecha"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></div>
          <span class="estado-badge estado-<?= $p['estado'] ?>"><?= estado_label($p['estado']) ?></span>
        </div>
        <div class="ped-card-right">
          <div class="ped-card-total"><?= precio_fmt($t) ?></div>
          <?php if ($dto > 0): ?>
            <div class="ped-card-dto">−<?= precio_fmt($dto) ?> (<?= escape($p['codigo_descuento']) ?>)</div>
          <?php endif; ?>
          <a href="?ver=<?= $p['id'] ?>" class="ped-card-btn">Ver detalle</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- DESKTOP: tabla -->
    <div class="hist-tabla-wrap">
      <table class="hist-tabla">
        <thead>
          <tr><th>#</th><th>Fecha</th><th>Estado</th><th>Total</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($pedidos as $p):
            $t   = total_mostrar($p);
            $dto = (float)($p['monto_descuento'] ?? 0);
          ?>
          <tr>
            <td style="font-weight:700;color:var(--negro)"><?= $p['id'] ?></td>
            <td><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></td>
            <td><span class="estado-badge estado-<?= $p['estado'] ?>"><?= estado_label($p['estado']) ?></span></td>
            <td>
              <span style="font-weight:700"><?= precio_fmt($t) ?></span>
              <?php if ($dto > 0): ?>
                <div style="font-size:.72rem;color:#22c55e;font-weight:600">
                  −<?= precio_fmt($dto) ?> (<?= escape($p['codigo_descuento']) ?>)
                </div>
              <?php endif; ?>
            </td>
            <td><a href="?ver=<?= $p['id'] ?>" class="btn-det">Ver detalle</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  <?php endif; ?>

</div>
</div><!-- /page-scroll -->
</div><!-- /page-layout -->
</body>
</html>
