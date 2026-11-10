<?php
// ============================================================
//  admin_extra.php — Secciones adicionales v3:
//  Novedades, Códigos de descuento, Promo 12+1, Config
//  Se incluye desde admin.php con: require 'admin_extra.php';
// ============================================================

// ── Acciones POST extra ──────────────────────────────────────
function handle_extra_actions(string $action, string &$flash_ok, string &$flash_err, string &$seccion): void {

    // ── NOVEDADES ────────────────────────────────────────────
    if ($action === 'guardar_novedad') {
        $nid    = (int)($_POST['nid'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $cont   = trim($_POST['contenido'] ?? '');
        $listas = 0;
        foreach ($_POST['listas_sel'] ?? [] as $l) $listas |= (int)$l;
        $activa  = isset($_POST['activa']) ? 1 : 0;
        $vence   = trim($_POST['vence_en'] ?? '') ?: null;
        $orden   = (int)($_POST['orden'] ?? 0);
        $img     = $_POST['img_actual'] ?? null;

        if (!empty($_FILES['imagen_nov']['name'])) {
            $ext = strtolower(pathinfo($_FILES['imagen_nov']['name'], PATHINFO_EXTENSION));
            if (in_array($ext,['jpg','jpeg','png','webp','gif']) && $_FILES['imagen_nov']['size'] < 3*1024*1024) {
                $dir = __DIR__.'/assets/img/novedades/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $nuevo = uniqid('nov_').'.'.$ext;
                if (move_uploaded_file($_FILES['imagen_nov']['tmp_name'], $dir.$nuevo)) $img = $nuevo;
            } else { $flash_err = 'Imagen inválida (máx 3MB, JPG/PNG/WEBP).'; }
        }

        if ($titulo && $cont && !$flash_err) {
            if ($nid) {
                db()->prepare('UPDATE novedades SET titulo=?,contenido=?,listas=?,activa=?,vence_en=?,orden=?,imagen=? WHERE id=?')
                   ->execute([$titulo,$cont,$listas,$activa,$vence,$orden,$img,$nid]);
                $flash_ok = 'Novedad actualizada.';
            } else {
                db()->prepare('INSERT INTO novedades (titulo,contenido,listas,activa,vence_en,orden,imagen) VALUES (?,?,?,?,?,?,?)')
                   ->execute([$titulo,$cont,$listas,$activa,$vence,$orden,$img]);
                $flash_ok = 'Novedad creada.';
            }
        } elseif (!$flash_err) { $flash_err = 'Completá título y contenido.'; }
        $seccion = 'novedades';
    }

    if ($action === 'toggle_novedad') {
        db()->prepare('UPDATE novedades SET activa=1-activa WHERE id=?')->execute([(int)$_POST['nid']]);
        $flash_ok = 'Novedad actualizada.'; $seccion = 'novedades';
    }
    if ($action === 'borrar_novedad') {
        db()->prepare('DELETE FROM novedades WHERE id=?')->execute([(int)$_POST['nid']]);
        $flash_ok = 'Novedad eliminada.'; $seccion = 'novedades';
    }

    // ── CÓDIGOS DE DESCUENTO ─────────────────────────────────
    if ($action === 'guardar_codigo') {
        $cid      = (int)($_POST['cid'] ?? 0);
        $codigo   = strtoupper(trim($_POST['codigo'] ?? ''));
        $tipo     = $_POST['tipo'] === 'monto_fijo' ? 'monto_fijo' : 'porcentaje';
        $valor    = (float)$_POST['valor'];
        $minimo   = (float)($_POST['monto_minimo'] ?? 0);
        $usos_max = max(1,(int)$_POST['usos_max']);
        $lista_id = $_POST['lista_id'] === '' ? null : (int)$_POST['lista_id'];
        $usr_id   = $_POST['usuario_id'] === '' ? null : (int)$_POST['usuario_id'];
        $activo   = isset($_POST['activo']) ? 1 : 0;
        $vence    = trim($_POST['vence_en'] ?? '') ?: null;

        if ($codigo && $valor > 0) {
            try {
                if ($cid) {
                    db()->prepare('UPDATE codigos_descuento SET codigo=?,tipo=?,valor=?,monto_minimo=?,usos_max=?,lista_id=?,usuario_id=?,activo=?,vence_en=? WHERE id=?')
                       ->execute([$codigo,$tipo,$valor,$minimo,$usos_max,$lista_id,$usr_id,$activo,$vence,$cid]);
                    $flash_ok = 'Código actualizado.';
                } else {
                    db()->prepare('INSERT INTO codigos_descuento (codigo,tipo,valor,monto_minimo,usos_max,lista_id,usuario_id,activo,vence_en) VALUES (?,?,?,?,?,?,?,?,?)')
                       ->execute([$codigo,$tipo,$valor,$minimo,$usos_max,$lista_id,$usr_id,$activo,$vence]);
                    $flash_ok = "Código «{$codigo}» creado.";
                }
            } catch (Throwable $e) { $flash_err = 'El código ya existe.'; }
        } else { $flash_err = 'Completá código y valor.'; }
        $seccion = 'descuentos';
    }

    if ($action === 'toggle_codigo') {
        db()->prepare('UPDATE codigos_descuento SET activo=1-activo WHERE id=?')->execute([(int)$_POST['cid']]);
        $flash_ok = 'Código actualizado.'; $seccion = 'descuentos';
    }
    if ($action === 'borrar_codigo') {
        db()->prepare('DELETE FROM codigos_descuento WHERE id=?')->execute([(int)$_POST['cid']]);
        $flash_ok = 'Código eliminado.'; $seccion = 'descuentos';
    }

    // ── CONFIG PROMO 12+1 ────────────────────────────────────
    if ($action === 'guardar_promo12') {
        set_config('promo12x1_activa', isset($_POST['promo12_activa']) ? '1' : '0');
        $listas12 = 0;
        foreach ($_POST['promo12_listas'] ?? [] as $l) $listas12 |= (int)$l;
        set_config('promo12x1_listas', (string)$listas12);
        $flash_ok = 'Promo 12+1 guardada.'; $seccion = 'config';
    }
}

// ── Render sección NOVEDADES ─────────────────────────────────
function render_novedades(string $flash_ok, string $flash_err): void {
    $editar = null;
    if (isset($_GET['editar_nov'])) {
        $s = db()->prepare('SELECT * FROM novedades WHERE id=? LIMIT 1');
        $s->execute([(int)$_GET['editar_nov']]);
        $editar = $s->fetch();
    }
    $novedades = db()->query('SELECT * FROM novedades ORDER BY orden ASC, created_at DESC')->fetchAll();
    $listas_def = [1=>'Distribuidor',4=>'Punto de Venta'];
    $listas_col = [1=>'#e30613',4=>'#3b82f6'];
    ?>
    <div class="edit-form">
      <h3><?= $editar ? 'Editar novedad' : 'Nueva novedad' ?></h3>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="guardar_novedad">
        <?php if ($editar): ?>
          <input type="hidden" name="nid" value="<?= $editar['id'] ?>">
          <input type="hidden" name="img_actual" value="<?= escape($editar['imagen']??'') ?>">
        <?php endif; ?>
        <div class="form-row">
          <div class="field" style="grid-column:1/-1"><label>Título</label>
            <input type="text" name="titulo" required value="<?= escape($editar['titulo']??'') ?>"
                   placeholder="Ej: ¡Nuevos lanzamientos Mayo 2026!"></div>
          <div class="field" style="grid-column:1/-1"><label>Contenido</label>
            <textarea name="contenido" rows="4"
                      style="width:100%;padding:.55rem .8rem;border:1.5px solid var(--gris-2);border-radius:6px;font-family:inherit;font-size:.9rem;outline:none;resize:vertical"
                      placeholder="Texto de la novedad..."><?= escape($editar['contenido']??'') ?></textarea></div>
          <div class="field"><label>Imagen (opcional, máx 3MB)</label>
            <input type="file" name="imagen_nov" accept="image/*">
            <?php if (!empty($editar['imagen'])): ?>
              <small style="color:var(--gris-4)">Actual: <?= escape($editar['imagen']) ?></small>
            <?php endif; ?>
          </div>
          <div class="field"><label>Fecha de vencimiento (opcional)</label>
            <input type="datetime-local" name="vence_en"
                   value="<?= $editar && $editar['vence_en'] ? date('Y-m-d\TH:i', strtotime($editar['vence_en'])) : '' ?>"></div>
          <div class="field"><label>Orden</label>
            <input type="number" name="orden" min="0" value="<?= $editar['orden']??0 ?>"></div>
        </div>
        <div style="margin-bottom:.8rem">
          <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);margin-bottom:.4rem">Visible para</div>
          <div style="display:flex;gap:.6rem;flex-wrap:wrap">
            <?php foreach ($listas_def as $bit => $lnom): ?>
            <?php $checked = $editar ? (($editar['listas'] & $bit) > 0) : true; ?>
            <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;font-size:.85rem">
              <input type="checkbox" name="listas_sel[]" value="<?= $bit ?>" <?= $checked?'checked':'' ?>>
              <span style="color:<?= $listas_col[$bit] ?>;font-weight:600"><?= $lnom ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.85rem;margin-bottom:.8rem">
          <input type="checkbox" name="activa" value="1" <?= !$editar||$editar['activa']?'checked':'' ?>>
          Novedad activa
        </label>
        <button type="submit" class="btn-sm rojo"><?= $editar?'Guardar':'Crear novedad' ?></button>
        <?php if ($editar): ?><a href="?s=novedades" style="margin-left:.5rem;font-size:.82rem;color:var(--gris-4)">Cancelar</a><?php endif; ?>
      </form>
    </div>

    <?php if (empty($novedades)): ?>
      <p style="color:var(--gris-4);text-align:center;padding:2rem">No hay novedades creadas aún.</p>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:.7rem">
      <?php foreach ($novedades as $n):
        $bits_activos = [];
        foreach ($listas_def as $bit => $lnom) if (($n['listas'] & $bit) > 0) $bits_activos[] = ['lnom'=>$lnom,'col'=>$listas_col[$bit]];
      ?>
      <div style="background:#fff;border-radius:10px;padding:1rem;box-shadow:0 2px 10px rgba(0,0,0,.07);display:flex;gap:.8rem;align-items:flex-start">
        <?php if ($n['imagen']): ?>
          <img src="assets/img/novedades/<?= escape($n['imagen']) ?>"
               style="width:60px;height:60px;object-fit:cover;border-radius:6px;flex-shrink:0">
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:.95rem;color:var(--negro)"><?= escape($n['titulo']) ?></div>
          <div style="font-size:.78rem;color:var(--gris-4);margin:.2rem 0"><?= mb_substr(escape($n['contenido']),0,80) ?>...</div>
          <div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.3rem">
            <?php foreach ($bits_activos as $b): ?>
              <span style="background:<?= $b['col'] ?>22;color:<?= $b['col'] ?>;font-size:.7rem;font-weight:700;padding:1px 7px;border-radius:99px"><?= $b['lnom'] ?></span>
            <?php endforeach; ?>
            <span class="estado-badge <?= $n['activa']?'estado-enviado':'estado-cancelado' ?>"><?= $n['activa']?'Activa':'Inactiva' ?></span>
            <?php if ($n['vence_en']): ?>
              <span style="font-size:.7rem;color:var(--gris-4)">Vence: <?= date('d/m/Y', strtotime($n['vence_en'])) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:.3rem;flex-shrink:0">
          <a href="?s=novedades&editar_nov=<?= $n['id'] ?>" class="btn-det">Editar</a>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="toggle_novedad">
            <input type="hidden" name="nid" value="<?= $n['id'] ?>">
            <button type="submit" class="btn-sm" style="background:<?= $n['activa']?'#888':'#22c55e' ?>;width:100%">
              <?= $n['activa']?'Pausar':'Activar' ?></button>
          </form>
          <form method="post" onsubmit="return confirm('¿Borrar esta novedad?')">
            <input type="hidden" name="action" value="borrar_novedad">
            <input type="hidden" name="nid" value="<?= $n['id'] ?>">
            <button type="submit" class="btn-sm" style="background:#c00;width:100%">Borrar</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif;
}

// ── Render sección DESCUENTOS ────────────────────────────────
function render_descuentos(): void {
    $editar   = null;
    if (isset($_GET['editar_cod'])) {
        $s = db()->prepare('SELECT * FROM codigos_descuento WHERE id=? LIMIT 1');
        $s->execute([(int)$_GET['editar_cod']]);
        $editar = $s->fetch();
    }
    $codigos  = db()->query('SELECT c.*, u.nombre AS unom FROM codigos_descuento c LEFT JOIN usuarios u ON c.usuario_id=u.id ORDER BY c.created_at DESC')->fetchAll();
    $usuarios = db()->query('SELECT id,nombre,lista_id FROM usuarios WHERE activo=1 AND es_admin=0 ORDER BY nombre ASC')->fetchAll();
    ?>
    <div class="edit-form">
      <h3><?= $editar ? 'Editar código' : 'Nuevo código de descuento' ?></h3>
      <form method="post">
        <input type="hidden" name="action" value="guardar_codigo">
        <?php if ($editar): ?><input type="hidden" name="cid" value="<?= $editar['id'] ?>"><?php endif; ?>
        <div class="form-row">
          <div class="field"><label>Código</label>
            <input type="text" name="codigo" required maxlength="60"
                   value="<?= escape($editar['codigo']??'') ?>" placeholder="Ej: MAYO20"
                   style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()"></div>
          <div class="field"><label>Tipo</label>
            <select name="tipo" id="tipo-sel" onchange="toggleTipo()">
              <option value="porcentaje" <?= (!$editar||$editar['tipo']==='porcentaje')?'selected':'' ?>>Porcentaje (%)</option>
              <option value="monto_fijo" <?= ($editar&&$editar['tipo']==='monto_fijo')?'selected':'' ?>>Monto fijo ($)</option>
            </select></div>
          <div class="field"><label id="valor-lbl">Valor (%)</label>
            <input type="number" name="valor" step="0.01" min="0" required
                   value="<?= $editar['valor']??'' ?>" placeholder="Ej: 10"></div>
          <div class="field"><label>Pedido mínimo ($)</label>
            <input type="number" name="monto_minimo" step="100" min="0"
                   value="<?= $editar['monto_minimo']??0 ?>" placeholder="0 = sin mínimo"></div>
          <div class="field"><label>Límite de usos</label>
            <input type="number" name="usos_max" min="1" required
                   value="<?= $editar['usos_max']??1 ?>" placeholder="1"></div>
          <div class="field"><label>Asignar a lista (opcional)</label>
            <select name="lista_id">
              <option value="">Todos los clientes</option>
              <?php foreach (LISTAS as $id => $l): ?>
              <option value="<?= $id ?>" <?= ($editar&&$editar['lista_id']==$id)?'selected':'' ?>><?= escape($l['nombre']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>Asignar a usuario específico (opcional)</label>
            <select name="usuario_id">
              <option value="">Cualquier usuario</option>
              <?php foreach ($usuarios as $uu): ?>
              <option value="<?= $uu['id'] ?>" <?= ($editar&&$editar['usuario_id']==$uu['id'])?'selected':'' ?>>
                <?= escape($uu['nombre']) ?> (<?= nombre_lista($uu['lista_id']) ?>)
              </option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>Fecha de vencimiento (opcional)</label>
            <input type="datetime-local" name="vence_en"
                   value="<?= $editar&&$editar['vence_en']?date('Y-m-d\TH:i',strtotime($editar['vence_en'])):''; ?>"></div>
        </div>
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.85rem;margin-bottom:.8rem">
          <input type="checkbox" name="activo" value="1" <?= !$editar||$editar['activo']?'checked':'' ?>>
          Código activo
        </label>
        <button type="submit" class="btn-sm rojo"><?= $editar?'Guardar':'Crear código' ?></button>
        <?php if ($editar): ?><a href="?s=descuentos" style="margin-left:.5rem;font-size:.82rem;color:var(--gris-4)">Cancelar</a><?php endif; ?>
      </form>
    </div>

    <div style="overflow-x:auto">
      <table class="hist-tabla">
        <thead><tr><th>Código</th><th>Tipo</th><th>Valor</th><th>Mín.</th><th>Usos</th><th>Asignado a</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($codigos)): ?>
            <tr><td colspan="8" style="text-align:center;color:var(--gris-4);padding:2rem">Sin códigos creados.</td></tr>
          <?php endif; ?>
          <?php foreach ($codigos as $c): ?>
          <tr>
            <td style="font-weight:700;font-family:monospace"><?= escape($c['codigo']) ?></td>
            <td><?= $c['tipo']==='porcentaje'?'%':'$' ?></td>
            <td><?= $c['tipo']==='porcentaje' ? $c['valor'].'%' : precio_fmt($c['valor']) ?></td>
            <td><?= $c['monto_minimo']>0?precio_fmt($c['monto_minimo']):'—' ?></td>
            <td><?= $c['usos_actuales'].'/'.$c['usos_max'] ?></td>
            <td style="font-size:.8rem">
              <?php if ($c['usuario_id']): ?>
                <span style="color:var(--negro)">👤 <?= escape($c['unom']??'') ?></span>
              <?php elseif ($c['lista_id']): ?>
                <span style="color:<?= color_lista($c['lista_id']) ?>">📋 <?= nombre_lista($c['lista_id']) ?></span>
              <?php else: ?>
                <span style="color:var(--gris-4)">Todos</span>
              <?php endif; ?>
            </td>
            <td><span class="estado-badge <?= $c['activo']?'estado-enviado':'estado-cancelado' ?>"><?= $c['activo']?'Activo':'Inactivo' ?></span></td>
            <td style="display:flex;gap:.3rem;flex-wrap:wrap">
              <a href="?s=descuentos&editar_cod=<?= $c['id'] ?>" class="btn-det">Editar</a>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="toggle_codigo">
                <input type="hidden" name="cid" value="<?= $c['id'] ?>">
                <button type="submit" class="btn-sm" style="background:<?= $c['activo']?'#888':'#22c55e' ?>"><?= $c['activo']?'Desact.':'Activar' ?></button>
              </form>
              <form method="post" onsubmit="return confirm('¿Borrar código?')" style="display:inline">
                <input type="hidden" name="action" value="borrar_codigo">
                <input type="hidden" name="cid" value="<?= $c['id'] ?>">
                <button type="submit" class="btn-sm" style="background:#c00">Borrar</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <script>
    function toggleTipo(){
      const t=document.getElementById('tipo-sel').value;
      document.getElementById('valor-lbl').textContent=t==='porcentaje'?'Valor (%)':'Valor ($)';
    }
    toggleTipo();
    </script>
    <?php
}

// ── Render config promo 12+1 ─────────────────────────────────
function render_promo12(): void {
    $activa  = get_config('promo12x1_activa','0')==='1';
    $listas  = (int)get_config('promo12x1_listas','7');
    $listas_def = [1=>'Distribuidor',4=>'Punto de Venta'];
    $listas_col = [1=>'#e30613',4=>'#3b82f6'];
    ?>
    <div class="config-card">
      <h3>Promo 12+1 (cada 12 unidades, 1 de regalo)</h3>
      <form method="post">
        <input type="hidden" name="action" value="guardar_promo12">
        <div class="field" style="margin-bottom:.7rem">
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
            <input type="checkbox" name="promo12_activa" value="1" <?= $activa?'checked':'' ?>>
            Promo activa
          </label>
        </div>
        <div style="margin-bottom:.8rem">
          <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gris-4);margin-bottom:.4rem">Aplica a</div>
          <div style="display:flex;gap:.6rem;flex-wrap:wrap">
            <?php foreach ($listas_def as $bit => $lnom): ?>
            <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;font-size:.85rem">
              <input type="checkbox" name="promo12_listas[]" value="<?= $bit ?>"
                     <?= ($listas & $bit)?'checked':'' ?>>
              <span style="color:<?= $listas_col[$bit] ?>;font-weight:600"><?= $lnom ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <button type="submit" class="btn-sm rojo">Guardar promo 12+1</button>
      </form>
      <p style="font-size:.78rem;color:var(--gris-4);margin-top:.8rem;line-height:1.5">
        Con esta promo activa, en la planilla de pedido cada producto muestra cuántas unidades faltan para la próxima unidad de regalo.<br>
        Al llegar a múltiplos de 12 (12, 24, 36…) se agrega automáticamente 1 unidad de regalo por cada 12, con precio $0 en el pedido.
      </p>
    </div>
    <?php
}
