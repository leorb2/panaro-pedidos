-- ============================================================
--  v6 · Ficha de clientes, libreta de direcciones, condiciones comerciales
--  Idempotente: se puede correr más de una vez (MariaDB).
-- ============================================================

-- ── Ficha del cliente (la completa el cliente) ──────────────
ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS razon_social       VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS nombre_fantasia    VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS cuit               VARCHAR(20)  NULL,
  ADD COLUMN IF NOT EXISTS condicion_iva      VARCHAR(20)  NULL,   -- ri | monotributo | no_alcanzado | consumidor_final
  ADD COLUMN IF NOT EXISTS domicilio_fiscal   TEXT         NULL,
  ADD COLUMN IF NOT EXISTS email_facturas     VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS contacto_nombre    VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS contacto_tel       VARCHAR(40)  NULL,
  ADD COLUMN IF NOT EXISTS factura_preferida  VARCHAR(5)   NOT NULL DEFAULT 'con',  -- con | sin
  ADD COLUMN IF NOT EXISTS aut1_nombre        VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS aut1_dni           VARCHAR(15)  NULL,
  ADD COLUMN IF NOT EXISTS aut1_tel           VARCHAR(40)  NULL,
  ADD COLUMN IF NOT EXISTS aut2_nombre        VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS aut2_dni           VARCHAR(15)  NULL,
  ADD COLUMN IF NOT EXISTS aut2_tel           VARCHAR(40)  NULL,
  ADD COLUMN IF NOT EXISTS ficha_completa_en  DATETIME     NULL,
  ADD COLUMN IF NOT EXISTS ficha_actualizada_en DATETIME   NULL;

-- ── Condiciones comerciales (solo admin) ────────────────────
ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS comprobante        VARCHAR(5)   NULL,     -- A | B | sin ; NULL = automático según IVA
  ADD COLUMN IF NOT EXISTS recargo_sin_factura DECIMAL(5,2) NULL,    -- NULL = usar el general
  ADD COLUMN IF NOT EXISTS condicion_pago     VARCHAR(20)  NOT NULL DEFAULT 'anticipado',
  ADD COLUMN IF NOT EXISTS limite_credito     DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS ajuste_lista       DECIMAL(6,2) NULL,     -- % sobre el precio de lista; NULL = el de la lista
  ADD COLUMN IF NOT EXISTS vendedor           VARCHAR(80)  NULL,
  ADD COLUMN IF NOT EXISTS notas_internas     TEXT         NULL,
  ADD COLUMN IF NOT EXISTS fiscal_verificado  TINYINT(1)   NOT NULL DEFAULT 0;

-- ── Libreta de direcciones ──────────────────────────────────
CREATE TABLE IF NOT EXISTS direcciones (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id        INT UNSIGNED NOT NULL,
  alias             VARCHAR(60)  NOT NULL,
  modalidad         VARCHAR(10)  NOT NULL DEFAULT 'domicilio',   -- domicilio | sucursal | retiro
  calle             VARCHAR(160) NULL,
  piso_depto        VARCHAR(40)  NULL,
  localidad         VARCHAR(100) NULL,
  provincia         VARCHAR(60)  NULL,
  cp                VARCHAR(12)  NULL,
  dias              VARCHAR(40)  NULL,        -- "lun,mar,mie,jue,vie"
  hora_desde        VARCHAR(5)   NULL,
  hora_hasta        VARCHAR(5)   NULL,
  transporte        VARCHAR(120) NULL,
  transporte_tel    VARCHAR(40)  NULL,
  transporte_origen VARCHAR(255) NULL,        -- depósito del transporte donde despachamos
  aclaraciones      TEXT         NULL,
  predeterminada    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_dir_usuario (usuario_id),
  CONSTRAINT fk_dir_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Descuentos por producto (visibles para el cliente) ──────
CREATE TABLE IF NOT EXISTS usuario_descuentos (
  usuario_id  INT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NOT NULL,
  porcentaje  DECIMAL(5,2) NOT NULL,
  PRIMARY KEY (usuario_id, producto_id),
  CONSTRAINT fk_ud_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE CASCADE,
  CONSTRAINT fk_ud_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Foto de las condiciones en cada pedido ──────────────────
ALTER TABLE pedidos
  ADD COLUMN IF NOT EXISTS direccion_id    INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS entrega         TEXT         NULL,     -- detalle completo de la entrega al momento del pedido
  ADD COLUMN IF NOT EXISTS factura         VARCHAR(5)   NULL,     -- con | sin
  ADD COLUMN IF NOT EXISTS comprobante     VARCHAR(5)   NULL,     -- A | B | sin
  ADD COLUMN IF NOT EXISTS impuesto_pct    DECIMAL(5,2) NULL,     -- 21 (IVA) o el recargo sin factura
  ADD COLUMN IF NOT EXISTS impuesto_monto  DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS total_a_cobrar  DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS ajuste_pct      DECIMAL(6,2) NULL,
  ADD COLUMN IF NOT EXISTS condicion_pago  VARCHAR(20)  NULL;

ALTER TABLE pedido_items
  ADD COLUMN IF NOT EXISTS precio_lista    DECIMAL(12,2) NULL,    -- precio del cliente antes del descuento por producto
  ADD COLUMN IF NOT EXISTS dto_pct         DECIMAL(5,2)  NULL;

-- ── Valores generales ───────────────────────────────────────
INSERT IGNORE INTO configuracion (clave, valor) VALUES
  ('recargo_sin_factura', '0'),
  ('ajuste_lista_1', '0'),
  ('ajuste_lista_3', '0');

-- ── Clientes actuales: su dirección pasa a la libreta ───────
INSERT INTO direcciones (usuario_id, alias, modalidad, calle, aclaraciones, predeterminada)
SELECT u.id, 'Principal', 'domicilio', LEFT(TRIM(u.direccion), 160),
       IF(CHAR_LENGTH(TRIM(u.direccion)) > 160, TRIM(u.direccion), NULL), 1
  FROM usuarios u
 WHERE u.es_admin = 0
   AND TRIM(COALESCE(u.direccion, '')) <> ''
   AND NOT EXISTS (SELECT 1 FROM direcciones d WHERE d.usuario_id = u.id);

-- Control
SELECT COUNT(*) AS clientes, SUM(cuit IS NOT NULL) AS con_cuit FROM usuarios WHERE es_admin = 0;
SELECT COUNT(*) AS direcciones_cargadas FROM direcciones;
