<?php
// ============================================================
//  index.php — Login v3
// ============================================================
require 'config.php';
session_start_safe();

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['is_admin'] ? 'admin.php' : 'pedido.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if ($email && $pass) {
        $st = db()->prepare('SELECT * FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();

        if ($u && password_verify($pass, $u['password'])) {
            $_SESSION['user_id']  = $u['id'];
            $_SESSION['is_admin'] = (bool)$u['es_admin'];
            $_SESSION['user']     = [
                'id'        => $u['id'],
                'nombre'    => $u['nombre'],
                'email'     => $u['email'],
                'lista_id'  => (int)$u['lista_id'],   // ← FIX: antes no se guardaba
                'whatsapp'  => $u['whatsapp'],
                'es_admin'  => (bool)$u['es_admin'],
            ];
            header('Location: ' . ($u['es_admin'] ? 'admin.php' : 'pedido.php'));
            exit;
        } else {
            $error = 'Email o contraseña incorrectos.';
        }
    } else {
        $error = 'Completá todos los campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Acceso Mayoristas — Panaro Car Care</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="login-page">
  <div class="login-box">
    <div class="logo-wrap">
      <img src="assets/img/logo.png" alt="Panaro Car Care">
    </div>
    <h2>Portal Mayoristas</h2>

    <?php if ($error): ?>
      <div class="alert-error"><?= escape($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <div class="form-group">
        <label for="email">Email</label>
        <input class="form-control" type="email" id="email" name="email"
               value="<?= escape($_POST['email'] ?? '') ?>"
               placeholder="tu@email.com" required autofocus>
      </div>
      <div class="form-group">
        <label for="password">Contraseña</label>
        <input class="form-control" type="password" id="password" name="password"
               placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn-primary">Ingresar →</button>
    </form>
  </div>
</div>
</body>
</html>
