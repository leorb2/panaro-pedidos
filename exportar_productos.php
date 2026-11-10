<?php
// ============================================================
//  exportar_productos.php — Descarga el catálogo completo en Excel
//  (mismo formato que se importa en Admin → Productos)
// ============================================================
require 'config.php';
require 'productos_excel.php';
admin_required();

if (!cargar_vendor() || !class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    die('<div style="font-family:system-ui;padding:2rem">Falta PhpSpreadsheet en el servidor. <a href="admin.php?s=productos">Volver</a></div>');
}
@set_time_limit(60);

$ss = exportar_productos_xlsx(db());
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Panaro productos portal ' . date('d-m-Y') . '.xlsx"');
header('Cache-Control: max-age=0');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
