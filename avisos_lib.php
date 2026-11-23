<?php
// ============================================================
//  avisos_lib.php — Avisos de pedido (email, Telegram, WhatsApp)
//  Se arman desde lo guardado en la base, así se pueden reenviar.
//  Cada envío queda registrado en cache/avisos.log
// ============================================================

function log_aviso(int $pid, string $canal, bool $ok, string $detalle = ''): void {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $linea = date('Y-m-d H:i:s') . " · pedido #$pid · $canal · " . ($ok ? 'OK' : 'FALLÓ') . ($detalle !== '' ? ' · ' . $detalle : '') . "\n";
    @file_put_contents($dir . '/avisos.log', $linea, FILE_APPEND | LOCK_EX);
}

// Telegram: devuelve [ok, respuesta]. Corta mensajes largos (límite 4096).
function telegram_enviar(string $mensaje): array {
    $token = defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '';
    if (!$token || $token === 'TU_BOT_TOKEN_AQUI') return [false, 'sin token configurado'];
    if (mb_strlen($mensaje) > 4000) $mensaje = mb_substr($mensaje, 0, 3990) . "\n…";
    $url  = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = ['chat_id' => TELEGRAM_CHAT_ID, 'text' => $mensaje];
    $resp = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_POSTFIELDS => http_build_query($data),
                                CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
        $resp = curl_exec($ch);
        if ($resp === false) $resp = 'curl: ' . curl_error($ch);
    } else {
        $resp = @file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data), 'timeout' => 10, 'ignore_errors' => true]]));
        if ($resp === false) $resp = 'fopen: ' . (error_get_last()['message'] ?? 'sin respuesta');
    }
    $j = json_decode((string)$resp, true);
    return [!empty($j['ok']), is_array($j) ? ($j['description'] ?? 'ok') : mb_substr((string)$resp, 0, 200)];
}

function cargar_pedido_aviso(int $pid): ?array {
    $st = db()->prepare('SELECT p.*, u.nombre AS unom, u.email AS uemail FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id WHERE p.id = ?');
    $st->execute([$pid]);
    $ped = $st->fetch();
    if (!$ped) return null;
    $si = db()->prepare('SELECT i.*, pr.codigo AS sku FROM pedido_items i LEFT JOIN productos pr ON pr.id = i.producto_id
                         WHERE i.pedido_id = ? ORDER BY i.es_regalo ASC, i.id ASC');
    $si->execute([$pid]);
    $ped['items'] = $si->fetchAll();
    $ped['cli']   = cliente((int)$ped['usuario_id']) ?? [];
    return $ped;
}

function texto_telegram_pedido(array $ped, bool $reenvio = false): string {
    $cli   = $ped['cli'];
    $fecha = date('d/m/Y H:i', strtotime($ped['created_at']));
    $total = (float)($ped['total_con_dto'] ?: $ped['total']);
    $aj    = (float)($ped['ajuste_pct'] ?? 0);
    $t  = ($reenvio ? "🔁 REENVÍO · " : "") . "🛒 NUEVO PEDIDO MAYORISTA\n\n";
    $t .= "Pedido #{$ped['id']}\nCliente: {$ped['unom']}\n";
    $t .= "Lista: " . nombre_lista((int)$ped['lista_id']) . ($aj != 0 ? " (ajuste " . ($aj > 0 ? '+' : '') . pct_fmt($aj) . ")" : '') . "\n";
    if (!empty($cli['razon_social'])) $t .= "Razón social: {$cli['razon_social']}" . ($cli['cuit'] ? " · {$cli['cuit']}" : '') . "\n";
    $t .= "Fecha: {$fecha}\n\n";
    foreach ($ped['items'] as $it) {
        $reg = !empty($it['es_regalo']) || (float)$it['precio_unit'] == 0;
        $t .= "• " . ($it['sku'] ? "[{$it['sku']}] " : '') . "{$it['nombre']} x{$it['cantidad']}"
            . ($reg ? " [REGALO]" : " — " . precio_fmt((float)$it['subtotal']) . (!empty($it['dto_pct']) ? " (−" . pct_fmt((float)$it['dto_pct']) . ")" : '')) . "\n";
    }
    if ((float)$ped['monto_descuento'] > 0) $t .= "\nDescuento ({$ped['codigo_descuento']}): − " . precio_fmt((float)$ped['monto_descuento']) . "\n";
    $t .= "\nSubtotal: " . precio_fmt($total) . "\n";
    if ($ped['factura']) {
        $t .= ($ped['factura'] === 'sin' ? "Recargo sin factura " : "IVA ") . pct_fmt((float)$ped['impuesto_pct']) . ": " . precio_fmt((float)$ped['impuesto_monto']) . "\n";
        $t .= "TOTAL A COBRAR: " . precio_fmt((float)$ped['total_a_cobrar']) . "\n";
        $t .= "Comprobante: " . (COMPROBANTES[$ped['comprobante']] ?? '—') . "\n";
    }
    if ($ped['condicion_pago']) $t .= "Pago: " . (CONDICIONES_PAGO[$ped['condicion_pago']] ?? $ped['condicion_pago']) . "\n";
    if ($ped['entrega'])        $t .= "\n🚚 Entrega:\n{$ped['entrega']}\n";
    elseif ($ped['envio_info']) $t .= "\nEnvío: {$ped['envio_info']}\n";
    if ($ped['notas'])          $t .= "\nNotas: {$ped['notas']}\n";
    return $t;
}

function html_mail_pedido(array $ped): string {
    $filas = '';
    foreach ($ped['items'] as $it) {
        $reg = !empty($it['es_regalo']) || (float)$it['precio_unit'] == 0;
        $filas .= "<tr>
          <td style='padding:8px 12px;border-bottom:1px solid #eee'>" . escape($it['nombre']) . "</td>
          <td style='padding:8px 12px;border-bottom:1px solid #eee;text-align:center'>{$it['cantidad']}</td>
          <td style='padding:8px 12px;border-bottom:1px solid #eee;text-align:right'>" . ($reg ? 'REGALO' : precio_fmt((float)$it['precio_unit'])) . "</td>
          <td style='padding:8px 12px;border-bottom:1px solid #eee;text-align:right;font-weight:700'>" . precio_fmt((float)$it['subtotal']) . "</td></tr>";
    }
    if ((float)$ped['monto_descuento'] > 0)
        $filas .= "<tr><td colspan='3' style='padding:8px 12px;color:#22c55e'>Descuento (" . escape($ped['codigo_descuento']) . ")</td><td style='padding:8px 12px;color:#22c55e;text-align:right;font-weight:700'>− " . precio_fmt((float)$ped['monto_descuento']) . "</td></tr>";
    $total = (float)($ped['total_con_dto'] ?: $ped['total']);
    $lista = nombre_lista((int)$ped['lista_id']);
    $extra = ($ped['entrega'] ? "<p><strong>Entrega:</strong><br>" . nl2br(escape($ped['entrega'])) . "</p>"
                              : ($ped['envio_info'] ? "<p><strong>Envío:</strong> " . escape($ped['envio_info']) . "</p>" : ''))
           . ($ped['notas'] ? "<p><strong>Notas:</strong> " . escape($ped['notas']) . "</p>" : '');
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
    <body style='font-family:Arial,sans-serif;background:#f4f4f4;padding:20px'>
    <div style='max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden'>
      <div style='background:#0d0d0d;padding:20px;text-align:center'>
        <h1 style='color:#fff;margin:0'>Panaro Car Care</h1>
        <p style='color:#bbb;font-size:14px;margin:4px 0 0'>Pedido #{$ped['id']} — {$lista}</p>
      </div>
      <div style='padding:24px'>
        <p><strong>Cliente:</strong> " . escape($ped['unom']) . " — <strong>Lista:</strong> {$lista}</p>
        <p style='color:#666;font-size:13px'>" . date('d/m/Y H:i', strtotime($ped['created_at'])) . "</p>
        <table style='width:100%;border-collapse:collapse'>
          <thead><tr style='background:#f4f4f4'>
            <th style='padding:8px 12px;text-align:left;font-size:11px'>Producto</th>
            <th style='padding:8px 12px;text-align:center;font-size:11px'>Cant.</th>
            <th style='padding:8px 12px;text-align:right;font-size:11px'>Precio</th>
            <th style='padding:8px 12px;text-align:right;font-size:11px'>Subtotal</th>
          </tr></thead><tbody>{$filas}</tbody>
        </table>
        <div style='text-align:right;border-top:2px solid #0d0d0d;padding-top:12px;margin-top:8px'>
          <span style='font-size:20px;font-weight:700'>TOTAL: " . precio_fmt($total) . "</span>
          <div style='font-size:12px;color:#777;margin-top:4px'>" . ($ped['factura'] === 'sin' ? 'Sin factura' : 'Con factura') . " · + IVA o recargo según condición</div>
        </div>
        {$extra}
      </div>
    </div></body></html>";
}

function texto_whatsapp_pedido(array $ped): string {
    $total = (float)($ped['total_con_dto'] ?: $ped['total']);
    $wt  = "*Panaro Car Care — Pedido #{$ped['id']}*\n";
    $wt .= "Lista: " . nombre_lista((int)$ped['lista_id']) . " | " . date('d/m/Y H:i', strtotime($ped['created_at'])) . "\nCliente: {$ped['unom']}\n";
    foreach ($ped['items'] as $it)
        $wt .= "• {$it['nombre']} ×{$it['cantidad']}" . ((float)$it['precio_unit'] > 0 ? " — " . precio_fmt((float)$it['subtotal']) : " [REGALO]") . "\n";
    if ((float)$ped['monto_descuento'] > 0) $wt .= "Descuento ({$ped['codigo_descuento']}): − " . precio_fmt((float)$ped['monto_descuento']) . "\n";
    $wt .= "*TOTAL: " . precio_fmt($total) . "* (+ IVA o recargo según condición)\n";
    $wt .= $ped['factura'] === 'sin' ? "Sin factura" : "Con factura";
    if ($ped['notas'])      $wt .= "\nNotas: {$ped['notas']}";
    if ($ped['envio_info']) $wt .= "\nEnvío: {$ped['envio_info']}";
    return $wt;
}

// Envía los avisos de un pedido. $al_cliente: también la copia por mail al cliente.
// Devuelve ['telegram' => [ok, detalle], 'mail' => ok, 'wa' => texto]
function notificar_pedido(int $pid, bool $al_cliente = true, bool $reenvio = false): array {
    $res = ['telegram' => [false, 'pedido no encontrado'], 'mail' => false, 'wa' => ''];
    try {
        $ped = cargar_pedido_aviso($pid);
        if (!$ped) return $res;

        // Telegram primero: es el aviso que más importa
        try { $res['telegram'] = telegram_enviar(texto_telegram_pedido($ped, $reenvio)); }
        catch (Throwable $e) { $res['telegram'] = [false, $e->getMessage()]; }
        log_aviso($pid, 'telegram', $res['telegram'][0], $res['telegram'][1]);

        try {
            $html    = html_mail_pedido($ped);
            $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
                     . "From: " . MAIL_NAME . " <" . MAIL_FROM . ">\r\nReply-To: " . MAIL_TO . "\r\n";
            $subj    = '=?UTF-8?B?' . base64_encode(($reenvio ? 'Reenvío · ' : '') . "Pedido #{$pid} — {$ped['unom']}") . '?=';
            $res['mail'] = @mail(MAIL_TO, $subj, $html, $headers, '-f' . MAIL_FROM);
            log_aviso($pid, 'mail Panaro', (bool)$res['mail'], MAIL_TO);
            if ($al_cliente && $ped['uemail'] && strcasecmp($ped['uemail'], MAIL_TO) !== 0) {
                $ok = @mail($ped['uemail'], $subj, $html, $headers, '-f' . MAIL_FROM);
                log_aviso($pid, 'mail cliente', (bool)$ok, $ped['uemail']);
            }
        } catch (Throwable $e) { log_aviso($pid, 'mail', false, $e->getMessage()); }

        $res['wa'] = texto_whatsapp_pedido($ped);
    } catch (Throwable $e) {
        log_aviso($pid, 'avisos', false, $e->getMessage());
    }
    return $res;
}
