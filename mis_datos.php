<?php
// ============================================================
//  mis_datos.php — Ficha del cliente y libreta de direcciones
//  (también recibe por AJAX las direcciones nuevas cargadas desde el pedido)
// ============================================================
require 'config.php';
require 'clientes_lib.php';
require 'ui_clientes.php';
auth_required();

$u   = current_user();
$uid = (int)$u['id'];
$c   = cliente($uid);
$flash_ok = $flash_err = '';
$ajax = !empty($_POST['ajax']);

function aviso_ficha_telegram(array $c, string $que): void {
    enviar_telegram("📋 " . $que . "\n\nCliente: " . ($c['nombre'] ?? '') . ($c['razon_social'] ? "\nRazón social: {$c['razon_social']}" : '')
        . ($c['cuit'] ? "\nCUIT/DNI: {$c['cuit']}" : '') . "\n\nRevisala en Admin → Usuarios → Ficha.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'guardar_ficha') {
        $estaba_completa = !empty($c['ficha_completa_en']);
        [$err, $cambios] = guardar_ficha_post($c, $_POST, false);
        if ($err) { $flash_err = implode(' ', $err); }
        else {
            $c = cliente($uid);
            $flash_ok = $cambios ? '✅ Datos guardados.' : 'No había cambios para guardar.';
            if ($cambios) {
                if (!$estaba_completa && !empty($c['ficha_completa_en'])) aviso_ficha_telegram($c, 'FICHA COMPLETA');
                else aviso_ficha_telegram($c, "Ficha actualizada: " . implode(', ', $cambios));
            }
            if (!ficha_completa($c) && !direcciones($uid)) $flash_ok .= ' Ahora cargá al menos una dirección de entrega.';
        }
    }

    if ($action === 'guardar_direccion') {
        $nueva = empty($_POST['dir_id']);
        [$id, $err] = guardar_direccion_post($uid, $_POST);
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            if ($err) { echo json_encode(['ok' => false, 'error' => $err]); exit; }
            $d = direccion($uid, $id);
            echo json_encode(['ok' => true, 'id' => $id, 'alias' => $d['alias'],
                              'modalidad' => MODALIDADES[$d['modalidad']], 'linea' => direccion_linea($d)]);
            exit;
        }
        if ($err) { $flash_err = $err; $_GET[$nueva ? 'nueva' : 'dir'] = $nueva ? 1 : (int)$_POST['dir_id']; $dir_post = $_POST; }
        else {
            $flash_ok = '✅ Dirección guardada.';
            $c = cliente($uid);
            if (ficha_completa($c) && empty($c['ficha_completa_en'])) {
                db()->prepare('UPDATE usuarios SET ficha_completa_en = NOW() WHERE id = ?')->execute([$uid]);
                aviso_ficha_telegram(cliente($uid), 'FICHA COMPLETA');
            }
        }
    }

    if ($action === 'borrar_direccion') {
        borrar_direccion($uid, (int)$_POST['dir_id']);
        $flash_ok = 'Dirección eliminada.';
    }
    if ($action === 'predeterminada') {
        if (direccion($uid, (int)$_POST['dir_id'])) marcar_predeterminada($uid, (int)$_POST['dir_id']);
        $flash_ok = 'Dirección predeterminada actualizada.';
    }
    $c = cliente($uid);
}

$dirs     = direcciones($uid);
$editar   = isset($_GET['dir']) ? (direccion($uid, (int)$_GET['dir']) ?: null) : null;
if (isset($dir_post)) $editar = array_merge($editar ?? [], $dir_post, ['id' => (int)($dir_post['dir_id'] ?? 0)]);
$mostrar_form_dir = $editar || isset($_GET['nueva']) || !$dirs;
$completa = ficha_completa($c);
$bloq     = !empty($c['fiscal_verificado']);

$lista_id     = (int)($u['lista_id'] ?? 3);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Mis datos — Panaro Car Care</title>
<link rel="stylesheet" href="style.css">
<style>
.page-layout{display:flex;flex-direction:column;height:100vh;overflow:hidden}
.header-fixo{flex-shrink:0;background:var(--negro);box-shadow:0 2px 12px rgba(0,0,0,.5);z-index:100}
.page-scroll{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch}
.logo{height:26px;width:auto;max-width:30%;object-fit:contain;flex-shrink:1}
@media(min-width:600px){.logo{height:40px;max-width:none}}
.site-header{background:var(--negro);display:flex;align-items:center;justify-content:space-between;padding:.55rem .8rem;gap:.4rem}
.estado-ficha{display:flex;gap:.6rem;align-items:center;font-size:.85rem;margin-bottom:1rem;flex-wrap:wrap}
.estado-ficha .pill{font-size:.72rem;font-weight:700;padding:.25rem .6rem;border-radius:99px}
</style>
<?= ui_clientes_css() ?>
<?= ui_direccion_js() ?>
</head>
<body>
<div class="page-layout">
<div class="header-fixo">
  <header class="site-header">
    <img src="assets/img/logo.png" alt="Panaro Car Care" class="logo">
    <div class="header-right" style="gap:.35rem">
      <span class="descuento-badge" style="background:<?= escape(color_lista($lista_id)) ?>;font-size:.7rem;max-width:85px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= escape(nombre_lista($lista_id)) ?></span>
      <?php if (!empty($u['es_admin'])): ?><a href="admin.php" class="btn-logout" style="border-color:#e30613;color:#e30613;font-size:.72rem;padding:.25rem .55rem">Admin</a><?php endif; ?>
      <a href="pedido.php" class="btn-logout" style="border-color:#444;color:#aaa;white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">+ Pedido</a>
      <a href="logout.php" class="btn-logout" style="font-size:.72rem;padding:.25rem .55rem">Salir</a>
    </div>
  </header>
</div>

<div class="page-scroll">
<div class="main-wrap" style="max-width:860px">
  <div class="page-nav">
    <a href="pedido.php">Nuevo pedido</a>
    <a href="historial.php">Mis pedidos</a>
    <a href="listas.php">Listas de precios</a>
    <a href="mis_datos.php" class="activo">Mis datos</a>
    <a href="perfil.php">Mi perfil</a>
  </div>

  <?php if ($flash_ok): ?><div class="flash-ok"><?= escape($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash-err"><?= escape($flash_err) ?></div><?php endif; ?>

  <div class="pedido-header"><h2>Mis datos</h2></div>
  <div class="estado-ficha">
    <?php if ($completa): ?>
      <span class="pill" style="background:#dcfce7;color:#166534">✓ Ficha completa</span>
      <span style="color:var(--gris-4)">Podés actualizarla cuando quieras.</span>
    <?php else: ?>
      <span class="pill" style="background:#fef3c7;color:#92400e">Ficha incompleta</span>
      <span style="color:var(--gris-4)">Completá tus datos fiscales, un contacto y al menos una dirección: así facturamos y despachamos sin demoras.</span>
    <?php endif; ?>
  </div>

  <!-- Direcciones -->
  <div class="fc-card" id="direcciones">
    <h3>Direcciones de entrega</h3>
    <p class="fc-sub">Guardá todas las que uses (local, depósito, sucursal del transporte o retiro en fábrica) y elegí una en cada pedido.</p>

    <?php if ($dirs && !$editar): ?>
    <div class="dir-lista" style="margin-bottom:1rem">
      <?php foreach ($dirs as $d):
        $acc = '<div class="dir-acc"><a href="?dir=' . $d['id'] . '#direcciones">Editar</a>'
             . (empty($d['predeterminada']) ? '<form method="post" style="display:inline"><input type="hidden" name="action" value="predeterminada"><input type="hidden" name="dir_id" value="' . $d['id'] . '"><button>Hacer predeterminada</button></form>' : '')
             . '<form method="post" style="display:inline" onsubmit="return confirm(\'¿Eliminar la dirección «' . escape(addslashes($d['alias'])) . '»?\')"><input type="hidden" name="action" value="borrar_direccion"><input type="hidden" name="dir_id" value="' . $d['id'] . '"><button style="color:#dc2626">Eliminar</button></form></div>';
        echo ui_direccion_tarjeta($d, $c, $acc);
      endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($mostrar_form_dir): ?>
      <form method="post" style="border-top:<?= $dirs && !$editar ? '1px solid #eee' : '0' ?>;padding-top:<?= $dirs && !$editar ? '1rem' : '0' ?>">
        <input type="hidden" name="action" value="guardar_direccion">
        <h4 style="margin:0 0 .8rem;font-size:.95rem"><?= $editar && !empty($editar['id']) ? 'Editar dirección' : 'Nueva dirección' ?></h4>
        <?php ui_direccion_campos($editar ?? ['predeterminada' => !$dirs]); ?>
        <div class="fc-btns">
          <button class="fc-btn">Guardar dirección</button>
          <?php if ($dirs): ?><a href="mis_datos.php#direcciones" class="fc-link">Cancelar</a><?php endif; ?>
        </div>
      </form>
    <?php else: ?>
      <a href="?nueva=1#direcciones" class="fc-btn sec" style="text-decoration:none;display:inline-block">+ Agregar dirección</a>
    <?php endif; ?>
  </div>

  <!-- Ficha -->
  <form method="post" class="fc-card" id="ficha">
    <input type="hidden" name="action" value="guardar_ficha">
    <h3>Datos de facturación y contacto</h3>
    <p class="fc-sub">Los usamos para emitir tus comprobantes y coordinar entregas.</p>
    <?php ui_ficha_campos($c, $bloq); ?>
    <div class="fc-btns"><button class="fc-btn">Guardar datos</button></div>
  </form>
</div>
</div>
</div>
</body>
</html>
