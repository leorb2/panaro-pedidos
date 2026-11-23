-- 23/11/2026
-- 1) QD03-5LC tenía cargado el precio por litro (11.200) en vez del bidón de 5 L.
--    Distribuidor = lista 4 × 5; Punto de Venta = Distribuidor × 1,20 (criterio del portal);
--    PVP = lista 1 (70.000) × 1,295, igual que el resto de los productos.
UPDATE productos SET precio_distribuidor = 56000, precio_pto_venta = 67200, pvp_sugerido = 90650
 WHERE codigo = 'QD03-5LC';

-- 2) Concentrados de 650 cc discontinuados (quedan ocultos, no se borran: hay pedidos que los referencian)
UPDATE productos SET activo = 0 WHERE codigo IN ('HC02-650C', 'ID03-650C', 'QD03-650C');

-- Control
SELECT codigo, activo, precio_distribuidor, precio_pto_venta, pvp_sugerido
  FROM productos WHERE codigo IN ('QD03-5LC','HC02-650C','ID03-650C','QD03-650C','PULVER-1L');

-- Pedidos que ya incluyeron QD03-5LC al precio equivocado
SELECT p.id AS pedido, p.created_at, u.nombre AS cliente, i.cantidad, i.precio_unit, p.estado
  FROM pedido_items i JOIN pedidos p ON p.id = i.pedido_id JOIN usuarios u ON u.id = p.usuario_id
  JOIN productos pr ON pr.id = i.producto_id
 WHERE pr.codigo = 'QD03-5LC' AND i.precio_unit > 0 AND i.precio_unit < 30000
 ORDER BY p.id;
