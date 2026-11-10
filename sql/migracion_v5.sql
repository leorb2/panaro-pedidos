-- ============================================================
--  PANARO CAR CARE — Migración v5
--  · Elimina la lista "Punto de Venta Original" (sus cuentas pasan a Punto de Venta)
--  · Agrega presentación y sección (regular / nuevo lanzamiento) a los productos
--  · Pasa los códigos a los SKU de la app (dash.panaro.com.ar)
--  · Nombres, presentación, sección, orden e imagen desde catalogo.xlsx
--  NO borra datos: pedidos, clientes y precios quedan como están.
--  Se puede ejecutar más de una vez sin problema.
--  Requiere MariaDB (la de Hostinger).
-- ============================================================

-- 1. Campos nuevos en productos
ALTER TABLE productos
  ADD COLUMN IF NOT EXISTS presentacion TEXT DEFAULT NULL AFTER descripcion,
  ADD COLUMN IF NOT EXISTS seccion ENUM('regular','nuevo') NOT NULL DEFAULT 'regular' AFTER presentacion;

-- 2. Punto de Venta Original (lista 2) pasa a Punto de Venta (lista 3)
UPDATE usuarios          SET lista_id = 3 WHERE lista_id = 2;
UPDATE codigos_descuento SET lista_id = 3 WHERE lista_id = 2;
-- Novedades: el bit 2 (PVO) se suma al bit 4 (PV)
UPDATE novedades SET listas = (listas & 1) | IF((listas & 6) > 0, 4, 0);
-- Promo 12+1: mismo criterio (la promo sigue desactivada)
UPDATE configuracion
   SET valor = CAST(((CAST(valor AS UNSIGNED) & 1) | IF((CAST(valor AS UNSIGNED) & 6) > 0, 4, 0)) AS CHAR)
 WHERE clave = 'promo12x1_listas';
-- Los pedidos viejos conservan lista_id = 2 para que el historial los siga mostrando como PVO.

-- 3. Códigos del portal → SKU de la app
UPDATE productos SET codigo = 'APC01-5LTC' WHERE codigo = 'APC01-5LC';
UPDATE productos SET codigo = 'BR01-5LT' WHERE codigo = 'BR01-5L';
UPDATE productos SET codigo = 'GP01-5LT' WHERE codigo = 'GP01-5L';
UPDATE productos SET codigo = 'IR01-5LT' WHERE codigo = 'IR01-5L';
UPDATE productos SET codigo = 'SW01-5LT' WHERE codigo = 'SW01-5L';
UPDATE productos SET codigo = 'HC02-5LTC' WHERE codigo = 'HC02-5LC';
UPDATE productos SET codigo = 'LC02-5LT' WHERE codigo = 'LC02-5L';
UPDATE productos SET codigo = 'EC03-5LT' WHERE codigo = 'EC03-5L';
UPDATE productos SET codigo = 'FW03-5LT' WHERE codigo = 'FW03-5L';
UPDATE productos SET codigo = 'FS03-5LT' WHERE codigo = 'FS03-5L';
UPDATE productos SET codigo = 'ID03-5LTC' WHERE codigo = 'ID03-5LC';
UPDATE productos SET codigo = 'QD03-5LTC' WHERE codigo = 'QD03-5LC';
UPDATE productos SET codigo = 'QW03-5LT' WHERE codigo = 'QW03-5L';
UPDATE productos SET codigo = 'TP03-5LT' WHERE codigo = 'TP03-5L';
UPDATE productos SET codigo = 'HC02-150' WHERE codigo = 'HC150';
UPDATE productos SET codigo = 'CC02-30' WHERE codigo = 'CC30';
UPDATE productos SET codigo = 'CC02-50' WHERE codigo = 'CC50';
UPDATE productos SET codigo = 'CC02-150' WHERE codigo = 'CC150';
UPDATE productos SET codigo = 'AC01-5LT' WHERE codigo = 'AC01-5L';
UPDATE productos SET codigo = 'GT01-5LT' WHERE codigo = 'GT01-5L';
UPDATE productos SET codigo = 'IC03-5LT' WHERE codigo = 'IC03-5L';
UPDATE productos SET codigo = 'TG03-5LT' WHERE codigo = 'TG03-5L';

-- 4. Datos del catálogo (nombre = descripción de la lista; descripcion = bajada)
UPDATE productos SET nombre = 'Limpiador Multiuso diluíble APC01', descripcion = 'Limpiador multiuso diluible. Elimina suciedad general. 650cc c/ pulverizador.', presentacion = 'Botella 650cc c/ pulverizador', seccion = 'regular', orden = 20, imagen = 'apc01-650.jpg' WHERE codigo = 'APC01-650';
UPDATE productos SET nombre = 'Limpiador Multiuso Concentrado - APC01', descripcion = 'Concentrado. Se diluye según el uso. Ideal para uso profesional. 650cc.', presentacion = 'Botella 650cc', seccion = 'regular', orden = 30, imagen = 'apc01-650c.jpg' WHERE codigo = 'APC01-650C';
UPDATE productos SET nombre = 'Limpiador Multiuso Concentrado - APC01', descripcion = 'Concentrado. Se diluye según el uso. Para lubricentros y detallers. Bidón 5L.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 10, imagen = 'apc01-5ltc.jpg' WHERE codigo = 'APC01-5LTC';
UPDATE productos SET nombre = 'Removedor de Insectos BR01', descripcion = 'Disuelve restos orgánicos sin rayar la pintura. 650cc c/ pulverizador.', presentacion = 'Botella 650cc c/ pulverizador', seccion = 'regular', orden = 50, imagen = 'br01-650.jpg' WHERE codigo = 'BR01-650';
UPDATE productos SET nombre = 'Removedor de Insectos BR01', descripcion = 'Removedor de insectos en formato económico profesional. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 40, imagen = 'br01-5lt.jpg' WHERE codigo = 'BR01-5LT';
UPDATE productos SET nombre = 'Limpia Cristales / Preparador de Superficies - GP01', descripcion = 'Sin rayas ni velos. Apto tintado. 650cc c/ pulverizador.', presentacion = 'Botella 650cc c/ pulverizador', seccion = 'regular', orden = 70, imagen = 'gp01-650.jpg' WHERE codigo = 'GP01-650';
UPDATE productos SET nombre = 'Limpia Cristales / Preparador de Superficies - GP01', descripcion = 'Limpia cristales concentrado para uso profesional. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 60, imagen = 'gp01-5lt.jpg' WHERE codigo = 'GP01-5LT';
UPDATE productos SET nombre = 'Removedor Férrico Reactivo - IR01', descripcion = 'Elimina partículas de frenos y contaminantes metálicos. 650cc.', presentacion = 'Botella 650cc c/ pulverizador', seccion = 'regular', orden = 90, imagen = 'ir01-650.jpg' WHERE codigo = 'IR01-650';
UPDATE productos SET nombre = 'Removedor Férrico Reactivo - IR01', descripcion = 'Removedor férrico reactivo en gran formato intensivo. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 80, imagen = 'ir01-5lt.jpg' WHERE codigo = 'IR01-5LT';
UPDATE productos SET nombre = 'Shampoo Prelavado Descontaminante Alcalino - SW01', descripcion = 'Ideal como primer paso del lavado. 650cc c/ pico aplicador.', presentacion = 'Botella 650cc c/ pico aplicador', seccion = 'regular', orden = 120, imagen = 'sw01-650.jpg' WHERE codigo = 'SW01-650';
UPDATE productos SET nombre = 'Shampoo Prelavado Descontaminante Alcalino - SW01', descripcion = 'Shampoo prelavado alcalino en formato profesional. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 130, imagen = 'sw01-5lt.jpg' WHERE codigo = 'SW01-5LT';
UPDATE productos SET nombre = 'Cera Líquida Híbrida - HC02', descripcion = 'Brillo espejo y protección duradera. 650cc c/ pulverizador.', presentacion = 'Botella 650cc c/ pulverizador', seccion = 'regular', orden = 210, imagen = 'hc02-650.jpg' WHERE codigo = 'HC02-650';
UPDATE productos SET nombre = 'Cera Líquida Híbrida - HC02 Concentrado (Rinde 15 litros)', descripcion = 'Concentrado. Rinde 15 litros. Para uso profesional. Bidón 5L.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 200, imagen = 'hc02-5ltc.jpg' WHERE codigo = 'HC02-5LTC';
UPDATE productos SET nombre = 'Hidratador de Cueros en Crema - LC02', descripcion = 'Nutre, protege y restaura el aspecto original. 650cc c/ pico aplicador.', presentacion = 'Botella 650cc c/ pico aplicador', seccion = 'regular', orden = 230, imagen = 'lc02-650.jpg' WHERE codigo = 'LC02-650';
UPDATE productos SET nombre = 'Hidratador de Cueros en Crema - LC02', descripcion = 'Hidratador de cueros en crema para uso intensivo. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 220, imagen = 'lc02-5lt.jpg' WHERE codigo = 'LC02-5LT';
UPDATE productos SET nombre = 'Limpiador acondicionador de Motores y Chasis 2 en 1 - EC03', descripcion = 'Limpiador 2 en 1. Seguro y efectivo. 650cc c/ pulverizador.', presentacion = 'Botella 650cc c/ pulverizador', seccion = 'regular', orden = 250, imagen = 'ec03-650.jpg' WHERE codigo = 'EC03-650';
UPDATE productos SET nombre = 'Limpiador acondicionador de Motores y Chasis 2 en 1 - EC03', descripcion = 'Limpiador motores y chasis 2 en 1 en gran formato. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 240, imagen = 'ec03-5lt.jpg' WHERE codigo = 'EC03-5LT';
UPDATE productos SET nombre = 'Shampoo Neutro con Cera - FW03', descripcion = 'Limpia y protege en un solo paso. 650cc c/ pico aplicador.', presentacion = 'Botella 650cc c/ pico aplicador', seccion = 'regular', orden = 290, imagen = 'fw03-650.jpg' WHERE codigo = 'FW03-650';
UPDATE productos SET nombre = 'Shampoo Neutro con Cera - FW03', descripcion = 'Shampoo neutro con cera en formato profesional. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 280, imagen = 'fw03-5lt.jpg' WHERE codigo = 'FW03-5LT';
UPDATE productos SET nombre = 'Shampoo Neutro Concentrado Alta Espuma - FS03', descripcion = 'Gran rendimiento. 650cc c/ pico aplicador.', presentacion = 'Botella 650cc c/ pico aplicador', seccion = 'regular', orden = 270, imagen = 'fs03-650.jpg' WHERE codigo = 'FS03-650';
UPDATE productos SET nombre = 'Shampoo Neutro Concentrado Alta Espuma - FS03', descripcion = 'Shampoo neutro concentrado alta espuma para uso intensivo. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 260, imagen = 'fs03-5lt.jpg' WHERE codigo = 'FS03-5LT';
UPDATE productos SET nombre = 'Detallador Acondicionador Interior 4 en 1 - ID03', descripcion = 'Limpia, acondiciona y protege. 650cc c/ pulverizador.', presentacion = 'Botella 650cc c/ pulverizador', seccion = 'regular', orden = 320, imagen = 'id03-650.jpg' WHERE codigo = 'ID03-650';
UPDATE productos SET nombre = 'Detallador Acondicionador Interior 4 en 1 - ID03 Concentrado (Rinde 25 litros)', descripcion = 'Concentrado. Rinde 25 litros. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 310, imagen = 'id03-5ltc.jpg' WHERE codigo = 'ID03-5LTC';
UPDATE productos SET nombre = 'Quick Detailer Cerámico Cera Rápida - QD03', descripcion = 'Cera rápida con protección cerámica. 650cc c/ pulverizador.', presentacion = 'Botella 650cc c/ pulverizador', seccion = 'regular', orden = 350, imagen = 'qd03-650.jpg' WHERE codigo = 'QD03-650';
UPDATE productos SET nombre = 'Quick Detailer Cerámico Cera Rápida - QD03 Concentrado (Rinde 25 litros)', descripcion = 'Concentrado. Rinde 25 litros. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 340, imagen = 'qd03-5ltc.jpg' WHERE codigo = 'QD03-5LTC';
UPDATE productos SET nombre = 'Lavado rápido en Seco Waterless - QW03', descripcion = 'Sin agua, sin marcas. 650cc c/ pulverizador.', presentacion = 'Botella 650cc c/ pulverizador', seccion = 'regular', orden = 370, imagen = 'qw03-650.jpg' WHERE codigo = 'QW03-650';
UPDATE productos SET nombre = 'Lavado rápido en Seco Waterless - QW03', descripcion = 'Lavado en seco en formato profesional. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 360, imagen = 'qw03-5lt.jpg' WHERE codigo = 'QW03-5LT';
UPDATE productos SET nombre = 'Acondicionador para Plásticos y Cubiertas Gel - TP03', descripcion = 'Negro profundo duradero. 650cc c/ pico aplicador.', presentacion = 'Botella 650cc c/ pico aplicador', seccion = 'regular', orden = 390, imagen = 'tp03-650.jpg' WHERE codigo = 'TP03-650';
UPDATE productos SET nombre = 'Acondicionador para Plásticos y Cubiertas Gel - TP03', descripcion = 'Para uso intensivo. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'regular', orden = 380, imagen = 'tp03-5lt.jpg' WHERE codigo = 'TP03-5LT';
UPDATE productos SET nombre = 'Sellador Híbrido hasta 12 meses - HC02 150cc', descripcion = 'Kit completo: botella aluminio 150cc + aplicador + paño microfibra.', presentacion = 'Botella de aluminio 150cc
Pulverizador Aplicador Paño microfibra', seccion = 'regular', orden = 180, imagen = 'hc02-150.jpg' WHERE codigo = 'HC02-150';
UPDATE productos SET nombre = 'Sellador Cerámico 9H 2 años Ceramic Coating - CC02', descripcion = 'Kit: botella vidrio 30cc + aplicador + guantes + paño microfibra.', presentacion = 'Botella de vidrio 30cc
Aplicador Guantes de nitrilo
Paño microfibra', seccion = 'regular', orden = 160, imagen = 'cc02-30.jpg' WHERE codigo = 'CC02-30';
UPDATE productos SET nombre = 'Sellador Cerámico 9H 2 años Ceramic Coating - CC02', descripcion = 'Kit: botella vidrio 50cc + aplicador + guantes + paño microfibra.', presentacion = 'Botella de vidrio 50cc
Aplicador Guantes de nitrilo
Paño microfibra', seccion = 'regular', orden = 170, imagen = 'cc02-50.jpg' WHERE codigo = 'CC02-50';
UPDATE productos SET nombre = 'Sellador Cerámico 9H 2 años Ceramic Coating - CC02', descripcion = 'Kit: botella aluminio 150cc + aplicador + guantes + paño microfibra.', presentacion = 'Botella de aluminio 150cc
Aplicador Guantes de nitrilo
Paño microfibra', seccion = 'regular', orden = 150, imagen = 'cc02-150.jpg' WHERE codigo = 'CC02-150';
UPDATE productos SET nombre = 'Limpiador Acido diluíble - AC01', descripcion = 'Limpiador ácido diluible para llantas y superficies resistentes. 650cc.', presentacion = 'Botella 650cc', seccion = 'nuevo', orden = 410, imagen = 'ac01-650.jpg' WHERE codigo = 'AC01-650';
UPDATE productos SET nombre = 'Limpiador Acido diluíble - AC01', descripcion = 'Limpiador ácido diluible en formato profesional. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'nuevo', orden = 400, imagen = 'ac01-5lt.jpg' WHERE codigo = 'AC01-5LT';
UPDATE productos SET nombre = 'Removedor de pegamento, brea y resinas - GT01', descripcion = 'Sin dañar la pintura. 650cc c/ pulverizador.', presentacion = 'Botella 650cc c/ pulverizador', seccion = 'nuevo', orden = 430, imagen = 'gt01-650.jpg' WHERE codigo = 'GT01-650';
UPDATE productos SET nombre = 'Removedor de pegamento, brea y resinas - GT01', descripcion = 'En gran formato para uso intensivo. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'nuevo', orden = 420, imagen = 'gt01-5lt.jpg' WHERE codigo = 'GT01-5LT';
UPDATE productos SET nombre = 'Acondicionador Interior diluible- IC03', descripcion = 'Limpia y protege tableros, plásticos y telas. 650cc.', presentacion = 'Botella 650cc c/ pico aplicador', seccion = 'nuevo', orden = 450, imagen = 'ic03-650.jpg' WHERE codigo = 'IC03-650';
UPDATE productos SET nombre = 'Acondicionador Interior diluible- IC03', descripcion = 'En formato profesional. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'nuevo', orden = 440, imagen = 'ic03-5lt.jpg' WHERE codigo = 'IC03-5LT';
UPDATE productos SET nombre = 'Acondicionador para Plásticos y Cubiertas Gel - TG03', descripcion = 'Fórmula mejorada. 650cc c/ pico aplicador.', presentacion = 'Botella 650cc c/ pico aplicador', seccion = 'nuevo', orden = 470, imagen = 'tg03-650.jpg' WHERE codigo = 'TG03-650';
UPDATE productos SET nombre = 'Acondicionador para Plásticos y Cubiertas Gel - TG03', descripcion = 'Para uso intensivo. Bidón 5 litros.', presentacion = 'Bidón x 5 litros', seccion = 'nuevo', orden = 460, imagen = 'tg03-5lt.jpg' WHERE codigo = 'TG03-5LT';

-- 5. Productos del portal que no están en el catálogo (se mantienen activos; datos de la lista Mayo 2026)
UPDATE productos SET nombre = 'Cera Líquida Híbrida - HC02 Concentrado (Rinde 2 litros)', descripcion = 'Concentrado. Rinde 2 litros. Máxima protección. 650cc.', presentacion = 'Botella 650cc c/ pulverizador', seccion = 'regular', orden = 215 WHERE codigo = 'HC02-650C';
UPDATE productos SET nombre = 'Detallador Acondicionador Interior 4 en 1 - ID03 Concentrado (Rinde 3 litros)', descripcion = 'Concentrado. Rinde 3 litros. 650cc.', presentacion = 'Botella 650cc', seccion = 'regular', orden = 325 WHERE codigo = 'ID03-650C';
UPDATE productos SET nombre = 'Quick Detailer Cerámico Cera Rápida - QD03 Concentrado (Rinde 3 litros)', descripcion = 'Concentrado. Rinde 3 litros. 650cc.', presentacion = 'Botella 650cc', seccion = 'regular', orden = 355 WHERE codigo = 'QD03-650C';
UPDATE productos SET nombre = 'Pulverizador resistente a químicos para dilución, con gatillo profesional - 1 Litro', descripcion = 'Resistente a químicos con gatillo profesional. Para dilución de concentrados.', presentacion = 'Pulverizador 1L con gatillo', seccion = 'nuevo', orden = 480 WHERE codigo = 'PULVER-1L';

-- 6. Productos del catálogo que el portal todavía no tiene: se crean OCULTOS y sin precio.
--    Cargales precio con el Excel de productos y activalos (columna activo = SI).
INSERT INTO productos (codigo, nombre, descripcion, presentacion, seccion, orden, imagen, precio_distribuidor, precio_pto_venta_original, precio_pto_venta, pvp_sugerido, activo)
  SELECT 'OSP01-5LT', 'OSP-01 One Step Polish - pulimento de un paso con cera', 'Pulimento con cera de un paso en formato profesional. Bidón 5 litros.', 'Bidon 5lts', 'nuevo', 432, 'osp01-5lt.jpg', 0, 0, 0, 0, 0
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM productos WHERE codigo = 'OSP01-5LT');
INSERT INTO productos (codigo, nombre, descripcion, presentacion, seccion, orden, imagen, precio_distribuidor, precio_pto_venta_original, precio_pto_venta, pvp_sugerido, activo)
  SELECT 'OSP01-650', 'OSP-01 One Step Polish - Pulimento de un paso con cera', 'Corrige y protege en un solo paso: pulido y cera. 650cc.', 'Botella 650cc', 'nuevo', 434, 'osp01-650.jpg', 0, 0, 0, 0, 0
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM productos WHERE codigo = 'OSP01-650');
INSERT INTO productos (codigo, nombre, descripcion, presentacion, seccion, orden, imagen, precio_distribuidor, precio_pto_venta_original, precio_pto_venta, pvp_sugerido, activo)
  SELECT 'TC01-5LT', 'TC-01 Touchless Cleaner', 'Remueve suciedad y film de ruta sin frotar la pintura. Bidón 5 litros.', 'Bidón 5 litros', 'regular', 140, 'tc01-5lt.jpg', 0, 0, 0, 0, 0
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM productos WHERE codigo = 'TC01-5LT');
INSERT INTO productos (codigo, nombre, descripcion, presentacion, seccion, orden, imagen, precio_distribuidor, precio_pto_venta_original, precio_pto_venta, pvp_sugerido, activo)
  SELECT 'HC02-5LT', 'Cera híbrida listo para usar HC-02', 'Brillo y repelencia al agua en formato profesional listo para usar. Bidón 5 litros.', 'Bidón 5 litros', 'regular', 190, 'hc02-5lt.jpg', 0, 0, 0, 0, 0
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM productos WHERE codigo = 'HC02-5LT');
INSERT INTO productos (codigo, nombre, descripcion, presentacion, seccion, orden, imagen, precio_distribuidor, precio_pto_venta_original, precio_pto_venta, pvp_sugerido, activo)
  SELECT 'ID03-5LT', 'Limpiador Acondicionador interior 4 en 1 listo para usar ID-03', 'Limpia, acondiciona y protege. Listo para usar, sin diluir. Bidón 5 litros.', 'Bidón 5 litros', 'regular', 300, 'id03-5lt.jpg', 0, 0, 0, 0, 0
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM productos WHERE codigo = 'ID03-5LT');
INSERT INTO productos (codigo, nombre, descripcion, presentacion, seccion, orden, imagen, precio_distribuidor, precio_pto_venta_original, precio_pto_venta, pvp_sugerido, activo)
  SELECT 'QD03-5LT', 'Quick Detailer Cerámico listo para usar QD-03 5 litros', 'Cera rápida con protección cerámica. Listo para usar, sin diluir. Bidón 5 litros.', 'Bidón 5 litros', 'regular', 330, 'qd03-5lt.jpg', 0, 0, 0, 0, 0
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM productos WHERE codigo = 'QD03-5LT');

-- 7. Control: tiene que devolver 0 filas (todos los productos con presentación)
SELECT codigo, nombre FROM productos WHERE presentacion IS NULL OR presentacion = '';

SELECT 'Migración v5 completada' AS resultado;
