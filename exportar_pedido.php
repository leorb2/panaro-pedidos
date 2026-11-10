<?php
// ============================================================
//  exportar_pedido.php — Exporta el detalle de un pedido
//  ?id=24&f=pdf        → descarga/muestra el PDF
//  ?id=24&f=excel      → descarga el XLSX
//  ?id=24&f=pdf_link   → genera el PDF público y devuelve la URL (JSON)
// ============================================================
require 'config.php';
admin_required();

$pid    = (int)($_GET['id'] ?? 0);
$format = $_GET['f'] ?? 'pdf';

if (!$pid) { die('Pedido no especificado.'); }

// ── Cargar pedido ────────────────────────────────────────────
$st = db()->prepare(
    'SELECT p.*, u.nombre AS unom, u.email AS uemail, u.whatsapp AS uwa, u.direccion AS udir
     FROM pedidos p JOIN usuarios u ON p.usuario_id = u.id
     WHERE p.id = ? LIMIT 1'
);
$st->execute([$pid]);
$ped = $st->fetch();
if (!$ped) { die('Pedido no encontrado.'); }

$si = db()->prepare('SELECT * FROM pedido_items WHERE pedido_id=? ORDER BY es_regalo ASC, id ASC');
$si->execute([$pid]);
$items = $si->fetchAll();

$lista_nombre = nombre_lista((int)$ped['lista_id']);
$lista_color  = color_lista((int)$ped['lista_id']);
$total_bruto  = (float)$ped['total'];
$monto_dto    = (float)($ped['monto_descuento'] ?? 0);
$total_final  = (float)($ped['total_con_dto'] ?: $ped['total']);
$fecha        = date('d/m/Y H:i', strtotime($ped['created_at']));

$estados = ['pendiente'=>'Pendiente','en_preparacion'=>'En preparación',
            'enviado'=>'Enviado','cancelado'=>'Cancelado'];
$estado_txt = $estados[$ped['estado']] ?? $ped['estado'];

// ============================================================
//  HTML del PDF
// ============================================================
function html_pedido(array $ped, array $items, string $lista_nombre, string $fecha,
                     float $total_bruto, float $monto_dto, float $total_final, string $estado_txt): string {

    $logo_path = __DIR__ . '/assets/img/logo.png';
    $logo_b64  = file_exists($logo_path)
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path))
        : '';

    $filas = '';
    foreach ($items as $it) {
        $es_reg = !empty($it['es_regalo']) || (float)$it['precio_unit'] == 0;
        $pu  = $es_reg ? '<span style="color:#16a34a;font-weight:bold">REGALO</span>' : precio_fmt((float)$it['precio_unit']);
        $sub = $es_reg ? '<span style="color:#16a34a;font-weight:bold">$0</span>'     : precio_fmt((float)$it['subtotal']);
        $bg  = $es_reg ? 'background:#f0fdf4;' : '';
        $filas .= '<tr style="'.$bg.'">
            <td style="padding:7px 9px;border-bottom:1px solid #eee;font-size:10px">'.htmlspecialchars($it['nombre']).'</td>
            <td style="padding:7px 9px;border-bottom:1px solid #eee;text-align:center;font-size:10px">'.(int)$it['cantidad'].'</td>
            <td style="padding:7px 9px;border-bottom:1px solid #eee;text-align:right;font-size:10px">'.$pu.'</td>
            <td style="padding:7px 9px;border-bottom:1px solid #eee;text-align:right;font-size:10px;font-weight:bold">'.$sub.'</td>
        </tr>';
    }

    $fila_dto = '';
    if ($monto_dto > 0) {
        $fila_dto = '<tr>
            <td colspan="3" style="padding:7px 9px;text-align:right;color:#16a34a;font-size:10px">
              Descuento ('.htmlspecialchars($ped['codigo_descuento'] ?? '').')</td>
            <td style="padding:7px 9px;text-align:right;color:#16a34a;font-weight:bold;font-size:10px">
              &minus; '.precio_fmt($monto_dto).'</td>
        </tr>
        <tr>
            <td colspan="3" style="padding:5px 9px;text-align:right;color:#777;font-size:9px">Subtotal sin descuento</td>
            <td style="padding:5px 9px;text-align:right;color:#777;font-size:9px">'.precio_fmt($total_bruto).'</td>
        </tr>';
    }

    $extra = '';
    if (!empty($ped['envio_info'])) {
        $extra .= '<p style="margin:3px 0;font-size:10px"><strong>Envío:</strong> '.htmlspecialchars($ped['envio_info']).'</p>';
    }
    if (!empty($ped['notas'])) {
        $extra .= '<p style="margin:3px 0;font-size:10px"><strong>Notas:</strong> '.nl2br(htmlspecialchars($ped['notas'])).'</p>';
    }

    $editado = '';
    if (!empty($ped['veces_editado']) && $ped['veces_editado'] > 0) {
        $editado = '<p style="margin:3px 0;font-size:9px;color:#b45309">
            Pedido modificado el '.date('d/m/Y H:i', strtotime($ped['editado_en'])).'</p>';
    }

    $logo_html = $logo_b64
        ? '<img src="'.$logo_b64.'" style="height:34px">'
        : '<span style="color:#fff;font-size:20px;font-weight:bold;letter-spacing:2px">PANARO</span>';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>
      @page { margin: 18mm 14mm; }
      body { font-family: DejaVu Sans, sans-serif; color:#111; font-size:11px; }
      table { width:100%; border-collapse:collapse; }
      th { background:#f2f2f2; padding:7px 9px; text-align:left; font-size:9px;
           text-transform:uppercase; letter-spacing:.5px; color:#555; }
    </style></head><body>

    <table style="margin-bottom:14px">
      <tr>
        <td style="background:#0d0d0d;padding:12px 14px;width:55%">'.$logo_html.'</td>
        <td style="background:#0d0d0d;padding:12px 14px;text-align:right;color:#fff">
          <div style="font-size:15px;font-weight:bold">PEDIDO #'.(int)$ped['id'].'</div>
          <div style="font-size:9px;color:#bbb;margin-top:2px">'.$fecha.'</div>
        </td>
      </tr>
    </table>

    <table style="margin-bottom:12px">
      <tr>
        <td style="width:60%;vertical-align:top;font-size:10px">
          <div style="font-size:8px;text-transform:uppercase;color:#888;letter-spacing:.5px">Cliente</div>
          <div style="font-size:13px;font-weight:bold;margin-bottom:2px">'.htmlspecialchars($ped['unom']).'</div>
          <div style="color:#666">'.htmlspecialchars($ped['uemail']).'</div>
        </td>
        <td style="width:40%;vertical-align:top;text-align:right;font-size:10px">
          <div style="font-size:8px;text-transform:uppercase;color:#888;letter-spacing:.5px">Lista de precios</div>
          <div style="font-weight:bold;margin-bottom:4px">'.$lista_nombre.'</div>
          <div style="font-size:8px;text-transform:uppercase;color:#888;letter-spacing:.5px">Estado</div>
          <div style="font-weight:bold">'.$estado_txt.'</div>
        </td>
      </tr>
    </table>

    <table>
      <thead><tr>
        <th>Producto</th>
        <th style="text-align:center;width:50px">Cant.</th>
        <th style="text-align:right;width:80px">P. Unit.</th>
        <th style="text-align:right;width:90px">Subtotal</th>
      </tr></thead>
      <tbody>'.$filas.$fila_dto.'</tbody>
    </table>

    <table style="margin-top:10px">
      <tr>
        <td style="text-align:right;border-top:2px solid #0d0d0d;padding-top:8px">
          <span style="font-size:12px;color:#666">TOTAL&nbsp;&nbsp;</span>
          <span style="font-size:19px;font-weight:bold">'.precio_fmt($total_final).'</span>
        </td>
      </tr>
    </table>

    <div style="margin-top:14px">'.$extra.$editado.'</div>

    <div style="margin-top:22px;padding-top:8px;border-top:1px solid #ddd;
                text-align:center;color:#999;font-size:8px">
      Panaro Car Care &nbsp;•&nbsp; www.panaro.com.ar &nbsp;•&nbsp; pedidos@panaro.com.ar<br>
      Precios en pesos argentinos. Documento generado el '.date('d/m/Y H:i').'.
    </div>
    </body></html>';
}

// ============================================================
//  EXPORTAR PDF
// ============================================================
if ($format === 'pdf' || $format === 'pdf_link') {

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        die('<div style="font-family:system-ui;padding:2rem;max-width:600px;margin:0 auto">
             <h2 style="color:#c00">Falta instalar Dompdf</h2>
             <p>Conectate por SSH a tu servidor y ejecutá:</p>
             <pre style="background:#0d0d0d;color:#e2e8f0;padding:1rem;border-radius:6px">cd '.__DIR__.'
php composer.phar require dompdf/dompdf</pre>
             <p><a href="admin.php?s=pedidos">← Volver al admin</a></p></div>');
    }
    require_once $autoload;

    if (!class_exists('\Dompdf\Dompdf')) {
        die('<div style="font-family:system-ui;padding:2rem">
             Dompdf no está instalado. Ejecutá por SSH: <code>php composer.phar require dompdf/dompdf</code>
             <br><a href="admin.php?s=pedidos">← Volver</a></div>');
    }

    $html = html_pedido($ped, $items, $lista_nombre, $fecha, $total_bruto, $monto_dto, $total_final, $estado_txt);

    $opts = new \Dompdf\Options();
    $opts->set('isRemoteEnabled', true);
    $opts->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($opts);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdf_data = $dompdf->output();

    // ── Guardar copia pública (para el link de WhatsApp) ─────
    if ($format === 'pdf_link') {
        $token = $ped['pdf_token'];
        if (!$token) {
            $token = bin2hex(random_bytes(6));
            db()->prepare('UPDATE pedidos SET pdf_token=? WHERE id=?')->execute([$token, $pid]);
        }
        $dir = __DIR__ . '/pdf/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        // Limpiar PDFs viejos de este pedido
        foreach (glob($dir.'pedido_'.$pid.'_*.pdf') as $viejo) @unlink($viejo);
        $nombre = 'pedido_'.$pid.'_'.$token.'.pdf';
        file_put_contents($dir.$nombre, $pdf_data);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'url' => rtrim(SITE_URL,'/').'/pdf/'.$nombre]);
        exit;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Panaro_Pedido_'.$pid.'.pdf"');
    echo $pdf_data;
    exit;
}

// ============================================================
//  EXPORTAR EXCEL
// ============================================================
if ($format === 'excel') {

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) { die('PhpSpreadsheet no está instalado.'); }
    require_once $autoload;

    $ss    = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('Pedido '.$pid);

    // Encabezado
    $sheet->mergeCells('A1:D1');
    $sheet->setCellValue('A1', 'PANARO CAR CARE — Pedido #'.$pid);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)
          ->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A1')->getFill()
          ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
          ->getStartColor()->setARGB('FF0D0D0D');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal('center')->setVertical('center');
    $sheet->getRowDimension(1)->setRowHeight(26);

    // Datos del cliente
    $meta = [
        ['Cliente',        $ped['unom']],
        ['Email',          $ped['uemail']],
        ['Lista',          $lista_nombre],
        ['Fecha',          $fecha],
        ['Estado',         $estado_txt],
        ['Envío',          $ped['envio_info'] ?: '—'],
        ['Notas',          $ped['notas'] ?: '—'],
    ];
    $r = 3;
    foreach ($meta as [$k, $v]) {
        $sheet->setCellValue('A'.$r, $k);
        $sheet->getStyle('A'.$r)->getFont()->setBold(true)->setSize(9);
        $sheet->mergeCells('B'.$r.':D'.$r);
        $sheet->setCellValue('B'.$r, $v);
        $sheet->getStyle('B'.$r)->getFont()->setSize(9);
        $r++;
    }

    // Cabecera de tabla
    $r++;
    $head_row = $r;
    foreach (['Producto','Cantidad','Precio Unit.','Subtotal'] as $i => $h) {
        $col = chr(65 + $i);
        $sheet->setCellValue($col.$r, $h);
        $sheet->getStyle($col.$r)->getFont()->setBold(true)->setSize(9)
              ->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($col.$r)->getFill()
              ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setARGB('FFE30613');
    }
    $r++;

    // Items
    foreach ($items as $it) {
        $es_reg = !empty($it['es_regalo']) || (float)$it['precio_unit'] == 0;
        $sheet->setCellValue('A'.$r, $it['nombre']);
        $sheet->setCellValue('B'.$r, (int)$it['cantidad']);
        $sheet->setCellValue('C'.$r, $es_reg ? 'REGALO' : (float)$it['precio_unit']);
        $sheet->setCellValue('D'.$r, (float)$it['subtotal']);
        if (!$es_reg) {
            $sheet->getStyle('C'.$r.':D'.$r)->getNumberFormat()->setFormatCode('"$"#,##0');
        } else {
            $sheet->getStyle('A'.$r.':D'.$r)->getFont()->getColor()->setARGB('FF16A34A');
        }
        $sheet->getStyle('A'.$r.':D'.$r)->getFont()->setSize(9);
        $sheet->getStyle('B'.$r)->getAlignment()->setHorizontal('center');
        $r++;
    }

    // Totales
    if ($monto_dto > 0) {
        $sheet->mergeCells('A'.$r.':C'.$r);
        $sheet->setCellValue('A'.$r, 'Subtotal sin descuento');
        $sheet->setCellValue('D'.$r, $total_bruto);
        $sheet->getStyle('D'.$r)->getNumberFormat()->setFormatCode('"$"#,##0');
        $sheet->getStyle('A'.$r.':D'.$r)->getAlignment()->setHorizontal('right');
        $sheet->getStyle('A'.$r.':D'.$r)->getFont()->setSize(9);
        $r++;

        $sheet->mergeCells('A'.$r.':C'.$r);
        $sheet->setCellValue('A'.$r, 'Descuento ('.$ped['codigo_descuento'].')');
        $sheet->setCellValue('D'.$r, -$monto_dto);
        $sheet->getStyle('D'.$r)->getNumberFormat()->setFormatCode('"$"#,##0');
        $sheet->getStyle('A'.$r.':D'.$r)->getFont()->setSize(9)->getColor()->setARGB('FF16A34A');
        $sheet->getStyle('A'.$r.':D'.$r)->getAlignment()->setHorizontal('right');
        $r++;
    }

    $sheet->mergeCells('A'.$r.':C'.$r);
    $sheet->setCellValue('A'.$r, 'TOTAL');
    $sheet->setCellValue('D'.$r, $total_final);
    $sheet->getStyle('A'.$r.':D'.$r)->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle('D'.$r)->getNumberFormat()->setFormatCode('"$"#,##0');
    $sheet->getStyle('A'.$r.':D'.$r)->getAlignment()->setHorizontal('right');
    $sheet->getStyle('A'.$r.':D'.$r)->getBorders()->getTop()
          ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM);

    // Anchos
    $sheet->getColumnDimension('A')->setWidth(48);
    $sheet->getColumnDimension('B')->setWidth(11);
    $sheet->getColumnDimension('C')->setWidth(15);
    $sheet->getColumnDimension('D')->setWidth(16);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Panaro_Pedido_'.$pid.'.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
    $writer->save('php://output');
    exit;
}

die('Formato no válido.');
