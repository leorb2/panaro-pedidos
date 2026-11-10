<?php
// ============================================================
//  config.php — v5
//  Las contraseñas y datos privados van en credenciales.php
//  (ese archivo NO se reemplaza en las actualizaciones).
// ============================================================

if (is_file(__DIR__ . '/credenciales.php')) {
    require __DIR__ . '/credenciales.php';
}

// Valores por defecto: solo se usan si credenciales.php no los define
defined('DB_HOST')            || define('DB_HOST', 'localhost');
defined('DB_NAME')            || define('DB_NAME', 'panaro_pedidos');
defined('DB_USER')            || define('DB_USER', '');
defined('DB_PASS')            || define('DB_PASS', '');
defined('MAIL_TO')            || define('MAIL_TO', 'pedidos@panaro.com.ar');
defined('MAIL_FROM')          || define('MAIL_FROM', 'noreply@panaro.com.ar');
defined('MAIL_NAME')          || define('MAIL_NAME', 'Panaro Car Care — Pedidos');
defined('WA_NUMBER')          || define('WA_NUMBER', '');
defined('SITE_URL')           || define('SITE_URL', 'https://pedidos.panaro.com.ar');
defined('TELEGRAM_BOT_TOKEN') || define('TELEGRAM_BOT_TOKEN', '');
defined('TELEGRAM_CHAT_ID')   || define('TELEGRAM_CHAT_ID', '');

// ── Listas de precios (dos escalas) ─────────────────────────
// El id 2 (Punto de Venta Original) se eliminó: solo queda en LISTAS_HISTORICAS
// para mostrar bien los pedidos viejos.
define('LISTAS', [
    1 => ['nombre' => 'Distribuidor',   'campo' => 'precio_distribuidor', 'color' => '#e30613', 'bit' => 1],
    3 => ['nombre' => 'Punto de Venta', 'campo' => 'precio_pto_venta',    'color' => '#3b82f6', 'bit' => 4],
]);
define('LISTAS_HISTORICAS', [
    2 => ['nombre' => 'Punto de Venta Original', 'color' => '#f97316'],
]);

// ── Base de datos ───────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (DB_USER === '') {
            die('<div style="font-family:system-ui;max-width:640px;margin:3rem auto;padding:1.5rem;border:2px solid #c00;border-radius:8px">
                 <h2 style="color:#c00;margin-top:0">Falta el archivo credenciales.php</h2>
                 <p>Copiá <code>credenciales.ejemplo.php</code> como <code>credenciales.php</code> y completá
                 los datos de la base de datos (los mismos que tenía tu config.php anterior).</p></div>');
        }
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            die('<div style="font-family:monospace;padding:2rem;color:#c00">Error BD: ' . $e->getMessage() . '</div>');
        }
    }
    return $pdo;
}

// ── Sesión ──────────────────────────────────────────────────
function session_start_safe(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

// Refresca los datos del usuario desde la base en cada página.
// Así un cambio de lista, nombre o dirección se ve sin volver a loguearse,
// y un usuario desactivado queda afuera en el acto.
function refrescar_sesion(): bool {
    static $hecho = false;
    if ($hecho) return true;
    $st = db()->prepare('SELECT * FROM usuarios WHERE id = ? LIMIT 1');
    $st->execute([(int)$_SESSION['user_id']]);
    $u = $st->fetch();
    if (!$u || !$u['activo']) {
        $_SESSION = [];
        session_destroy();
        return false;
    }
    $lista = (int)$u['lista_id'];
    if (!$u['es_admin'] && !isset(LISTAS[$lista])) $lista = 3;   // cualquier lista vieja → Punto de Venta
    $_SESSION['is_admin'] = (bool)$u['es_admin'];
    $_SESSION['user'] = [
        'id'        => (int)$u['id'],
        'nombre'    => $u['nombre'],
        'email'     => $u['email'],
        'lista_id'  => isset(LISTAS[$lista]) ? $lista : 1,
        'whatsapp'  => $u['whatsapp'],
        'direccion' => $u['direccion'] ?? '',
        'es_admin'  => (bool)$u['es_admin'],
    ];
    $hecho = true;
    return true;
}
function auth_required(): void {
    session_start_safe();
    if (empty($_SESSION['user_id']) || !refrescar_sesion()) { header('Location: index.php'); exit; }
}
function admin_required(): void {
    auth_required();
    if (empty($_SESSION['is_admin'])) { header('Location: index.php'); exit; }
}
function current_user(): array {
    session_start_safe();
    return $_SESSION['user'] ?? [];
}

// ── Formato ─────────────────────────────────────────────────
function precio_fmt(float $n): string {
    return '$' . number_format($n, 0, ',', '.');
}
function escape(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
// Primera línea de la presentación (los kits la tienen en varias)
function presentacion_corta(?string $p): string {
    $l = trim(strtok((string)$p, "\n"));
    return $l;
}
// Nombre que se guarda en el pedido: los 650cc y los 5 litros comparten nombre,
// así que se agrega la presentación para que no se confundan.
function nombre_item(array $p): string {
    $pres = presentacion_corta($p['presentacion'] ?? '');
    return $pres !== '' ? $p['nombre'] . ' — ' . $pres : $p['nombre'];
}

// ── Configuración ───────────────────────────────────────────
function get_config(string $key, string $default = ''): string {
    try {
        $st = db()->prepare('SELECT valor FROM configuracion WHERE clave=? LIMIT 1');
        $st->execute([$key]);
        $row = $st->fetch();
        return $row ? $row['valor'] : $default;
    } catch (Throwable $e) { return $default; }
}
function set_config(string $key, string $value): void {
    db()->prepare('INSERT INTO configuracion (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)')
       ->execute([$key, $value]);
}

// ── Listas ──────────────────────────────────────────────────
function campo_precio(int $lista_id): string {
    return LISTAS[$lista_id]['campo'] ?? 'precio_pto_venta';
}
function nombre_lista(int $lista_id): string {
    return LISTAS[$lista_id]['nombre'] ?? LISTAS_HISTORICAS[$lista_id]['nombre'] ?? 'Lista';
}
function color_lista(int $lista_id): string {
    return LISTAS[$lista_id]['color'] ?? LISTAS_HISTORICAS[$lista_id]['color'] ?? '#888';
}
function bit_lista(int $lista_id): int {
    return LISTAS[$lista_id]['bit'] ?? 0;
}

// Novedades activas para una lista
function get_novedades(int $lista_id): array {
    $bit = bit_lista($lista_id);
    try {
        $st = db()->prepare(
            'SELECT * FROM novedades
             WHERE activa=1
               AND (vence_en IS NULL OR vence_en > NOW())
               AND (listas & ?) > 0
             ORDER BY orden ASC, created_at DESC
             LIMIT 10'
        );
        $st->execute([$bit]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

// Validar código de descuento
function validar_codigo(string $cod, int $lista_id, int $usuario_id, float $total): array {
    if (!$cod) return ['ok' => false, 'error' => ''];
    try {
        $st = db()->prepare('SELECT * FROM codigos_descuento WHERE codigo=? AND activo=1 LIMIT 1');
        $st->execute([strtoupper(trim($cod))]);
        $c = $st->fetch();
        if (!$c) return ['ok' => false, 'error' => 'Código no válido.'];
        if ($c['vence_en'] && strtotime($c['vence_en']) < time())
            return ['ok' => false, 'error' => 'El código venció.'];
        if ($c['usos_actuales'] >= $c['usos_max'])
            return ['ok' => false, 'error' => 'El código alcanzó su límite de usos.'];
        if ($c['usuario_id'] && $c['usuario_id'] != $usuario_id)
            return ['ok' => false, 'error' => 'Este código no está disponible para tu cuenta.'];
        if ($c['lista_id'] && $c['lista_id'] != $lista_id)
            return ['ok' => false, 'error' => 'Este código no está disponible para tu lista de precios.'];
        if ($total < $c['monto_minimo'])
            return ['ok' => false, 'error' => 'El pedido mínimo para este código es ' . precio_fmt($c['monto_minimo']) . '.'];
        if ($c['tipo'] === 'porcentaje') {
            $dto = round($total * ((float)$c['valor'] / 100), 2);
        } else {
            $dto = min((float)$c['valor'], $total);
        }
        return ['ok' => true, 'dto' => $dto, 'tipo' => $c['tipo'], 'valor' => (float)$c['valor'], 'id' => (int)$c['id']];
    } catch (Throwable $e) { return ['ok' => false, 'error' => 'Error al validar el código.']; }
}

// Calcular unidades de regalo 12+1
function calc_regalo(int $qty): int {
    return (int)floor($qty / 12);
}

// Enviar mensaje a Telegram
function enviar_telegram(string $mensaje): void {
    $token   = TELEGRAM_BOT_TOKEN;
    $chat_id = TELEGRAM_CHAT_ID;
    if (!$token || $token === 'TU_BOT_TOKEN_AQUI') return; // no configurado
    $url  = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = http_build_query(['chat_id' => $chat_id, 'text' => $mensaje]);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => $data,
        'timeout'       => 5,
        'ignore_errors' => true,
    ]]);
    @file_get_contents($url, false, $ctx);
}

// Autoload de composer (PhpSpreadsheet, Dompdf)
function cargar_vendor(): bool {
    static $ok = null;
    if ($ok === null) {
        $f = __DIR__ . '/vendor/autoload.php';
        $ok = is_file($f);
        if ($ok) require_once $f;
    }
    return $ok;
}
