<?php
// ============================================================
//  admin.php — v2: listas de precios + importación Excel
// ============================================================
require 'config.php';
require 'admin_extra.php';
require 'productos_excel.php';
require 'clientes_lib.php';
require 'avisos_lib.php';
admin_required();

$seccion   = $_GET['s'] ?? 'pedidos';
$flash_ok  = '';
$flash_err = '';

// ── Acciones POST ────────────────────────────────────────────
// Si el archivo supera post_max_size, PHP descarta todo el POST sin avisar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $flash_err = 'El archivo es demasiado grande para el servidor (máximo ' . tam_legible(limite_subida_bytes()) . ').';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Manejar acciones extra (novedades, descuentos, promo12)
    if (in_array($action, [
        'guardar_novedad','toggle_novedad','borrar_novedad',
        'guardar_codigo','toggle_codigo','borrar_codigo',
        'guardar_promo12'
    ])) {
        handle_extra_actions($action, $flash_ok, $flash_err, $seccion);
    }

    if ($action === 'borrar_pedido') {
        $del_id = (int)$_POST['pedido_id'];
        // Confirmación explícita enviada desde el formulario
        if (($_POST['confirmar'] ?? '') !== 'SI') {
            $flash_err = 'La eliminación no fue confirmada.';
        } else {
            try {
                $q = db()->prepare('SELECT codigo_descuento, pdf_token FROM pedidos WHERE id=? LIMIT 1');
                $q->execute([$del_id]);
                $info = $q->fetch();

                // Devolver el uso del código de descuento
                if ($info && $info['codigo_descuento']) {
                    db()->prepare('UPDATE codigos_descuento
                                   SET usos_actuales = GREATEST(0, usos_actuales - 1)
                                   WHERE codigo = ?')->execute([$info['codigo_descuento']]);
                }

                // Borrar PDF generado, si existe
                foreach (glob(__DIR__.'/pdf/pedido_'.$del_id.'_*.pdf') as $f_pdf) @unlink($f_pdf);

                // Borrar el pedido (los ítems y el log se borran en cascada)
                db()->prepare('DELETE FROM pedido_items WHERE pedido_id=?')->execute([$del_id]);
                db()->prepare('DELETE FROM pedidos WHERE id=?')->execute([$del_id]);

                $flash_ok = "Pedido #{$del_id} eliminado definitivamente.";
            } catch (Throwable $e) {
                $flash_err = 'No se pudo eliminar el pedido: '.$e->getMessage();
            }
        }
        $seccion = 'pedidos';
        unset($_GET['ver']);   // volver al listado
    }

    if ($action === 'reenviar_avisos') {
        $pid_av = (int)$_POST['pedido_id'];
        $r = notificar_pedido($pid_av, !empty($_POST['al_cliente']), true);
        $txt = 'Telegram: ' . ($r['telegram'][0] ? 'enviado' : 'falló (' . $r['telegram'][1] . ')')
             . ' · Mail a ' . MAIL_TO . ': ' . ($r['mail'] ? 'aceptado por el servidor' : 'falló');
        if ($r['telegram'][0] && $r['mail']) $flash_ok = "Avisos del pedido #{$pid_av} reenviados. $txt";
        else $flash_err = "Pedido #{$pid_av}: $txt";
        $seccion = 'pedidos';
        $_GET['ver'] = $pid_av;
    }

    if ($action === 'estado_pedido') {
        $pid    = (int)$_POST['pedido_id'];
        $estado = $_POST['estado'];
        if (in_array($estado, ['pendiente','en_preparacion','enviado','cancelado'])) {
            db()->prepare('UPDATE pedidos SET estado=? WHERE id=?')->execute([$estado,$pid]);
            $flash_ok = 'Estado actualizado.';
        }
        $seccion = 'pedidos';
    }

    if ($action === 'guardar_wa_texto') {
        set_config('wa_texto_pedido', trim($_POST['wa_texto_pedido'] ?? ''));
        $flash_ok = 'Plantilla de WhatsApp guardada.';
        $seccion  = 'config';
    }

    if ($action === 'guardar_config_comercial') {
        $rec = (string)($_POST['recargo_sin_factura'] ?? '0');
        set_config('recargo_sin_factura', isset(RECARGOS_SIN_FACTURA[$rec]) ? $rec : '0');
        foreach (LISTAS as $lid => $l) {
            $aj = max(-90, min(100, (float)str_replace(',', '.', (string)($_POST['ajuste_lista_'.$lid] ?? '0'))));
            set_config('ajuste_lista_'.$lid, (string)$aj);
        }
        $flash_ok = 'Condiciones generales guardadas.';
        $seccion  = 'config';
    }

    if ($action === 'guardar_config') {
        set_config('promo_activa', isset($_POST['promo_activa']) ? '1' : '0');
        set_config('promo_monto',  (string)(float)$_POST['promo_monto']);
        set_config('promo_texto',  trim($_POST['promo_texto']));
        $flash_ok = 'Configuración guardada.';
        $seccion  = 'config';
    }

    if ($action === 'crear_usuario') {
        $nom      = trim($_POST['nombre']);
        $email    = trim($_POST['email']);
        $pass     = $_POST['password'];
        $lista_id = (int)$_POST['lista_id'];
        $wa       = trim($_POST['whatsapp'] ?? '');
        if ($nom && $email && $pass && isset(LISTAS[$lista_id])) {
              $dir_new = trim($_POST['direccion'] ?? '');
            try {
                db()->prepare('INSERT INTO usuarios (nombre,email,password,lista_id,whatsapp,direccion) VALUES (?,?,?,?,?,?)')
                   ->execute([$nom,$email,password_hash($pass,PASSWORD_DEFAULT),$lista_id,$wa,$dir_new]);
                $nuevo_id = (int)db()->lastInsertId();
                if ($dir_new !== '') {
                    db()->prepare("INSERT INTO direcciones (usuario_id, alias, modalidad, calle, aclaraciones, predeterminada) VALUES (?, 'Principal', 'domicilio', ?, ?, 1)")
                       ->execute([$nuevo_id, mb_substr($dir_new, 0, 160), mb_strlen($dir_new) > 160 ? $dir_new : null]);
                }
                $flash_ok = "Usuario «{$nom}» creado con lista ".nombre_lista($lista_id).". Al entrar se le va a pedir que complete su ficha (es opcional); también la podés completar vos desde «Ficha».";
            } catch (Throwable $e) { $flash_err = 'El email ya existe.'; }
        } else { $flash_err = 'Completá todos los campos.'; }
        $seccion = 'usuarios';
    }

if ($action === 'editar_usuario') {
        $uid      = (int)$_POST['uid'];
        $lista_id = (int)$_POST['lista_id'];
        $nom      = trim($_POST['nombre']);
        $wa       = trim($_POST['whatsapp'] ?? '');
        $dir      = trim($_POST['direccion'] ?? '');
        $new_pass = trim($_POST['new_password'] ?? '');
        if (isset(LISTAS[$lista_id]) && $nom) {
            if ($new_pass) {
                if (strlen($new_pass) < 6) {
                    $flash_err = 'La contraseña debe tener al menos 6 caracteres.';
                } else {
                    db()->prepare('UPDATE usuarios SET lista_id=?,nombre=?,whatsapp=?,direccion=?,password=? WHERE id=?')
                       ->execute([$lista_id,$nom,$wa,$dir,password_hash($new_pass,PASSWORD_DEFAULT),$uid]);
                    $flash_ok = 'Usuario y contraseña actualizados.';
                }
            } else {
                db()->prepare('UPDATE usuarios SET lista_id=?,nombre=?,whatsapp=?,direccion=? WHERE id=?')
                   ->execute([$lista_id,$nom,$wa,$dir,$uid]);
                $flash_ok = 'Usuario actualizado.';
            }
        }
        $seccion = 'usuarios';
    }

    // ── Borrar usuarios (uno o varios) ───────────────────────
    // Requiere confirmar=SI. Si alguno tiene pedidos, además borrar_pedidos=1
    // (el formulario pide escribir BORRAR). Nunca borra administradores ni a uno mismo.
    if ($action === 'borrar_usuarios') {
        $uids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['uids'] ?? [])))));
        $yo   = (int)($_SESSION['user_id'] ?? 0);
        if (($_POST['confirmar'] ?? '') !== 'SI') {
            $flash_err = 'El borrado no fue confirmado.';
        } elseif (!$uids) {
            $flash_err = 'No seleccionaste ningún usuario.';
        } else {
            $in = implode(',', $uids);   // enteros ya validados
            $us = db()->query("SELECT u.id, u.nombre, u.es_admin, COUNT(p.id) AS npedidos
                               FROM usuarios u LEFT JOIN pedidos p ON p.usuario_id = u.id
                               WHERE u.id IN ($in) GROUP BY u.id")->fetchAll();
            $us = array_filter($us, fn($u) => !$u['es_admin'] && (int)$u['id'] !== $yo);
            $con_pedidos = array_sum(array_column($us, 'npedidos'));
            if (!$us) {
                $flash_err = 'Esos usuarios no se pueden borrar (los administradores no se borran).';
            } elseif ($con_pedidos > 0 && ($_POST['borrar_pedidos'] ?? '') !== '1') {
                $flash_err = 'Hay usuarios con pedidos: para borrarlos tenés que confirmar que también se borran sus pedidos.';
            } else {
                try {
                    $db  = db();
                    $ids = implode(',', array_map(fn($u) => (int)$u['id'], $us));
                    $db->beginTransaction();
                    // Pedidos: devolver usos de códigos y borrar PDFs (ítems e historial van en cascada)
                    $peds = $db->query("SELECT id, codigo_descuento FROM pedidos WHERE usuario_id IN ($ids)")->fetchAll();
                    $dto  = $db->prepare('UPDATE codigos_descuento SET usos_actuales = GREATEST(0, usos_actuales - 1) WHERE codigo = ?');
                    foreach ($peds as $pd) if ($pd['codigo_descuento']) $dto->execute([$pd['codigo_descuento']]);
                    $db->exec("DELETE FROM pedidos WHERE usuario_id IN ($ids)");
                    // Códigos de descuento exclusivos de esos usuarios (si quedaran sin dueño valdrían para todos)
                    $ncod = $db->exec("DELETE FROM codigos_descuento WHERE usuario_id IN ($ids)");
                    $db->exec("DELETE FROM usuarios WHERE id IN ($ids) AND es_admin = 0");
                    $db->commit();
                    foreach ($peds as $pd) foreach (glob(__DIR__.'/pdf/pedido_'.(int)$pd['id'].'_*.pdf') ?: [] as $f) @unlink($f);

                    $nombres = implode(', ', array_map(fn($u) => '«'.$u['nombre'].'»', $us));
                    $extra = [];
                    if ($peds) $extra[] = count($peds).' pedido'.(count($peds) > 1 ? 's' : '');
                    if ($ncod) $extra[] = $ncod.' código'.($ncod > 1 ? 's' : '').' de descuento exclusivo'.($ncod > 1 ? 's' : '');
                    $flash_ok = (count($us) === 1 ? "Usuario $nombres eliminado" : count($us)." usuarios eliminados: $nombres")
                              . ($extra ? ' — junto con '.implode(' y ', $extra) : '') . '.';
                    if (count($us) < count($uids)) $flash_ok .= ' (Los administradores no se borran.)';
                } catch (Throwable $e) {
                    if (db()->inTransaction()) db()->rollBack();
                    $flash_err = 'No se pudo borrar: ' . $e->getMessage();
                }
            }
        }
        $seccion = 'usuarios';
        unset($_GET['editar']);
    }

    if ($action === 'toggle_usuario') {
        db()->prepare('UPDATE usuarios SET activo=1-activo WHERE id=? AND es_admin=0')
           ->execute([(int)$_POST['uid']]);
        $flash_ok = 'Usuario actualizado.';
        $seccion  = 'usuarios';
    }

    // ── Guardar producto (crear/editar) ──────────────────────
    if ($action === 'guardar_producto') {
        $pid   = (int)($_POST['pid'] ?? 0);
        $cod   = trim($_POST['codigo'] ?? '');
        $nom   = trim($_POST['nombre'] ?? '');
        $pres  = str_replace("\r", '', trim($_POST['presentacion'] ?? ''));
        $des   = trim($_POST['descripcion'] ?? '');
        $secc  = ($_POST['seccion'] ?? '') === 'nuevo' ? 'nuevo' : 'regular';
        $p1    = (float)str_replace(',', '.', $_POST['precio_distribuidor'] ?? 0);
        $p3    = (float)str_replace(',', '.', $_POST['precio_pto_venta'] ?? 0);
        $pvp   = (float)str_replace(',', '.', $_POST['pvp_sugerido'] ?? 0);
        $ord   = (int)($_POST['orden'] ?? 0);
        $img   = $_POST['img_actual'] ?? null;

        if (!empty($_FILES['imagen']['name'])) {
            $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','gif']) && $_FILES['imagen']['size'] < 4*1024*1024) {
                $nuevo = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $cod ?: uniqid('prod'))) . '.jpg';
                $dest  = __DIR__ . '/assets/img/productos/' . $nuevo;
                if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
                if (guardar_miniatura(file_get_contents($_FILES['imagen']['tmp_name']), $dest)) $img = $nuevo;
                else $flash_err = 'No se pudo procesar la imagen.';
            } else { $flash_err = 'Imagen inválida (máx 4MB, JPG/PNG/WEBP).'; }
        }

        if ($nom && $cod && !$flash_err) {
            $otro = db()->prepare('SELECT id FROM productos WHERE codigo = ? AND id <> ? LIMIT 1');
            $otro->execute([$cod, $pid]);
            if ($otro->fetch()) {
                $flash_err = "El código {$cod} ya lo usa otro producto.";
            } else {
                $data = [$cod, $nom, $pres, $des, $secc, $p1, $p3, $pvp, $ord, $img];
                if ($pid) {
                    $data[] = $pid;
                    db()->prepare('UPDATE productos SET codigo=?, nombre=?, presentacion=?, descripcion=?, seccion=?,
                        precio_distribuidor=?, precio_pto_venta=?, pvp_sugerido=?, orden=?, imagen=? WHERE id=?')->execute($data);
                    $flash_ok = 'Producto actualizado.';
                } else {
                    db()->prepare('INSERT INTO productos
                        (codigo, nombre, presentacion, descripcion, seccion, precio_distribuidor, precio_pto_venta, pvp_sugerido, orden, imagen)
                        VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($data);
                    $flash_ok = 'Producto creado.';
                }
            }
        } elseif (!$flash_err) { $flash_err = 'Completá código y descripción.'; }
        $seccion = 'productos';
    }

    if ($action === 'toggle_producto') {
        db()->prepare('UPDATE productos SET activo=1-activo WHERE id=?')->execute([(int)$_POST['pid']]);
        $flash_ok = 'Producto actualizado.';
        $seccion  = 'productos';
    }

    // ── Importar Excel ───────────────────────────────────────
    if ($action === 'importar_excel') {
        $modo = ($_POST['modo_importar'] ?? 'todo') === 'solo_precios' ? 'solo_precios' : 'todo';
        $seccion = 'productos';
        if (empty($_FILES['excel_file']['name']) || !is_uploaded_file($_FILES['excel_file']['tmp_name'])) {
            $flash_err = 'Seleccioná un archivo Excel.';
        } else {
            $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'csv'], true)) {
                $flash_err = 'Formato no válido. Usá .xlsx (o .csv).';
            } elseif ($ext === 'xlsx' && !cargar_vendor()) {
                $flash_err = 'Falta PhpSpreadsheet en el servidor.';
            } else {
                try {
                    [$filas, $err] = leer_filas_productos($_FILES['excel_file']['tmp_name'], $ext);
                    if ($err) {
                        $flash_err = $err;
                    } else {
                        $r = importar_productos(db(), $filas, $modo, __DIR__ . '/assets/img/productos');
                        if ($r['actualizados'] || $r['nuevos']) {
                            $flash_ok = "✅ Importación: {$r['actualizados']} actualizado(s), {$r['nuevos']} nuevo(s).";
                            if ($r['renombrados']) $flash_ok .= ' Códigos cambiados: ' . implode(', ', $r['renombrados']) . '.';
                        }
                        if ($r['errores']) $flash_err = implode(' · ', $r['errores']);
                    }
                } catch (Throwable $e) {
                    $flash_err = 'Error al leer el archivo: ' . $e->getMessage();
                }
            }
        }
    }
}
// ── Cargar datos ─────────────────────────────────────────────
$pedidos   = [];
$usuarios  = [];
$productos = [];
$ver_ped   = null;
$editar_prod = null;

if ($seccion === 'pedidos') {
    if (isset($_GET['ver'])) {
        $s = db()->prepare('SELECT p.*,u.nombre AS unom,u.email AS uemail,u.whatsapp,u.lista_id AS ulista
                            FROM pedidos p JOIN usuarios u ON p.usuario_id=u.id WHERE p.id=?');
        $s->execute([(int)$_GET['ver']]);
        $ver_ped = $s->fetch();
        if ($ver_ped) {
            $s2 = db()->prepare('SELECT i.*, pr.codigo AS sku FROM pedido_items i
                                 LEFT JOIN productos pr ON pr.id = i.producto_id
                                 WHERE i.pedido_id=? ORDER BY i.id');
            $s2->execute([$ver_ped['id']]);
            $ver_ped['items'] = $s2->fetchAll();
        }
    }
    $filtro_u = (int)($_GET['u'] ?? 0);   // ?u=ID → solo los pedidos de ese cliente
    $sp = db()->prepare('SELECT p.*,u.nombre AS unom,u.email AS uemail,u.lista_id AS ulista
                         FROM pedidos p JOIN usuarios u ON p.usuario_id=u.id
                         WHERE (? = 0 OR p.usuario_id = ?)
                         ORDER BY p.created_at DESC LIMIT 200');
    $sp->execute([$filtro_u, $filtro_u]);
    $pedidos = $sp->fetchAll();
}

if ($seccion === 'usuarios') {
    if (isset($_GET['editar'])) {
        $s = db()->prepare('SELECT * FROM usuarios WHERE id=? LIMIT 1');
        $s->execute([(int)$_GET['editar']]);
        $edit_u = $s->fetch();
    }
    $usuarios = db()->query('SELECT u.*, COUNT(p.id) AS npedidos, MAX(p.created_at) AS ultimo_pedido
                             FROM usuarios u LEFT JOIN pedidos p ON p.usuario_id = u.id
                             GROUP BY u.id ORDER BY u.es_admin ASC, u.nombre ASC')->fetchAll();
}

if ($seccion === 'productos') {
    if (isset($_GET['editar'])) {
        $s = db()->prepare('SELECT * FROM productos WHERE id=? LIMIT 1');
        $s->execute([(int)$_GET['editar']]);
        $editar_prod = $s->fetch();
    }
    $productos = db()->query("SELECT * FROM productos ORDER BY (seccion = 'nuevo') ASC, orden ASC, id ASC")->fetchAll();
}

$promo_activa = get_config('promo_activa','1');
$promo_monto  = get_config('promo_monto','50000');
$promo_texto  = get_config('promo_texto','');

function estado_label(string $e): string {
    return ['pendiente'=>'Pendiente','en_preparacion'=>'En preparación',
            'enviado'=>'Enviado','cancelado'=>'Cancelado'][$e] ?? $e;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Panaro Car Care</title>
<link rel="stylesheet" href="style.css">
<style>
.edit-form{background:var(--blanco);border-radius:10px;padding:1.4rem;box-shadow:var(--sombra);margin-bottom:1.5rem}
.edit-form h3{font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);margin-bottom:1rem}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
@media(max-width:600px){.form-row{grid-template-columns:1fr}}
.field label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);display:block;margin-bottom:.3rem}
.field input,.field select,.field textarea{width:100%;padding:.55rem .8rem;border:1.5px solid var(--gris-2);border-radius:var(--radio);font-family:inherit;font-size:.9rem;outline:none;transition:border-color var(--trans)}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--rojo)}
.field{margin-bottom:.7rem}
.precios-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.6rem}
@media(max-width:700px){.precios-grid{grid-template-columns:1fr 1fr}}
.precio-field label{font-size:.65rem!important}
.import-box{background:#f8f8f8;border:2px dashed #ddd;border-radius:10px;padding:1.4rem;margin-bottom:1.2rem}
.import-box h3{font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);margin-bottom:1rem}
.lista-badge{display:inline-block;padding:.2rem .6rem;border-radius:99px;font-size:.72rem;font-weight:700}
</style>
</head>
<body>

<header class="site-header" style="position:sticky;top:0;z-index:100;display:flex;align-items:center;justify-content:space-between;gap:.4rem;padding:.55rem .8rem">
  <img src="assets/img/logo.png" alt="Panaro"
       style="height:26px;width:auto;max-width:30%;object-fit:contain;flex-shrink:1">
  <div class="header-right" style="gap:.35rem;flex-shrink:0">
    <span style="color:#666;font-size:.72rem;white-space:nowrap" class="hide-mobile">Admin</span>
    <a href="pedido.php" class="btn-logout" style="border-color:#444;color:#888;white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Portal</a>
    <a href="logout.php" class="btn-logout" style="white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Salir</a>
  </div>
</header>

<div class="main-wrap">

  <div class="admin-nav">
    <a href="?s=pedidos"    class="<?= $seccion==='pedidos'    ?'activo':'' ?>">📦 Pedidos</a>
    <a href="?s=usuarios"   class="<?= $seccion==='usuarios'   ?'activo':'' ?>">👤 Usuarios</a>
    <a href="?s=productos"  class="<?= $seccion==='productos'  ?'activo':'' ?>">🧴 Productos</a>
    <a href="?s=novedades"  class="<?= $seccion==='novedades'  ?'activo':'' ?>">📢 Novedades</a>
    <a href="?s=descuentos" class="<?= $seccion==='descuentos' ?'activo':'' ?>">🎟️ Descuentos</a>
    <a href="?s=config"     class="<?= $seccion==='config'     ?'activo':'' ?>">⚙️ Config</a>
    <a href="listas.php">📄 Listas de precios</a>
    <a href="pedido.php"    style="border-color:#e30613;color:#e30613">→ Portal</a>
  </div>

  <?php if ($flash_ok):  ?><div class="flash-ok"><?= escape($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash-err"><?= escape($flash_err) ?></div><?php endif; ?>

  <!-- ══════════════════════════════════════════════════════
       PEDIDOS
  ══════════════════════════════════════════════════════ -->
  <?php if ($seccion === 'pedidos'): ?>

    <?php if ($ver_ped): ?>
    <div class="edit-form">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem">
        <div>
          <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;margin:0">Pedido #<?= $ver_ped['id'] ?></h2>
          <div style="font-size:.82rem;color:var(--gris-4)">
            <?= date('d/m/Y H:i',strtotime($ver_ped['created_at'])) ?>
            — <strong><?= escape($ver_ped['unom']) ?></strong>
            — Lista: <span style="color:<?= color_lista($ver_ped['lista_id']) ?>"><?= nombre_lista($ver_ped['lista_id']) ?></span>
          </div>
        </div>
        <span class="estado-badge estado-<?= $ver_ped['estado'] ?>"><?= estado_label($ver_ped['estado']) ?></span>
      </div>
      <table class="det-tabla" style="margin-bottom:1rem">
        <thead><tr><th>SKU</th><th>Producto</th><th>Cant.</th><th>Precio unit.</th><th>Subtotal</th></tr></thead>
        <tbody>
          <?php foreach ($ver_ped['items'] as $it):
            $es_reg = !empty($it['es_regalo']) || (float)$it['precio_unit'] == 0; ?>
          <tr <?= $es_reg ? 'style="background:#f0fdf4"' : '' ?>>
            <td style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.8rem;font-weight:700;white-space:nowrap"><?= escape($it['sku'] ?? '—') ?></td>
            <td><?= escape($it['nombre']) ?></td>
            <td><?= $it['cantidad'] ?></td>
            <td><?= $es_reg ? '<span style="color:#16a34a;font-weight:700">REGALO</span>' : precio_fmt((float)$it['precio_unit']) ?></td>
            <td><?= $es_reg ? '<span style="color:#16a34a;font-weight:700">$0</span>' : precio_fmt((float)$it['subtotal']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <?php if ($ver_ped['monto_descuento'] > 0): ?>
          <tr>
            <td colspan="4" style="text-align:right;color:#22c55e;font-size:.88rem">
              Descuento (<?= escape($ver_ped['codigo_descuento']) ?>)
            </td>
            <td style="color:#22c55e;font-weight:700">− <?= precio_fmt((float)$ver_ped['monto_descuento']) ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <td colspan="4">TOTAL<?= $ver_ped['monto_descuento'] > 0 ? ' CON DESCUENTO' : '' ?></td>
            <td><?= precio_fmt((float)($ver_ped['total_con_dto'] ?: $ver_ped['total'])) ?></td>
          </tr>
        </tfoot>
      </table>
      <?php $cli_ped = cliente((int)$ver_ped['usuario_id']) ?? []; ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:.8rem;margin:.4rem 0 .8rem">
        <?php if (!empty($ver_ped['entrega'])): $el = explode("\n", $ver_ped['entrega']); ?>
        <div style="background:#f8f8f8;border-radius:8px;padding:.7rem .85rem;font-size:.83rem;line-height:1.5">
          <div style="font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:.2rem">🚚 Entrega</div>
          <strong><?= escape(array_shift($el)) ?></strong><br><?= nl2br(escape(implode("\n", $el))) ?>
        </div>
        <?php elseif ($ver_ped['envio_info']): ?>
        <div style="background:#f8f8f8;border-radius:8px;padding:.7rem .85rem;font-size:.83rem"><strong>Envío:</strong> <?= escape($ver_ped['envio_info']) ?></div>
        <?php endif; ?>
        <?php $fis = $cli_ped ? datos_fiscales_txt($cli_ped) : []; $cond = condiciones_pedido_txt($ver_ped); if ($fis || $cond): ?>
        <div style="background:#f5f3ff;border-radius:8px;padding:.7rem .85rem;font-size:.83rem;line-height:1.6">
          <div style="font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#7c3aed;margin-bottom:.2rem">🧾 Facturación (interno)</div>
          <?php foreach ($fis + $cond as $k => $v): ?><div><span style="color:#777"><?= escape($k) ?>:</span> <strong><?= escape($v) ?></strong></div><?php endforeach; ?>
          <a href="admin_cliente.php?id=<?= (int)$ver_ped['usuario_id'] ?>" style="font-size:.78rem;color:#7c3aed">Ver ficha del cliente →</a>
        </div>
        <?php endif; ?>
      </div>
      <?php if ($ver_ped['notas']): ?><p style="font-size:.85rem"><strong>Notas:</strong> <?= escape($ver_ped['notas']) ?></p><?php endif; ?>
      <?php if (!empty($ver_ped['veces_editado']) && $ver_ped['veces_editado'] > 0): ?>
        <p style="font-size:.8rem;color:#b45309;margin-top:.6rem">
          ✏️ Pedido editado <?= $ver_ped['veces_editado'] ?> vez(ces) — última: <?= date('d/m/Y H:i', strtotime($ver_ped['editado_en'])) ?> por <?= escape($ver_ped['editado_por'] ?? 'Admin') ?>
        </p>
      <?php endif; ?>

      <!-- Acciones -->
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:1rem">
        <a href="editar_pedido.php?id=<?= $ver_ped['id'] ?>" class="btn-sm" style="text-decoration:none;background:#0d0d0d">✏️ Editar pedido</a>
        <a href="exportar_pedido.php?id=<?= $ver_ped['id'] ?>&f=pdf" target="_blank" class="btn-sm" style="text-decoration:none;background:#dc2626">📄 Ver PDF</a>
        <a href="exportar_pedido.php?id=<?= $ver_ped['id'] ?>&f=excel" class="btn-sm" style="text-decoration:none;background:#16a34a">📊 Excel</a>
        <a href="exportar_pedido.php?id=<?= $ver_ped['id'] ?>&f=packing" target="_blank" class="btn-sm" style="text-decoration:none;background:#7c3aed">📦 Packing list</a>
        <form method="post" style="display:inline" onsubmit="return confirm('¿Reenviar el aviso del pedido #<?= $ver_ped['id'] ?> por Telegram y mail?')">
          <input type="hidden" name="action" value="reenviar_avisos">
          <input type="hidden" name="pedido_id" value="<?= $ver_ped['id'] ?>">
          <button type="submit" class="btn-sm" style="background:#0ea5e9">🔔 Reenviar avisos</button>
        </form>
      </div>

      <form method="post" class="form-inline" style="margin-top:.8rem;flex-wrap:wrap;gap:.5rem">
        <input type="hidden" name="action" value="estado_pedido">
        <input type="hidden" name="pedido_id" value="<?= $ver_ped['id'] ?>">
        <select name="estado">
          <?php foreach (['pendiente','en_preparacion','enviado','cancelado'] as $e): ?>
          <option value="<?= $e ?>" <?= $ver_ped['estado']===$e?'selected':'' ?>><?= estado_label($e) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-sm">Actualizar estado</button>
      </form>

      <!-- WhatsApp con mensaje editable -->
      <?php
        $wa_num = preg_replace('/\D/','', $ver_ped['whatsapp'] ?? '');
        $wa_tpl = get_config('wa_texto_pedido', "Hola {cliente}!\n\nRecibimos tu pedido #{pedido}. ¡Gracias!\n\nTotal: {total}\n{link_pdf}");
        $n_items = 0;
        foreach ($ver_ped['items'] as $_it) { if (empty($_it['es_regalo'])) $n_items++; }
        $wa_msg = strtr($wa_tpl, [
            '{cliente}'        => $ver_ped['unom'],
            '{pedido}'         => $ver_ped['id'],
            '{total}'          => precio_fmt((float)($ver_ped['total_con_dto'] ?: $ver_ped['total'])),
            '{cantidad_items}' => $n_items,
            '{estado}'         => estado_label($ver_ped['estado']),
            '{link_pdf}'       => '',
        ]);
      ?>
      <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;padding:1rem;margin-top:1rem">
        <h4 style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:#166534;margin-bottom:.6rem">
          📲 Enviar confirmación por WhatsApp
        </h4>
        <?php if (!$wa_num): ?>
          <p style="font-size:.83rem;color:#7a5200;margin:0">
            Este cliente no tiene número de WhatsApp cargado.
            <a href="?s=usuarios&editar=<?= $ver_ped['usuario_id'] ?>" style="color:#166534;font-weight:600">Agregarlo →</a>
          </p>
        <?php else: ?>
          <textarea id="wa-texto" rows="9"
                    style="width:100%;padding:.7rem .85rem;border:1.5px solid #86efac;border-radius:6px;font-family:inherit;font-size:.85rem;outline:none;resize:vertical;line-height:1.5"><?= escape($wa_msg) ?></textarea>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-top:.6rem">
            <button type="button" onclick="enviarWA(<?= $ver_ped['id'] ?>, '<?= $wa_num ?>')"
                    class="btn-sm" style="background:#25d366">📲 Generar PDF y abrir WhatsApp</button>
            <span id="wa-estado" style="font-size:.8rem;color:#166534"></span>
          </div>
          <p style="font-size:.75rem;color:#4b7c5a;margin-top:.5rem;line-height:1.4">
            Se genera el PDF, se agrega el link al final del mensaje y se abre WhatsApp con el texto listo para enviar.
          </p>
        <?php endif; ?>
      </div>

      <!-- Eliminar pedido -->
      <div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:8px;padding:1rem;margin-top:1rem">
        <h4 style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:#991b1b;margin-bottom:.5rem">
          Zona de riesgo
        </h4>
        <p style="font-size:.82rem;color:#7f1d1d;margin:0 0 .7rem;line-height:1.5">
          Al eliminar el pedido se borran también sus productos, el historial de ediciones y el PDF generado.
          Si tenía un código de descuento, se le devuelve el uso. <strong>Esta acción no se puede deshacer.</strong>
        </p>
        <form method="post" onsubmit="return confirmarBorrado(<?= $ver_ped['id'] ?>)">
          <input type="hidden" name="action" value="borrar_pedido">
          <input type="hidden" name="pedido_id" value="<?= $ver_ped['id'] ?>">
          <input type="hidden" name="confirmar" value="SI">
          <button type="submit" class="btn-sm" style="background:#dc2626">🗑️ Eliminar pedido #<?= $ver_ped['id'] ?></button>
        </form>
      </div>

      <div style="margin-top:1rem"><a href="?s=pedidos" class="btn-det">← Volver</a></div>

      <script>
      function confirmarBorrado(pid){
        return confirm('¿Eliminar definitivamente el pedido #'+pid+'?\n\n' +
                       'Se borran el pedido, sus productos, el historial de ediciones y el PDF.\n' +
                       'Esta acción NO se puede deshacer.');
      }
      function enviarWA(pid, num){
        const est = document.getElementById('wa-estado');
        est.textContent = 'Generando PDF…';
        fetch('exportar_pedido.php?id='+pid+'&f=pdf_link')
          .then(r => r.json())
          .then(d => {
            let txt = document.getElementById('wa-texto').value;
            if (d.ok && d.url) {
              txt = txt.includes('{link_pdf}')
                  ? txt.replace('{link_pdf}', d.url)
                  : txt.trim() + '\n\n' + d.url;
              est.textContent = '✅ PDF listo';
            } else {
              txt = txt.replace('{link_pdf}', '');
              est.textContent = '⚠️ Sin PDF, se envía solo el texto';
            }
            window.open('https://wa.me/'+num+'?text='+encodeURIComponent(txt), '_blank');
          })
          .catch(() => {
            let txt = document.getElementById('wa-texto').value.replace('{link_pdf}','');
            est.textContent = '⚠️ Sin PDF, se envía solo el texto';
            window.open('https://wa.me/'+num+'?text='+encodeURIComponent(txt), '_blank');
          });
      }
      </script>
    </div>
    <?php endif; ?>

    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.6rem;margin-bottom:1rem">
      <?= $filtro_u && $pedidos ? 'Pedidos de ' . escape($pedidos[0]['unom']) : 'Todos los pedidos' ?>
      <?php if ($filtro_u): ?><a href="?s=pedidos" style="font-family:inherit;font-size:.8rem;margin-left:.6rem;color:var(--gris-4)">ver todos</a><?php endif; ?>
    </h2>
    <div style="overflow-x:auto">
      <table class="hist-tabla">
        <thead><tr><th>#</th><th>Fecha</th><th>Cliente</th><th>Lista</th><th>Total</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($pedidos)): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--gris-4);padding:2rem">Sin pedidos aún.</td></tr>
          <?php endif; ?>
          <?php foreach ($pedidos as $p): ?>
          <tr>
            <td style="font-weight:700"><?= $p['id'] ?></td>
            <td><?= date('d/m/Y H:i',strtotime($p['created_at'])) ?></td>
            <td><?= escape($p['unom']) ?></td>
            <td><span class="lista-badge" style="background:<?= color_lista($p['ulista']) ?>22;color:<?= color_lista($p['ulista']) ?>"><?= nombre_lista($p['ulista']) ?></span></td>
            <td style="font-weight:700"><?= precio_fmt((float)($p['total_con_dto'] ?: $p['total'])) ?>
              <?php if ($p['monto_descuento'] > 0): ?>
                <div style="font-size:.7rem;color:#22c55e;font-weight:600">
                  −<?= precio_fmt((float)$p['monto_descuento']) ?> (<?= escape($p['codigo_descuento']) ?>)
                </div>
              <?php endif; ?>
            </td>
            <td><span class="estado-badge estado-<?= $p['estado'] ?>"><?= estado_label($p['estado']) ?></span></td>
            <td style="display:flex;gap:.3rem;flex-wrap:wrap">
              <a href="?s=pedidos&ver=<?= $p['id'] ?>" class="btn-det">Ver</a>
              <a href="editar_pedido.php?id=<?= $p['id'] ?>" class="btn-det" style="border-color:#0d0d0d;color:#0d0d0d">Editar</a>
              <a href="exportar_pedido.php?id=<?= $p['id'] ?>&f=pdf" target="_blank" class="btn-det" style="border-color:#dc2626;color:#dc2626">PDF</a>
              <a href="exportar_pedido.php?id=<?= $p['id'] ?>&f=packing" target="_blank" class="btn-det" style="border-color:#7c3aed;color:#7c3aed" title="Packing list sin precios">Packing</a>
              <form method="post" style="display:inline" onsubmit="return confirmarBorradoLista(<?= $p['id'] ?>)">
                <input type="hidden" name="action" value="borrar_pedido">
                <input type="hidden" name="pedido_id" value="<?= $p['id'] ?>">
                <input type="hidden" name="confirmar" value="SI">
                <button type="submit" class="btn-det" style="border-color:#dc2626;color:#dc2626;background:none;cursor:pointer;font-family:inherit">🗑️</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  <!-- ══════════════════════════════════════════════════════
       USUARIOS
  ══════════════════════════════════════════════════════ -->
    <script>
    function confirmarBorradoLista(pid){
      return confirm('¿Eliminar definitivamente el pedido #'+pid+'?\n\n' +
                     'Se borran el pedido, sus productos, el historial de ediciones y el PDF.\n' +
                     'Esta acción NO se puede deshacer.');
    }
    </script>

  <?php elseif ($seccion === 'usuarios'): ?>

    <?php if (!empty($edit_u)): ?>
    <div class="edit-form">
      <h3>Editar usuario — <?= escape($edit_u['nombre']) ?></h3>
      <form method="post">
        <input type="hidden" name="action" value="editar_usuario">
        <input type="hidden" name="uid" value="<?= $edit_u['id'] ?>">
        <div class="form-row">
          <div class="field"><label>Nombre</label>
            <input type="text" name="nombre" value="<?= escape($edit_u['nombre']) ?>" required></div>
          <div class="field"><label>Lista de precios</label>
            <select name="lista_id">
              <?php foreach (LISTAS as $id => $l): ?>
              <option value="<?= $id ?>" <?= $edit_u['lista_id']==$id?'selected':'' ?>>
                <?= escape($l['nombre']) ?>
              </option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>WhatsApp</label>
            <input type="text" name="whatsapp" value="<?= escape($edit_u['whatsapp']??'') ?>"></div>
            <div class="field" style="grid-column:1/-1"><label>Dirección por defecto</label>
            <textarea name="direccion" rows="2"
                      style="width:100%;padding:.55rem .8rem;border:1.5px solid var(--gris-2);border-radius:6px;font-family:inherit;font-size:.9rem;outline:none;resize:vertical"
                      placeholder="Ej: Av. Corrientes 1234, CABA — L a V 9 a 18hs"><?= escape($edit_u['direccion']??'') ?></textarea></div>
          <div class="field" style="grid-column:1/-1">
            <label>Nueva contraseña <span style="color:var(--gris-3);font-weight:400;text-transform:none;letter-spacing:0">(dejar vacío para no cambiarla)</span></label>
            <input type="text" name="new_password" placeholder="Mínimo 6 caracteres" autocomplete="new-password">
          </div>
        </div>
        <button type="submit" class="btn-sm">Guardar</button>
        <a href="?s=usuarios" style="margin-left:.5rem;font-size:.82rem;color:var(--gris-4)">Cancelar</a>
      </form>
    </div>
    <?php endif; ?>

    <div class="edit-form">
      <h3>Nuevo usuario mayorista</h3>
      <form method="post">
        <input type="hidden" name="action" value="crear_usuario">
        <div class="form-row">
          <div class="field"><label>Nombre</label><input type="text" name="nombre" placeholder="Empresa / nombre" required></div>
          <div class="field"><label>Email (login)</label><input type="email" name="email" required></div>
          <div class="field"><label>Contraseña</label><input type="text" name="password" required></div>
          <div class="field"><label>Lista de precios</label>
            <select name="lista_id">
              <?php foreach (LISTAS as $id => $l): ?>
              <option value="<?= $id ?>"><?= escape($l['nombre']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>WhatsApp (opcional)</label><input type="text" name="whatsapp" placeholder="5491112345678"></div>
          <div class="field" style="grid-column:1/-1"><label>Dirección por defecto (opcional)</label>
            <textarea name="direccion" rows="2"
                      style="width:100%;padding:.55rem .8rem;border:1.5px solid var(--gris-2);border-radius:6px;font-family:inherit;font-size:.9rem;outline:none;resize:vertical"
                      placeholder="Ej: Av. Corrientes 1234, CABA — L a V 9 a 18hs"></textarea></div>
        </div>
        <button type="submit" class="btn-sm rojo">Crear usuario</button>
      </form>
    </div>

    <div class="usr-barra">
      <span id="usr-sel-txt">Marcá usuarios para borrar varios a la vez.</span>
      <button type="button" class="btn-sm" id="usr-sel-btn" style="background:#dc2626" disabled
              onclick="pedirBorrado(seleccionados())">🗑️ Eliminar seleccionados</button>
    </div>
    <div style="overflow-x:auto">
      <table class="hist-tabla">
        <thead><tr><th style="width:28px"><input type="checkbox" id="usr-todos" title="Seleccionar todos"></th><th>Nombre</th><th>Email</th><th>Lista</th><th>Pedidos</th><th>Rol</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($usuarios as $uu): ?>
          <tr>
            <td><?php if (!$uu['es_admin'] && (int)$uu['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
              <input type="checkbox" class="usr-chk" value="<?= $uu['id'] ?>"
                     data-nombre="<?= escape($uu['nombre']) ?>" data-email="<?= escape($uu['email']) ?>"
                     data-pedidos="<?= (int)$uu['npedidos'] ?>">
            <?php endif; ?></td>
            <td style="font-weight:600"><?= escape($uu['nombre']) ?></td>
            <td style="font-size:.83rem"><?= escape($uu['email']) ?></td>
            <td>
              <?php if (!$uu['es_admin']): ?>
              <span class="lista-badge" style="background:<?= color_lista($uu['lista_id']) ?>22;color:<?= color_lista($uu['lista_id']) ?>">
                <?= nombre_lista($uu['lista_id']) ?>
              </span>
              <?php else: ?> — <?php endif; ?>
            </td>
            <td style="font-size:.83rem;white-space:nowrap">
              <?php if ($uu['npedidos']): ?>
                <a href="?s=pedidos&u=<?= $uu['id'] ?>" style="color:inherit"><?= (int)$uu['npedidos'] ?></a>
                <span style="color:var(--gris-3);font-size:.75rem">· <?= date('d/m/y', strtotime($uu['ultimo_pedido'])) ?></span>
              <?php else: ?><span style="color:var(--gris-3)">—</span><?php endif; ?>
            </td>
            <td><?= $uu['es_admin'] ? '<span class="estado-badge estado-en_preparacion">Admin</span>' : 'Mayorista' ?></td>
            <td><span class="estado-badge <?= $uu['activo']?'estado-enviado':'estado-cancelado' ?>"><?= $uu['activo']?'Activo':'Inactivo' ?></span></td>
            <td style="display:flex;gap:.4rem;flex-wrap:wrap">
              <?php if (!$uu['es_admin']): ?>
              <a href="admin_cliente.php?id=<?= $uu['id'] ?>" class="btn-det" style="border-color:#7c3aed;color:#7c3aed">Ficha<?= !empty($uu['ficha_completa_en']) ? ' ✓' : '' ?></a>
              <a href="?s=usuarios&editar=<?= $uu['id'] ?>" class="btn-det">Editar</a>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="toggle_usuario">
                <input type="hidden" name="uid" value="<?= $uu['id'] ?>">
                <button type="submit" class="btn-sm" style="background:<?= $uu['activo']?'#888':'#22c55e' ?>">
                  <?= $uu['activo']?'Desactivar':'Activar' ?></button>
              </form>
              <button type="button" class="btn-det" style="border-color:#dc2626;color:#dc2626"
                      onclick="pedirBorrado([this.closest('tr').querySelector('.usr-chk')])">Eliminar</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Confirmación de borrado -->
    <div class="modal-fondo" id="modal-borrar" hidden>
      <form method="post" class="modal-caja" id="form-borrar" onsubmit="return validarBorrado()">
        <input type="hidden" name="action" value="borrar_usuarios">
        <input type="hidden" name="confirmar" value="SI">
        <div id="borrar-uids"></div>
        <h3 id="borrar-titulo">Eliminar usuario</h3>
        <ul id="borrar-lista"></ul>
        <div id="borrar-aviso-pedidos" class="modal-aviso" hidden>
          <strong id="borrar-npedidos"></strong>
          Se van a borrar también esos pedidos, con sus productos, historial y PDF.
          Si solo querés que no entre más, usá <em>Desactivar</em>: conserva el historial.
          <label style="display:block;margin-top:.7rem;font-weight:600">Escribí <code>BORRAR</code> para confirmar:
            <input type="text" id="borrar-texto" autocomplete="off" style="display:block;width:100%;margin-top:.3rem;padding:.5rem .7rem;border:1.5px solid #fca5a5;border-radius:6px;font:inherit;text-transform:uppercase">
          </label>
          <input type="hidden" name="borrar_pedidos" id="borrar-pedidos" value="0">
        </div>
        <p style="font-size:.82rem;color:#7f1d1d;margin:.8rem 0 0"><strong>Esta acción no se puede deshacer.</strong></p>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1.1rem">
          <button type="button" class="btn-det" onclick="cerrarBorrado()">Cancelar</button>
          <button type="submit" class="btn-sm" id="borrar-ok" style="background:#dc2626">Eliminar</button>
        </div>
      </form>
    </div>
    <style>
    .usr-barra{display:flex;align-items:center;justify-content:space-between;gap:.8rem;flex-wrap:wrap;margin:0 0 .6rem;font-size:.82rem;color:var(--gris-4)}
    .usr-barra button:disabled{opacity:.35;cursor:default}
    .modal-fondo{position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:1rem;z-index:1000}
    .modal-fondo[hidden]{display:none}
    .modal-caja{background:#fff;border-radius:10px;max-width:460px;width:100%;padding:1.3rem 1.4rem;box-shadow:0 12px 40px rgba(0,0,0,.35);max-height:90vh;overflow:auto}
    .modal-caja h3{font-size:1.1rem;margin:0 0 .7rem;color:#991b1b}
    .modal-caja ul{margin:0;padding-left:1.1rem;font-size:.88rem;line-height:1.6;max-height:180px;overflow:auto}
    .modal-caja li small{color:var(--gris-4)}
    .modal-aviso{margin-top:.9rem;background:#fef2f2;border:1.5px solid #fecaca;border-radius:8px;padding:.8rem .9rem;font-size:.82rem;color:#7f1d1d;line-height:1.5}
    </style>
    <script>
    (function(){
      const chks = () => [...document.querySelectorAll('.usr-chk')];
      window.seleccionados = () => chks().filter(c => c.checked);
      function actualizar(){
        const n = seleccionados().length;
        document.getElementById('usr-sel-btn').disabled = !n;
        document.getElementById('usr-sel-txt').textContent = n ? n + ' seleccionado' + (n>1?'s':'') : 'Marcá usuarios para borrar varios a la vez.';
      }
      document.addEventListener('change', e => {
        if (e.target.id === 'usr-todos') chks().forEach(c => c.checked = e.target.checked);
        if (e.target.id === 'usr-todos' || e.target.classList.contains('usr-chk')) actualizar();
      });
      const esc = t => t.replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
      let conPedidos = 0;
      window.pedirBorrado = function(lista){
        lista = lista.filter(Boolean); if (!lista.length) return;
        conPedidos = lista.reduce((a, c) => a + (+c.dataset.pedidos), 0);
        document.getElementById('borrar-titulo').textContent =
          lista.length === 1 ? '¿Eliminar este usuario?' : '¿Eliminar estos ' + lista.length + ' usuarios?';
        document.getElementById('borrar-lista').innerHTML = lista.map(c =>
          '<li><strong>' + esc(c.dataset.nombre) + '</strong> <small>' + esc(c.dataset.email) +
          (+c.dataset.pedidos ? ' · ' + c.dataset.pedidos + ' pedido' + (c.dataset.pedidos>1?'s':'') : ' · sin pedidos') + '</small></li>').join('');
        document.getElementById('borrar-uids').innerHTML = lista.map(c =>
          '<input type="hidden" name="uids[]" value="' + (+c.value) + '">').join('');
        const av = document.getElementById('borrar-aviso-pedidos');
        av.hidden = !conPedidos;
        document.getElementById('borrar-npedidos').textContent = conPedidos
          ? (lista.length === 1 ? 'Este usuario tiene ' : 'Entre todos tienen ') + conPedidos + ' pedido' + (conPedidos>1?'s':'') + '.' : '';
        document.getElementById('borrar-texto').value = '';
        document.getElementById('borrar-pedidos').value = conPedidos ? '1' : '0';
        document.getElementById('modal-borrar').hidden = false;
        (conPedidos ? document.getElementById('borrar-texto') : document.getElementById('borrar-ok')).focus();
      };
      window.cerrarBorrado = () => { document.getElementById('modal-borrar').hidden = true; };
      window.validarBorrado = function(){
        if (conPedidos && document.getElementById('borrar-texto').value.trim().toUpperCase() !== 'BORRAR') {
          const t = document.getElementById('borrar-texto'); t.focus(); t.style.borderColor = '#dc2626';
          return false;
        }
        return true;
      };
      document.getElementById('modal-borrar').addEventListener('click', e => { if (e.target.id === 'modal-borrar') cerrarBorrado(); });
      document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarBorrado(); });
    })();
    </script>

  <!-- ══════════════════════════════════════════════════════
       PRODUCTOS
  ══════════════════════════════════════════════════════ -->
  <?php elseif ($seccion === 'productos'): ?>

    <!-- Excel: descargar y volver a subir -->
    <div class="import-box">
      <h3>📊 Productos en Excel</h3>
      <p style="font-size:.84rem;color:var(--gris-4);margin:0 0 1rem;line-height:1.5">
        Descargá el catálogo completo, corregí SKU, descripciones, presentación, sección, orden o precios,
        y volvé a subir el mismo archivo. La columna <code>id</code> permite cambiar un SKU sin duplicar el producto.
        Las celdas vacías no borran nada.
      </p>
      <a href="exportar_productos.php" class="btn-sm" style="text-decoration:none;background:#16a34a;display:inline-block;margin-bottom:1rem">
        ⬇️ Descargar productos (Excel)</a>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="importar_excel">
        <div class="form-row" style="align-items:end">
          <div class="field">
            <label>Subir Excel (.xlsx)</label>
            <input type="file" name="excel_file" accept=".xlsx,.csv" required>
          </div>
          <div class="field">
            <label>Modo de importación</label>
            <select name="modo_importar">
              <option value="todo">Importar todo (SKU, textos, sección, orden, activo y precios)</option>
              <option value="solo_precios">Solo precios (no toca nada más)</option>
            </select>
          </div>
        </div>
        <button type="submit" class="btn-sm rojo">📥 Importar</button>
      </form>
    </div>

    <!-- Formulario crear/editar -->
    <div class="edit-form">
      <h3><?= $editar_prod ? 'Editar producto' : 'Nuevo producto' ?></h3>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="guardar_producto">
        <?php if ($editar_prod): ?>
          <input type="hidden" name="pid" value="<?= $editar_prod['id'] ?>">
          <input type="hidden" name="img_actual" value="<?= escape($editar_prod['imagen'] ?? '') ?>">
        <?php endif; ?>
        <div class="form-row">
          <div class="field"><label>SKU / código</label>
            <input type="text" name="codigo" required value="<?= escape($editar_prod['codigo'] ?? '') ?>" placeholder="APC01-650"></div>
          <div class="field"><label>Sección</label>
            <select name="seccion">
              <option value="regular" <?= ($editar_prod['seccion'] ?? '') !== 'nuevo' ? 'selected' : '' ?>>Regular</option>
              <option value="nuevo"   <?= ($editar_prod['seccion'] ?? '') === 'nuevo' ? 'selected' : '' ?>>Nuevo lanzamiento</option>
            </select></div>
          <div class="field" style="grid-column:1/-1"><label>Descripción (sale en la lista y en el portal)</label>
            <input type="text" name="nombre" required value="<?= escape($editar_prod['nombre'] ?? '') ?>"></div>
          <div class="field"><label>Presentación (Enter = otra línea)</label>
            <textarea name="presentacion" rows="3" placeholder="Botella 650cc c/ pulverizador"><?= escape($editar_prod['presentacion'] ?? '') ?></textarea></div>
          <div class="field"><label>Bajada / texto corto (opcional)</label>
            <textarea name="descripcion" rows="3"><?= escape($editar_prod['descripcion'] ?? '') ?></textarea></div>
        </div>
        <div class="precios-grid" style="margin-bottom:.8rem">
          <?php foreach ([
              ['precio_distribuidor', 'Distribuidor (+IVA)',   '#e30613'],
              ['precio_pto_venta',    'Punto de Venta (+IVA)', '#3b82f6'],
              ['pvp_sugerido',        'PVP sugerido (final)',  '#16a34a'],
          ] as [$campo, $label, $color]): ?>
          <div class="field precio-field">
            <label style="color:<?= $color ?>">● <?= $label ?></label>
            <input type="number" name="<?= $campo ?>" step="0.01" min="0"
                   value="<?= $editar_prod[$campo] ?? '' ?>" placeholder="0">
          </div>
          <?php endforeach; ?>
        </div>
        <div class="form-row">
          <div class="field"><label>Imagen</label>
            <input type="file" name="imagen" accept="image/*">
            <?php if (!empty($editar_prod['imagen'])): ?>
              <small style="color:var(--gris-4)">Actual: <?= escape($editar_prod['imagen']) ?></small>
            <?php endif; ?>
          </div>
          <div class="field"><label>Orden en la lista</label>
            <input type="number" name="orden" min="0" value="<?= $editar_prod['orden'] ?? 0 ?>"></div>
        </div>
        <button type="submit" class="btn-sm rojo"><?= $editar_prod ? 'Guardar cambios' : 'Crear producto' ?></button>
        <?php if ($editar_prod): ?>
          <a href="?s=productos" style="margin-left:.5rem;font-size:.82rem;color:var(--gris-4)">Cancelar</a>
        <?php endif; ?>
      </form>
    </div>

    <div style="overflow-x:auto">
      <table class="hist-tabla">
        <thead>
          <tr>
            <th>SKU</th><th>Descripción</th><th>Presentación</th>
            <th style="color:#e30613">Distribuidor</th>
            <th style="color:#3b82f6">Pto. Venta</th>
            <th style="color:#16a34a">PVP</th>
            <th>Estado</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($productos as $prod): ?>
          <tr <?= $prod['activo'] ? '' : 'style="opacity:.55"' ?>>
            <td style="font-size:.8rem;color:var(--gris-4);white-space:nowrap"><?= escape($prod['codigo']) ?></td>
            <td style="font-weight:600"><?= escape($prod['nombre']) ?>
              <?php if (($prod['seccion'] ?? '') === 'nuevo'): ?><span class="estado-badge estado-en_preparacion" style="margin-left:4px">Nuevo</span><?php endif; ?></td>
            <td style="font-size:.8rem;color:var(--gris-4)"><?= nl2br(escape($prod['presentacion'] ?? '')) ?></td>
            <td><?= precio_fmt((float)$prod['precio_distribuidor']) ?></td>
            <td><?= precio_fmt((float)$prod['precio_pto_venta']) ?></td>
            <td><?= precio_fmt((float)$prod['pvp_sugerido']) ?></td>
            <td><span class="estado-badge <?= $prod['activo'] ? 'estado-enviado' : 'estado-cancelado' ?>"><?= $prod['activo'] ? 'Visible' : 'Oculto' ?></span></td>
            <td style="display:flex;gap:.4rem;flex-wrap:wrap">
              <a href="?s=productos&editar=<?= $prod['id'] ?>" class="btn-det">Editar</a>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="toggle_producto">
                <input type="hidden" name="pid" value="<?= $prod['id'] ?>">
                <button type="submit" class="btn-sm" style="background:<?= $prod['activo'] ? '#888' : '#22c55e' ?>">
                  <?= $prod['activo'] ? 'Ocultar' : 'Mostrar' ?></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  <?php elseif ($seccion === 'novedades'): ?>
    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.6rem;margin-bottom:1rem">Novedades</h2>
    <?php render_novedades($flash_ok, $flash_err); ?>

  <?php elseif ($seccion === 'descuentos'): ?>
    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.6rem;margin-bottom:1rem">Códigos de Descuento</h2>
    <?php render_descuentos(); ?>

  <!-- ══════════════════════════════════════════════════════
       CONFIG
  ══════════════════════════════════════════════════════ -->
  <?php elseif ($seccion === 'config'): ?>
    <div class="config-grid">
      <?php render_promo12(); ?>
      <div class="config-card">
        <h3>Promoción activa</h3>
        <form method="post">
          <input type="hidden" name="action" value="guardar_config">
          <div class="field">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
              <input type="checkbox" name="promo_activa" value="1" <?= $promo_activa==='1'?'checked':'' ?>>
              Mostrar barra de progreso
            </label>
          </div>
          <div class="field"><label>Monto mínimo ($)</label>
            <input type="number" name="promo_monto" value="<?= escape($promo_monto) ?>" step="100" min="0"></div>
          <div class="field"><label>Texto de la promo</label>
            <input type="text" name="promo_texto" value="<?= escape($promo_texto) ?>"></div>
          <button type="submit" class="btn-sm rojo">Guardar configuración</button>
        </form>
      </div>
      <div class="config-card" style="grid-column:1/-1">
        <h3>📲 Plantilla del mensaje de WhatsApp</h3>
        <form method="post">
          <input type="hidden" name="action" value="guardar_wa_texto">
          <div class="field">
            <textarea name="wa_texto_pedido" rows="12"
                      style="width:100%;padding:.7rem .85rem;border:1.5px solid var(--gris-2);border-radius:6px;font-family:inherit;font-size:.86rem;outline:none;resize:vertical;line-height:1.5"><?= escape(get_config('wa_texto_pedido','')) ?></textarea>
          </div>
          <div style="background:#f8f8f8;border-radius:6px;padding:.7rem .9rem;margin-bottom:.8rem;font-size:.78rem;line-height:1.8;color:var(--gris-4)">
            <strong style="color:var(--negro)">Variables disponibles:</strong><br>
            <code>{cliente}</code> nombre del cliente &nbsp;·&nbsp;
            <code>{pedido}</code> número de pedido &nbsp;·&nbsp;
            <code>{total}</code> total final<br>
            <code>{cantidad_items}</code> cantidad de productos &nbsp;·&nbsp;
            <code>{estado}</code> estado del pedido &nbsp;·&nbsp;
            <code>{link_pdf}</code> link al PDF
          </div>
          <button type="submit" class="btn-sm rojo">Guardar plantilla</button>
        </form>
      </div>

      <div class="config-card">
        <h3>🧾 Facturación y ajustes de lista</h3>
        <form method="post">
          <input type="hidden" name="action" value="guardar_config_comercial">
          <div class="field"><label>Recargo general para pedidos sin factura</label>
            <select name="recargo_sin_factura">
              <?php $rg = get_config('recargo_sin_factura','0'); foreach (RECARGOS_SIN_FACTURA as $k => $t): ?>
                <option value="<?= $k ?>" <?= (string)(float)$rg === (string)(float)$k ? 'selected' : '' ?>><?= $t ?></option>
              <?php endforeach; ?>
            </select>
            <small style="color:var(--gris-4);display:block;margin-top:.25rem">Cada cliente puede tener uno propio en su ficha. Los clientes no lo ven.</small></div>
          <?php foreach (LISTAS as $lid => $l): ?>
          <div class="field"><label>Ajuste de precios · <?= escape($l['nombre']) ?> (%)</label>
            <input type="number" name="ajuste_lista_<?= $lid ?>" step="0.1" min="-90" max="100" value="<?= escape(get_config('ajuste_lista_'.$lid,'0')) ?>"></div>
          <?php endforeach; ?>
          <small style="color:var(--gris-4);display:block;margin:-.3rem 0 .8rem">Negativo = descuento, positivo = recargo. Se aplica a todos los clientes de la lista que no tengan un ajuste propio; el cliente ve directamente el precio final.</small>
          <button type="submit" class="btn-sm rojo">Guardar</button>
        </form>
      </div>

      <div class="config-card">
        <h3>Listas de precios</h3>
        <?php foreach (LISTAS as $id => $l): ?>
        <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.6rem">
          <span style="width:10px;height:10px;border-radius:50%;background:<?= $l['color'] ?>;display:inline-block;flex-shrink:0"></span>
          <div>
            <div style="font-weight:700;font-size:.88rem"><?= escape($l['nombre']) ?></div>
            <div style="font-size:.75rem;color:var(--gris-4)">Campo BD: <code><?= $l['campo'] ?></code></div>
          </div>
        </div>
        <?php endforeach; ?>
        <p style="font-size:.78rem;color:var(--gris-4);margin-top:.5rem">
          Para modificar los nombres de lista, editá el archivo <code>config.php</code>.
        </p>
      </div>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
