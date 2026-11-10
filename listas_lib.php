<?php
// ============================================================
//  listas_lib.php — Listas de precios PDF (diseño Panaro)
//  Replica las listas "Mayo 2026": encabezado con redes, franja ANMAT,
//  miniaturas, sección "Nuevos lanzamientos" en hoja aparte y pie "Hoja N de M".
//  Se genera al momento desde la base del portal, con la fecha del día.
// ============================================================

// ── Definición de las tres listas ───────────────────────────
// acceso: ids de lista de usuario que pueden descargarla (el admin ve todas)
const LISTAS_PDF = [
    'distribuidor' => [
        'titulo'   => 'Distribuidor',
        'archivo'  => 'Panaro lista de precios Distribuidor',
        'acceso'   => [1],
        'columnas' => [
            ['campo' => 'precio_distribuidor', 'titulo' => 'DISTRIBUIDOR', 'ancho' => 62],
            ['campo' => 'precio_pto_venta',    'titulo' => 'REVENDEDOR',   'ancho' => 56],
            ['campo' => 'pvp_sugerido',        'titulo' => 'PVP SUGERIDO', 'ancho' => 54, 'destacada' => true],
        ],
        'info'     => 'Vigente al {fecha}  •  Precios en $ +IVA  •  Sujetos a cambio',
        'leyenda'  => 'Precios en pesos argentinos +IVA  •  Pueden variar sin previo aviso  •  panaro.com.ar',
        'leyenda_nuevos' => 'Nuevos productos {mes}  •  Precios en $ +IVA',
        'descripcion' => 'Precio distribuidor, revendedor (punto de venta) y PVP sugerido.',
    ],
    'punto_venta' => [
        'titulo'   => 'Punto de Venta',
        'archivo'  => 'Panaro lista de precios Punto de Venta',
        'acceso'   => [1, 3],
        'columnas' => [
            ['campo' => 'precio_pto_venta', 'titulo' => 'PUNTO DE VENTA', 'ancho' => 92],
            ['campo' => 'pvp_sugerido',     'titulo' => 'PVP SUGERIDO',   'ancho' => 80, 'destacada' => true],
        ],
        'info'     => 'Vigente al {fecha}  •  Precios en $ +IVA  •  Sujetos a cambio',
        'leyenda'  => 'Precios en pesos argentinos +IVA  •  Pueden variar sin previo aviso  •  panaro.com.ar',
        'leyenda_nuevos' => 'Nuevos productos {mes}  •  Precios en $ +IVA',
        'descripcion' => 'Precio punto de venta y PVP sugerido.',
    ],
    'publico' => [
        'titulo'   => 'Precios al público',
        'archivo'  => 'Panaro lista de precios al publico',
        'acceso'   => [1, 3],
        'columnas' => [
            ['campo' => 'pvp_sugerido', 'titulo' => 'PRECIO FINAL', 'ancho' => 80, 'destacada' => true],
        ],
        'info'     => 'Lista de precios al público  •  Vigente al {fecha}  •  Precios finales',
        'leyenda'  => 'Precios en pesos argentinos  •  Precios finales con IVA incluido  •  Pueden variar sin previo aviso  •  panaro.com.ar',
        'leyenda_nuevos' => 'Nuevos productos {mes}  •  Precios finales',
        'descripcion' => 'Precio final con IVA, para mostrar o reenviar a clientes finales.',
    ],
];

const LISTAS_PDF_VERSION = '5.0';   // cambiarla invalida la caché de PDFs

// Listas que puede descargar un usuario
function listas_permitidas(array $u): array {
    $out = [];
    foreach (LISTAS_PDF as $k => $def) {
        if (!empty($u['es_admin']) || in_array((int)($u['lista_id'] ?? 0), $def['acceso'], true)) $out[$k] = $def;
    }
    return $out;
}

function mes_anio_es(int $ts): string {
    $m = ['', 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO',
          'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
    return $m[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

// Productos de una lista: activos y con todos los precios de la lista cargados
function filas_lista(PDO $db, string $tipo): array {
    $def = LISTAS_PDF[$tipo];
    $campos = array_column($def['columnas'], 'campo');
    $cond = implode(' AND ', array_map(fn($c) => "$c > 0", $campos));
    return $db->query(
        "SELECT id, codigo, nombre, presentacion, seccion, imagen, orden,
                precio_distribuidor, precio_pto_venta, pvp_sugerido
         FROM productos
         WHERE activo = 1 AND $cond
         ORDER BY (seccion = 'nuevo') ASC, orden ASC, id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// "$ 5,220" — mismo formato que las listas impresas
function precio_lista(float $n): string {
    return '$ ' . number_format(round($n), 0, '.', ',');
}

function data_uri(string $file): string {
    if (!is_file($file)) return '';
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file));
}

// ── HTML de la lista ─────────────────────────────────────────
function html_lista(string $tipo, array $filas, string $base, int $ts): string {
    $def   = LISTAS_PDF[$tipo];
    $cols  = $def['columnas'];
    $fecha = date('d/m/Y', $ts);
    $mes   = mes_anio_es($ts);
    $e     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    // Anchos (pt). Área útil 534pt; lo que no usan los precios va a descripción/presentación.
    $w_img = 44; $w_cod = 54;
    $w_prec = array_sum(array_column($cols, 'ancho'));
    $resto = 534 - $w_img - $w_cod - $w_prec;
    $w_desc = round($resto * 0.61); $w_pres = $resto - $w_desc;
    $ncols = 4 + count($cols);

    $f = fn($archivo) => 'file://' . $base . '/assets/fonts/' . $archivo;
    $header_img = data_uri($base . '/assets/img/header-panaro.jpg');

    $css = '
    @font-face { font-family: "HerosCn"; font-weight: normal; font-style: normal; src: url("' . $f('herosc-regular.ttf') . '") format("truetype"); }
    @font-face { font-family: "HerosCn"; font-weight: bold;   font-style: normal; src: url("' . $f('herosc-bold.ttf') . '") format("truetype"); }
    @font-face { font-family: "HerosCn"; font-weight: normal; font-style: italic; src: url("' . $f('herosc-italic.ttf') . '") format("truetype"); }
    @page { margin: 52pt 39pt 36pt 22pt; }
    body { font-family: "HerosCn", sans-serif; font-size: 7.7pt; color: #111; margin: 0; }
    table.lista { width: 534pt; border-collapse: collapse;
                  border-left: 0.6pt solid #cc0000; border-right: 0.6pt solid #cc0000; }
    td, th { padding: 0; }
    .banda-logo td { background: #111111; height: 70pt; vertical-align: middle; padding-left: 4pt; }
    .banda-logo img { height: 57pt; }
    .linea-roja td { background: #cc0000; height: 3pt; font-size: 0; line-height: 0; }
    .banda-info td { background: #1a1a1a; height: 14pt; color: #b9b9b9; font-size: 6.9pt; padding: 0; vertical-align: middle; }
    .banda-anmat td { background: #e8a020; height: 17pt; font-weight: bold; font-size: 8.6pt; color: #1a1a1a; padding-left: 6pt; vertical-align: middle; }
    .check { font-family: "DejaVu Sans", sans-serif; color: #3d6f8c; font-size: 11pt; font-weight: normal; }
    .linea-negra td { background: #111111; height: 2pt; font-size: 0; line-height: 0; }
    .linea-oro td { background: #c8a800; font-size: 0; line-height: 0; }
    .banda-nuevos td { background: #cc0000; height: 28pt; color: #fff; font-weight: bold; font-size: 12.5pt;
                       text-align: center; vertical-align: middle; letter-spacing: 0.3pt; }
    .estrella { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; }
    tr.cab th { background: #990000; color: #fff; font-weight: bold; font-size: 7.8pt; height: 16pt;
                text-align: center; vertical-align: middle; border-top: 1pt solid #880000; border-bottom: 1.5pt solid #660000; }
    tr.cab th.dest { background: #cc0000; }
    tr.prod td { height: 31.8pt; vertical-align: middle; border-bottom: 0.5pt solid #dddddd; }
    tr.prod.par td { background: #f5f5f5; }
    tr.prod { page-break-inside: avoid; }
    td.img { text-align: center; }
    td.img img { height: 26pt; max-width: 34pt; }
    td.cod { font-weight: bold; text-align: center; }
    td.desc { padding-left: 4pt; padding-right: 6pt; line-height: 1.25; }
    td.pres { padding-right: 4pt; line-height: 1.25; }
    td.num { text-align: right; padding-right: 5pt; white-space: nowrap; }
    td.num.dest { font-weight: bold; color: #cc0000; }
    tr.espacio td { height: 12pt; font-size: 0; }
    tr.pie td { border-top: 2.6pt solid #cc0000; background: #111111; color: #9a9a9a; font-style: italic;
                font-size: 7pt; text-align: center; height: 10pt; vertical-align: middle; }
    .salto { page-break-before: always; }
    ';

    $anchos = [$w_img, $w_cod, $w_desc, $w_pres];
    foreach ($cols as $c) $anchos[] = $c['ancho'];
    // Dompdf suma el relleno al ancho: se descuenta para no pasarse del margen
    $relleno = [0, 0, 10, 4];
    foreach ($cols as $c) $relleno[] = 5;
    $w = fn($i) => ' style="width:' . ($anchos[$i] - $relleno[$i]) . 'pt"';

    $cabecera = function (bool $nuevos) use ($def, $cols, $ncols, $header_img, $fecha, $mes, $e, $w) {
        $info = str_replace('{fecha}', $fecha, $def['info']);
        $h  = '<thead>';
        $h .= '<tr class="banda-logo"><td colspan="' . $ncols . '">'
            . ($header_img ? '<img src="' . $header_img . '">' : '') . '</td></tr>';
        $h .= '<tr class="linea-roja"><td colspan="' . $ncols . '"></td></tr>';
        $h .= '<tr class="banda-info"><td colspan="' . $ncols . '">'
            . '<table style="width:100%;border-collapse:collapse"><tr>'
            . '<td style="text-align:left;padding:0 0 0 5pt">www.panaro.com.ar&nbsp;&nbsp;|&nbsp;&nbsp;info@panaro.com.ar&nbsp;&nbsp;|&nbsp;&nbsp;11 2406-4888</td>'
            . '<td style="text-align:right;padding:0 6pt 0 0">' . $e($info) . '</td></tr></table></td></tr>';
        $h .= '<tr class="banda-anmat"><td colspan="' . $ncols . '"><span class="check">&#10004;</span>&nbsp;&nbsp;&nbsp;PRODUCTOS REGISTRADOS Y APROBADOS POR ANMAT</td></tr>';
        $h .= '<tr class="linea-negra"><td colspan="' . $ncols . '"></td></tr>';
        if ($nuevos) {
            $h .= '<tr class="linea-oro"><td colspan="' . $ncols . '" style="height:4pt"></td></tr>';
            $h .= '<tr class="banda-nuevos"><td colspan="' . $ncols . '"><span class="estrella">&#9733;</span>&nbsp;&nbsp;NUEVOS LANZAMIENTOS&nbsp;&nbsp;&mdash;&nbsp;&nbsp;' . $e($mes) . '&nbsp;&nbsp;<span class="estrella">&#9733;</span></td></tr>';
            $h .= '<tr class="linea-oro"><td colspan="' . $ncols . '" style="height:3pt"></td></tr>';
        }
        $h .= '<tr class="cab"><th' . $w(0) . '></th><th' . $w(1) . '>CÓDIGO</th><th' . $w(2) . '>DESCRIPCIÓN</th><th' . $w(3) . '>PRESENTACIÓN</th>';
        foreach ($cols as $i => $c) $h .= '<th' . $w(4 + $i) . ' class="' . (!empty($c['destacada']) ? 'dest' : '') . '">' . $e($c['titulo']) . '</th>';
        $h .= '</tr></thead>';
        return $h;
    };

    $cuerpo = function (array $lista, string $leyenda) use ($cols, $ncols, $base, $e, $w) {
        $b = '<tbody>';
        foreach ($lista as $i => $p) {
            $img = $p['imagen'] ? data_uri($base . '/assets/img/productos/' . basename($p['imagen'])) : '';
            $b .= '<tr class="prod' . ($i % 2 === 0 ? ' par' : '') . '">'
                . '<td class="img"' . $w(0) . '>' . ($img ? '<img src="' . $img . '">' : '') . '</td>'
                . '<td class="cod"' . $w(1) . '>' . $e($p['codigo']) . '</td>'
                . '<td class="desc"' . $w(2) . '>' . $e($p['nombre']) . '</td>'
                . '<td class="pres"' . $w(3) . '>' . nl2br($e(trim((string)$p['presentacion']))) . '</td>';
            foreach ($cols as $i => $c) {
                $b .= '<td class="num' . (!empty($c['destacada']) ? ' dest' : '') . '"' . $w(4 + $i) . '>' . precio_lista((float)$p[$c['campo']]) . '</td>';
            }
            $b .= '</tr>';
        }
        $b .= '<tr class="espacio"><td colspan="' . $ncols . '"></td></tr>';
        $b .= '<tr class="pie"><td colspan="' . $ncols . '">' . $e($leyenda) . '</td></tr>';
        return $b . '</tbody>';
    };

    $regulares = array_values(array_filter($filas, fn($p) => $p['seccion'] !== 'nuevo'));
    $nuevos    = array_values(array_filter($filas, fn($p) => $p['seccion'] === 'nuevo'));

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';
    if ($regulares) {
        $html .= '<table class="lista">' . $cabecera(false) . $cuerpo($regulares, $def['leyenda']) . '</table>';
    }
    if ($nuevos) {
        $html .= '<table class="lista' . ($regulares ? ' salto' : '') . '">' . $cabecera(true)
               . $cuerpo($nuevos, str_replace('{mes}', ucwords(strtolower($mes)), $def['leyenda_nuevos'])) . '</table>';
    }
    return $html . '</body></html>';
}

// ── PDF ──────────────────────────────────────────────────────
// $base: carpeta del portal (donde están assets/ y cache/)
function pdf_lista(string $tipo, array $filas, string $base, int $ts): string {
    $cacheFont = $base . '/cache/dompdf';
    if (!is_dir($cacheFont)) @mkdir($cacheFont, 0755, true);

    $opts = new \Dompdf\Options();
    $opts->set('chroot', [$base]);
    $opts->set('fontDir', $cacheFont);
    $opts->set('fontCache', $cacheFont);
    $opts->set('tempDir', $cacheFont);
    $opts->set('isRemoteEnabled', false);
    $opts->set('isFontSubsettingEnabled', true);
    $opts->set('defaultFont', 'HerosCn');
    $opts->set('dpi', 96);

    $dompdf = new \Dompdf\Dompdf($opts);
    $dompdf->loadHtml(html_lista($tipo, $filas, $base, $ts), 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Pie de página: "Panaro Car Care® 2026" ··· "Hoja N de M"
    $canvas = $dompdf->getCanvas();
    $fm   = $dompdf->getFontMetrics();
    $font = $fm->getFont('HerosCn', 'normal') ?: $fm->getFont('Helvetica', 'normal');
    $size = 7.6;
    $color = [0.1, 0.1, 0.1];
    $canvas->page_text(23, 815, 'Panaro Car Care® ' . date('Y', $ts), $font, $size, $color);
    $total = $canvas->get_page_count();
    $ancho = $fm->getTextWidth('Hoja ' . $total . ' de ' . $total, $font, $size);
    $canvas->page_text(573.6 - $ancho, 815, 'Hoja {PAGE_NUM} de {PAGE_COUNT}', $font, $size, $color);

    return $dompdf->output();
}

// PDF con caché por día: se regenera si cambian precios/productos o cambia la fecha
function pdf_lista_cache(PDO $db, string $tipo, string $base): array {
    $ts    = time();
    $filas = filas_lista($db, $tipo);
    $clave = md5(LISTAS_PDF_VERSION . '|' . $tipo . '|' . date('Y-m-d', $ts) . '|' . json_encode($filas));
    $dir   = $base . '/cache/listas';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $archivo = $dir . '/' . $tipo . '_' . $clave . '.pdf';
    if (!is_file($archivo)) {
        foreach (glob($dir . '/' . $tipo . '_*.pdf') ?: [] as $viejo) @unlink($viejo);
        @file_put_contents($archivo, pdf_lista($tipo, $filas, $base, $ts));
    }
    $nombre = LISTAS_PDF[$tipo]['archivo'] . ' ' . date('d-m-Y', $ts) . '.pdf';
    return ['archivo' => $archivo, 'nombre' => $nombre, 'productos' => count($filas)];
}
