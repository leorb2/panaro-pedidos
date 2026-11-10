<?php
// ============================================================
//  lista_pdf.php — Descarga de una lista de precios en PDF
//  ?l=distribuidor | punto_venta | publico   (&descargar=1 para forzar descarga)
//  Se genera al momento con la fecha del día; queda en caché hasta que
//  cambie un precio, un producto o el día.
// ============================================================
require 'config.php';
require 'listas_lib.php';
auth_required();

$u    = current_user();
$tipo = (string)($_GET['l'] ?? '');
$permitidas = listas_permitidas($u);

if (!isset($permitidas[$tipo])) {
    http_response_code(403);
    die('<div style="font-family:system-ui;padding:2rem">No tenés acceso a esta lista. <a href="listas.php">Volver</a></div>');
}
if (!cargar_vendor() || !class_exists('\Dompdf\Dompdf')) {
    die('<div style="font-family:system-ui;padding:2rem">Falta instalar Dompdf en el servidor. <a href="listas.php">Volver</a></div>');
}

@set_time_limit(90);
@ini_set('memory_limit', '256M');

try {
    $r = pdf_lista_cache(db(), $tipo, __DIR__);
} catch (Throwable $e) {
    die('<div style="font-family:system-ui;padding:2rem">No se pudo generar la lista: ' . escape($e->getMessage()) . '</div>');
}
if (!is_file($r['archivo'])) {
    die('<div style="font-family:system-ui;padding:2rem">No se pudo guardar la lista. Revisá que la carpeta <code>cache/</code> tenga permisos de escritura (755).</div>');
}

header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['descargar']) ? 'attachment' : 'inline') . '; filename="' . $r['nombre'] . '"');
header('Content-Length: ' . filesize($r['archivo']));
header('Cache-Control: private, no-cache');
readfile($r['archivo']);
