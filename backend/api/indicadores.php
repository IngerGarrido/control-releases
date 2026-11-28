<?php
/**
 * Indicadores económicos para la barra superior (hoy: UF del día).
 *
 * GET indicadores.php → { uf: { valor, fecha } | null }
 *
 * Se pide desde el servidor (y no desde el navegador, que fallaba por CORS) a
 * varias fuentes públicas, en orden, hasta que una responda: mindicador.cl
 * estuvo caído y con una sola fuente el indicador desaparecía. El valor se
 * guarda 6 horas; si ninguna responde se entrega el último conocido (hasta 7
 * días) y no se reintenta por 15 minutos.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

/** GET corto a una URL pública; '' si falla. */
function ufGet(string $url): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (indicadores)',
        ]);
        $raw = curl_exec($ch);
        $ok = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        return $ok && is_string($raw) ? $raw : '';
    }
    if (ini_get('allow_url_fopen')) {
        return (string)@file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 4]]));
    }
    return '';
}

/**
 * UF del día desde la primera fuente que responda con un valor creíble.
 * Cada fuente: URL + cómo sacar [valor, fecha] de su JSON.
 * @return ?array ['valor' => float, 'fecha' => 'AAAA-MM-DD', 'fuente' => host]
 */
function ufDesdeFuentes(): ?array {
    $num = fn($v) => is_string($v) ? (float)str_replace(',', '.', str_replace('.', '', $v)) : (float)$v; // «41.419,22» → 41419.22
    $fuentes = [
        'https://findic.cl/api/uf'                         => fn($j) => [$j['serie'][0]['valor'] ?? null, $j['serie'][0]['fecha'] ?? ''],
        'https://api.boostr.cl/economy/indicator/uf.json'  => fn($j) => [$j['data']['value'] ?? null, $j['data']['date'] ?? ''],
        'https://api.gael.cloud/general/public/monedas/UF' => fn($j) => [isset($j['Valor']) ? $num($j['Valor']) : null, $j['Fecha'] ?? ''],
        'https://mindicador.cl/api/uf'                     => fn($j) => [$j['serie'][0]['valor'] ?? null, $j['serie'][0]['fecha'] ?? ''],
    ];
    foreach ($fuentes as $url => $leer) {
        $j = json_decode(ufGet($url), true);
        if (!is_array($j)) continue;
        [$valor, $fecha] = $leer($j);
        $valor = is_numeric($valor) ? (float)$valor : 0.0;
        // Rango de cordura: una UF fuera de esto es un dato roto, no la UF.
        if ($valor < 20000 || $valor > 200000) continue;
        return ['valor' => $valor, 'fecha' => substr((string)$fecha, 0, 10) ?: date('Y-m-d'), 'fuente' => (string)parse_url($url, PHP_URL_HOST)];
    }
    return null;
}

try {
    require_once __DIR__ . '/../config.php';
    cors();
    requireAuth();
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') err('Método no permitido', 405);

    $uf = cacheGet('indicador_uf');
    if ($uf === null && cacheGet('indicador_uf_fallo') === null) {
        $uf = ufDesdeFuentes();
        if ($uf !== null) {
            cacheSet('indicador_uf', $uf, 6 * 3600);
            cacheSet('indicador_uf_ultimo', $uf, 7 * 86400);
        } else {
            cacheSet('indicador_uf_fallo', ['at' => time()], 15 * 60);
        }
    }
    if ($uf === null) $uf = cacheGet('indicador_uf_ultimo');

    ok(['uf' => $uf]);
} catch (Throwable $e) {
    errSafe($e, 500);
}
