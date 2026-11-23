<?php
// ============================================================
//  admin_cliente.php — Ficha completa de un cliente (solo admin)
//  Datos del cliente + condiciones comerciales ocultas + descuentos
//  por producto + libreta de direcciones.
// ============================================================
require 'config.php';
require 'clientes_lib.php';
require 'ui_clientes.php';
admin_required();

$uid = (int)($_GET['id'] ?? $_POST['uid'] ?? 0);
$c   = $uid ? cliente($uid) : null;
if (!$c || !empty($c['es_admin'])) { header('Location: admin.php?s=usuarios'); exit; }

$flash_ok = $flash_err = '';
$num = fn($v) => trim((string)$v) === '' ? null : (float)str_replace(',', '.', (string)$v);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'guardar_condiciones') {
        $lista = (int)($_POST['lista_id'] ?? $c['lista_id']);
        $comp  = in_array($_POST['comprobante'] ?? '', ['A', 'B'], true) ? $_POST['comprobante'] : null;
        $rec   = $_POST['recargo_sin_factura'] ?? '';
        $rec   = isset(RECARGOS_SIN_FACTURA[$rec]) ? (float)$rec : null;
        $pago  = isset(CONDICIONES_PAGO[$_POST['condicion_pago'] ?? '']) ? $_POST['condicion_pago'] : 'anticipado';
        $aj    = $num($_POST['ajuste_lista'] ?? '');
        if ($aj !== null) $aj = max(-90, min(100, $aj));
        $lim   = $num($_POST['limite_credito'] ?? '');
        db()->prepare('UPDATE usuarios SET lista_id = ?, comprobante = ?, recargo_sin_factura = ?, condicion_pago = ?, ajuste_lista = ?,
                              limite_credito = ?, vendedor = ?, notas_internas = ?, fiscal_verificado = ? WHERE id = ?')
           ->execute([isset(LISTAS[$lista]) ? $lista : $c['lista_id'], $comp, $rec, $pago, $aj, $lim,
                      trim($_POST['vendedor'] ?? '') ?: null, trim($_POST['notas_internas'] ?? '') ?: null,
                      !empty($_POST['fiscal_verificado']) ? 1 : 0, $uid]);
        $flash_ok = 'Condiciones comerciales guardadas.';
    }

    if ($action === 'guardar_descuentos') {
        $db = db();
        $db->beginTransaction();
        $db->prepare('DELETE FROM usuario_descuentos WHERE usuario_id = ?')->execute([$uid]);
        $ins = $db->prepare('INSERT INTO usuario_descuentos (usuario_id, producto_id, porcentaje) VALUES (?, ?, ?)');
        $n = 0;
        foreach ((array)($_POST['dto'] ?? []) as $pid => $pct) {
            $pct = $num($pct);
            if ($pct === null || $pct <= 0) continue;
            $ins->execute([$uid, (int)$pid, min(90, $pct)]);
            $n++;
        }
        $db->commit();
        $flash_ok = $n ? "Descuentos guardados ($n producto" . ($n > 1 ? 's' : '') . ')' : 'Sin descuentos por producto.';
    }

    if ($action === 'guardar_ficha') {
        [$err, $cambios] = guardar_ficha_post($c, $_POST, true);
        $flash_err = implode(' ', $err);
        if (!$err) $flash_ok = $cambios ? 'Ficha guardada.' : 'No había cambios.';
    }

    if ($action === 'guardar_direccion') {
        [, $err] = guardar_direccion_post($uid, $_POST);
        if ($err) $flash_err = $err; else $flash_ok = 'Dirección guardada.';
    }
    if ($action === 'borrar_direccion') { borrar_direccion($uid, (int)$_POST['dir_id']); $flash_ok = 'Dirección eliminada.'; }
    if ($action === 'predeterminada' && direccion($uid, (int)$_POST['dir_id'])) { marcar_predeterminada($uid, (int)$_POST['dir_id']); $flash_ok = 'Predeterminada actualizada.'; }

    $c = cliente($uid);
    if ($c && ficha_completa($c) && empty($c['ficha_completa_en'])) {
        db()->prepare('UPDATE usuarios SET ficha_completa_en = NOW() WHERE id = ?')->execute([$uid]);
        $c = cliente($uid);
    }
}

$dirs   = direcciones($uid);
$editar = isset($_GET['dir']) ? direccion($uid, (int)$_GET['dir']) : null;
$dtos   = descuentos_producto($uid);
$prods  = db()->query("SELECT id, codigo, nombre, presentacion, activo, precio_distribuidor, precio_pto_venta FROM productos
                       ORDER BY activo DESC, (seccion='nuevo'), orden, id")->fetchAll();
$campo  = campo_precio((int)$c['lista_id']);
$aj_gen = (float)get_config('ajuste_lista_' . (int)$c['lista_id'], '0');
$rec_gen = (float)get_config('recargo_sin_factura', '0');
$ajuste = ajuste_cliente($c);
$st = db()->prepare("SELECT COUNT(*) n, MAX(created_at) ult, SUM(COALESCE(total_con_dto, total)) tot FROM pedidos WHERE usuario_id = ? AND estado <> 'cancelado'");
$st->execute([$uid]);
$resumen = $st->fetch();
$completa = ficha_completa($c);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Ficha · <?= escape($c['nombre']) ?> — Admin Panaro</title>
<link rel="stylesheet" href="style.css">
<?= ui_clientes_css() ?>
<?= ui_direccion_js() ?>
<style>
body{background:#f4f4f4}
.adm-top{background:var(--negro);padding:.7rem 1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}
.adm-top img{height:30px}
.adm-top a{color:#bbb;font-size:.82rem;text-decoration:none;border:1px solid #444;border-radius:5px;padding:.3rem .7rem}
.wrap{max-width:980px;margin:0 auto;padding:1.2rem 1rem 3rem}
.cab{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1rem}
.cab h1{font-family:'Bebas Neue',sans-serif;font-size:2rem;letter-spacing:.03em;margin:0;line-height:1}
.cab .sub{font-size:.84rem;color:#666;margin-top:.3rem}
.kpis{display:flex;gap:.6rem;flex-wrap:wrap}
.kpi{background:#fff;border-radius:8px;padding:.5rem .8rem;box-shadow:0 1px 6px rgba(0,0,0,.06);font-size:.75rem;color:#777}
.kpi b{display:block;font-size:1rem;color:var(--negro)}
.fc-card.interno{border-top:4px solid #7c3aed}
.fc-card.interno h3{color:#5b21b6}
.dto-tabla{width:100%;border-collapse:collapse;font-size:.84rem}
.dto-tabla th{font-size:.66rem;text-transform:uppercase;letter-spacing:.05em;color:#888;text-align:left;padding:.4rem .5rem;border-bottom:1px solid #eee}
.dto-tabla td{padding:.35rem .5rem;border-bottom:1px solid #f2f2f2}
.dto-tabla input{width:72px;padding:.35rem .45rem;border:1.5px solid var(--gris-2);border-radius:5px;font:inherit;text-align:right}
.dto-tabla tr.on{background:#f0fdf4}
.dto-tabla .sku{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.76rem;font-weight:700;white-space:nowrap}
.dto-scroll{max-height:420px;overflow:auto;border:1px solid #eee;border-radius:7px}
</style>
</head>
<body>
<div class="adm-top">
  <img src="assets/img/logo.png" alt="Panaro">
  <div style="display:flex;gap:.5rem"><a href="admin.php?s=usuarios">← Usuarios</a><a href="admin.php?s=pedidos&u=<?= $uid ?>">Pedidos</a></div>
</div>
<div class="wrap">
  <?php if ($flash_ok): ?><div class="flash-ok"><?= escape($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash-err"><?= escape($flash_err) ?></div><?php endif; ?>

  <div class="cab">
    <div>
      <h1><?= escape($c['nombre']) ?></h1>
      <div class="sub"><?= escape($c['email']) ?> · <span style="color:<?= escape(color_lista((int)$c['lista_id'])) ?>;font-weight:700"><?= escape(nombre_lista((int)$c['lista_id'])) ?></span>
        · <?= $completa ? '<span style="color:#16a34a;font-weight:700">Ficha completa</span>' : '<span style="color:#b45309;font-weight:700">Ficha incompleta</span>' ?>
        <?= $c['ficha_actualizada_en'] ? ' · actualizada ' . date('d/m/Y', strtotime($c['ficha_actualizada_en'])) : '' ?></div>
    </div>
    <div class="kpis">
      <div class="kpi">Pedidos<b><?= (int)$resumen['n'] ?></b></div>
      <div class="kpi">Total comprado<b><?= precio_fmt((float)$resumen['tot']) ?></b></div>
      <div class="kpi">Último pedido<b><?= $resumen['ult'] ? date('d/m/y', strtotime($resumen['ult'])) : '—' ?></b></div>
      <?php if ($c['limite_credito'] !== null): ?><div class="kpi">Límite de crédito<b><?= precio_fmt((float)$c['limite_credito']) ?></b></div><?php endif; ?>
    </div>
  </div>

  <!-- Condiciones comerciales -->
  <form method="post" class="fc-card interno">
    <input type="hidden" name="action" value="guardar_condiciones">
    <input type="hidden" name="uid" value="<?= $uid ?>">
    <h3>Condiciones comerciales</h3>
    <p class="fc-sub">Solo las ve el admin. Se guardan en cada pedido al momento de hacerlo.</p>
    <div class="fc-grid">
      <div class="fc-f"><label>Lista de precios</label>
        <select name="lista_id"><?php foreach (LISTAS as $id => $l): ?><option value="<?= $id ?>" <?= (int)$c['lista_id'] === $id ? 'selected' : '' ?>><?= escape($l['nombre']) ?></option><?php endforeach; ?></select></div>
      <div class="fc-f"><label>Ajuste de lista (%)</label>
        <input type="number" name="ajuste_lista" step="0.1" min="-90" max="100" value="<?= $c['ajuste_lista'] !== null ? escape((string)(float)$c['ajuste_lista']) : '' ?>" placeholder="General de la lista: <?= escape(pct_fmt($aj_gen)) ?>">
        <small>Negativo = descuento, positivo = recargo. Vacío = el general de la lista (<?= escape(pct_fmt($aj_gen)) ?>). Hoy aplica: <strong><?= ($ajuste > 0 ? '+' : '') . escape(pct_fmt($ajuste)) ?></strong>.</small></div>
      <div class="fc-f"><label>Comprobante (pedidos con factura)</label>
        <select name="comprobante">
          <option value="">Automático según IVA (<?= escape(COMPROBANTES[comprobante_auto($c)]) ?>)</option>
          <option value="A" <?= $c['comprobante'] === 'A' ? 'selected' : '' ?>>Factura A</option>
          <option value="B" <?= $c['comprobante'] === 'B' ? 'selected' : '' ?>>Factura B</option>
        </select></div>
      <div class="fc-f"><label>Recargo pedidos sin factura</label>
        <select name="recargo_sin_factura">
          <option value="">General (<?= escape(pct_fmt($rec_gen)) ?>)</option>
          <?php foreach (RECARGOS_SIN_FACTURA as $k => $t): ?>
            <option value="<?= $k ?>" <?= $c['recargo_sin_factura'] !== null && (float)$c['recargo_sin_factura'] === (float)$k ? 'selected' : '' ?>><?= $t ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fc-f"><label>Condición de pago</label>
        <select name="condicion_pago"><?= sel_opts(CONDICIONES_PAGO, $c['condicion_pago'] ?? 'anticipado', false) ?></select></div>
      <div class="fc-f"><label>Límite de crédito ($) <span class="opc">(informativo)</span></label>
        <input type="number" name="limite_credito" step="1000" min="0" value="<?= $c['limite_credito'] !== null ? escape((string)(float)$c['limite_credito']) : '' ?>"></div>
      <div class="fc-f"><label>Vendedor / zona</label>
        <input type="text" name="vendedor" maxlength="80" value="<?= escape($c['vendedor'] ?? '') ?>"></div>
      <div class="fc-f" style="display:flex;align-items:flex-end"><label style="display:flex;gap:.45rem;align-items:center;text-transform:none;letter-spacing:0;font-size:.86rem;color:var(--negro);font-weight:600;margin:0 0 .6rem">
        <input type="checkbox" name="fiscal_verificado" value="1" <?= !empty($c['fiscal_verificado']) ? 'checked' : '' ?>> Datos fiscales verificados <span class="opc" style="font-weight:400;color:#888">(el cliente ya no puede cambiarlos)</span></label></div>
      <div class="fc-f full"><label>Notas internas</label>
        <textarea name="notas_internas" rows="2"><?= escape($c['notas_internas'] ?? '') ?></textarea></div>
    </div>
    <div class="fc-btns"><button class="fc-btn" style="background:#7c3aed">Guardar condiciones</button></div>
  </form>

  <!-- Descuentos por producto -->
  <form method="post" class="fc-card interno">
    <input type="hidden" name="action" value="guardar_descuentos">
    <input type="hidden" name="uid" value="<?= $uid ?>">
    <h3>Descuentos por producto</h3>
    <p class="fc-sub">El cliente los ve en el pedido: precio tachado y «−X %». Se suman al ajuste de lista y a los códigos de descuento. Dejá vacío para no aplicar.</p>
    <div class="dto-scroll">
      <table class="dto-tabla">
        <thead><tr><th>SKU</th><th>Producto</th><th style="text-align:right">Su precio</th><th style="text-align:right">Desc. %</th><th style="text-align:right">Final</th></tr></thead>
        <tbody>
        <?php foreach ($prods as $p):
          $d  = $dtos[(int)$p['id']] ?? 0;
          $pc = precio_cliente((float)$p[$campo], $ajuste, $d); ?>
          <tr class="<?= $d > 0 ? 'on' : '' ?>" style="<?= $p['activo'] ? '' : 'opacity:.45' ?>">
            <td class="sku"><?= escape($p['codigo']) ?></td>
            <td><?= escape($p['nombre']) ?> <span style="color:#888;font-size:.76rem"><?= escape(presentacion_corta($p['presentacion'])) ?></span><?= $p['activo'] ? '' : ' <em style="font-size:.72rem">(inactivo)</em>' ?></td>
            <td style="text-align:right"><?= precio_fmt($pc['lista']) ?></td>
            <td style="text-align:right"><input type="number" name="dto[<?= $p['id'] ?>]" step="0.5" min="0" max="90" value="<?= $d > 0 ? escape((string)$d) : '' ?>"></td>
            <td style="text-align:right;font-weight:700"><?= precio_fmt($pc['final']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="fc-btns"><button class="fc-btn" style="background:#7c3aed">Guardar descuentos</button></div>
  </form>

  <!-- Direcciones -->
  <div class="fc-card" id="direcciones">
    <h3>Direcciones de entrega</h3>
    <?php if ($dirs && !$editar): ?>
      <div class="dir-lista" style="margin-bottom:1rem">
      <?php foreach ($dirs as $d):
        $acc = '<div class="dir-acc"><a href="?id=' . $uid . '&dir=' . $d['id'] . '#direcciones">Editar</a>'
             . (empty($d['predeterminada']) ? '<form method="post" style="display:inline"><input type="hidden" name="uid" value="' . $uid . '"><input type="hidden" name="action" value="predeterminada"><input type="hidden" name="dir_id" value="' . $d['id'] . '"><button>Predeterminada</button></form>' : '')
             . '<form method="post" style="display:inline" onsubmit="return confirm(\'¿Eliminar esta dirección?\')"><input type="hidden" name="uid" value="' . $uid . '"><input type="hidden" name="action" value="borrar_direccion"><input type="hidden" name="dir_id" value="' . $d['id'] . '"><button style="color:#dc2626">Eliminar</button></form></div>';
        echo ui_direccion_tarjeta($d, $c, $acc);
      endforeach; ?>
      </div>
    <?php elseif (!$dirs): ?>
      <p class="fc-sub">Todavía no tiene direcciones cargadas.</p>
    <?php endif; ?>
    <details <?= $editar ? 'open' : '' ?>>
      <summary style="cursor:pointer;font-weight:700;font-size:.88rem;margin-bottom:.8rem"><?= $editar ? 'Editar dirección' : '+ Agregar dirección' ?></summary>
      <form method="post">
        <input type="hidden" name="action" value="guardar_direccion">
        <input type="hidden" name="uid" value="<?= $uid ?>">
        <?php ui_direccion_campos($editar ?: ['predeterminada' => !$dirs]); ?>
        <div class="fc-btns"><button class="fc-btn">Guardar dirección</button><?php if ($editar): ?><a class="fc-link" href="?id=<?= $uid ?>#direcciones">Cancelar</a><?php endif; ?></div>
      </form>
    </details>
  </div>

  <!-- Ficha -->
  <form method="post" class="fc-card" id="ficha">
    <input type="hidden" name="action" value="guardar_ficha">
    <input type="hidden" name="uid" value="<?= $uid ?>">
    <h3>Datos del cliente</h3>
    <p class="fc-sub">Lo que completa el cliente en «Mis datos». Desde acá podés corregir todo, incluso los datos fiscales verificados.</p>
    <?php ui_ficha_campos($c, false); ?>
    <div class="fc-btns"><button class="fc-btn">Guardar datos</button></div>
  </form>
</div>
</body>
</html>
