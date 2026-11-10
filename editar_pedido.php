<?php
// ============================================================
//  editar_pedido.php — Edición de pedidos por el administrador
//  Recalcula regalos 12+1 y descuento automáticamente
// ============================================================
require 'config.php';
admin_required();

$admin = current_user();
$pid   = (int)($_GET['id'] ?? $_POST['pedido_id'] ?? 0);

if (!$pid) { header('Location: admin.php?s=pedidos'); exit; }

$flash_ok  = '';
$flash_err = '';

// ── Cargar pedido ────────────────────────────────────────────
function cargar_pedido(int $pid): ?array {
    $st = db()->prepare(
        'SELECT p.*, u.nombre AS unom, u.email AS uemail, u.whatsapp AS uwa
         FROM pedidos p JOIN usuarios u ON p.usuario_id = u.id
         WHERE p.id = ? LIMIT 1'
    );
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) return null;
    $si = db()->prepare('SELECT * FROM pedido_items WHERE pedido_id=? ORDER BY id ASC');
    $si->execute([$pid]);
    $p['items'] = $si->fetchAll();
    return $p;
}

$ped = cargar_pedido($pid);
if (!$ped) { die('<div style="font-family:system-ui;padding:2rem">Pedido no encontrado. <a href="admin.php?s=pedidos">Volver</a></div>'); }

$lista_id     = (int)$ped['lista_id'];
$lista_nombre = nombre_lista($lista_id);
$lista_color  = color_lista($lista_id);
$campo_precio = campo_precio($lista_id);

// ── Promo 12+1 (según config actual y lista del cliente) ─────
$promo12_activa = get_config('promo12x1_activa','0') === '1';
$promo12_listas = (int)get_config('promo12x1_listas','7');
$promo12_aplica = $promo12_activa && (($promo12_listas & bit_lista($lista_id)) > 0);

// ── Recalcular descuento sin chequear límite de usos ─────────
// (el uso ya fue contabilizado al crear el pedido)
function recalcular_descuento(?string $codigo, float $total): array {
    if (!$codigo) return ['dto' => 0.0, 'aviso' => ''];
    $st = db()->prepare('SELECT * FROM codigos_descuento WHERE codigo=? LIMIT 1');
    $st->execute([$codigo]);
    $c = $st->fetch();
    if (!$c) return ['dto' => 0.0, 'aviso' => "El código {$codigo} ya no existe. Se quitó el descuento."];
    if ($total < (float)$c['monto_minimo']) {
        return ['dto' => 0.0,
                'aviso' => "El nuevo total no alcanza el mínimo de ".precio_fmt((float)$c['monto_minimo'])." del código {$codigo}. Se quitó el descuento."];
    }
    $dto = $c['tipo'] === 'porcentaje'
        ? round($total * ((float)$c['valor'] / 100), 2)
        : min((float)$c['valor'], $total);
    return ['dto' => (float)$dto, 'aviso' => ''];
}

// ── Guardar cambios ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'guardar_edicion') {

    $cantidades = $_POST['qty'] ?? [];
    $notas      = trim($_POST['notas'] ?? '');
    $envio_info = trim($_POST['envio_info'] ?? '');

    $productos_bd = db()->query("SELECT * FROM productos ORDER BY orden ASC")->fetchAll();
    $prod_map     = array_column($productos_bd, null, 'id');

    $items = [];
    $total = 0.0;

    foreach ($cantidades as $prod_id => $qty) {
        $qty = (int)$qty;
        if ($qty <= 0) continue;
        $prod_id = (int)$prod_id;
        if (!isset($prod_map[$prod_id])) continue;
        $p    = $prod_map[$prod_id];
        $prec = (float)$p[$campo_precio];
        $sub  = $prec * $qty;
        $total += $sub;
        $items[] = [
            'producto_id' => $prod_id,
            'nombre'      => nombre_item($p),
            'precio_unit' => $prec,
            'cantidad'    => $qty,
            'subtotal'    => $sub,
            'es_regalo'   => 0,
        ];
    }

    // Recalcular regalos 12+1
    if ($promo12_aplica) {
        foreach ($cantidades as $prod_id => $qty) {
            $qty = (int)$qty;
            if ($qty < 12) continue;
            $prod_id = (int)$prod_id;
            if (!isset($prod_map[$prod_id])) continue;
            $reg = calc_regalo($qty);
            if ($reg > 0) {
                $items[] = [
                    'producto_id' => $prod_id,
                    'nombre'      => nombre_item($prod_map[$prod_id]).' [REGALO 12+1]',
                    'precio_unit' => 0,
                    'cantidad'    => $reg,
                    'subtotal'    => 0,
                    'es_regalo'   => 1,
                ];
            }
        }
    }

    if (empty($items)) {
        $flash_err = 'El pedido debe tener al menos un producto.';
    } else {
        // Recalcular descuento sobre el nuevo total
        $rec        = recalcular_descuento($ped['codigo_descuento'], $total);
        $monto_dto  = $rec['dto'];
        $aviso_dto  = $rec['aviso'];
        $total_final = max(0, $total - $monto_dto);
        $total_antes = (float)($ped['total_con_dto'] ?: $ped['total']);

        $db = db();
        $db->beginTransaction();
        try {
            // Reemplazar items
            $db->prepare('DELETE FROM pedido_items WHERE pedido_id=?')->execute([$pid]);
            $st = $db->prepare(
                'INSERT INTO pedido_items (pedido_id,producto_id,nombre,precio_unit,cantidad,subtotal,es_regalo)
                 VALUES (?,?,?,?,?,?,?)'
            );
            foreach ($items as $it) {
                $st->execute([$pid, $it['producto_id'], $it['nombre'], $it['precio_unit'],
                              $it['cantidad'], $it['subtotal'], $it['es_regalo']]);
            }

            // Actualizar cabecera
            $db->prepare(
                'UPDATE pedidos
                 SET total=?, monto_descuento=?, total_con_dto=?, notas=?, envio_info=?,
                     codigo_descuento=?, editado_en=NOW(), editado_por=?, veces_editado=veces_editado+1
                 WHERE id=?'
            )->execute([
                $total, $monto_dto, $total_final, $notas, $envio_info,
                $monto_dto > 0 ? $ped['codigo_descuento'] : null,
                $admin['nombre'] ?? 'Admin', $pid
            ]);

            // Log de auditoría
            $resumen = count($items).' ítem(s). Total: '.precio_fmt($total_antes).' → '.precio_fmt($total_final);
            if ($aviso_dto) $resumen .= ' | '.$aviso_dto;
            $db->prepare(
                'INSERT INTO pedido_ediciones (pedido_id,admin_nombre,total_antes,total_despues,detalle)
                 VALUES (?,?,?,?,?)'
            )->execute([$pid, $admin['nombre'] ?? 'Admin', $total_antes, $total_final, $resumen]);

            $db->commit();
            $flash_ok = '✅ Pedido actualizado correctamente.';
            if ($aviso_dto) $flash_err = '⚠️ '.$aviso_dto;

            // Invalidar PDF anterior
            db()->prepare('UPDATE pedidos SET pdf_token=NULL WHERE id=?')->execute([$pid]);

            $ped = cargar_pedido($pid);
        } catch (Throwable $e) {
            $db->rollBack();
            $flash_err = 'Error al guardar: '.$e->getMessage();
        }
    }
}

// ── Cantidades actuales por producto (sin regalos) ───────────
$qty_actual = [];
foreach ($ped['items'] as $it) {
    if (!empty($it['es_regalo']) || (float)$it['precio_unit'] == 0) continue;
    $qty_actual[(int)$it['producto_id']] = (int)$it['cantidad'];
}

$productos = db()->query(
    "SELECT id,codigo,nombre,presentacion,imagen,{$campo_precio} AS mi_precio
     FROM productos WHERE activo=1 ORDER BY orden ASC"
)->fetchAll();

// Productos inactivos que están en el pedido (para no perderlos)
$ids_activos = array_column($productos, 'id');
foreach (array_keys($qty_actual) as $pid_item) {
    if (!in_array($pid_item, $ids_activos)) {
        $ex = db()->prepare("SELECT id,codigo,nombre,presentacion,imagen,{$campo_precio} AS mi_precio FROM productos WHERE id=?");
        $ex->execute([$pid_item]);
        if ($row = $ex->fetch()) $productos[] = $row;
    }
}

// Historial de ediciones
$ediciones = db()->prepare('SELECT * FROM pedido_ediciones WHERE pedido_id=? ORDER BY created_at DESC LIMIT 10');
$ediciones->execute([$pid]);
$ediciones = $ediciones->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Editar Pedido #<?= $pid ?> — Panaro Car Care</title>
<link rel="stylesheet" href="style.css">
<style>
.site-header{background:var(--negro);display:flex;align-items:center;justify-content:space-between;padding:.55rem .8rem;gap:.4rem;position:sticky;top:0;z-index:100}
.logo{height:26px;width:auto;max-width:30%;object-fit:contain}
@media(min-width:600px){.logo{height:40px;max-width:none}}

.edit-head{background:var(--blanco);border-radius:10px;padding:1.2rem;box-shadow:var(--sombra);margin-bottom:1.2rem}
.edit-warn{background:#fff8e6;border:1.5px solid #f0b429;color:#7a5200;padding:.8rem 1rem;border-radius:8px;font-size:.86rem;margin-bottom:1.2rem;line-height:1.5}

.prod-edit{display:grid;grid-template-columns:48px 1fr 120px 150px 120px;gap:.7rem;align-items:center;padding:.65rem .9rem;border-bottom:1px solid var(--gris-2)}
.prod-edit:last-child{border-bottom:none}
.prod-edit:hover{background:#fafafa}
.prod-edit.activo{background:#fff8f8}
.prod-edit img,.prod-ph{width:44px;height:44px;object-fit:cover;border-radius:6px;background:var(--gris-2);display:flex;align-items:center;justify-content:center;font-size:18px}

.qty-ctrl{display:flex;align-items:center;gap:5px;justify-content:center}
.qty-btn{width:36px;height:36px;border-radius:7px;border:none;font-size:19px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;flex-shrink:0;position:relative;-webkit-tap-highlight-color:transparent;touch-action:manipulation;user-select:none}
.qty-btn::after{content:'';position:absolute;inset:-6px}
.qty-btn *{pointer-events:none}
.qty-btn:active{transform:scale(.9)}
.qty-btn-minus{background:var(--gris-2);color:var(--gris-4)}
.qty-btn-minus.activo{background:#fee2e2;color:var(--rojo)}
.qty-btn-plus{background:var(--rojo);color:#fff}
.qty-num{width:44px;height:36px;text-align:center;border:1.5px solid var(--gris-2);border-radius:5px;font-size:15px;font-weight:700;outline:none;-moz-appearance:textfield;font-family:inherit}
.qty-num::-webkit-outer-spin-button,.qty-num::-webkit-inner-spin-button{-webkit-appearance:none}
.qty-num.activo{border-color:var(--rojo);background:#fff8f8}

.regalo-tag{display:inline-block;background:#dcfce7;color:#166534;font-size:.7rem;font-weight:700;padding:.15rem .45rem;border-radius:99px;margin-top:3px}

.resumen-edit{background:var(--negro);border-radius:10px;padding:1.3rem;color:#fff;position:sticky;bottom:1rem;margin-top:1.2rem}
.re-linea{display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid #222;font-size:.88rem;color:#aaa}
.re-linea .v{color:#fff;font-weight:600}
.re-dto{display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid #222;font-size:.88rem;color:#22c55e;font-weight:600}
.re-total{display:flex;justify-content:space-between;align-items:baseline;margin-top:.7rem;padding-top:.7rem;border-top:2px solid #333}
.re-total .l{font-family:'Bebas Neue',sans-serif;font-size:1.3rem;color:#aaa;letter-spacing:.05em}
.re-total .m{font-family:'Bebas Neue',sans-serif;font-size:2rem;color:#fff}

.field label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);display:block;margin-bottom:.3rem}
.field input,.field textarea{width:100%;padding:.6rem .8rem;border:1.5px solid var(--gris-2);border-radius:6px;font-family:inherit;font-size:.9rem;outline:none;resize:vertical}
.field input:focus,.field textarea:focus{border-color:var(--rojo)}
.field{margin-bottom:.8rem}

@media(max-width:768px){
  html,body{overflow-x:hidden}
  .prod-edit{grid-template-columns:44px 1fr auto;grid-template-rows:auto auto;gap:.3rem .6rem}
  .prod-edit img,.prod-ph{grid-row:1/3}
  .pe-info{grid-column:2;grid-row:1}
  .pe-precio{grid-column:3;grid-row:1;text-align:right;font-size:.88rem}
  .qty-ctrl{grid-column:2;grid-row:2;justify-content:flex-start}
  .pe-sub{grid-column:3;grid-row:2;text-align:right;font-size:.88rem}
}
</style>
</head>
<body>

<header class="site-header">
  <img src="assets/img/logo.png" alt="Panaro" class="logo">
  <div class="header-right" style="gap:.35rem">
    <a href="admin.php?s=pedidos&ver=<?= $pid ?>" class="btn-logout" style="border-color:#444;color:#aaa;font-size:.72rem;padding:.25rem .55rem;white-space:nowrap">← Volver</a>
    <a href="logout.php" class="btn-logout" style="font-size:.72rem;padding:.25rem .55rem">Salir</a>
  </div>
</header>

<div class="main-wrap">

  <?php if ($flash_ok):  ?><div class="flash-ok"><?= escape($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash-err"><?= escape($flash_err) ?></div><?php endif; ?>

  <div class="edit-head">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.6rem">
      <div>
        <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.9rem;margin:0">Editar Pedido #<?= $pid ?></h2>
        <div style="font-size:.85rem;color:var(--gris-4);margin-top:.2rem">
          <strong><?= escape($ped['unom']) ?></strong> —
          <span style="color:<?= $lista_color ?>;font-weight:600"><?= escape($lista_nombre) ?></span> —
          <?= date('d/m/Y H:i', strtotime($ped['created_at'])) ?>
        </div>
        <?php if ($ped['veces_editado'] > 0): ?>
        <div style="font-size:.78rem;color:#b45309;margin-top:.3rem">
          ✏️ Editado <?= $ped['veces_editado'] ?> vez(ces) — última: <?= date('d/m/Y H:i', strtotime($ped['editado_en'])) ?> por <?= escape($ped['editado_por']) ?>
        </div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:.4rem;flex-wrap:wrap">
        <a href="exportar_pedido.php?id=<?= $pid ?>&f=pdf" target="_blank" class="btn-sm" style="text-decoration:none;background:#dc2626">📄 PDF</a>
        <a href="exportar_pedido.php?id=<?= $pid ?>&f=excel" class="btn-sm" style="text-decoration:none;background:#16a34a">📊 Excel</a>
      </div>
    </div>
  </div>

  <div class="edit-warn">
    <strong>Al guardar se recalculan automáticamente:</strong><br>
    • Las unidades de regalo 12+1 <?= $promo12_aplica ? '(activas para esta lista)' : '(no aplican a esta lista)' ?><br>
    • El descuento del código <?= $ped['codigo_descuento'] ? '<strong>'.escape($ped['codigo_descuento']).'</strong> sobre el nuevo total' : '(este pedido no tiene código)' ?><br>
    • El cliente <strong>no</strong> recibe aviso automático — coordinalo vos.
  </div>

  <form method="post" id="form-edit">
    <input type="hidden" name="action" value="guardar_edicion">
    <input type="hidden" name="pedido_id" value="<?= $pid ?>">

    <div class="tabla-pedido">
      <?php foreach ($productos as $p):
        $q   = $qty_actual[(int)$p['id']] ?? 0;
        $pr  = (float)$p['mi_precio'];
      ?>
      <div class="prod-edit <?= $q > 0 ? 'activo' : '' ?>" id="row-<?= $p['id'] ?>">
        <?php if ($p['imagen']): ?>
          <img src="assets/img/productos/<?= escape($p['imagen']) ?>" alt="">
        <?php else: ?>
          <div class="prod-ph">🧴</div>
        <?php endif; ?>

        <div class="pe-info">
          <div style="font-weight:600;font-size:.9rem;line-height:1.25"><?= escape($p['nombre']) ?></div>
          <?php if (!empty($p['presentacion'])): ?><div style="font-size:.76rem;color:#444"><?= escape(presentacion_corta($p['presentacion'])) ?></div><?php endif; ?>
          <div style="font-size:.7rem;color:#aaa"><?= escape($p['codigo']) ?></div>
          <?php if ($promo12_aplica): ?>
            <div id="reg-<?= $p['id'] ?>" class="regalo-tag" style="display:none"></div>
          <?php endif; ?>
        </div>

        <div class="pe-precio" style="text-align:right;font-weight:700;color:<?= $lista_color ?>">
          <?= precio_fmt($pr) ?>
        </div>

        <div class="qty-ctrl">
          <button type="button" class="qty-btn qty-btn-minus <?= $q>0?'activo':'' ?>" onclick="cambiar(<?= $p['id'] ?>,-1)">−</button>
          <input type="number" class="qty-num <?= $q>0?'activo':'' ?>" name="qty[<?= $p['id'] ?>]"
                 id="qty-<?= $p['id'] ?>" min="0" max="9999" value="<?= $q ?>"
                 data-precio="<?= $pr ?>" data-id="<?= $p['id'] ?>"
                 oninput="recalc()" onfocus="this.select()">
          <button type="button" class="qty-btn qty-btn-plus" onclick="cambiar(<?= $p['id'] ?>,1)">+</button>
        </div>

        <div class="pe-sub" id="sub-<?= $p['id'] ?>" style="text-align:right;font-weight:700">
          <?= $q > 0 ? precio_fmt($pr*$q) : '—' ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="background:var(--blanco);border-radius:10px;padding:1.2rem;box-shadow:var(--sombra);margin-top:1.2rem">
      <div class="field">
        <label>Dirección / datos de envío</label>
        <input type="text" name="envio_info" value="<?= escape($ped['envio_info'] ?? '') ?>">
      </div>
      <div class="field" style="margin-bottom:0">
        <label>Notas del pedido</label>
        <textarea name="notas" rows="3"><?= escape($ped['notas'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="resumen-edit">
      <div class="re-linea"><span>Productos</span><span class="v" id="r-items">0</span></div>
      <?php if ($promo12_aplica): ?>
      <div class="re-linea"><span>Unidades de regalo</span><span class="v" id="r-regalos" style="color:#22c55e">0</span></div>
      <?php endif; ?>
      <div class="re-linea"><span>Subtotal</span><span class="v" id="r-subtotal">$0</span></div>
      <?php if ($ped['codigo_descuento']): ?>
      <div class="re-dto">
        <span>Descuento (<?= escape($ped['codigo_descuento']) ?>)</span>
        <span id="r-dto">se recalcula al guardar</span>
      </div>
      <?php endif; ?>
      <div class="re-total">
        <span class="l">SUBTOTAL</span>
        <span class="m" id="r-total">$0</span>
      </div>
      <button type="submit" class="btn-enviar" style="margin-top:1rem">Guardar cambios</button>
      <a href="admin.php?s=pedidos&ver=<?= $pid ?>"
         style="display:block;text-align:center;margin-top:.6rem;color:#888;font-size:.82rem;text-decoration:none">Cancelar</a>
    </div>
  </form>

  <?php if ($ediciones): ?>
  <div style="background:var(--blanco);border-radius:10px;padding:1.2rem;box-shadow:var(--sombra);margin-top:1.5rem">
    <h3 style="font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);margin-bottom:.8rem">Historial de ediciones</h3>
    <?php foreach ($ediciones as $e): ?>
    <div style="padding:.5rem 0;border-bottom:1px solid var(--gris-2);font-size:.82rem">
      <div style="color:var(--gris-4)"><?= date('d/m/Y H:i', strtotime($e['created_at'])) ?> — <strong><?= escape($e['admin_nombre']) ?></strong></div>
      <div><?= escape($e['detalle']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<script>
(function(){
  const promo12 = <?= $promo12_aplica ? 'true' : 'false' ?>;
  function fmt(n){ return '$'+Math.round(n).toLocaleString('es-AR'); }

  window.cambiar = function(id,d){
    const i = document.getElementById('qty-'+id);
    i.value = Math.max(0,(parseInt(i.value)||0)+d);
    recalc();
  };

  window.recalc = function(){
    let total=0, count=0, regalos=0;
    document.querySelectorAll('.qty-num').forEach(inp=>{
      const q  = Math.max(0,parseInt(inp.value)||0);
      const pr = parseFloat(inp.dataset.precio);
      const id = inp.dataset.id;
      inp.value = q;
      const sub  = pr*q;
      const subEl= document.getElementById('sub-'+id);
      const row  = document.getElementById('row-'+id);
      const minus= inp.previousElementSibling;

      if(q>0){
        subEl.textContent = fmt(sub);
        inp.classList.add('activo'); minus.classList.add('activo'); row.classList.add('activo');
        total += sub; count++;
      } else {
        subEl.textContent = '—';
        inp.classList.remove('activo'); minus.classList.remove('activo'); row.classList.remove('activo');
      }

      if(promo12){
        const tag = document.getElementById('reg-'+id);
        if(tag){
          const r = Math.floor(q/12);
          if(r>0){ tag.style.display='inline-block'; tag.textContent='🎁 +'+r+' de regalo'; regalos += r; }
          else { tag.style.display='none'; }
        }
      }
    });

    document.getElementById('r-items').textContent = count;
    document.getElementById('r-subtotal').textContent = fmt(total);
    document.getElementById('r-total').textContent = fmt(total);
    const rg = document.getElementById('r-regalos');
    if(rg) rg.textContent = regalos;
  };

  recalc();
})();
</script>
</body>
</html>
