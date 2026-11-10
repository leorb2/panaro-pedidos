<?php
// perfil.php — v3: cambio contraseña + dirección por defecto
require 'config.php';
auth_required();
$u = current_user();

$lista_id     = (int)($u['lista_id'] ?? 3);
$lista_nombre = nombre_lista($lista_id);
$lista_color  = color_lista($lista_id);
$flash_ok  = '';
$flash_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'cambiar_pass') {
        $pass_actual = $_POST['pass_actual'] ?? '';
        $pass_nueva  = $_POST['pass_nueva']  ?? '';
        $pass_rep    = $_POST['pass_rep']    ?? '';
        if (!$pass_actual || !$pass_nueva || !$pass_rep) {
            $flash_err = 'Completá todos los campos.';
        } elseif ($pass_nueva !== $pass_rep) {
            $flash_err = 'Las contraseñas nuevas no coinciden.';
        } elseif (strlen($pass_nueva) < 6) {
            $flash_err = 'La contraseña debe tener al menos 6 caracteres.';
        } else {
            $st = db()->prepare('SELECT password FROM usuarios WHERE id=? LIMIT 1');
            $st->execute([$u['id']]);
            $row = $st->fetch();
            if (!$row || !password_verify($pass_actual, $row['password'])) {
                $flash_err = 'La contraseña actual es incorrecta.';
            } else {
                db()->prepare('UPDATE usuarios SET password=? WHERE id=?')
                   ->execute([password_hash($pass_nueva, PASSWORD_DEFAULT), $u['id']]);
                $flash_ok = '✅ Contraseña actualizada correctamente.';
            }
        }
    }

    if ($action === 'guardar_direccion') {
        $dir = trim($_POST['direccion'] ?? '');
        db()->prepare('UPDATE usuarios SET direccion=? WHERE id=?')->execute([$dir, $u['id']]);
        $_SESSION['user']['direccion'] = $dir;
        $u['direccion'] = $dir;
        $flash_ok = '✅ Dirección guardada correctamente.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Mi Perfil — Panaro Car Care</title>
<link rel="stylesheet" href="style.css">
<style>
.page-layout{display:flex;flex-direction:column;height:100vh;overflow:hidden}
.header-fixo{flex-shrink:0;background:var(--negro);box-shadow:0 2px 12px rgba(0,0,0,.5);z-index:100}
.page-scroll{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch}
.logo{height:26px;width:auto;max-width:30%;object-fit:contain;flex-shrink:1}
@media(min-width:600px){.logo{height:40px;max-width:none}}
.site-header{background:var(--negro);display:flex;align-items:center;justify-content:space-between;padding:.55rem .8rem;gap:.4rem}
.perfil-card{background:var(--blanco);border-radius:10px;padding:1.5rem;box-shadow:0 2px 16px rgba(0,0,0,.08);max-width:480px;margin-bottom:1.2rem}
.field{margin-bottom:.9rem}
.field label{display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);margin-bottom:.3rem}
.field input,.field textarea{width:100%;padding:.65rem .85rem;border:1.5px solid var(--gris-2);border-radius:6px;font-family:inherit;font-size:.95rem;outline:none;transition:border-color .15s;resize:vertical}
.field input:focus,.field textarea:focus{border-color:var(--rojo)}
</style>
</head>
<body>
<div class="page-layout">
<div class="header-fixo" id="sticky-header">
  <header class="site-header">
    <img src="assets/img/logo.png" alt="Panaro Car Care" class="logo">
    <div class="header-right" style="gap:.35rem">
      <span class="descuento-badge" style="background:<?= escape($lista_color) ?>;font-size:.7rem;white-space:nowrap">
        <?= escape($lista_nombre) ?>
      </span>
      <?php if (!empty($u['es_admin'])): ?>
      <a href="admin.php" class="btn-logout" style="border-color:#e30613;color:#e30613;white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Admin</a>
      <?php endif; ?>
      <a href="pedido.php" class="btn-logout" style="border-color:#444;color:#aaa;white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Pedido</a>
      <a href="logout.php" class="btn-logout" style="white-space:nowrap;font-size:.72rem;padding:.25rem .55rem">Salir</a>
    </div>
  </header>
</div>

<div class="page-scroll">
<div class="main-wrap">
  <div class="page-nav">
    <a href="pedido.php">Nuevo pedido</a>
    <a href="historial.php">Mis pedidos</a>
    <a href="listas.php">Listas de precios</a>
    <a href="perfil.php" class="activo">Mi perfil</a>
  </div>
  <div class="pedido-header"><h2>Mi Perfil</h2></div>

  <?php if ($flash_ok): ?><div class="flash-ok"><?= escape($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="flash-err"><?= escape($flash_err) ?></div><?php endif; ?>

  <!-- Info usuario -->
  <div class="perfil-card">
    <div style="margin-bottom:.6rem">
      <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);margin-bottom:2px">Nombre</div>
      <div style="font-size:1rem;font-weight:600"><?= escape($u['nombre']) ?></div>
    </div>
    <div style="margin-bottom:.6rem">
      <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);margin-bottom:2px">Email</div>
      <div style="font-size:.95rem"><?= escape($u['email']) ?></div>
    </div>
    <div>
      <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);margin-bottom:4px">Lista de precios</div>
      <span class="descuento-badge" style="background:<?= escape($lista_color) ?>"><?= escape($lista_nombre) ?></span>
    </div>
  </div>

  <!-- Dirección por defecto -->
  <div class="perfil-card">
    <h3 style="font-family:'Bebas Neue',sans-serif;font-size:1.4rem;margin-bottom:1.2rem">Dirección de Envío por Defecto</h3>
    <p style="font-size:.82rem;color:var(--gris-4);margin-bottom:1rem">Se pre-completa automáticamente en cada pedido. Podés editarla antes de enviar.</p>
    <form method="post">
      <input type="hidden" name="action" value="guardar_direccion">
      <div class="field">
        <label>Dirección completa</label>
        <textarea name="direccion" rows="3"
                  placeholder="Ej: Av. Corrientes 1234, CABA — Lunes a viernes de 9 a 18hs"><?= escape($u['direccion'] ?? '') ?></textarea>
      </div>
      <button type="submit" class="btn-enviar" style="max-width:220px;font-size:1rem;padding:.7rem">Guardar dirección</button>
    </form>
  </div>

  <!-- Cambio de contraseña -->
  <div class="perfil-card">
    <h3 style="font-family:'Bebas Neue',sans-serif;font-size:1.4rem;margin-bottom:1.2rem">Cambiar Contraseña</h3>
    <form method="post">
      <input type="hidden" name="action" value="cambiar_pass">
      <div class="field"><label>Contraseña actual</label>
        <input type="password" name="pass_actual" placeholder="••••••••" required></div>
      <div class="field"><label>Contraseña nueva</label>
        <input type="password" name="pass_nueva" placeholder="Mínimo 6 caracteres" required></div>
      <div class="field"><label>Repetir contraseña nueva</label>
        <input type="password" name="pass_rep" placeholder="Repetí la nueva contraseña" required></div>
      <button type="submit" class="btn-enviar" style="max-width:220px;font-size:1rem;padding:.7rem">Cambiar contraseña</button>
    </form>
  </div>

</div>
</div>
</div>
</body>
</html>
