<?php
// ============================================================
//  clientes_lib.php — Ficha del cliente, libreta de direcciones,
//  condiciones comerciales y precio final de cada cliente.
// ============================================================

const CONDICIONES_IVA = [
    'ri'               => 'Responsable Inscripto',
    'monotributo'      => 'Monotributista',
    'no_alcanzado'     => 'No alcanzado',
    'consumidor_final' => 'Consumidor Final',
];
const COMPROBANTES = ['A' => 'Factura A', 'B' => 'Factura B', 'sin' => 'Sin factura'];
const RECARGOS_SIN_FACTURA = ['0' => '0 %', '10.5' => '10,5 %', '21' => '21 %'];
const IVA_PCT = 21.0;
const CONDICIONES_PAGO = [
    'anticipado' => 'Pago anticipado',
    'contado'    => 'Contado contra entrega',
    'cc15'       => 'Cuenta corriente 15 días',
    'cc30'       => 'Cuenta corriente 30 días',
    'cc45'       => 'Cuenta corriente 45 días',
    'cc60'       => 'Cuenta corriente 60 días',
];
const MODALIDADES = [
    'domicilio' => 'Envío a domicilio',
    'sucursal'  => 'Envío a sucursal del transporte',
    'retiro'    => 'Retiro por fábrica / depósito',
];
const DIAS_SEMANA = ['lun' => 'Lun', 'mar' => 'Mar', 'mie' => 'Mié', 'jue' => 'Jue', 'vie' => 'Vie', 'sab' => 'Sáb'];
const PROVINCIAS = ['CABA', 'Buenos Aires', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba', 'Corrientes', 'Entre Ríos',
    'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza', 'Misiones', 'Neuquén', 'Río Negro', 'Salta', 'San Juan',
    'San Luis', 'Santa Cruz', 'Santa Fe', 'Santiago del Estero', 'Tierra del Fuego', 'Tucumán'];

// Campos de la ficha que edita el cliente (los fiscales se bloquean al verificarlos)
const FICHA_CAMPOS_FISCALES = ['razon_social', 'cuit', 'condicion_iva'];
const FICHA_CAMPOS = ['razon_social', 'nombre_fantasia', 'cuit', 'condicion_iva', 'domicilio_fiscal', 'email_facturas',
    'contacto_nombre', 'contacto_tel', 'factura_preferida',
    'aut1_nombre', 'aut1_dni', 'aut1_tel', 'aut2_nombre', 'aut2_dni', 'aut2_tel'];
const FICHA_ETIQUETAS = [
    'razon_social' => 'Razón social', 'nombre_fantasia' => 'Nombre de fantasía', 'cuit' => 'CUIT / DNI',
    'condicion_iva' => 'Condición frente al IVA', 'domicilio_fiscal' => 'Domicilio fiscal',
    'email_facturas' => 'Email para facturas', 'contacto_nombre' => 'Responsable de compras',
    'contacto_tel' => 'Teléfono de contacto', 'factura_preferida' => 'Factura',
    'aut1_nombre' => 'Autorizado 1', 'aut1_dni' => 'DNI autorizado 1', 'aut1_tel' => 'Tel. autorizado 1',
    'aut2_nombre' => 'Autorizado 2', 'aut2_dni' => 'DNI autorizado 2', 'aut2_tel' => 'Tel. autorizado 2',
];

// ── Lectura ─────────────────────────────────────────────────
function cliente(int $uid): ?array {
    $st = db()->prepare('SELECT * FROM usuarios WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}

function ficha_completa(array $c): bool {
    return trim((string)($c['razon_social'] ?? '')) !== ''
        && trim((string)($c['cuit'] ?? '')) !== ''
        && isset(CONDICIONES_IVA[$c['condicion_iva'] ?? ''])
        && trim((string)($c['contacto_nombre'] ?? '') . ($c['contacto_tel'] ?? '')) !== ''
        && count(direcciones((int)$c['id'])) > 0;
}

function solo_digitos(?string $s): string { return preg_replace('/\D+/', '', (string)$s); }

// CUIT: 11 dígitos con dígito verificador; DNI: 7 u 8 dígitos
function cuit_valido(string $v): bool {
    $d = solo_digitos($v);
    if (strlen($d) === 7 || strlen($d) === 8) return true;
    if (strlen($d) !== 11) return false;
    $pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $suma = 0;
    for ($i = 0; $i < 10; $i++) $suma += (int)$d[$i] * $pesos[$i];
    $ver = 11 - ($suma % 11);
    $ver = $ver === 11 ? 0 : ($ver === 10 ? 9 : $ver);
    return $ver === (int)$d[10];
}
function cuit_fmt(?string $v): string {
    $d = solo_digitos($v);
    return strlen($d) === 11 ? substr($d, 0, 2) . '-' . substr($d, 2, 8) . '-' . substr($d, 10) : (string)$v;
}

// ── Condiciones comerciales ─────────────────────────────────
// Ajuste de lista en % (negativo = descuento, positivo = recargo). Oculto al cliente.
function ajuste_cliente(array $c): float {
    if ($c['ajuste_lista'] !== null && $c['ajuste_lista'] !== '') return (float)$c['ajuste_lista'];
    return (float)get_config('ajuste_lista_' . (int)$c['lista_id'], '0');
}
function comprobante_auto(array $c): string {
    return ($c['condicion_iva'] ?? '') === 'ri' ? 'A' : 'B';
}
// Comprobante que corresponde a un pedido con o sin factura
function comprobante_pedido(array $c, string $factura): string {
    if ($factura === 'sin') return 'sin';
    $adm = $c['comprobante'] ?? null;
    return in_array($adm, ['A', 'B'], true) ? $adm : comprobante_auto($c);
}
function recargo_sin_factura(array $c): float {
    if ($c['recargo_sin_factura'] !== null && $c['recargo_sin_factura'] !== '') return (float)$c['recargo_sin_factura'];
    return (float)get_config('recargo_sin_factura', '0');
}
// IVA (con factura) o recargo (sin factura) del pedido
function impuesto_pedido(array $c, string $factura): float {
    return $factura === 'sin' ? recargo_sin_factura($c) : IVA_PCT;
}
function pct_fmt(float $p): string {
    return rtrim(rtrim(number_format($p, 2, ',', '.'), '0'), ',') . ' %';
}

// Descuentos por producto: [producto_id => %]
function descuentos_producto(int $uid): array {
    $st = db()->prepare('SELECT producto_id, porcentaje FROM usuario_descuentos WHERE usuario_id = ?');
    $st->execute([$uid]);
    return array_map('floatval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

// Precio final de un producto para un cliente.
// lista = precio de la lista con el ajuste oculto ya aplicado (lo que el cliente ve como "su precio")
// final = lista con el descuento por producto
function precio_cliente(float $base, float $ajuste, float $dto): array {
    $lista = round($base * (1 + $ajuste / 100));
    $final = $dto > 0 ? round($lista * (1 - $dto / 100)) : $lista;
    return ['lista' => (float)$lista, 'dto' => $dto, 'final' => (float)$final];
}

// Precios de todos los productos para un cliente: [producto_id => precio_cliente()]
function precios_cliente(array $c, array $productos): array {
    $campo  = campo_precio((int)$c['lista_id']);
    $ajuste = ajuste_cliente($c);
    $dtos   = descuentos_producto((int)$c['id']);
    $out = [];
    foreach ($productos as $p) {
        $base = (float)($p[$campo] ?? 0);
        $out[(int)$p['id']] = precio_cliente($base, $ajuste, $dtos[(int)$p['id']] ?? 0);
    }
    return $out;
}

// ── Direcciones ─────────────────────────────────────────────
function direcciones(int $uid): array {
    $st = db()->prepare('SELECT * FROM direcciones WHERE usuario_id = ? ORDER BY predeterminada DESC, alias ASC, id ASC');
    $st->execute([$uid]);
    return $st->fetchAll();
}
function direccion(int $uid, int $id): ?array {
    $st = db()->prepare('SELECT * FROM direcciones WHERE id = ? AND usuario_id = ? LIMIT 1');
    $st->execute([$id, $uid]);
    return $st->fetch() ?: null;
}

function horario_txt(array $d): string {
    $dias = array_filter(explode(',', (string)$d['dias']));
    $txt  = '';
    if ($dias) {
        $orden = array_keys(DIAS_SEMANA);
        $idx = array_map(fn($x) => array_search($x, $orden, true), $dias);
        sort($idx);
        $consecutivos = count($idx) > 2 && $idx === range($idx[0], end($idx));
        $txt = $consecutivos
            ? DIAS_SEMANA[$orden[$idx[0]]] . ' a ' . DIAS_SEMANA[$orden[end($idx)]]
            : implode(', ', array_map(fn($i) => DIAS_SEMANA[$orden[$i]], $idx));
    }
    if ($d['hora_desde'] || $d['hora_hasta']) $txt .= ($txt ? ' ' : '') . trim(($d['hora_desde'] ?: '?') . ' a ' . ($d['hora_hasta'] ?: '?')) . ' h';
    return $txt;
}

function domicilio_txt(array $d): string {
    $l1 = trim(($d['calle'] ?? '') . ($d['piso_depto'] ? ' ' . $d['piso_depto'] : ''));
    $loc = trim((string)($d['localidad'] ?? '')); $prov = trim((string)($d['provincia'] ?? ''));
    $l2 = implode(', ', array_filter([$loc, strcasecmp($loc, $prov) === 0 ? '' : $prov]));
    if ($d['cp']) $l2 .= ($l2 ? ' ' : '') . '(CP ' . $d['cp'] . ')';
    return implode(', ', array_filter([$l1, $l2]));
}

// Una línea (para listados, historial y el campo envio_info de siempre)
function direccion_linea(array $d): string {
    $m = $d['modalidad'] ?? 'domicilio';
    if ($m === 'retiro') return 'Retira por fábrica / depósito';
    $txt = domicilio_txt($d);
    if ($m === 'sucursal') $txt = 'Sucursal ' . ($d['transporte'] ?: 'transporte') . ($txt ? ': ' . $txt : '');
    $h = horario_txt($d);
    return $txt . ($h && $m === 'domicilio' ? ' — ' . $h : '');
}

// Detalle completo, varias líneas (se guarda en el pedido como "foto")
function direccion_detalle(array $d, array $c): string {
    $m = $d['modalidad'] ?? 'domicilio';
    $l = [(MODALIDADES[$m] ?? $m) . ($d['alias'] ? ' (' . $d['alias'] . ')' : '')];
    if ($m !== 'retiro') {
        if ($m === 'sucursal') {
            $l[] = 'Transporte: ' . ($d['transporte'] ?: '—') . ($d['transporte_tel'] ? ' · Tel. ' . $d['transporte_tel'] : '');
            if ($d['transporte_origen']) $l[] = 'Despachar en: ' . $d['transporte_origen'];
            $l[] = 'Sucursal destino: ' . domicilio_txt($d);
        } else {
            $l[] = domicilio_txt($d);
            if ($d['transporte']) $l[] = 'Transporte: ' . $d['transporte'] . ($d['transporte_tel'] ? ' · Tel. ' . $d['transporte_tel'] : '')
                . ($d['transporte_origen'] ? ' · Despachar en: ' . $d['transporte_origen'] : '');
        }
        if ($h = horario_txt($d)) $l[] = 'Recepción: ' . $h;
    }
    if ($m === 'retiro' || $m === 'sucursal') {
        foreach (autorizados($c) as $a) $l[] = 'Autorizado: ' . $a;
    }
    if (trim((string)$d['aclaraciones']) !== '') $l[] = 'Aclaraciones: ' . trim($d['aclaraciones']);
    return implode("\n", array_filter($l, fn($x) => trim($x) !== ''));
}

function autorizados(array $c): array {
    $out = [];
    foreach ([1, 2] as $n) {
        $nom = trim((string)($c["aut{$n}_nombre"] ?? ''));
        if ($nom === '') continue;
        $out[] = $nom . ($c["aut{$n}_dni"] ? ' · DNI ' . $c["aut{$n}_dni"] : '') . ($c["aut{$n}_tel"] ? ' · Tel. ' . $c["aut{$n}_tel"] : '');
    }
    return $out;
}

// Guarda una dirección desde un POST. Devuelve [id, error]
function guardar_direccion_post(int $uid, array $in): array {
    $id  = (int)($in['dir_id'] ?? 0);
    $mod = $in['modalidad'] ?? 'domicilio';
    if (!isset(MODALIDADES[$mod])) $mod = 'domicilio';
    $dias = array_values(array_intersect(array_keys(DIAS_SEMANA), (array)($in['dias'] ?? [])));
    $hora = fn($h) => preg_match('/^\d{1,2}:\d{2}$/', (string)$h) ? str_pad($h, 5, '0', STR_PAD_LEFT) : null;
    $v = [
        'alias'             => trim($in['alias'] ?? '') ?: ($mod === 'retiro' ? 'Retiro en fábrica' : 'Dirección'),
        'modalidad'         => $mod,
        'calle'             => trim($in['calle'] ?? ''),
        'piso_depto'        => trim($in['piso_depto'] ?? ''),
        'localidad'         => trim($in['localidad'] ?? ''),
        'provincia'         => in_array($in['provincia'] ?? '', PROVINCIAS, true) ? $in['provincia'] : '',
        'cp'                => trim($in['cp'] ?? ''),
        'dias'              => implode(',', $dias),
        'hora_desde'        => $hora($in['hora_desde'] ?? ''),
        'hora_hasta'        => $hora($in['hora_hasta'] ?? ''),
        'transporte'        => trim($in['transporte'] ?? ''),
        'transporte_tel'    => trim($in['transporte_tel'] ?? ''),
        'transporte_origen' => trim($in['transporte_origen'] ?? ''),
        'aclaraciones'      => trim($in['aclaraciones'] ?? ''),
    ];
    if ($mod !== 'retiro') {
        if ($v['calle'] === '' || $v['localidad'] === '' || $v['provincia'] === '' || $v['cp'] === '')
            return [0, $mod === 'sucursal'
                ? 'Completá la dirección de la sucursal donde retirás: calle, localidad, provincia y código postal.'
                : 'Completá calle y número, localidad, provincia y código postal.'];
        if ($mod === 'sucursal' && $v['transporte'] === '') return [0, 'Indicá el transporte.'];
    }
    foreach ($v as $k => $x) if ($x === '') $v[$k] = null;
    $v['alias'] = mb_substr($v['alias'], 0, 60);

    $db = db();
    if ($id && direccion($uid, $id)) {
        $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($v)));
        $db->prepare("UPDATE direcciones SET $set WHERE id = ? AND usuario_id = ?")
           ->execute([...array_values($v), $id, $uid]);
    } else {
        $cols = implode(',', array_keys($v));
        $db->prepare("INSERT INTO direcciones (usuario_id, $cols) VALUES (?" . str_repeat(',?', count($v)) . ")")
           ->execute([$uid, ...array_values($v)]);
        $id = (int)$db->lastInsertId();
    }
    // La primera (o la marcada) queda como predeterminada
    $hay_pred = (int)$db->query('SELECT COUNT(*) FROM direcciones WHERE predeterminada = 1 AND usuario_id = ' . $uid)->fetchColumn();
    if (!empty($in['predeterminada']) || !$hay_pred) marcar_predeterminada($uid, $id);
    return [$id, ''];
}

function marcar_predeterminada(int $uid, int $id): void {
    db()->prepare('UPDATE direcciones SET predeterminada = (id = ?) WHERE usuario_id = ?')->execute([$id, $uid]);
}

function borrar_direccion(int $uid, int $id): void {
    db()->prepare('DELETE FROM direcciones WHERE id = ? AND usuario_id = ?')->execute([$id, $uid]);
    $q = db()->prepare('SELECT id FROM direcciones WHERE usuario_id = ? ORDER BY predeterminada DESC, id ASC LIMIT 1');
    $q->execute([$uid]);
    if ($otra = $q->fetchColumn()) marcar_predeterminada($uid, (int)$otra);
}

// ── Ficha: guardar desde POST ───────────────────────────────
// $admin = true permite tocar los campos fiscales aunque estén verificados.
// Devuelve [errores[], cambios[]]
function guardar_ficha_post(array $c, array $in, bool $admin): array {
    $bloq = !$admin && !empty($c['fiscal_verificado']);
    $v = [];
    foreach (FICHA_CAMPOS as $k) {
        if ($bloq && in_array($k, FICHA_CAMPOS_FISCALES, true)) continue;
        if (!array_key_exists($k, $in)) continue;
        $v[$k] = trim((string)$in[$k]);
    }
    $err = [];
    if (isset($v['cuit']) && $v['cuit'] !== '') {
        if (!cuit_valido($v['cuit'])) $err[] = 'El CUIT no es válido (revisá los 11 números) o el DNI no tiene 7 u 8 dígitos.';
        else $v['cuit'] = cuit_fmt($v['cuit']);
    }
    if (isset($v['condicion_iva']) && $v['condicion_iva'] !== '' && !isset(CONDICIONES_IVA[$v['condicion_iva']])) $v['condicion_iva'] = '';
    if (isset($v['factura_preferida'])) $v['factura_preferida'] = $v['factura_preferida'] === 'sin' ? 'sin' : 'con';
    if (isset($v['email_facturas']) && $v['email_facturas'] !== '' && !filter_var($v['email_facturas'], FILTER_VALIDATE_EMAIL))
        $err[] = 'El email para facturas no es válido.';
    foreach (['aut1_dni', 'aut2_dni'] as $k) if (isset($v[$k]) && $v[$k] !== '') $v[$k] = solo_digitos($v[$k]);
    if ($err) return [$err, []];

    $cambios = [];
    foreach ($v as $k => $x) {
        if ((string)($c[$k] ?? '') !== $x) $cambios[] = FICHA_ETIQUETAS[$k] ?? $k;
        if ($x === '' && $k !== 'factura_preferida') $v[$k] = null;
    }
    if (!$v) return [[], []];
    $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($v)));
    db()->prepare("UPDATE usuarios SET $set, ficha_actualizada_en = NOW() WHERE id = ?")
       ->execute([...array_values($v), (int)$c['id']]);

    $nuevo = cliente((int)$c['id']);
    if (ficha_completa($nuevo) && empty($nuevo['ficha_completa_en']))
        db()->prepare('UPDATE usuarios SET ficha_completa_en = NOW() WHERE id = ?')->execute([(int)$c['id']]);
    return [[], $cambios];
}

// ── Bloques de texto para PDF, Telegram y admin ─────────────
function condiciones_pedido_txt(array $ped): array {
    $out = [];
    if (!empty($ped['factura'])) {
        $out['Comprobante'] = COMPROBANTES[$ped['comprobante'] ?? ''] ?? ($ped['factura'] === 'sin' ? 'Sin factura' : 'Con factura');
        $out[$ped['factura'] === 'sin' ? 'Recargo' : 'IVA'] = pct_fmt((float)$ped['impuesto_pct']) . ' · ' . precio_fmt((float)$ped['impuesto_monto']);
        $out['Total a cobrar'] = precio_fmt((float)$ped['total_a_cobrar']);
    }
    if (!empty($ped['condicion_pago'])) $out['Condición de pago'] = CONDICIONES_PAGO[$ped['condicion_pago']] ?? $ped['condicion_pago'];
    if ($ped['ajuste_pct'] !== null && (float)$ped['ajuste_pct'] != 0) $out['Ajuste de lista'] = ((float)$ped['ajuste_pct'] > 0 ? '+' : '') . pct_fmt((float)$ped['ajuste_pct']);
    return $out;
}

function datos_fiscales_txt(array $c): array {
    $out = [];
    if ($c['razon_social'])  $out['Razón social'] = $c['razon_social'];
    if ($c['cuit'])          $out['CUIT / DNI'] = $c['cuit'] . (!empty($c['fiscal_verificado']) ? ' ✓' : '');
    if ($c['condicion_iva']) $out['IVA'] = CONDICIONES_IVA[$c['condicion_iva']] ?? $c['condicion_iva'];
    if ($c['email_facturas']) $out['Email facturas'] = $c['email_facturas'];
    return $out;
}
