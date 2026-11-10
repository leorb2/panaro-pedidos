<?php
// ============================================================
//  productos_excel.php — Exportar / importar el catálogo en Excel
//  El Excel que se descarga es el mismo formato que se importa:
//  bajás, corregís (SKU, nombres, precios) y lo volvés a subir.
//  La columna "id" permite cambiar el SKU sin duplicar el producto.
// ============================================================

// Columnas del Excel: clave técnica => [título amigable, ancho]
const PRODUCTOS_COLUMNAS = [
    'id'                  => ['ID interno (no modificar)', 9],
    'codigo'              => ['SKU / código', 14],
    'nombre'              => ['Descripción (sale en la lista)', 46],
    'presentacion'        => ['Presentación (Alt+Enter = otra línea)', 30],
    'descripcion'         => ['Bajada / texto corto del portal', 40],
    'seccion'             => ['regular  o  nuevo', 11],
    'precio_distribuidor' => ['Precio distribuidor +IVA', 15],
    'precio_pto_venta'    => ['Precio punto de venta +IVA', 15],
    'pvp_sugerido'        => ['PVP sugerido final', 15],
    'orden'               => ['Orden en la lista', 9],
    'activo'              => ['Activo  SI / NO', 9],
    'imagen'              => ['Archivo de imagen', 18],
];

// Códigos viejos del portal (antes de la v5) → SKU de la app.
// Si alguien sube una planilla vieja, el producto se encuentra igual y no se duplica.
const ALIAS_CODIGOS = [
    'APC01-5LC' => 'APC01-5LTC',
    'BR01-5L' => 'BR01-5LT',
    'GP01-5L' => 'GP01-5LT',
    'IR01-5L' => 'IR01-5LT',
    'SW01-5L' => 'SW01-5LT',
    'HC02-5LC' => 'HC02-5LTC',
    'LC02-5L' => 'LC02-5LT',
    'EC03-5L' => 'EC03-5LT',
    'FW03-5L' => 'FW03-5LT',
    'FS03-5L' => 'FS03-5LT',
    'ID03-5LC' => 'ID03-5LTC',
    'QD03-5LC' => 'QD03-5LTC',
    'QW03-5L' => 'QW03-5LT',
    'TP03-5L' => 'TP03-5LT',
    'HC150' => 'HC02-150',
    'CC30' => 'CC02-30',
    'CC50' => 'CC02-50',
    'CC150' => 'CC02-150',
    'AC01-5L' => 'AC01-5LT',
    'GT01-5L' => 'GT01-5LT',
    'IC03-5L' => 'IC03-5LT',
    'TG03-5L' => 'TG03-5LT',
];

// Convierte lo que venga de la celda en número (o null si está vacía)
function num_excel($v): ?float {
    if ($v === null) return null;
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = trim(str_replace(['$', ' ', "\u{00A0}"], '', (string)$v));
    if ($s === '') return null;
    if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $s)) return (float)preg_replace('/[.,]/', '', $s);   // 5.220 / 5,220
    if (preg_match('/^\d+,\d{1,2}$/', $s)) return (float)str_replace(',', '.', $s);                 // 5220,50
    $s = preg_replace('/[^\d.\-]/', '', $s);
    return $s === '' ? null : (float)$s;
}

function si_no($v): ?int {
    if ($v === null) return null;
    $s = strtoupper(trim((string)$v));
    if ($s === '') return null;
    if (in_array($s, ['SI', 'SÍ', 'S', '1', 'TRUE', 'VERDADERO', 'X'], true)) return 1;
    if (in_array($s, ['NO', 'N', '0', 'FALSE', 'FALSO'], true)) return 0;
    return null;
}

// ── Exportar ─────────────────────────────────────────────────
function exportar_productos_xlsx(PDO $db): \PhpOffice\PhpSpreadsheet\Spreadsheet {
    $Fill = \PhpOffice\PhpSpreadsheet\Style\Fill::class;
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $ss->getActiveSheet();
    $ws->setTitle('Productos');
    $claves = array_keys(PRODUCTOS_COLUMNAS);
    $n = count($claves);
    $ult = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($n);
    $col = fn(int $i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);

    // Fila 1: título
    $ws->mergeCells("A1:{$ult}1");
    $ws->setCellValue('A1', 'PANARO CAR CARE — Productos del portal de pedidos · ' . date('d/m/Y H:i'));
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setARGB('FFFFFFFF');
    $ws->getStyle('A1')->getFill()->setFillType($Fill::FILL_SOLID)->getStartColor()->setARGB('FF0D0D0D');
    $ws->getRowDimension(1)->setRowHeight(26);

    // Fila 2: instrucciones
    $ws->mergeCells("A2:{$ult}2");
    $ws->setCellValue('A2', 'Editá lo que necesites y subí este mismo archivo en Admin → Productos → Importar. '
        . 'No cambies la fila 3 ni la columna "id": con el id podés cambiar el SKU sin duplicar el producto. '
        . 'Filas nuevas: dejá el id vacío. Precios sin símbolo $.');
    $ws->getStyle('A2')->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF555555');
    $ws->getStyle('A2')->getAlignment()->setWrapText(true)->setVertical('center');
    $ws->getRowDimension(2)->setRowHeight(30);

    // Fila 3: claves técnicas · Fila 4: títulos
    $colores = ['precio_distribuidor' => 'FFE30613', 'precio_pto_venta' => 'FF3B82F6', 'pvp_sugerido' => 'FF16A34A',
                'id' => 'FF777777'];
    $i = 1;
    foreach (PRODUCTOS_COLUMNAS as $k => [$titulo, $ancho]) {
        $c = $col($i++);
        $ws->setCellValue("{$c}3", $k);
        $ws->setCellValue("{$c}4", $titulo);
        $ws->getStyle("{$c}3")->getFont()->setBold(true)->setSize(9)->getColor()->setARGB('FFFFFFFF');
        $ws->getStyle("{$c}3")->getFill()->setFillType($Fill::FILL_SOLID)->getStartColor()->setARGB($colores[$k] ?? 'FF0D0D0D');
        $ws->getStyle("{$c}4")->getFont()->setItalic(true)->setSize(8)->getColor()->setARGB('FF666666');
        $ws->getStyle("{$c}4")->getFill()->setFillType($Fill::FILL_SOLID)->getStartColor()->setARGB('FFF4F4F4');
        $ws->getStyle("{$c}3:{$c}4")->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
        $ws->getColumnDimension($c)->setWidth($ancho);
    }
    $ws->getRowDimension(4)->setRowHeight(28);

    // Datos
    $rows = $db->query('SELECT * FROM productos ORDER BY (seccion = \'nuevo\') ASC, orden ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $r = 5;
    foreach ($rows as $p) {
        $i = 1;
        foreach (array_keys(PRODUCTOS_COLUMNAS) as $k) {
            $c = $col($i++);
            $v = $p[$k] ?? '';
            if ($k === 'activo') $v = $p['activo'] ? 'SI' : 'NO';
            if (in_array($k, ['id', 'orden'], true)) { $ws->setCellValueExplicit("{$c}{$r}", (int)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC); continue; }
            if (str_starts_with($k, 'precio') || $k === 'pvp_sugerido') {
                $ws->setCellValueExplicit("{$c}{$r}", (float)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                $ws->getStyle("{$c}{$r}")->getNumberFormat()->setFormatCode('#,##0');
                continue;
            }
            $ws->setCellValueExplicit("{$c}{$r}", (string)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        if (!$p['activo']) $ws->getStyle("A{$r}:{$ult}{$r}")->getFont()->getColor()->setARGB('FF999999');
        $r++;
    }
    $fin = max(5, $r - 1);
    $ws->getStyle("A5:{$ult}{$fin}")->getAlignment()->setVertical('top');
    $ws->getStyle("D5:D{$fin}")->getAlignment()->setWrapText(true);
    $ws->getStyle("A5:A{$fin}")->getFont()->getColor()->setARGB('FF888888');
    $ws->freezePane('C5');
    $ws->setAutoFilter("A4:{$ult}{$fin}");

    // Listas desplegables para sección y activo
    $cs = $col(array_search('seccion', array_keys(PRODUCTOS_COLUMNAS)) + 1);
    $ca = $col(array_search('activo', array_keys(PRODUCTOS_COLUMNAS)) + 1);
    foreach ([[$cs, '"regular,nuevo"'], [$ca, '"SI,NO"']] as [$c, $lista]) {
        $dv = $ws->getCell("{$c}5")->getDataValidation();
        $dv->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST)->setAllowBlank(true)
           ->setShowDropDown(true)->setFormula1($lista);
        $ws->setDataValidation("{$c}5:{$c}" . ($fin + 200), clone $dv);
    }

    // Hoja de instrucciones
    $ins = $ss->createSheet();
    $ins->setTitle('Instrucciones');
    $txt = [
        'CÓMO USAR ESTE EXCEL',
        '',
        '1. Corregí lo que necesites: SKU, descripción, presentación, sección, orden, precios o activo.',
        '2. Guardalo como .xlsx (no cambies el formato) y subilo en Admin → Productos → Importar.',
        '3. Elegí el modo: "Importar todo" aplica todos los cambios; "Solo precios" cambia únicamente los 3 precios.',
        '',
        'COLUMNAS',
        'id: identificador interno. NO lo modifiques. Gracias a él podés cambiar el SKU sin duplicar el producto.',
        'codigo: SKU del producto (el de dash.panaro.com.ar). Sin espacios.',
        'nombre: descripción que sale en la lista de precios y en el portal.',
        'presentacion: envase. Para los kits, Alt+Enter dentro de la celda para pasar a otra línea.',
        'descripcion: bajada corta (opcional).',
        'seccion: regular o nuevo (nuevo = sale bajo "Nuevos lanzamientos").',
        'precio_distribuidor / precio_pto_venta / pvp_sugerido: números, sin $ ni puntos de miles.',
        'orden: número para ordenar dentro de cada sección.',
        'activo: SI o NO. Un producto en NO no aparece en el portal ni en las listas.',
        'imagen: nombre del archivo dentro de assets/img/productos/.',
        '',
        'PRODUCTOS NUEVOS: agregá una fila con el id vacío. Para que se vea, necesita los tres precios y activo = SI.',
        'Las celdas que dejes vacías no borran datos: el valor que ya está en el portal se mantiene.',
    ];
    foreach ($txt as $j => $t) {
        $ins->setCellValue('A' . ($j + 1), $t);
        if ($t === strtoupper($t) && $t !== '') $ins->getStyle('A' . ($j + 1))->getFont()->setBold(true);
    }
    $ins->getColumnDimension('A')->setWidth(110);
    $ss->setActiveSheetIndex(0);
    return $ss;
}

// ── Leer filas del archivo subido ───────────────────────────
// Devuelve [filas, error]. Cada fila: array clave => valor crudo (+ '_fila', '_img').
function leer_filas_productos(string $tmp, string $ext): array {
    $filas = [];
    if ($ext === 'csv') {
        $f = fopen($tmp, 'r');
        $primera = (string)fgets($f);
        rewind($f);
        $sep = (substr_count($primera, ';') > substr_count($primera, ',')) ? ';' : ',';
        $header = null; $n = 0;
        while (($line = fgetcsv($f, 0, $sep)) !== false) {
            $n++;
            if (!$header) {
                $clean = array_map(fn($x) => strtolower(trim((string)$x)), $line);
                if (in_array('codigo', $clean, true)) $header = $clean;
                continue;
            }
            $line = array_slice(array_pad($line, count($header), ''), 0, count($header));
            $rec = array_combine($header, $line);
            $rec['_fila'] = $n;
            $filas[] = $rec;
        }
        fclose($f);
        return $header ? [$filas, ''] : [[], 'No encontré la fila de encabezados (la que dice "codigo").'];
    }

    // XLSX — valores reales de las celdas (sin el formato de miles)
    $ss   = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
    $data = $ss->getSheet(0)->toArray(null, true, false, false);
    $header_row = null; $header = [];
    foreach ($data as $ri => $row) {
        $clean = array_map(fn($x) => strtolower(trim((string)$x)), $row);
        if (in_array('codigo', $clean, true)) { $header_row = $ri; $header = $clean; break; }
    }
    if ($header_row === null) return [[], 'No encontré la fila de encabezados (la que dice "codigo").'];

    // Imágenes pegadas en la columna A (formato de la planilla vieja con fotos)
    $img_por_fila = imagenes_por_fila_xlsx($tmp);

    foreach ($data as $ri => $row) {
        if ($ri <= $header_row) continue;
        $row = array_slice(array_pad($row, count($header), null), 0, count($header));
        $rec = array_combine($header, $row);
        $rec['_fila'] = $ri + 1;
        $rec['_img']  = $img_por_fila[$ri + 1] ?? null;
        $filas[] = $rec;
    }
    return [$filas, ''];
}

// Mapa fila Excel => bytes de la foto anclada en la columna A
function imagenes_por_fila_xlsx(string $tmp): array {
    $out = [];
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) return $out;
    $rels = $zip->getFromName('xl/drawings/_rels/drawing1.xml.rels');
    $draw = $zip->getFromName('xl/drawings/drawing1.xml');
    if ($rels && $draw) {
        $rid = [];
        foreach (new SimpleXMLElement($rels) as $rel) $rid[(string)$rel['Id']] = basename((string)$rel['Target']);
        $dom = new SimpleXMLElement($draw);
        $ns = ['xdr' => 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing',
               'a'   => 'http://schemas.openxmlformats.org/drawingml/2006/main'];
        foreach ($ns as $k => $v) $dom->registerXPathNamespace($k, $v);
        foreach ($dom->xpath('//xdr:twoCellAnchor | //xdr:oneCellAnchor') as $a) {
            foreach ($ns as $k => $v) $a->registerXPathNamespace($k, $v);
            $fr = $a->xpath('xdr:from/xdr:row'); $fc = $a->xpath('xdr:from/xdr:col'); $bl = $a->xpath('.//a:blip');
            if (!$fr || !$bl || ($fc && (int)(string)$fc[0] !== 0)) continue;
            $id = (string)$bl[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['embed'];
            if (isset($rid[$id])) $out[(int)(string)$fr[0] + 1] = $zip->getFromName('xl/media/' . $rid[$id]);
        }
    }
    $zip->close();
    return $out;
}

function guardar_miniatura(string $bytes, string $destino): bool {
    $src = @imagecreatefromstring($bytes);
    if (!$src) return false;
    $w = imagesx($src); $h = imagesy($src);
    $size = min(max($w, $h), 300);
    $dst = imagecreatetruecolor($size, $size);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    $scale = min($size / $w, $size / $h);
    $nw = (int)($w * $scale); $nh = (int)($h * $scale);
    imagecopyresampled($dst, $src, (int)(($size - $nw) / 2), (int)(($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
    $ok = imagejpeg($dst, $destino, 88);
    imagedestroy($src); imagedestroy($dst);
    return $ok;
}

// ── Importar ─────────────────────────────────────────────────
// $modo: 'todo' | 'solo_precios'. Devuelve resumen.
function importar_productos(PDO $db, array $filas, string $modo, string $dir_img): array {
    $res = ['actualizados' => 0, 'nuevos' => 0, 'renombrados' => [], 'errores' => [], 'omitidos' => 0];
    if (!$filas) { $res['errores'][] = 'El archivo no tiene filas de productos.'; return $res; }

    $cols = array_keys($filas[0]);
    $req = ['codigo', 'precio_distribuidor', 'precio_pto_venta', 'pvp_sugerido'];
    $faltan = array_diff($req, $cols);
    if ($faltan) { $res['errores'][] = 'Faltan columnas: ' . implode(', ', $faltan); return $res; }
    $tiene = fn($k) => in_array($k, $cols, true);

    $porId  = $db->prepare('SELECT * FROM productos WHERE id = ? LIMIT 1');
    $porCod = $db->prepare('SELECT * FROM productos WHERE codigo = ? LIMIT 1');

    $db->beginTransaction();
    try {
        foreach ($filas as $f) {
            $cod = trim((string)($f['codigo'] ?? ''));
            // fila vacía o la fila de títulos amigables ("SKU / código")
            if ($cod === '' || preg_match('/\s/', $cod)) { $res['omitidos']++; continue; }
            $fila = $f['_fila'] ?? '?';

            $actual = null;
            $id = $tiene('id') ? (int)num_excel($f['id']) : 0;
            if ($id > 0) { $porId->execute([$id]); $actual = $porId->fetch(PDO::FETCH_ASSOC) ?: null; }
            if (!$actual) { $porCod->execute([$cod]); $actual = $porCod->fetch(PDO::FETCH_ASSOC) ?: null; }
            if (!$actual && isset(ALIAS_CODIGOS[strtoupper($cod)])) {
                $cod = ALIAS_CODIGOS[strtoupper($cod)];
                $porCod->execute([$cod]); $actual = $porCod->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $p1 = num_excel($f['precio_distribuidor'] ?? null);
            $p3 = num_excel($f['precio_pto_venta'] ?? null);
            $pv = num_excel($f['pvp_sugerido'] ?? null);

            if ($modo === 'solo_precios') {
                if (!$actual) { $res['errores'][] = "Fila $fila ($cod): no existe en el portal (en modo «solo precios» no se crean productos)."; continue; }
                $set = []; $val = [];
                foreach (['precio_distribuidor' => $p1, 'precio_pto_venta' => $p3, 'pvp_sugerido' => $pv] as $c => $v) {
                    if ($v !== null) { $set[] = "$c = ?"; $val[] = $v; }
                }
                if ($set) { $val[] = $actual['id']; $db->prepare('UPDATE productos SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($val); }
                $res['actualizados']++;
                continue;
            }

            // Modo "todo": solo se escriben las columnas presentes y con valor
            $d = [];
            if ($tiene('nombre')       && trim((string)$f['nombre']) !== '')       $d['nombre']       = trim((string)$f['nombre']);
            if ($tiene('presentacion') && trim((string)$f['presentacion']) !== '') $d['presentacion'] = str_replace("\r", '', trim((string)$f['presentacion']));
            if ($tiene('descripcion')  && $f['descripcion'] !== null)              $d['descripcion']  = trim((string)$f['descripcion']);
            if ($tiene('seccion')) {
                $s = strtolower(trim((string)$f['seccion']));
                if (in_array($s, ['nuevo', 'nuevos'], true)) $d['seccion'] = 'nuevo';
                elseif ($s === 'regular') $d['seccion'] = 'regular';
            }
            if ($tiene('orden') && num_excel($f['orden']) !== null) $d['orden'] = (int)num_excel($f['orden']);
            if ($tiene('activo') && si_no($f['activo']) !== null)   $d['activo'] = si_no($f['activo']);
            if ($p1 !== null) $d['precio_distribuidor'] = $p1;
            if ($p3 !== null) $d['precio_pto_venta']    = $p3;
            if ($pv !== null) $d['pvp_sugerido']        = $pv;

            // Imagen: pegada en el Excel, o nombre de archivo en la columna
            if (!empty($f['_img'])) {
                $nombre = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $cod)) . '.jpg';
                if (guardar_miniatura($f['_img'], rtrim($dir_img, '/') . '/' . $nombre)) $d['imagen'] = $nombre;
            } elseif ($tiene('imagen') && trim((string)$f['imagen']) !== '') {
                $d['imagen'] = basename(trim((string)$f['imagen']));
            }

            if ($actual) {
                if ($cod !== $actual['codigo']) {
                    $porCod->execute([$cod]);
                    $otro = $porCod->fetch(PDO::FETCH_ASSOC);
                    if ($otro && (int)$otro['id'] !== (int)$actual['id']) {
                        $res['errores'][] = "Fila $fila: el código $cod ya lo usa otro producto (id {$otro['id']}). No se cambió.";
                        continue;
                    }
                    $d['codigo'] = $cod;
                    $res['renombrados'][] = $actual['codigo'] . ' → ' . $cod;
                }
                if ($d) {
                    $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($d)));
                    $db->prepare("UPDATE productos SET $set WHERE id = ?")->execute([...array_values($d), $actual['id']]);
                }
                $res['actualizados']++;
            } else {
                if (empty($d['nombre'])) { $res['errores'][] = "Fila $fila ($cod): producto nuevo sin descripción. No se creó."; continue; }
                $d['codigo'] = $cod;
                $d += ['precio_distribuidor' => 0, 'precio_pto_venta' => 0, 'pvp_sugerido' => 0, 'precio_pto_venta_original' => 0];
                if (!isset($d['activo'])) $d['activo'] = ($d['precio_distribuidor'] > 0 && $d['precio_pto_venta'] > 0 && $d['pvp_sugerido'] > 0) ? 1 : 0;
                $c = implode(', ', array_keys($d));
                $q = implode(', ', array_fill(0, count($d), '?'));
                $db->prepare("INSERT INTO productos ($c) VALUES ($q)")->execute(array_values($d));
                $res['nuevos']++;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        $res['errores'][] = 'Error de base de datos, no se guardó nada: ' . $e->getMessage();
        $res['actualizados'] = $res['nuevos'] = 0; $res['renombrados'] = [];
    }
    return $res;
}
