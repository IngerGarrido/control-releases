<?php
/**
 * Entry point del SPA.
 * Sirve el index.html compilado desde frontend/dist/ sin depender de mod_rewrite,
 * y reescribe las rutas de assets para que el navegador las pida con prefijo
 * /frontend/dist/.
 *
 * Si el instalador todavía no se ejecutó, redirige a /backend/install/.
 */

// Si no hay .env, llevar al instalador
$envPath = __DIR__ . '/backend/.env';
if (!is_file($envPath)) {
    header('Location: backend/install/'); // relativo: sirve en subcarpeta
    exit;
}

$indexPath = __DIR__ . '/frontend/dist/index.html';
if (!is_file($indexPath)) {
    http_response_code(500);
    echo "Frontend no compilado. Falta frontend/dist/index.html.";
    exit;
}

$html = file_get_contents($indexPath);

// Todas las rutas quedan RELATIVAS a esta carpeta, para que la app funcione
// igual en la raíz del dominio que en una subcarpeta, y sin mod_rewrite:
//   ./assets/… o /assets/…   → frontend/dist/assets/…
//   /manifest.json, /icon.svg → manifest.json, icon.svg
$html = preg_replace('#(src|href)="(?:\./|/)?assets/#', '$1="frontend/dist/assets/', $html);
$html = preg_replace('#(src|href)="/(manifest\.json|icon\.svg)"#', '$1="$2"', $html);

header('Content-Type: text/html; charset=utf-8');
echo $html;
