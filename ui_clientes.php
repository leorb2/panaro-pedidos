<?php
// ============================================================
//  ui_clientes.php — Formularios compartidos de ficha y direcciones
//  (los usan mis_datos.php, admin_cliente.php y el pedido)
// ============================================================

function ui_clientes_css(): string {
    return <<<'CSS'
<style>
.fc-card{background:#fff;border-radius:10px;box-shadow:0 2px 16px rgba(0,0,0,.07);padding:1.2rem 1.3rem;margin-bottom:1.2rem}
.fc-card h3{font-family:'Bebas Neue',sans-serif;font-size:1.35rem;letter-spacing:.04em;margin:0 0 .25rem;color:var(--negro)}
.fc-card .fc-sub{font-size:.8rem;color:var(--gris-4);margin:0 0 1rem;line-height:1.45}
.fc-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.8rem 1rem}
.fc-grid .full{grid-column:1/-1}
@media(max-width:640px){.fc-grid{grid-template-columns:1fr}}
.fc-f label{display:block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);margin-bottom:.3rem}
.fc-f label .opc{font-weight:400;text-transform:none;letter-spacing:0;color:#aaa}
.fc-f input[type=text],.fc-f input[type=email],.fc-f input[type=tel],.fc-f input[type=number],.fc-f input[type=time],.fc-f select,.fc-f textarea{
  width:100%;padding:.6rem .75rem;border:1.5px solid var(--gris-2);border-radius:6px;font-family:inherit;font-size:.9rem;outline:none;background:#fff;box-sizing:border-box}
.fc-f input:focus,.fc-f select:focus,.fc-f textarea:focus{border-color:var(--rojo)}
.fc-f input[readonly]{background:#f5f5f5;color:#666}
.fc-f small{display:block;font-size:.74rem;color:var(--gris-4);margin-top:.25rem;line-height:1.4}
.fc-seg{display:flex;gap:.4rem;flex-wrap:wrap}
.fc-seg label{display:flex;align-items:center;gap:.4rem;border:1.5px solid var(--gris-2);border-radius:7px;padding:.55rem .8rem;font-size:.86rem;font-weight:600;text-transform:none;letter-spacing:0;color:var(--negro);cursor:pointer;margin:0;flex:1;min-width:150px}
.fc-seg label:has(input:checked){border-color:var(--rojo);background:#fff5f5}
.fc-dias{display:flex;gap:.3rem;flex-wrap:wrap}
.fc-dias label{display:flex;align-items:center;gap:.25rem;border:1.5px solid var(--gris-2);border-radius:6px;padding:.4rem .55rem;font-size:.82rem;text-transform:none;letter-spacing:0;color:var(--negro);cursor:pointer;margin:0}
.fc-dias label:has(input:checked){border-color:var(--rojo);background:#fff5f5}
.fc-horas{display:flex;gap:.5rem;align-items:center}
.fc-horas input{max-width:130px}
.fc-sep{grid-column:1/-1;border-top:1px solid #eee;margin:.3rem 0 0;padding-top:.7rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#999}
.fc-bloq{background:#f8f8f8;border-radius:7px;padding:.6rem .8rem;font-size:.8rem;color:#555;grid-column:1/-1}
.fc-btns{display:flex;gap:.6rem;align-items:center;margin-top:1.1rem;flex-wrap:wrap}
.fc-btn{background:var(--rojo);color:#fff;border:none;border-radius:7px;padding:.7rem 1.3rem;font-family:inherit;font-size:.9rem;font-weight:700;cursor:pointer}
.fc-btn.sec{background:var(--gris-2);color:var(--negro)}
.fc-link{font-size:.82rem;color:var(--gris-4);background:none;border:none;cursor:pointer;font-family:inherit;text-decoration:underline;padding:0}
.dir-lista{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:.8rem}
.dir-item{border:1.5px solid var(--gris-2);border-radius:9px;padding:.85rem .95rem;display:flex;flex-direction:column;gap:.3rem;background:#fff}
.dir-item.pred{border-color:var(--rojo)}
.dir-item .dir-alias{font-weight:700;font-size:.92rem;display:flex;justify-content:space-between;gap:.5rem;align-items:center}
.dir-item .dir-tag{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;background:var(--rojo);color:#fff;padding:2px 6px;border-radius:3px}
.dir-item .dir-mod{font-size:.72rem;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.04em}
.dir-item .dir-txt{font-size:.82rem;color:#444;line-height:1.45;white-space:pre-line}
.dir-item .dir-acc{display:flex;gap:.8rem;margin-top:.35rem;flex-wrap:wrap}
.dir-item .dir-acc button,.dir-item .dir-acc a{font-size:.78rem;background:none;border:none;padding:0;color:var(--gris-4);text-decoration:underline;cursor:pointer;font-family:inherit}
.fc-aviso{background:#fffbeb;border:1.5px solid #fde68a;border-radius:9px;padding:.8rem 1rem;font-size:.85rem;color:#78350f;line-height:1.5;margin-bottom:1rem}
.fc-aviso a{color:#b45309;font-weight:700}
.fc-modal{position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:flex-start;justify-content:center;padding:1rem;z-index:1000;overflow-y:auto}
.fc-modal[hidden]{display:none}
.fc-modal .fc-card{max-width:640px;width:100%;margin:2vh 0}
</style>
CSS;
}

function sel_opts(array $ops, ?string $actual, bool $vacio = true, string $txt_vacio = 'Elegí…'): string {
    $h = $vacio ? '<option value="">' . escape($txt_vacio) . '</option>' : '';
    foreach ($ops as $k => $v) {
        $k = is_int($k) ? $v : $k;
        $h .= '<option value="' . escape((string)$k) . '"' . ((string)$actual === (string)$k ? ' selected' : '') . '>' . escape($v) . '</option>';
    }
    return $h;
}

// Campos de la ficha. $bloq_fiscal: el cliente no puede cambiar razón social, CUIT e IVA.
function ui_ficha_campos(array $c, bool $bloq_fiscal): void {
    $v  = fn($k) => escape((string)($c[$k] ?? ''));
    $ro = $bloq_fiscal ? ' readonly' : '';
    ?>
    <div class="fc-grid">
      <div class="fc-sep">Datos fiscales</div>
      <?php if ($bloq_fiscal): ?>
        <div class="fc-bloq">🔒 Razón social, CUIT y condición de IVA ya fueron verificados. Si cambiaron, escribinos y los actualizamos.</div>
      <?php endif; ?>
      <div class="fc-f"><label>Razón social</label>
        <input type="text" name="razon_social" value="<?= $v('razon_social') ?>" maxlength="200" placeholder="Como figura en AFIP / ARCA"<?= $ro ?>></div>
      <div class="fc-f"><label>Nombre de fantasía <span class="opc">(opcional)</span></label>
        <input type="text" name="nombre_fantasia" value="<?= $v('nombre_fantasia') ?>" maxlength="200" placeholder="Nombre del local o marca"></div>
      <div class="fc-f"><label>CUIT / DNI</label>
        <input type="text" name="cuit" value="<?= $v('cuit') ?>" maxlength="20" inputmode="numeric" placeholder="20-12345678-9"<?= $ro ?>></div>
      <div class="fc-f"><label>Condición frente al IVA</label>
        <?php if ($bloq_fiscal): ?>
          <input type="text" value="<?= escape(CONDICIONES_IVA[$c['condicion_iva'] ?? ''] ?? '') ?>" readonly>
        <?php else: ?>
          <select name="condicion_iva"><?= sel_opts(CONDICIONES_IVA, $c['condicion_iva'] ?? '') ?></select>
        <?php endif; ?></div>
      <div class="fc-f full"><label>Factura</label>
        <div class="fc-seg">
          <label><input type="radio" name="factura_preferida" value="con" <?= ($c['factura_preferida'] ?? 'con') !== 'sin' ? 'checked' : '' ?>> Necesito factura</label>
          <label><input type="radio" name="factura_preferida" value="sin" <?= ($c['factura_preferida'] ?? '') === 'sin' ? 'checked' : '' ?>> No necesito factura</label>
        </div>
        <small>Es lo que te proponemos por defecto: en cada pedido podés elegir otra opción.</small></div>
      <div class="fc-f full"><label>Domicilio fiscal <span class="opc">(si es distinto al de entrega)</span></label>
        <input type="text" name="domicilio_fiscal" value="<?= $v('domicilio_fiscal') ?>" maxlength="255" placeholder="Calle y número, localidad, provincia, CP"></div>
      <div class="fc-f full"><label>Email para recibir facturas <span class="opc">(opcional)</span></label>
        <input type="email" name="email_facturas" value="<?= $v('email_facturas') ?>" maxlength="190" placeholder="administracion@tuempresa.com"></div>

      <div class="fc-sep">Contacto</div>
      <div class="fc-f"><label>Responsable de compras</label>
        <input type="text" name="contacto_nombre" value="<?= $v('contacto_nombre') ?>" maxlength="120" placeholder="Nombre y apellido"></div>
      <div class="fc-f"><label>Teléfono / WhatsApp</label>
        <input type="tel" name="contacto_tel" value="<?= $v('contacto_tel') ?>" maxlength="40" placeholder="11 2345-6789"></div>

      <div class="fc-sep">Personas autorizadas a retirar</div>
      <div class="fc-f full"><small style="margin:0">Para retiros en fábrica, depósito o sucursal del transporte. Tienen que presentar DNI al retirar.</small></div>
      <?php foreach ([1, 2] as $n): ?>
        <div class="fc-f"><label>Nombre y apellido <?= $n === 2 ? '<span class="opc">(opcional)</span>' : '' ?></label>
          <input type="text" name="aut<?= $n ?>_nombre" value="<?= $v("aut{$n}_nombre") ?>" maxlength="120"></div>
        <div class="fc-f" style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
          <div><label>DNI</label><input type="text" name="aut<?= $n ?>_dni" value="<?= $v("aut{$n}_dni") ?>" maxlength="15" inputmode="numeric"></div>
          <div><label>Teléfono</label><input type="tel" name="aut<?= $n ?>_tel" value="<?= $v("aut{$n}_tel") ?>" maxlength="40"></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}

// Campos de una dirección (formulario completo)
function ui_direccion_campos(array $d, string $pref = 'd'): void {
    $v   = fn($k) => escape((string)($d[$k] ?? ''));
    $mod = $d['modalidad'] ?? 'domicilio';
    $dias = array_filter(explode(',', (string)($d['dias'] ?? 'lun,mar,mie,jue,vie')));
    ?>
    <input type="hidden" name="dir_id" value="<?= (int)($d['id'] ?? 0) ?>">
    <div class="fc-grid dir-form" data-mod="<?= escape($mod) ?>">
      <div class="fc-f full"><label>¿Cómo recibís el pedido?</label>
        <div class="fc-seg">
          <?php foreach (MODALIDADES as $k => $t): ?>
            <label><input type="radio" name="modalidad" value="<?= $k ?>" <?= $mod === $k ? 'checked' : '' ?> onchange="fcModalidad(this)"> <?= escape($t) ?></label>
          <?php endforeach; ?>
        </div></div>
      <div class="fc-f full"><label>Nombre para reconocerla</label>
        <input type="text" name="alias" value="<?= $v('alias') ?>" maxlength="60" placeholder="Ej: Local Palermo, Depósito, Sucursal Rosario"></div>

      <div class="fc-sep m-no-retiro"><span class="m-dom">Dirección de entrega</span><span class="m-suc">Sucursal del transporte donde retirás</span></div>
      <div class="fc-f m-no-retiro"><label>Calle y número</label><input type="text" name="calle" value="<?= $v('calle') ?>" maxlength="160"></div>
      <div class="fc-f m-no-retiro"><label>Piso / depto / local <span class="opc">(opcional)</span></label><input type="text" name="piso_depto" value="<?= $v('piso_depto') ?>" maxlength="40"></div>
      <div class="fc-f m-no-retiro"><label>Localidad</label><input type="text" name="localidad" value="<?= $v('localidad') ?>" maxlength="100"></div>
      <div class="fc-f m-no-retiro" style="display:grid;grid-template-columns:1fr 110px;gap:.6rem">
        <div><label>Provincia</label><select name="provincia"><?= sel_opts(PROVINCIAS, $d['provincia'] ?? '') ?></select></div>
        <div><label>Cód. postal</label><input type="text" name="cp" value="<?= $v('cp') ?>" maxlength="12"></div>
      </div>

      <div class="fc-sep m-no-retiro">Transporte <span class="m-dom opc" style="text-transform:none;font-weight:400">(si lo despachamos por transporte)</span></div>
      <div class="fc-f m-no-retiro"><label>Transporte a utilizar</label><input type="text" name="transporte" value="<?= $v('transporte') ?>" maxlength="120" placeholder="Ej: Vía Cargo, Andreani, Expreso…"></div>
      <div class="fc-f m-no-retiro"><label>Teléfono del transporte <span class="opc">(opcional)</span></label><input type="tel" name="transporte_tel" value="<?= $v('transporte_tel') ?>" maxlength="40"></div>
      <div class="fc-f full m-no-retiro"><label>Depósito del transporte donde lo despachamos <span class="opc">(en Buenos Aires)</span></label>
        <input type="text" name="transporte_origen" value="<?= $v('transporte_origen') ?>" maxlength="255" placeholder="Dirección del transporte en origen"></div>

      <div class="fc-sep m-dom-only">Días y horarios de recepción</div>
      <div class="fc-f full m-dom-only"><div class="fc-dias">
        <?php foreach (DIAS_SEMANA as $k => $t): ?>
          <label><input type="checkbox" name="dias[]" value="<?= $k ?>" <?= in_array($k, $dias, true) ? 'checked' : '' ?>> <?= $t ?></label>
        <?php endforeach; ?></div></div>
      <div class="fc-f full m-dom-only"><div class="fc-horas">
        <span style="font-size:.82rem">de</span><input type="time" name="hora_desde" value="<?= $v('hora_desde') ?>">
        <span style="font-size:.82rem">a</span><input type="time" name="hora_hasta" value="<?= $v('hora_hasta') ?>"></div></div>

      <div class="fc-f full m-retiro-only"><div class="fc-bloq">Retirás por nuestra fábrica o depósito. Las personas autorizadas se cargan en <strong>Mis datos</strong> y tienen que presentar DNI.</div></div>

      <div class="fc-f full"><label>Aclaraciones <span class="opc">(opcional)</span></label>
        <textarea name="aclaraciones" rows="2" maxlength="500" placeholder="Timbre, referencias, quién recibe…"><?= $v('aclaraciones') ?></textarea></div>
      <div class="fc-f full"><label style="display:flex;align-items:center;gap:.45rem;text-transform:none;letter-spacing:0;font-size:.85rem;color:var(--negro);font-weight:600">
        <input type="checkbox" name="predeterminada" value="1" <?= !empty($d['predeterminada']) || empty($d['id']) ? 'checked' : '' ?>> Usar como dirección predeterminada</label></div>
    </div>
    <?php
}

// JS: mostrar/ocultar campos según la modalidad
function ui_direccion_js(): string {
    return <<<'JS'
<style>
.dir-form .m-suc,.dir-form .m-retiro-only{display:none}
.dir-form[data-mod=sucursal] .m-dom{display:none}
.dir-form[data-mod=sucursal] .m-suc{display:inline}
.dir-form[data-mod=sucursal] .m-dom-only{display:none}
.dir-form[data-mod=retiro] .m-no-retiro,.dir-form[data-mod=retiro] .m-dom-only{display:none}
.dir-form[data-mod=retiro] .m-retiro-only{display:block}
</style>
<script>
function fcModalidad(r){ r.closest('.dir-form').dataset.mod = r.value; }
</script>
JS;
}

// Tarjeta de una dirección guardada
function ui_direccion_tarjeta(array $d, array $c, string $acciones = ''): string {
    $txt = direccion_detalle($d, $c);
    $txt = preg_replace('/^[^\n]*\n?/', '', $txt);   // la primera línea (modalidad) va aparte
    return '<div class="dir-item' . (!empty($d['predeterminada']) ? ' pred' : '') . '">
      <div class="dir-alias"><span>' . escape($d['alias']) . '</span>' . (!empty($d['predeterminada']) ? '<span class="dir-tag">Predeterminada</span>' : '') . '</div>
      <div class="dir-mod">' . escape(MODALIDADES[$d['modalidad']] ?? '') . '</div>
      <div class="dir-txt">' . escape($txt ?: '—') . '</div>' . $acciones . '</div>';
}
