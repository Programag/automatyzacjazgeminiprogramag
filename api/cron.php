<?php
/**
 * PaperlyAI – zadania w tle (bez AI – nic nie trafia do Gemini):
 *   • nowa poczta w Gmailu → e-mail z listą nadawców i tematów (dla osób, które to włączyły),
 *   • przypomnienia o terminach z rejestru umów (wypowiedzenie, koniec, waloryzacja),
 *   • poranne podsumowanie dnia e-mailem (dla osób, które to włączyły),
 *   • powiadomienia na telefon (Web Push): nowa poczta, odpowiedzi czekające w Skrzynce AI,
 *     terminy płatności faktur wystawionych w KSeF, terminy umów,
 *   • sprzątanie wygasłych sesji.
 * Uruchamianie: harmonogram zadań hostingu co 15–30 minut:
 *   php /ścieżka/do/api/cron.php            (z serwera – bez klucza)
 *   https://<domena>/api/cron.php?key=…     (adres z klucza – widoczny w Panelu technika dla administratora)
 */
declare(strict_types=1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/jobs.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    header('Cache-Control: no-store');
    if (!hash_equals(cron_key(), (string) ($_GET['key'] ?? ''))) { http_response_code(403); echo "Brak dostępu (nieprawidłowy klucz).\n"; exit; }
}
@set_time_limit(280);
ignore_user_abort(true);

$log = function (string $m) { echo date('H:i:s') . ' ' . $m . "\n"; };
try {
    $stats = run_jobs($log, !empty($_GET['force']));
    if ($stats) $log("Gotowe. Powiadomienia o poczcie: {$stats['mail']}, przypomnienia o umowach: {$stats['contracts']}, podsumowania: {$stats['digest']}, powiadomienia na telefon: {$stats['push']}, błędy: {$stats['errors']}.");
} catch (Throwable $e) {
    http_response_code(500);
    $log('Błąd: ' . $e->getMessage());
}
