-- 23/11/2026 · Punto de Venta = Lista 1 − 5 %  (lista 1 en $ por litro: los bidones de 5 L × 5)
-- Reactiva los concentrados de 650 cc hasta liquidar stock.
UPDATE productos SET activo = 1 WHERE codigo IN ('HC02-650C','ID03-650C','QD03-650C');

UPDATE productos SET precio_pto_venta = 10545.00 WHERE codigo = 'QD03-650';  -- lista 1: 11100
UPDATE productos SET precio_pto_venta = 32775.00 WHERE codigo = 'QD03-5L';  -- lista 1: 34500
UPDATE productos SET precio_pto_venta = 66500.00 WHERE codigo = 'QD03-5LC';  -- lista 1: 70000
UPDATE productos SET precio_pto_venta = 11970.00 WHERE codigo = 'HC02-650';  -- lista 1: 12600
UPDATE productos SET precio_pto_venta = 39900.00 WHERE codigo = 'HC02-5L';  -- lista 1: 42000
UPDATE productos SET precio_pto_venta = 68400.00 WHERE codigo = 'HC02-5LC';  -- lista 1: 72000
UPDATE productos SET precio_pto_venta = 42560.00 WHERE codigo = 'HC02-150';  -- lista 1: 44800
UPDATE productos SET precio_pto_venta = 45600.00 WHERE codigo = 'CC02-30';  -- lista 1: 48000
UPDATE productos SET precio_pto_venta = 62700.00 WHERE codigo = 'CC02-50';  -- lista 1: 66000
UPDATE productos SET precio_pto_venta = 148200.00 WHERE codigo = 'CC02-150';  -- lista 1: 156000
UPDATE productos SET precio_pto_venta = 8265.00 WHERE codigo = 'SW01-650';  -- lista 1: 8700
UPDATE productos SET precio_pto_venta = 37050.00 WHERE codigo = 'SW01-5L';  -- lista 1: 39000
UPDATE productos SET precio_pto_venta = 8360.00 WHERE codigo = 'FW03-650';  -- lista 1: 8800
UPDATE productos SET precio_pto_venta = 38000.00 WHERE codigo = 'FW03-5L';  -- lista 1: 40000
UPDATE productos SET precio_pto_venta = 8455.00 WHERE codigo = 'FS03-650';  -- lista 1: 8900
UPDATE productos SET precio_pto_venta = 38000.00 WHERE codigo = 'FS03-5L';  -- lista 1: 40000
UPDATE productos SET precio_pto_venta = 8360.00 WHERE codigo = 'AC01-650';  -- lista 1: 8800
UPDATE productos SET precio_pto_venta = 35625.00 WHERE codigo = 'AC01-5L';  -- lista 1: 37500
UPDATE productos SET precio_pto_venta = 7600.00 WHERE codigo = 'APC01-650';  -- lista 1: 8000
UPDATE productos SET precio_pto_venta = 9025.00 WHERE codigo = 'APC01-650C';  -- lista 1: 9500
UPDATE productos SET precio_pto_venta = 36575.00 WHERE codigo = 'APC01-5LC';  -- lista 1: 38500
UPDATE productos SET precio_pto_venta = 9975.00 WHERE codigo = 'EC03-650';  -- lista 1: 10500
UPDATE productos SET precio_pto_venta = 40375.00 WHERE codigo = 'EC03-5L';  -- lista 1: 42500
UPDATE productos SET precio_pto_venta = 8360.00 WHERE codigo = 'GP01-650';  -- lista 1: 8800
UPDATE productos SET precio_pto_venta = 31350.00 WHERE codigo = 'GP01-5L';  -- lista 1: 33000
UPDATE productos SET precio_pto_venta = 8170.00 WHERE codigo = 'QW03-650';  -- lista 1: 8600
UPDATE productos SET precio_pto_venta = 29925.00 WHERE codigo = 'QW03-5L';  -- lista 1: 31500
UPDATE productos SET precio_pto_venta = 8835.00 WHERE codigo = 'BR01-650';  -- lista 1: 9300
UPDATE productos SET precio_pto_venta = 32300.00 WHERE codigo = 'BR01-5L';  -- lista 1: 34000
UPDATE productos SET precio_pto_venta = 12635.00 WHERE codigo = 'IR01-650';  -- lista 1: 13300
UPDATE productos SET precio_pto_venta = 60800.00 WHERE codigo = 'IR01-5L';  -- lista 1: 64000
UPDATE productos SET precio_pto_venta = 17955.00 WHERE codigo = 'GT01-650';  -- lista 1: 18900
UPDATE productos SET precio_pto_venta = 94050.00 WHERE codigo = 'GT01-5L';  -- lista 1: 99000
UPDATE productos SET precio_pto_venta = 8075.00 WHERE codigo = 'ID03-650';  -- lista 1: 8500
UPDATE productos SET precio_pto_venta = 25175.00 WHERE codigo = 'ID03-5L';  -- lista 1: 26500
UPDATE productos SET precio_pto_venta = 58900.00 WHERE codigo = 'ID03-5LC';  -- lista 1: 62000
UPDATE productos SET precio_pto_venta = 14250.00 WHERE codigo = 'IC03-650';  -- lista 1: 15000
UPDATE productos SET precio_pto_venta = 83125.00 WHERE codigo = 'IC03-5L';  -- lista 1: 87500
UPDATE productos SET precio_pto_venta = 13585.00 WHERE codigo = 'TP03-650';  -- lista 1: 14300
UPDATE productos SET precio_pto_venta = 74575.00 WHERE codigo = 'TP03-5L';  -- lista 1: 78500
UPDATE productos SET precio_pto_venta = 15010.00 WHERE codigo = 'TG03-650';  -- lista 1: 15800
UPDATE productos SET precio_pto_venta = 89775.00 WHERE codigo = 'TG03-5L';  -- lista 1: 94500
UPDATE productos SET precio_pto_venta = 13110.00 WHERE codigo = 'LC02-650';  -- lista 1: 13800
UPDATE productos SET precio_pto_venta = 67925.00 WHERE codigo = 'LC02-5L';  -- lista 1: 71500
UPDATE productos SET precio_pto_venta = 44175.00 WHERE codigo = 'TC01-5L';  -- lista 1: 46500
UPDATE productos SET precio_pto_venta = 32490.00 WHERE codigo = 'OSP01-650';  -- lista 1: 34200
UPDATE productos SET precio_pto_venta = 139650.00 WHERE codigo = 'OSP01-5L';  -- lista 1: 147000

SELECT codigo, activo, precio_distribuidor, precio_pto_venta, pvp_sugerido FROM productos ORDER BY orden, id;
