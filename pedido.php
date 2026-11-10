<?php
// ============================================================
//  pedido.php — v3: dirección por defecto, 12+1, códigos dto,
//               novedades, link admin↔portal
// ============================================================
require 'config.php';
auth_required();
$u = current_user();

$lista_id     = (int)($u['lista_id'] ?? 3);
$lista_nombre = nombre_lista($lista_id);
$lista_color  = color_lista($lista_id);
$campo_precio = campo_precio($lista_id);

$flash_ok  = '';
$flash_err = '';
$pedido_id = null;
$wa_link   = '';

// ── Promo 12+1 config ───────────────────────────────────────
$promo12_activa  = get_config('promo12x1_activa','0') === '1';
$promo12_listas  = (int)get_config('promo12x1_listas','7');
$promo12_aplica  = $promo12_activa && (($promo12_listas & bit_lista($lista_id)) > 0);

// ── Procesar envío ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enviar') {
    $cantidades  = $_POST['qty']      ?? [];
    $regalos     = $_POST['qty_reg']  ?? [];
    $notas       = trim($_POST['notas']      ?? '');
    $envio_info  = trim($_POST['envio_info'] ?? '');
    $cod_dto     = strtoupper(trim($_POST['codigo_descuento'] ?? ''));

    $productos_bd = db()->query("SELECT * FROM productos WHERE activo=1 ORDER BY orden ASC")->fetchAll();
    $prod_map     = array_column($productos_bd, null, 'id');

    $items = [];
    $total = 0;
    foreach ($cantidades as $pid => $qty) {
        $qty = (int)$qty;
        if ($qty <= 0) continue;
        $pid  = (int)$pid;
        if (!isset($prod_map[$pid])) continue;
        $p    = $prod_map[$pid];
        $prec = (float)$p[$campo_precio];
        $sub  = $prec * $qty;
        $total += $sub;
        $items[] = ['producto_id'=>$pid,'nombre'=>nombre_item($p),'precio_unit'=>$prec,'cantidad'=>$qty,'subtotal'=>$sub,'regalo'=>0];
    }
    // Items de regalo (precio $0)
    if ($promo12_aplica) {
        foreach ($cantidades as $pid => $qty) {
            $qty = (int)$qty;
            if ($qty < 12) continue;
            $pid  = (int)$pid;
            if (!isset($prod_map[$pid])) continue;
            $reg = calc_regalo($qty);
            if ($reg > 0) {
                $items[] = ['producto_id'=>$pid,'nombre'=>nombre_item($prod_map[$pid]).' [REGALO 12+1]',
                            'precio_unit'=>0,'cantidad'=>$reg,'subtotal'=>0,'regalo'=>1];
            }
        }
    }

    // Validar código de descuento
    $monto_dto  = 0;
    $cod_dto_ok = '';
    if ($cod_dto) {
        $val = validar_codigo($cod_dto, $lista_id, $u['id'], $total);
        if ($val['ok']) {
            $monto_dto  = (float)$val['dto'];
            $cod_dto_ok = $cod_dto;
        } else {
            $flash_err = $val['error'];
        }
    }
    $total_final = max(0, $total - $monto_dto);

    if (empty($items) && !$flash_err) {
        $flash_err = 'Agregá al menos un producto al pedido.';
    }

    if (!$flash_err) {
        $db = db();
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO pedidos (usuario_id,lista_id,total,notas,envio_info,codigo_descuento,monto_descuento,total_con_dto)
                          VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$u['id'],$lista_id,$total,$notas,$envio_info,
                          $cod_dto_ok ?: null, $monto_dto, $total_final]);
            $pedido_id = $db->lastInsertId();
            $st = $db->prepare('INSERT INTO pedido_items (pedido_id,producto_id,nombre,precio_unit,cantidad,subtotal) VALUES (?,?,?,?,?,?)');
            foreach ($items as $it)
                $st->execute([$pedido_id,$it['producto_id'],$it['nombre'],$it['precio_unit'],$it['cantidad'],$it['subtotal']]);
            // Incrementar uso del código
            if ($cod_dto_ok) {
                $db->prepare('UPDATE codigos_descuento SET usos_actuales=usos_actuales+1 WHERE codigo=?')
                   ->execute([$cod_dto_ok]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            $flash_err = 'Error al guardar: '.$e->getMessage();
            $pedido_id = null;
        }

        if ($pedido_id) {
            $fecha = date('d/m/Y H:i');
            // Email
            $filas = '';
            foreach ($items as $it) {
                $filas .= "<tr>
                  <td style='padding:8px 12px;border-bottom:1px solid #eee'>{$it['nombre']}</td>
                  <td style='padding:8px 12px;border-bottom:1px solid #eee;text-align:center'>{$it['cantidad']}</td>
                  <td style='padding:8px 12px;border-bottom:1px solid #eee;text-align:right'>".precio_fmt($it['precio_unit'])."</td>
                  <td style='padding:8px 12px;border-bottom:1px solid #eee;text-align:right;font-weight:700'>".precio_fmt($it['subtotal'])."</td>
                </tr>";
            }
            $dto_html = $monto_dto > 0
                ? "<tr><td colspan='3' style='padding:8px 12px;color:#22c55e'>Descuento ({$cod_dto_ok})</td><td style='padding:8px 12px;color:#22c55e;text-align:right;font-weight:700'>− ".precio_fmt($monto_dto)."</td></tr>"
                : '';
            $extra = ($notas      ? "<p><strong>Notas:</strong> ".escape($notas)."</p>" : '').
                     ($envio_info ? "<p><strong>Envío:</strong> ".escape($envio_info)."</p>" : '');

            $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
            <body style='font-family:Arial,sans-serif;background:#f4f4f4;padding:20px'>
            <div style='max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden'>
              <div style='background:#0d0d0d;padding:20px;text-align:center'>
                <h1 style='color:#fff;margin:0'>Panaro Car Care</h1>
                <p style='color:#bbb;font-size:14px;margin:4px 0 0'>Pedido #{$pedido_id} — {$lista_nombre}</p>
              </div>
              <div style='padding:24px'>
                <p><strong>Cliente:</strong> ".escape($u['nombre'])." — <strong>Lista:</strong> {$lista_nombre}</p>
                <p style='color:#666;font-size:13px'>{$fecha}</p>
                <table style='width:100%;border-collapse:collapse'>
                  <thead><tr style='background:#f4f4f4'>
                    <th style='padding:8px 12px;text-align:left;font-size:11px'>Producto</th>
                    <th style='padding:8px 12px;text-align:center;font-size:11px'>Cant.</th>
                    <th style='padding:8px 12px;text-align:right;font-size:11px'>Precio</th>
                    <th style='padding:8px 12px;text-align:right;font-size:11px'>Subtotal</th>
                  </tr></thead>
                  <tbody>{$filas}{$dto_html}</tbody>
                </table>
                <div style='text-align:right;border-top:2px solid #0d0d0d;padding-top:12px;margin-top:8px'>
                  <span style='font-size:20px;font-weight:700'>TOTAL: ".precio_fmt($total_final)."</span>
                </div>
                {$extra}
              </div>
            </div></body></html>";

            $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: ".MAIL_NAME." <".MAIL_FROM.">\r\nReply-To: ".MAIL_TO."\r\n";
            $subj = '=?UTF-8?B?'.base64_encode("Pedido #{$pedido_id} — {$u['nombre']}").'?=';
            @mail(MAIL_TO, $subj, $html, $headers);
            if ($u['email']) @mail($u['email'], $subj, $html, $headers);

            // WhatsApp
            $wt  = "*Panaro Car Care — Pedido #{$pedido_id}*\n";
            $wt .= "Lista: {$lista_nombre} | {$fecha}\nCliente: {$u['nombre']}\n";
            foreach ($items as $it)
                $wt .= "• {$it['nombre']} ×{$it['cantidad']}" . ($it['precio_unit'] > 0 ? " — ".precio_fmt($it['subtotal']) : " [REGALO]") . "\n";
            if ($monto_dto > 0) $wt .= "Descuento ({$cod_dto_ok}): − ".precio_fmt($monto_dto)."\n";
            $wt .= "*TOTAL: ".precio_fmt($total_final)."*";
            if ($notas)      $wt .= "\nNotas: {$notas}";
            if ($envio_info) $wt .= "\nEnvío: {$envio_info}";
            $wa_link = 'https://wa.me/'.WA_NUMBER.'?text='.urlencode($wt);

            // ── TELEGRAM ─────────────────────────────────────────
            $telegram  = "🛒 NUEVO PEDIDO MAYORISTA\n\n";
            $telegram .= "Pedido #{$pedido_id}\n";
            $telegram .= "Cliente: {$u['nombre']}\n";
            $telegram .= "Lista: {$lista_nombre}\n";
            $telegram .= "Fecha: {$fecha}\n\n";
            foreach ($items as $it) {
                $telegram .= "• {$it['nombre']} x{$it['cantidad']}";
                if ($it['precio_unit'] > 0) {
                    $telegram .= " — ".precio_fmt($it['subtotal']);
                } else {
                    $telegram .= " [REGALO]";
                }
                $telegram .= "\n";
            }
            if ($monto_dto > 0) {
                $telegram .= "\nDescuento ({$cod_dto_ok}): − ".precio_fmt($monto_dto)."\n";
            }
            $telegram .= "\nTOTAL: ".precio_fmt($total_final)."\n";
            if ($notas)      $telegram .= "\nNotas: {$notas}\n";
            if ($envio_info) $telegram .= "\nEnvío: {$envio_info}\n";
            enviar_telegram($telegram);
            // ─────────────────────────────────────────────────────

            $flash_ok = "✅ ¡Pedido #{$pedido_id} enviado! Te llega una copia por email.";
        }
    }
}

// Cargar datos
$productos = db()->query(
    "SELECT id,codigo,nombre,descripcion,presentacion,seccion,imagen,{$campo_precio} AS mi_precio,pvp_sugerido
     FROM productos WHERE activo=1 AND {$campo_precio} > 0
     ORDER BY (seccion = 'nuevo') ASC, orden ASC, id ASC"
)->fetchAll();

$promo_activa = get_config('promo_activa','1') === '1';
$promo_monto  = (float)get_config('promo_monto','50000');
$promo_texto  = get_config('promo_texto','Envío gratis a partir de $50.000');

// Dirección por defecto del usuario
$dir_default = $u['direccion'] ?? '';

// Novedades activas
$novedades = get_novedades($lista_id);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Nuevo Pedido — Panaro Car Care</title>
<link rel="stylesheet" href="style.css">
<style>
/* ── Layout fijo iOS-safe ─────────────────────────────────── */
.page-layout{display:flex;flex-direction:column;height:100vh;overflow:hidden}
.header-fixo{flex-shrink:0;background:var(--negro);box-shadow:0 2px 12px rgba(0,0,0,.5);z-index:100}
.page-scroll{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch}
.logo{height:26px;width:auto;max-width:30%;object-fit:contain;flex-shrink:1}
@media(min-width:600px){.logo{height:40px;max-width:none}}
.site-header{background:var(--negro);display:flex;align-items:center;justify-content:space-between;padding:.55rem .8rem;gap:.4rem}

/* ── Promo bar ────────────────────────────────────────────── */
.promo-bar-sticky{background:#111;border-top:1px solid #1e1e1e;padding:.5rem .8rem;display:flex;flex-direction:column;gap:.35rem}
.promo-top-row{display:flex;justify-content:space-between;align-items:center;font-size:.75rem}
.promo-top-row .ptexto{color:#bbb}.promo-top-row .pfalta{color:#666;font-weight:600}
.promo-top-row .pfalta.ok{color:#22c55e}
.promo-track{background:#222;border-radius:99px;height:5px;overflow:hidden}
.promo-fill{height:100%;border-radius:99px;background:var(--rojo);transition:width .4s ease,background .3s ease}
.promo-fill.ok{background:#22c55e}

/* ── Novedades ────────────────────────────────────────────── */
.novedades-wrap{margin-bottom:1rem}
.novedad-card{background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);overflow:hidden;margin-bottom:.7rem;border-left:4px solid var(--rojo)}
.novedad-card:last-child{margin-bottom:0}
.novedad-body{padding:.9rem 1rem}
.novedad-titulo{font-family:'Bebas Neue',sans-serif;font-size:1.2rem;color:var(--negro);margin-bottom:.3rem;letter-spacing:.04em}
.novedad-texto{font-size:.85rem;color:#444;line-height:1.5}
.novedad-img{width:100%;max-height:180px;object-fit:cover}

/* ── Botones +/− ─────────────────────────────────────────── */
.qty-ctrl{display:flex;align-items:center;gap:5px}
.qty-btn{width:38px;height:38px;border-radius:7px;border:none;font-size:20px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;flex-shrink:0;padding:0;position:relative;-webkit-tap-highlight-color:transparent;touch-action:manipulation;user-select:none;transition:opacity .15s ease,transform .1s ease}
.qty-btn::after{content:'';position:absolute;inset:-6px}
.qty-btn *{pointer-events:none}
.qty-btn:active{transform:scale(.9);opacity:.8}
.qty-btn-minus{background:var(--gris-2);color:var(--gris-4)}
.qty-btn-minus.activo{background:#fee2e2;color:var(--rojo)}
.qty-btn-plus{background:var(--rojo);color:#fff}
.qty-btn-plus:hover{background:var(--rojo-d)}
.qty-num{width:42px;height:38px;text-align:center;border:1.5px solid var(--gris-2);border-radius:5px;font-size:15px;font-weight:700;color:var(--negro);outline:none;-moz-appearance:textfield;font-family:inherit;transition:border-color .15s ease}
.qty-num::-webkit-outer-spin-button,.qty-num::-webkit-inner-spin-button{-webkit-appearance:none}
.qty-num.activo{border-color:var(--rojo);background:#fff8f8}

/* ── Regalo badge ─────────────────────────────────────────── */
.regalo-badge{display:inline-flex;align-items:center;gap:4px;background:#dcfce7;color:#166534;font-size:.72rem;font-weight:700;padding:.2rem .5rem;border-radius:99px;margin-top:3px;animation:popIn .3s ease}
@keyframes popIn{from{transform:scale(0);opacity:0}to{transform:scale(1);opacity:1}}

/* ── Código de descuento ──────────────────────────────────── */
.dto-wrap{background:var(--blanco);border-radius:10px;padding:1rem;box-shadow:var(--sombra);margin-top:1.2rem}
.dto-wrap h3{font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);margin-bottom:.6rem}
.dto-input-row{display:flex;gap:.5rem}
.dto-input-row input{flex:1;padding:.6rem .8rem;border:1.5px solid var(--gris-2);border-radius:6px;font-family:inherit;font-size:.9rem;text-transform:uppercase;outline:none;transition:border-color .15s}
.dto-input-row input:focus{border-color:var(--rojo)}
.dto-input-row button{padding:.6rem 1rem;background:var(--negro);color:#fff;border:none;border-radius:6px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;white-space:nowrap}
.dto-ok{color:#166534;font-size:.82rem;font-weight:600;margin-top:.4rem}
.dto-err{color:var(--rojo);font-size:.82rem;margin-top:.4rem}
.dto-linea{display:flex;justify-content:space-between;color:#22c55e;font-size:.88rem;font-weight:600;padding:.4rem 0;border-bottom:1px solid #222}

/* ── Resumen columnas igual altura ────────────────────────── */
.resumen-wrap{margin-top:1.5rem;display:flex;gap:1.5rem;align-items:stretch}
.notas-box,.total-box{flex:1}
.notas-box{display:flex;flex-direction:column;background:var(--blanco);border-radius:10px;box-shadow:var(--sombra);padding:1.2rem}
.notas-box textarea{flex:1}
@media(max-width:768px){
  html,body{overflow-x:hidden;width:100%}
  .tabla-head{display:none}
  .prod-row{display:grid;grid-template-columns:52px 1fr auto;grid-template-rows:auto auto;gap:.3rem .6rem;padding:.9rem .8rem}
  .prod-img,.prod-img-placeholder{grid-row:1/3;width:48px;height:48px}
  .prod-info{grid-column:2;grid-row:1}
  .prod-precio-base{display:none}
  .prod-precio-may{grid-column:3;grid-row:1;text-align:right;font-size:.9rem}
  .qty-ctrl{grid-column:2;grid-row:2}
  .prod-subtotal{grid-column:3;grid-row:2;font-size:.88rem}
  .resumen-wrap{flex-direction:column!important}
  .notas-box,.total-box{width:100%!important;flex:none!important}
  .config-grid{grid-template-columns:1fr}
  .header-right .hide-mobile{display:none}
}
.prod-pres{font-size:.78rem;color:#444;margin-top:2px;line-height:1.3;font-weight:500}
.tag-nuevo{display:inline-block;background:var(--rojo);color:#fff;font-size:.6rem;font-weight:700;letter-spacing:.06em;padding:1px 6px;border-radius:3px;margin-left:4px;vertical-align:middle}
</style>
</head>
<body>
<div class="page-layout">

<div class="header-fixo" id="sticky-header">
  <header class="site-header">
    <img src="assets/img/logo.png" alt="Panaro Car Care" class="logo">
    <div class="header-right" style="gap:.35rem">
      <span class="descuento-badge" style="background:<?= escape($lista_color) ?>;font-size:.65rem;padding:.2rem .5rem;white-space:nowrap;max-width:85px;overflow:hidden;text-overflow:ellipsis">
        <?= escape($lista_nombre) ?>
      </span>
      <?php if (!empty($u['es_admin'])): ?>
      <a href="admin.php" class="btn-logout" style="border-color:#e30613;color:#e30613;white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Admin</a>
      <?php endif; ?>
      <a href="historial.php" class="btn-logout" style="border-color:#444;color:#aaa;white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Pedidos</a>
      <a href="perfil.php" class="btn-logout" style="border-color:#444;color:#aaa;white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Perfil</a>
      <a href="logout.php" class="btn-logout" style="white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Salir</a>
    </div>
  </header>

  <?php if ($promo_activa): ?>
  <div class="promo-bar-sticky" id="promo-bar">
    <div class="promo-top-row">
      <span class="ptexto"><?= escape($promo_texto) ?></span>
      <span class="pfalta" id="promo-falta">$0 / <?= precio_fmt($promo_monto) ?></span>
    </div>
    <div class="promo-track">
      <div class="promo-fill" id="promo-fill" style="width:0%"></div>
    </div>
  </div>
  <?php endif; ?>
</div><!-- /header-fixo -->

<div class="page-scroll">
<div class="main-wrap">

  <?php if ($flash_ok): ?><div class="flash-ok"><?= escape($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash-err"><?= escape($flash_err) ?></div><?php endif; ?>

  <!-- Novedades -->
  <?php if ($novedades): ?>
  <div class="novedades-wrap">
    <?php foreach ($novedades as $nov): ?>
    <div class="novedad-card">
      <?php if ($nov['imagen']): ?>
        <img src="assets/img/novedades/<?= escape($nov['imagen']) ?>" alt="" class="novedad-img">
      <?php endif; ?>
      <div class="novedad-body">
        <div class="novedad-titulo">📢 <?= escape($nov['titulo']) ?></div>
        <div class="novedad-texto"><?= nl2br(escape($nov['contenido'])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Encabezado -->
  <div class="pedido-header">
    <h2>Nuevo Pedido
      <span id="items-badge" class="items-badge" style="display:none">0</span>
    </h2>
    <div style="font-size:.8rem;color:var(--gris-4);text-align:right">
      Precios lista <strong style="color:<?= escape($lista_color) ?>"><?= escape($lista_nombre) ?></strong><br>
      <a href="listas.php" style="color:var(--rojo);font-weight:600;text-decoration:none">📄 Descargar listas de precios →</a>
    </div>
  </div>

  <form method="post" id="form-pedido">
    <input type="hidden" name="action" value="enviar">

    <!-- Tabla de productos -->
    <div class="tabla-pedido">
      <div class="tabla-head">
        <span></span>
        <span>Producto</span>
        <span class="th-precio">PVP Sugerido</span>
        <span class="th-precio">Tu precio</span>
        <span class="th-cant" style="text-align:center">Cantidad</span>
        <span class="th-sub">Subtotal</span>
      </div>

      <?php foreach ($productos as $i => $p): ?>
      <div class="prod-row" id="row-<?= $p['id'] ?>" style="animation-delay:<?= $i*.025 ?>s">
        <?php if ($p['imagen']): ?>
          <img src="assets/img/productos/<?= escape($p['imagen']) ?>" alt="<?= escape($p['nombre']) ?>" class="prod-img">
        <?php else: ?>
          <div class="prod-img-placeholder">🧴</div>
        <?php endif; ?>

        <div class="prod-info">
          <div class="prod-nombre"><?= escape($p['nombre']) ?>
            <?php if (($p['seccion'] ?? '') === 'nuevo'): ?><span class="tag-nuevo">NUEVO</span><?php endif; ?>
          </div>
          <?php if (!empty($p['presentacion'])): ?>
            <div class="prod-pres"><?= nl2br(escape($p['presentacion'])) ?></div>
          <?php endif; ?>
          <div style="font-size:.7rem;color:#aaa;margin-top:1px"><?= escape($p['codigo'] ?? '') ?></div>
          <?php if ($promo12_aplica): ?>
            <div id="reg-badge-<?= $p['id'] ?>" style="display:none" class="regalo-badge">🎁 <span id="reg-txt-<?= $p['id'] ?>"></span></div>
          <?php endif; ?>
        </div>

        <div class="prod-precio-base"><?= precio_fmt((float)$p['pvp_sugerido']) ?></div>
        <div class="prod-precio-may" style="color:<?= escape($lista_color) ?>"><?= precio_fmt((float)$p['mi_precio']) ?></div>

        <div class="qty-ctrl">
          <button type="button" class="qty-btn qty-btn-minus" onclick="cambiarQty(<?= $p['id'] ?>,-1)">−</button>
          <input type="number" class="qty-num" name="qty[<?= $p['id'] ?>]"
                 id="qty-<?= $p['id'] ?>" min="0" max="9999" value="0"
                 data-precio="<?= (float)$p['mi_precio'] ?>" data-id="<?= $p['id'] ?>"
                 oninput="recalcular()" onfocus="this.select()" autocomplete="off">
          <button type="button" class="qty-btn qty-btn-plus" onclick="cambiarQty(<?= $p['id'] ?>,1)">+</button>
        </div>

        <div class="prod-subtotal vacio" id="sub-<?= $p['id'] ?>">—</div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Código de descuento -->
    <div class="dto-wrap">
      <h3>¿Tenés un código de descuento?</h3>
      <div class="dto-input-row">
        <input type="text" id="inp-codigo" placeholder="CÓDIGO" maxlength="60" autocomplete="off"
               oninput="this.value=this.value.toUpperCase()">
        <button type="button" onclick="aplicarCodigo()">Aplicar</button>
      </div>
      <div id="dto-msg"></div>
      <input type="hidden" name="codigo_descuento" id="hid-codigo">
    </div>

    <!-- Resumen + notas -->
    <div class="resumen-wrap">
      <div class="notas-box">
        <h3 style="font-size:1rem;margin-bottom:.8rem">Información adicional</h3>
        <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);display:block;margin-bottom:.3rem">
          Dirección / datos de envío
        </label>
        <input type="text" name="envio_info" id="envio_info"
               value="<?= escape($dir_default) ?>"
               placeholder="Ej: Calle Falsa 123, CABA — L a V 9 a 18hs"
               style="width:100%;padding:.6rem .8rem;border:1.5px solid var(--gris-2);border-radius:6px;font-family:inherit;font-size:.88rem;outline:none;margin-bottom:.8rem">
        <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);display:block;margin-bottom:.3rem">
          Notas del pedido
        </label>
        <textarea name="notas" rows="5"
                  placeholder="Instrucciones especiales, consultas…"
                  style="width:100%;padding:.6rem .8rem;border:1.5px solid var(--gris-2);border-radius:6px;font-family:inherit;font-size:.88rem;outline:none;resize:none;flex:1"></textarea>
      </div>

      <div class="total-box">
        <h3>Resumen del pedido</h3>
        <div class="total-linea">
          <span>Productos</span><span class="val" id="res-items">0</span>
        </div>
        <div class="total-linea">
          <span>Lista</span>
          <span class="val" style="color:<?= escape($lista_color) ?>;font-size:.85rem"><?= escape($lista_nombre) ?></span>
        </div>
        <div id="dto-linea-wrap" style="display:none" class="dto-linea">
          <span id="dto-linea-lbl">Descuento</span>
          <span id="dto-linea-val">— $0</span>
        </div>
        <div class="total-final">
          <span class="lbl">TOTAL</span>
          <span class="monto" id="res-total">$0</span>
        </div>
        <div id="total-original-wrap" style="display:none;text-align:right;margin-top:.2rem">
          <span style="font-size:.78rem;color:#888;text-decoration:line-through" id="res-total-orig"></span>
        </div>
        <button type="submit" class="btn-enviar" id="btn-enviar" disabled>Enviar Pedido</button>
        <?php if ($wa_link): ?>
        <a href="<?= escape($wa_link) ?>" target="_blank" class="btn-wa" style="display:block;margin-top:.6rem">
          📲 Enviar también por WhatsApp
        </a>
        <?php endif; ?>
      </div>
    </div>
  </form>
</div>
</div><!-- /page-scroll -->
</div><!-- /page-layout -->

<script>
(function(){
  const promoMonto  = <?= $promo_activa ? $promo_monto : 0 ?>;
  const promoActiva = <?= $promo_activa ? 'true' : 'false' ?>;
  const promo12     = <?= $promo12_aplica ? 'true' : 'false' ?>;
  let descuento     = 0;
  let codigoOk      = '';

  function fmt(n){ return '$'+Math.round(n).toLocaleString('es-AR'); }

  window.cambiarQty = function(id,d){
    const inp=document.getElementById('qty-'+id);
    inp.value=Math.max(0,(parseInt(inp.value)||0)+d);
    recalcular();
  };

  window.recalcular = function(){
    let total=0, count=0;
    document.querySelectorAll('.qty-num').forEach(inp=>{
      const qty   = Math.max(0,parseInt(inp.value)||0);
      const precio= parseFloat(inp.dataset.precio);
      const id    = inp.dataset.id;
      const sub   = precio*qty;
      const subEl = document.getElementById('sub-'+id);
      const row   = document.getElementById('row-'+id);
      const minus = inp.previousElementSibling;
      inp.value = qty;

      if(qty>0){
        subEl.textContent=fmt(sub); subEl.classList.remove('vacio');
        inp.classList.add('activo'); minus.classList.add('activo');
        row.classList.add('tiene-cant');
        total+=sub; count++;
      } else {
        subEl.textContent='—'; subEl.classList.add('vacio');
        inp.classList.remove('activo'); minus.classList.remove('activo');
        row.classList.remove('tiene-cant');
      }

      // Promo 12+1
      if(promo12){
        const badge = document.getElementById('reg-badge-'+id);
        const txt   = document.getElementById('reg-txt-'+id);
        if(badge && txt){
          const reg    = Math.floor(qty/12);
          const faltan = qty>0 ? 12-(qty%12===0?12:qty%12) : 12;
          if(reg>0){
            badge.style.display='inline-flex';
            txt.textContent = '¡'+reg+' unidad'+(reg>1?'es':'')+' de regalo!';
          } else if(qty>0) {
            badge.style.display='inline-flex';
            badge.style.background='#fef9c3'; badge.style.color='#854d0e';
            txt.textContent = 'Faltan '+faltan+' para regalo';
          } else {
            badge.style.display='none';
          }
        }
      }
    });

    document.getElementById('res-items').textContent = count;
    const totalFinal = Math.max(0, total - descuento);
    document.getElementById('res-total').textContent  = fmt(totalFinal);

    // Mostrar total original si hay descuento
    const origWrap = document.getElementById('total-original-wrap');
    const origEl   = document.getElementById('res-total-orig');
    if(descuento>0 && total>0){
      origWrap.style.display='block';
      origEl.textContent = fmt(total);
    } else {
      origWrap.style.display='none';
    }

    const badge = document.getElementById('items-badge');
    badge.style.display = count>0?'inline':'none';
    badge.textContent   = count;
    document.getElementById('btn-enviar').disabled = (count===0);

    // Promo bar
    if(promoActiva && promoMonto>0){
      const pct  = Math.min(100,(total/promoMonto)*100);
      const fill = document.getElementById('promo-fill');
      const falta= document.getElementById('promo-falta');
      fill.style.width = Math.max(0,pct)+'%';
      if(total>=promoMonto){
        fill.classList.add('ok');
        falta.textContent='✅ ¡Promoción alcanzada!';
        falta.classList.add('ok');
      } else {
        fill.classList.remove('ok');
        falta.textContent=fmt(total)+' / '+fmt(promoMonto);
        falta.classList.remove('ok');
      }
    }
  };

  // Aplicar código de descuento vía AJAX
  window.aplicarCodigo = function(){
    const cod = document.getElementById('inp-codigo').value.trim().toUpperCase();
    const msg = document.getElementById('dto-msg');
    if(!cod){ msg.innerHTML='<div class="dto-err">Ingresá un código.</div>'; return; }

    // Calcular total actual
    let total=0;
    document.querySelectorAll('.qty-num').forEach(inp=>{
      total += parseFloat(inp.dataset.precio)*(parseInt(inp.value)||0);
    });

    fetch('validar_codigo.php?cod='+encodeURIComponent(cod)+'&total='+total)
      .then(r=>r.json())
      .then(data=>{
        if(data.ok){
          descuento  = data.dto;
          codigoOk   = cod;
          document.getElementById('hid-codigo').value = cod;
          msg.innerHTML='<div class="dto-ok">✅ Código aplicado: −'+fmt(data.dto)+'</div>';
          // Mostrar línea de descuento en resumen
          document.getElementById('dto-linea-wrap').style.display='flex';
          document.getElementById('dto-linea-lbl').textContent = 'Descuento ('+cod+')';
          document.getElementById('dto-linea-val').textContent = '− '+fmt(data.dto);
          recalcular();
        } else {
          descuento=0; codigoOk='';
          document.getElementById('hid-codigo').value='';
          document.getElementById('dto-linea-wrap').style.display='none';
          msg.innerHTML='<div class="dto-err">'+data.error+'</div>';
          recalcular();
        }
      })
      .catch(()=>{ msg.innerHTML='<div class="dto-err">Error al validar el código.</div>'; });
  };

  recalcular();
})();
</script>
</body>
</html>
