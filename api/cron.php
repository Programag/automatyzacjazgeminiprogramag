<?php
/**
 * PaperlyAI – zadania w tle (bez AI – nic nie trafia do Gemini):
 *   • nowa poczta w Gmailu → e-mail z listą nadawców i tematów (dla osób, które to włączyły),
 *   • przypomnienia o terminach z rejestru umów (wypowiedzenie, koniec, waloryzacja),
 *   • poranne podsumowanie dnia e-mailem (dla osób, które to włączyły),
 *   • sprzątanie wygasłych sesji.
 * Uruchamianie: harmonogram zadań hostingu co 15–30 minut:
 *   php /ścieżka/do/api/cron.php            (z serwera – bez klucza)
 *   https://<domena>/api/cron.php?key=…     (adres z klucza – widoczny w Panelu technika dla administratora)
 */
declare(strict_types=1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/google.php';
require __DIR__ . '/lib/mailer.php';

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
    db();
    $lock = (int) meta_get('cron_lock', '0');
    if ($lock > time() - 600 && empty($_GET['force'])) { $log('Poprzednie uruchomienie jeszcze trwa – pomijam.'); exit; }
    meta_set('cron_lock', (string) time());
    $stats = ['mail' => 0, 'contracts' => 0, 'digest' => 0, 'errors' => 0];
    foreach (['job_new_mail', 'job_contracts', 'job_digest', 'job_cleanup'] as $job) {
        try { $job($log, $stats); }
        catch (Throwable $e) { $stats['errors']++; $log("$job: " . $e->getMessage()); }
    }
    meta_set('cron_last', (string) time());
    meta_set('cron_lock', '0');
    $log("Gotowe. Powiadomienia o poczcie: {$stats['mail']}, przypomnienia o umowach: {$stats['contracts']}, podsumowania: {$stats['digest']}, błędy: {$stats['errors']}.");
} catch (Throwable $e) {
    http_response_code(500);
    $log('Błąd: ' . $e->getMessage());
}

/* ------------------------------------------------------------------ pomocnicze */
function notice_sent(string $k): bool { return (bool) q1('SELECT 1 AS x FROM notices WHERE k = ?', [mb_substr($k, 0, 190)]); }
function notice_mark(string $k): void { q((is_sqlite() ? 'INSERT OR REPLACE' : 'REPLACE') . ' INTO notices (k, created_at) VALUES (?, ?)', [mb_substr($k, 0, 190), time()]); }
function recipient(array $user, array $prefs): string { return $prefs['email'] ?: $user['email']; }
function kv_get(int $wsId, int $userId, string $k): ?string { $r = q1('SELECT v FROM kv WHERE ws_id = ? AND user_id = ? AND k = ? AND deleted = 0', [$wsId, $userId, $k]); return $r['v'] ?? null; }
function kv_put(int $wsId, int $userId, string $k, string $v): void {
    q((is_sqlite() ? 'INSERT OR REPLACE' : 'REPLACE') . ' INTO kv (ws_id, user_id, k, v, deleted, updated_at, updated_by) VALUES (?, ?, ?, ?, 0, ?, 0)', [$wsId, $userId, $k, $v, time()]);
}
function user_ws(int $userId): int { return (int) (q1('SELECT ws_id FROM members WHERE user_id = ? ORDER BY created_at DESC LIMIT 1', [$userId])['ws_id'] ?? 0); }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function pl_date(string $iso): string { $t = strtotime($iso . ' 12:00:00'); return $t ? date('d.m.Y', $t) : $iso; }
function days_word(int $d): string { return $d === 0 ? 'dziś' : ($d === 1 ? 'jutro' : "za $d dni"); }

/** Nieprzeczytane wiadomości ze skrzynki odbiorczej (bez treści – tylko nadawca, temat, fragment). */
function gmail_unread(string $access, int $max, string $newer = '1d'): array {
    $q = "in:inbox is:unread newer_than:$newer -category:promotions -category:social -category:forums";
    $list = gmail_get($access, '/messages?maxResults=' . $max . '&q=' . rawurlencode($q));
    return ['ids' => array_map(fn($m) => (string) $m['id'], (array) ($list['messages'] ?? [])), 'estimate' => (int) ($list['resultSizeEstimate'] ?? 0)];
}
function gmail_meta(string $access, string $id): array {
    $m = gmail_get($access, '/messages/' . rawurlencode($id) . '?format=metadata&metadataHeaders=From&metadataHeaders=Subject&metadataHeaders=Date');
    $hd = [];
    foreach ((array) ($m['payload']['headers'] ?? []) as $x) $hd[strtolower((string) $x['name'])] = (string) $x['value'];
    $from = $hd['from'] ?? '';
    $name = preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $from, $mm) ? (trim($mm[1]) ?: $mm[2]) : $from;
    $addr = $mm[2] ?? $from;
    return ['id' => $id, 'from' => iconv_mime_decode($name, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $name, 'fromEmail' => strtolower(trim($addr)), 'subject' => iconv_mime_decode($hd['subject'] ?? '', ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: '(bez tematu)', 'snippet' => html_entity_decode((string) ($m['snippet'] ?? ''), ENT_QUOTES, 'UTF-8')];
}
function mail_rows(array $items): string {
    $out = '<table style="width:100%;border-collapse:collapse;font-size:14px">';
    foreach ($items as $i) $out .= '<tr><td style="padding:8px 0;border-top:1px solid #eef2f7"><b>' . h($i['from']) . '</b><br>' . h($i['subject']) . '<br><span style="color:#64748b;font-size:13px">' . h(mb_substr($i['snippet'], 0, 140)) . '</span></td></tr>';
    return $out . '</table>';
}

/* ------------------------------------------------------------------ nowa poczta */
function job_new_mail(callable $log, array &$stats): void {
    if (!google_offline_ready()) return;
    $from = strtolower(mail_from()[0]);
    foreach (qall("SELECT u.*, g.scope FROM users u JOIN google_tokens g ON g.user_id = u.id WHERE g.refresh_enc IS NOT NULL AND g.refresh_enc <> ''") as $u) {
        $prefs = user_prefs($u);
        if (!$prefs['newMail'] || !str_contains((string) $u['scope'], SCOPE_GMAIL)) continue;
        $wsId = user_ws((int) $u['id']);
        if (!$wsId) continue;
        try {
            $access = google_access_token((int) $u['id'])['access_token'];
            $ids = gmail_unread($access, 25)['ids'];
            $seenRaw = kv_get($wsId, (int) $u['id'], 'srv:gmail_seen');
            $seen = $seenRaw ? (json_decode($seenRaw, true) ?: []) : null;
            $new = $seen === null ? [] : array_values(array_diff($ids, $seen));
            kv_put($wsId, (int) $u['id'], 'srv:gmail_seen', json_encode(array_slice(array_values(array_unique(array_merge($ids, $seen ?? []))), 0, 300)));
            if (!$new) continue;
            $items = [];
            foreach (array_slice($new, 0, 10) as $id) {
                $m = gmail_meta($access, $id);
                if ($m['fromEmail'] !== $from) $items[] = $m;
            }
            if (!$items) continue;
            $n = count($new);
            $subject = $n === 1 ? "Nowa wiadomość: {$items[0]['subject']}" : "Masz $n nowe wiadomości w skrzynce";
            send_mail(recipient($u, $prefs), mb_substr($subject, 0, 150), mail_layout($n === 1 ? 'Nowa wiadomość w skrzynce' : "Nowe wiadomości: $n",
                '<p>Do skrzynki ' . h((string) q1('SELECT email FROM google_tokens WHERE user_id = ?', [(int) $u['id']])['email']) . ' przyszły nowe wiadomości:</p>' . mail_rows($items)
                . ($n > count($items) ? '<p style="color:#64748b">…i ' . ($n - count($items)) . ' więcej.</p>' : '')
                . '<p style="color:#64748b;font-size:13px">Odpowiedzi przygotujesz w Skrzynce AI – sam wybierasz, które wiadomości przeanalizować.</p>',
                'Otwórz Skrzynkę AI', app_url() . '?open=inbox'));
            $stats['mail']++;
        } catch (Throwable $e) { $stats['errors']++; $log('Poczta użytkownika #' . $u['id'] . ': ' . $e->getMessage()); }
    }
}

/* ------------------------------------------------------------------ rejestr umów */
function iso_ok(string $s): bool { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false; }
function ct_events(array $c): array {
    $out = [];
    $notice = null;
    if (iso_ok((string) ($c['ostatniDzienWypowiedzenia'] ?? ''))) $notice = $c['ostatniDzienWypowiedzenia'];
    elseif (iso_ok((string) ($c['dataZakonczenia'] ?? '')) && ((int) ($c['wypowiedzenieMiesiace'] ?? 0) || (int) ($c['wypowiedzenieDni'] ?? 0))) {
        $d = new DateTime($c['dataZakonczenia'] . ' 12:00:00');
        if ((int) ($c['wypowiedzenieMiesiace'] ?? 0)) {
            $day = (int) $d->format('j'); $d->modify('first day of this month')->modify('-' . (int) $c['wypowiedzenieMiesiace'] . ' months');
            $d->setDate((int) $d->format('Y'), (int) $d->format('n'), min($day, (int) $d->format('t')));
        }
        if ((int) ($c['wypowiedzenieDni'] ?? 0)) $d->modify('-' . (int) $c['wypowiedzenieDni'] . ' days');
        $notice = $d->format('Y-m-d');
    }
    if ($notice) $out[] = ['kind' => 'notice', 'date' => $notice, 'label' => 'Ostatni dzień na wypowiedzenie'];
    if (iso_ok((string) ($c['dataZakonczenia'] ?? ''))) $out[] = ['kind' => 'end', 'date' => $c['dataZakonczenia'], 'label' => !empty($c['automatycznePrzedluzenie']) ? 'Automatyczne przedłużenie' : 'Koniec umowy'];
    if (iso_ok((string) ($c['dataWaloryzacji'] ?? ''))) $out[] = ['kind' => 'index', 'date' => $c['dataWaloryzacji'], 'label' => 'Waloryzacja'];
    return $out;
}
/** Terminy umów firmy w najbliższych dniach: [ [contract, event, days], ... ] */
function ws_contract_events(int $wsId, int $horizon = 0): array {
    $today = new DateTime('today');
    $out = [];
    foreach (qall("SELECT v FROM kv WHERE ws_id = ? AND user_id = 0 AND deleted = 0 AND k LIKE 'idb:contracts:%'", [$wsId]) as $r) {
        $c = json_decode((string) $r['v'], true);
        if (!is_array($c) || empty($c['id'])) continue;
        $remind = (int) ($c['przypomnij'] ?? 60) ?: 60;
        foreach (ct_events($c) as $e) {
            $days = (int) $today->diff(new DateTime($e['date']))->format('%r%a');
            if ($days < 0 || $days > ($horizon ?: $remind)) continue;
            $out[] = ['c' => $c, 'e' => $e, 'days' => $days, 'remind' => $remind];
        }
    }
    usort($out, fn($a, $b) => $a['days'] <=> $b['days']);
    return $out;
}
function job_contracts(callable $log, array &$stats): void {
    foreach (qall("SELECT DISTINCT ws_id FROM kv WHERE user_id = 0 AND deleted = 0 AND k LIKE 'idb:contracts:%'") as $w) {
        $wsId = (int) $w['ws_id'];
        $ws = q1('SELECT * FROM workspaces WHERE id = ?', [$wsId]);
        if (!$ws) continue;
        $due = [];
        foreach (ws_contract_events($wsId) as $x) {
            $steps = array_values(array_unique(array_filter([$x['remind'], 30, 14, 7, 3, 1, 0], fn($t) => $t <= $x['remind'])));
            sort($steps);
            $step = null;
            foreach ($steps as $t) if ($x['days'] <= $t) { $step = $t; break; }
            if ($step === null) continue;
            $key = "ct:$wsId:{$x['c']['id']}:{$x['e']['kind']}:{$x['e']['date']}:$step";
            if (notice_sent($key)) continue;
            $x['key'] = $key;
            $due[] = $x;
        }
        if (!$due) continue;
        $rows = '';
        foreach ($due as $x) {
            $name = trim((string) ($x['c']['nazwa'] ?? '')) ?: 'Umowa';
            $who = trim((string) ($x['c']['kontrahent'] ?? ''));
            $color = $x['days'] <= 14 ? '#b91c1c' : '#b45309';
            $rows .= '<tr><td style="padding:9px 0;border-top:1px solid #eef2f7"><b>' . h($name) . '</b>' . ($who ? ' – ' . h($who) : '')
                . '<br><span style="color:' . $color . '">' . h($x['e']['label']) . ': ' . pl_date($x['e']['date']) . ' (' . days_word($x['days']) . ')</span>'
                . (!empty($x['c']['formaWypowiedzenia']) && $x['e']['kind'] === 'notice' ? '<br><span style="color:#64748b;font-size:13px">Forma wypowiedzenia: ' . h((string) $x['c']['formaWypowiedzenia']) . '</span>' : '')
                . (!empty($x['c']['opiekun']) ? '<br><span style="color:#64748b;font-size:13px">Odpowiada: ' . h((string) $x['c']['opiekun']) . '</span>' : '') . '</td></tr>';
        }
        $html = '<p>Zbliżają się terminy z rejestru umów firmy <b>' . h($ws['name']) . '</b>:</p><table style="width:100%;border-collapse:collapse;font-size:14px">' . $rows . '</table>';
        $sent = 0;
        foreach (qall('SELECT u.*, m.role FROM members m JOIN users u ON u.id = m.user_id WHERE m.ws_id = ?', [$wsId]) as $u) {
            $prefs = user_prefs($u, $u['role']);
            if (!$prefs['contracts']) continue;
            try {
                send_mail(recipient($u, $prefs), count($due) === 1 ? 'Umowa: ' . mb_substr((string) ($due[0]['c']['nazwa'] ?: $due[0]['c']['kontrahent'] ?? ''), 0, 80) . ' – ' . mb_strtolower($due[0]['e']['label']) . ' ' . days_word($due[0]['days']) : 'Rejestr umów: ' . count($due) . ' zbliżające się terminy',
                    mail_layout('Przypomnienie z rejestru umów', $html, 'Otwórz rejestr umów', app_url() . '?open=contracts'));
                $sent++;
            } catch (Throwable $e) { $stats['errors']++; $log('Umowy (firma #' . $wsId . '): ' . $e->getMessage()); }
        }
        foreach ($due as $x) notice_mark($x['key']);
        $stats['contracts'] += $sent;
    }
}

/* ------------------------------------------------------------------ poranne podsumowanie */
function job_digest(callable $log, array &$stats): void {
    $today = date('Y-m-d');
    $hour = (int) date('G');
    $dow = (int) date('N');
    foreach (qall('SELECT * FROM users') as $u) {
        $prefs = user_prefs($u);
        if (!$prefs['digest'] || $hour < (int) $prefs['digestHour'] || $hour > 14 || $dow > 5) continue;
        $key = "dg:{$u['id']}:$today";
        if (notice_sent($key)) continue;
        notice_mark($key);
        try {
            $parts = [];
            $g = google_token_row((int) $u['id']);
            if ($g && $g['refresh_enc'] && google_offline_ready() && str_contains((string) $g['scope'], SCOPE_GMAIL)) {
                try {
                    $access = google_access_token((int) $u['id'])['access_token'];
                    $un = gmail_unread($access, 8);
                    if ($un['ids']) {
                        $items = array_map(fn($id) => gmail_meta($access, $id), $un['ids']);
                        $parts[] = '<h2 style="font-size:16px;margin:18px 0 6px">Poczta (' . h($g['email']) . ')</h2><p>Nieprzeczytane z ostatniej doby: <b>' . max(count($un['ids']), $un['estimate']) . '</b></p>' . mail_rows($items);
                    } else $parts[] = '<h2 style="font-size:16px;margin:18px 0 6px">Poczta</h2><p>Brak nieprzeczytanych wiadomości z ostatniej doby.</p>';
                } catch (Throwable $e) { $parts[] = '<p style="color:#b45309">Nie udało się sprawdzić poczty: ' . h($e->getMessage()) . '</p>'; }
            }
            $rows = '';
            foreach (qall('SELECT ws_id FROM members WHERE user_id = ?', [(int) $u['id']]) as $m) {
                foreach (ws_contract_events((int) $m['ws_id'], 30) as $x) {
                    $rows .= '<li>' . h(trim((string) ($x['c']['nazwa'] ?? '')) ?: 'Umowa') . (!empty($x['c']['kontrahent']) ? ' – ' . h((string) $x['c']['kontrahent']) : '') . ': ' . h(mb_strtolower($x['e']['label'])) . ' ' . pl_date($x['e']['date']) . ' (' . days_word($x['days']) . ')</li>';
                }
            }
            if ($rows) $parts[] = '<h2 style="font-size:16px;margin:18px 0 6px">Terminy umów w ciągu 30 dni</h2><ul style="padding-left:18px">' . $rows . '</ul>';
            $y = q1('SELECT SUM(requests) AS r FROM usage_daily WHERE user_id = ? AND day = ?', [(int) $u['id'], date('Y-m-d', strtotime('-1 day'))]);
            if ((int) ($y['r'] ?? 0)) $parts[] = '<p style="color:#64748b">Wczoraj skorzystałeś(-aś) z AI ' . (int) $y['r'] . ' razy.</p>';
            if (!$parts) $parts[] = '<p>Brak pilnych spraw na dziś – miłego dnia!</p>';
            $first = explode(' ', trim((string) $u['name']))[0] ?: '';
            send_mail(recipient($u, $prefs), 'Twój dzień w PaperlyAI – ' . date('d.m'), mail_layout('Dzień dobry' . ($first ? ", $first" : '') . '!', implode('', $parts), 'Otwórz PaperlyAI', app_url()));
            $stats['digest']++;
        } catch (Throwable $e) { $stats['errors']++; $log('Podsumowanie użytkownika #' . $u['id'] . ': ' . $e->getMessage()); }
    }
}

/* ------------------------------------------------------------------ sprzątanie */
function job_cleanup(callable $log, array &$stats): void {
    q('DELETE FROM sessions WHERE expires_at < ?', [time()]);
    q('DELETE FROM uploads WHERE created_at < ?', [time() - 2 * 86400]);
    q('DELETE FROM invites WHERE expires_at < ?', [time() - 30 * 86400]);
    q('DELETE FROM notices WHERE created_at < ?', [time() - 400 * 86400]);
    q('DELETE FROM rate_limits WHERE win < ?', [time() - 2 * 86400]);
    q('DELETE FROM ai_calls WHERE created_at < ?', [time() - 400 * 86400]);
    foreach ((array) @glob(rtrim(sys_get_temp_dir(), '/\\') . '/paperly_cancel_*') as $f) if (@filemtime($f) < time() - 3600) @unlink($f);
}
