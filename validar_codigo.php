<?php
// validar_codigo.php — AJAX endpoint
require 'config.php';
auth_required();
header('Content-Type: application/json');

$u       = current_user();
$cod     = strtoupper(trim($_GET['cod']  ?? ''));
$total   = (float)($_GET['total'] ?? 0);
$lista_id= (int)($u['lista_id'] ?? 3);

$result = validar_codigo($cod, $lista_id, $u['id'], $total);
echo json_encode($result);
?>
