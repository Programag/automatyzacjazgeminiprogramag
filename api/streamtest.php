<?php
/**
 * Diagnostyka: czy hosting przekazuje odpowiedź strumieniowo (jak odpowiedzi AI), czy buforuje ją w całości.
 * Wysyła 4 krótkie fragmenty co 0,8 s – bez AI, bez danych użytkownika, bez dostępu do bazy.
 */
declare(strict_types=1);
header('Content-Type: text/event-stream');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
header('X-Robots-Tag: noindex');
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
while (ob_get_level() > 0) ob_end_flush();
set_time_limit(15);
$t0 = microtime(true);
// ?pad=N (KB, maks. 128) – dopełnienie komentarzem SSE, żeby sprawdzić, od jakiego rozmiaru bufor hostingu się opróżnia
$pad = max(0, min(128, (int) ($_GET['pad'] ?? 0))) * 1024;
for ($i = 1; $i <= 4; $i++) {
    if ($pad) echo ':' . str_repeat(' ', $pad) . "\n\n";
    echo 'data: ' . json_encode(['n' => $i, 'serverMs' => (int) round((microtime(true) - $t0) * 1000)]) . "\n\n";
    @flush();
    if ($i < 4) usleep(800000);
}
