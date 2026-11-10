# Portal de pedidos Panaro — v5

Qué trae:
- Listas de precios en PDF (Distribuidor, Punto de Venta y Precio al público) generadas al momento con la fecha del día.
- El admin descarga el Excel de productos y lo vuelve a importar.
- Desaparece "Punto de Venta Original": quedan dos listas, Distribuidor y Punto de Venta.

## Instalación (en este orden)

### 1. Backup
En phpMyAdmin, elegí la base, después **Exportar → Rápido → SQL** y guardá el archivo.
Bajá también una copia de `config.php` del servidor.

### 2. Credenciales (¡antes de subir nada!)
1. Abrí tu `config.php` actual (el del servidor) y copiá los valores de DB_HOST, DB_NAME, DB_USER, DB_PASS, WA_NUMBER, TELEGRAM_TOKEN y TELEGRAM_CHAT_ID.
2. Hacé una copia de `credenciales.ejemplo.php` con el nombre **`credenciales.php`** y pegá esos valores.
3. Subí `credenciales.php` a la misma carpeta que `config.php`.

Desde ahora las claves viven en `credenciales.php`. Así podés reemplazar `config.php` en futuras versiones sin perder el acceso a la base.

### 3. Migración de la base
En phpMyAdmin, abrí **SQL**, pegá el contenido de `migracion_v5.sql` y ejecutalo.
- Las cuentas de Punto de Venta Original pasan a Punto de Venta.
- Se renombran 22 SKU para que coincidan con los del catálogo, y se cargan nombre, presentación y sección.
- Los pedidos viejos no se tocan.
- Se puede correr dos veces sin problema. Al final muestra una tabla de control.

### 4. Subir archivos
Subí todo el contenido del ZIP a la carpeta del portal, pisando lo que haya:
- los archivos `.php`, y el `.htaccess` de la raíz
- `assets/fonts/` (tipografía de las listas), `assets/img/header-panaro.jpg` y `assets/img/productos/` (47 fotos)
- la carpeta `cache/` con su `.htaccess` (permisos 755; las subcarpetas se crean solas)

No subas `migracion_v5.sql`, `credenciales.ejemplo.php` ni este LEEME (igual el `.htaccess` los bloquea).

Dompdf y PhpSpreadsheet ya están instalados en `vendor/`, así que no hace falta instalar nada más.

### 5. Probar
1. Entrá como admin, abrí **Productos** y tocá **Descargar productos (Excel)**.
2. Entrá con una cuenta de cliente, abrí **Listas de precios** y después **Ver PDF**.
   La primera vez tarda unos segundos porque arma la tipografía. Después sale de caché.

## Uso diario
- **Cambiar precios:** descargá el Excel, editalo y subilo en modo "Solo precios", o editá el producto en el admin. Los PDF se regeneran solos.
- **Productos nuevos:** cargalos con Sección "Nuevo lanzamiento" y aparecen en la hoja de *Nuevos lanzamientos* del PDF, con el mes en curso.
- Un producto entra en las listas solo si está **activo** y tiene **los 3 precios cargados**.
