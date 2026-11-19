<?php
/**
 * PaperlyAI – API serwera (JSON). Wywołanie: api/?a=<akcja>
 * Aplikacja działa także bez serwera (wszystko w przeglądarce) – ten plik dodaje konta firmowe,
 * wspólne dane zespołu, klucz AI na serwerze z limitami, Gmail bez ponownego logowania, e-maile, GUS i KSeF.
 */
declare(strict_types=1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/google.php';
require __DIR__ . '/lib/mailer.php';
require __DIR__ . '/lib/webpush.php';

header('X-Robots-Tag: noindex');

const SYNC_ITEM_MAX = 15 * 1024 * 1024;   // jeden element (np. dokument bazy wiedzy)
const SYNC_PULL_BUDGET = 8 * 1024 * 1024; // dane w jednej odpowiedzi

run_json(function () {
    $a = preg_replace('/[^a-z_]/', '', (string) ($_GET['a'] ?? 'health'));
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') require_same_origin();
    elseif ($method !== 'GET') fail(405, 'method', 'Niedozwolona metoda.');
    $post = $method === 'POST';

    switch ($a) {
        case 'health':
            json_out(health());
            // harmonogram hostingu nie działa? zadania (powiadomienia, przypomnienia) uruchamiają się w tle po odpowiedzi
            try { require_once __DIR__ . '/lib/jobs.php'; maybe_lazy_jobs(); } catch (Throwable $e) { /* bez zadań w tle */ }
            return;
        case 'me': json_out(me_payload(require_user())); return;
        case 'login': if (!$post) break; rate_limit('login', client_ip(), 20, 600); json_out(action_login()); return;
        case 'logout': if (!$post) break; if (db_configured()) end_session(); json_out(['ok' => true]); return;
        case 'invite_info': rate_limit('invite', client_ip(), 30, 600); json_out(invite_info(str_in($_GET, 'code', 40))); return;
        case 'join': if (!$post) break; rate_limit('invite', client_ip(), 30, 600); json_out(action_join(require_user(), str_in(body_json(), 'code', 40))); return;
        case 'workspace_create': if (!$post) break; json_out(action_workspace_create()); return;
        case 'workspace_switch': if (!$post) break; json_out(action_workspace_switch()); return;
        case 'workspace_update': if (!$post) break; json_out(action_workspace_update()); return;
        case 'members': json_out(action_members()); return;
        case 'member_update': if (!$post) break; json_out(action_member_update()); return;
        case 'invites': json_out(action_invites()); return;
        case 'invite_create': if (!$post) break; rate_limit('invite_create', 'u' . require_user()['user']['id'], 30, 3600); json_out(action_invite_create()); return;
        case 'invite_delete': if (!$post) break; json_out(action_invite_delete()); return;
        case 'usage': json_out(action_usage()); return;
        case 'ai_speed': json_out(action_ai_speed()); return;
        case 'ai_effects': json_out(action_ai_effects()); return;
        case 'ai_cancel': if (!$post) break; require_user(); ai_cancel_request(preg_replace('/[^a-f0-9]/', '', strtolower((string) (body_json()['id'] ?? '')))); json_out(['ok' => true]); return;
        case 'sync': json_out($post ? action_sync_push() : action_sync_pull()); return;
        case 'google_token': json_out(action_google_token()); return;
        case 'google_disconnect': if (!$post) break; json_out(action_google_disconnect()); return;
        case 'notify_prefs': if (!$post) break; json_out(action_notify_prefs()); return;
        case 'push_key': require_user(); json_out(['key' => vapid_public_b64()]); return;
        case 'push_subscribe': if (!$post) break; rate_limit('push', 'u' . require_user()['user']['id'], 30, 3600); json_out(action_push_subscribe()); return;
        case 'push_unsubscribe': if (!$post) break; json_out(action_push_unsubscribe()); return;
        case 'push_test': if (!$post) break; rate_limit('pushtest', 'u' . require_user()['user']['id'], 10, 3600); json_out(action_push_test()); return;
        case 'test_mail': if (!$post) break; rate_limit('mail', 'u' . require_user()['user']['id'], 5, 3600); json_out(action_test_mail()); return;
        case 'server': json_out(action_server_status()); return;
        case 'server_settings': if (!$post) break; json_out(action_server_settings()); return;
        case 'gemini_key': if (!$post) break; rate_limit('keycheck', 'u' . require_user()['user']['id'], 10, 600); json_out(action_gemini_key()); return;
        case 'company_gemini_key': if (!$post) break; rate_limit('keycheck', 'u' . require_user()['user']['id'], 10, 600); json_out(action_company_gemini_key()); return;
        case 'gus': rate_limit('gus', 'u' . require_user()['user']['id'], 60, 3600); json_out(action_gus()); return;
        case 'ksef_settings': if (!$post) break; json_out(action_ksef_settings()); return;
        case 'ksef_test': if (!$post) break; rate_limit('ksef', 'u' . require_user()['user']['id'], 30, 600); json_out(action_ksef_test()); return;
        case 'ksef_list': if (!$post) break; rate_limit('ksef', 'u' . require_user()['user']['id'], 30, 600); json_out(action_ksef_list()); return;
        case 'ksef_xml': json_out(action_ksef_xml()); return;
        case 'inv_profile': json_out(action_inv_profile($post)); return;
        case 'inv_preview': if (!$post) break; json_out(action_inv_preview()); return;
        case 'inv_issue': if (!$post) break; rate_limit('ksef_issue', 'u' . require_user()['user']['id'], 60, 3600); json_out(action_inv_issue()); return;
        case 'inv_status': if (!$post) break; rate_limit('ksef', 'u' . require_user()['user']['id'], 30, 600); json_out(action_inv_status()); return;
        case 'inv_list': json_out(action_inv_list()); return;
        case 'inv_xml': json_out(action_inv_xml()); return;
        case 'inv_paid': if (!$post) break; json_out(action_inv_paid()); return;
        case 'ksef_sales': if (!$post) break; rate_limit('ksef', 'u' . require_user()['user']['id'], 30, 600); json_out(action_ksef_sales()); return;
        case 'fetch_url': if (!$post) break; rate_limit('fetch', 'u' . require_user()['user']['id'], 40, 3600); json_out(action_fetch_url()); return;
    }
    fail(404, 'unknown', 'Nieznana akcja.');
});

/* ======================================================================== stan serwera */
function features(): array {
    $dbOk = false;
    if (db_configured()) { try { db(); $dbOk = true; } catch (Throwable $e) { $dbOk = false; } }
    return [
        'db' => $dbOk,
        'gemini' => $dbOk,
        'googleLogin' => $dbOk && (bool) cfg('GOOGLE_CLIENT_ID'),
        'gmailOffline' => $dbOk && google_offline_ready(),
        'mail' => $dbOk && mail_configured(),
        'gus' => $dbOk,
        'ksef' => $dbOk,
        'fetchUrl' => $dbOk && function_exists('curl_init'),
        'push' => $dbOk && push_supported(),
    ];
}
function health(): array {
    $f = features();
    $out = ['ok' => true, 'app' => 'paperly', 'version' => PAPERLY_VERSION, 'features' => $f, 'googleClientId' => (string) cfg('GOOGLE_CLIENT_ID', ''), 'msClientId' => (string) cfg('MS_CLIENT_ID', ''), 'uploadMax' => upload_max_bytes()];
    if ($f['db']) remember_public_url();
    if ($f['db'] && ($s = current_session())) $out['me'] = me_payload($s);
    if (db_configured() && !$f['db']) $out['dbError'] = 'Nie udało się połączyć z bazą danych – ' . ($GLOBALS['paperly_db_hint'] ?? 'sprawdź sekrety DB_HOST, DB_NAME, DB_USER i DB_PASS.');
    return $out;
}

function me_payload(array $s): array {
    $u = $s['user']; $ws = $s['ws'];
    $g = google_token_row((int) $u['id']);
    $list = qall('SELECT w.id, w.name, w.type, m.role FROM members m JOIN workspaces w ON w.id = m.ws_id WHERE m.user_id = ? ORDER BY m.created_at', [(int) $u['id']]);
    $out = [
        'user' => ['id' => (int) $u['id'], 'email' => $u['email'], 'name' => $u['name'], 'picture' => $u['picture'], 'isAdmin' => (bool) (int) $u['is_admin']],
        'workspace' => null,
        'workspaces' => array_map(fn($w) => ['id' => (int) $w['id'], 'name' => $w['name'], 'type' => $w['type'], 'role' => $w['role']], $list),
        'google' => ['connected' => (bool) ($g && $g['refresh_enc']), 'email' => $g['email'] ?? '', 'gmail' => google_has_scope($g, SCOPE_GMAIL), 'calendar' => google_has_scope($g, SCOPE_CALENDAR), 'error' => $g['last_error'] ?? ''],
        'prefs' => user_prefs($u, $s['role']),
        'canCreate' => can_create_workspace($u),
    ];
    if ($ws) {
        $set = ws_settings($ws);
        $out['workspace'] = [
            'id' => (int) $ws['id'], 'name' => $ws['name'], 'type' => $ws['type'], 'nip' => $ws['nip'], 'role' => $s['role'],
            'dailyLimit' => (int) $ws['daily_limit'],
            'ksef' => ['configured' => !empty($set['ksefToken']), 'env' => $set['ksefEnv'] ?? 'prod', 'nip' => $set['ksefNip'] ?? $ws['nip']],
        ];
        [$k, $src] = resolve_gemini_key($s);
        $own = user_gemini_key((int) $u['id']);
        $out['ai'] = ['source' => $src, 'own' => $own !== '', 'ownMask' => $own !== '' ? mask_key($own) : '', 'company' => !empty($set['geminiKey']), 'companyMask' => !empty($set['geminiKey']) ? mask_key((string) decrypt_str($set['geminiKey'])) : ''];
        $out['usage'] = ['today' => usage_today((int) $u['id'], (int) $ws['id'])['requests'], 'limit' => in_array($src, ['company', 'operator'], true) ? member_limit($s) : 0];
    }
    return $out;
}
function can_create_workspace(array $u): bool {
    if ((int) $u['is_admin']) return true;
    return (bool) server_settings()['signupOpen'];
}

/* ======================================================================== logowanie */
function action_login(): array {
    $b = body_json();
    $info = google_verify_access_token(str_in($b, 'access_token', 4096));
    $now = time();
    $u = q1('SELECT * FROM users WHERE sub = ?', [$info['sub']]);
    if ($u) {
        q('UPDATE users SET email = ?, name = ?, picture = ?, last_login = ? WHERE id = ?', [$info['email'], mb_substr($info['name'], 0, 190), mb_substr($info['picture'], 0, 500), $now, (int) $u['id']]);
    } else {
        $first = (int) (q1('SELECT COUNT(*) AS n FROM users')['n'] ?? 0) === 0;
        q('INSERT INTO users (sub, email, name, picture, is_admin, prefs, created_at, last_login) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$info['sub'], $info['email'], mb_substr($info['name'], 0, 190), mb_substr($info['picture'], 0, 500), $first ? 1 : 0, '{}', $now, $now]);
    }
    $u = q1('SELECT * FROM users WHERE sub = ?', [$info['sub']]);
    $m = q1('SELECT ws_id FROM members WHERE user_id = ? ORDER BY created_at DESC LIMIT 1', [(int) $u['id']]);
    create_session((int) $u['id'], (int) ($m['ws_id'] ?? 0));
    $code = str_in($b, 'invite', 40);
    $joined = null;
    if ($code !== '') {
        try { $joined = action_join(['user' => $u], $code, true); } catch (ApiError $e) { $joined = ['error' => $e->getMessage()]; }
    }
    $s = fresh_session((int) $u['id']);
    return ['ok' => true, 'me' => me_payload($s), 'givenName' => $info['givenName'], 'invite' => $joined];
}
/** Sesja po zmianach w tym samym zapytaniu (np. tuż po zalogowaniu). */
function fresh_session(int $userId, int $wsId = 0): array {
    $u = q1('SELECT * FROM users WHERE id = ?', [$userId]);
    $m = $wsId ? q1('SELECT * FROM members WHERE ws_id = ? AND user_id = ?', [$wsId, $userId]) : q1('SELECT * FROM members WHERE user_id = ? ORDER BY created_at DESC LIMIT 1', [$userId]);
    $ws = $m ? q1('SELECT * FROM workspaces WHERE id = ?', [(int) $m['ws_id']]) : null;
    return ['user' => $u, 'ws' => $ws, 'member' => $ws ? $m : null, 'role' => $ws ? $m['role'] : null, 'sid' => ''];
}
function set_session_ws(int $wsId): void {
    $t = session_token();
    if ($t !== '') q('UPDATE sessions SET ws_id = ? WHERE id = ?', [$wsId, hash('sha256', $t)]);
}

/* ======================================================================== firmy i zespół */
function action_workspace_create(): array {
    $s = require_user();
    $u = $s['user'];
    if (!can_create_workspace($u)) fail(403, 'signup_closed', 'Na tym serwerze nowe konta zakładają tylko osoby zaproszone – poproś firmę o link zaproszenia.');
    $b = body_json();
    $type = str_in($b, 'type', 16) === 'personal' ? 'personal' : 'company';
    $name = str_in($b, 'name', 190);
    $nip = preg_replace('/\D/', '', str_in($b, 'nip', 20));
    if ($type === 'company' && $nip !== '' && !valid_nip($nip)) fail(400, 'bad_nip', 'Nieprawidłowy NIP (zła suma kontrolna).');
    if ($type === 'personal') { $name = $name ?: $u['name']; $nip = ''; }
    if ($name === '') $name = $type === 'company' ? 'Moja firma' : $u['name'];
    $now = time();
    q('INSERT INTO workspaces (name, type, nip, owner_id, daily_limit, settings, created_at) VALUES (?, ?, ?, ?, 0, ?, ?)', [$name, $type, $nip, (int) $u['id'], '{}', $now]);
    $wsId = (int) db()->lastInsertId();
    q('INSERT INTO members (ws_id, user_id, role, daily_limit, created_at) VALUES (?, ?, ?, 0, ?)', [$wsId, (int) $u['id'], 'owner', $now]);
    set_session_ws($wsId);
    return ['ok' => true, 'me' => me_payload(fresh_session((int) $u['id'], $wsId))];
}
function action_workspace_switch(): array {
    $s = require_user();
    $id = (int) (body_json()['id'] ?? 0);
    if (!q1('SELECT 1 AS x FROM members WHERE ws_id = ? AND user_id = ?', [$id, (int) $s['user']['id']])) fail(404, 'not_member', 'Nie należysz do tego zespołu.');
    set_session_ws($id);
    return ['ok' => true, 'me' => me_payload(fresh_session((int) $s['user']['id'], $id))];
}
function action_workspace_update(): array {
    $s = require_manager();
    $b = body_json();
    $ws = $s['ws'];
    $name = array_key_exists('name', $b) ? str_in($b, 'name', 190) : $ws['name'];
    $nip = array_key_exists('nip', $b) ? preg_replace('/\D/', '', str_in($b, 'nip', 20)) : $ws['nip'];
    if ($nip !== '' && !valid_nip($nip)) fail(400, 'bad_nip', 'Nieprawidłowy NIP (zła suma kontrolna).');
    $type = array_key_exists('type', $b) ? (str_in($b, 'type', 16) === 'personal' ? 'personal' : 'company') : $ws['type'];
    if ($type !== $ws['type'] && $s['role'] !== 'owner') fail(403, 'forbidden', 'Typ konta może zmienić tylko właściciel.');
    $limit = array_key_exists('dailyLimit', $b) ? max(0, min(100000, (int) $b['dailyLimit'])) : (int) $ws['daily_limit'];
    q('UPDATE workspaces SET name = ?, nip = ?, type = ?, daily_limit = ? WHERE id = ?', [$name ?: $ws['name'], $nip, $type, $limit, (int) $ws['id']]);
    return ['ok' => true, 'me' => me_payload(fresh_session((int) $s['user']['id'], (int) $ws['id']))];
}
function action_members(): array {
    $s = require_ws();
    $wsId = (int) $s['ws']['id'];
    $since = date('Y-m-d', strtotime('-29 days'));
    $rows = qall('SELECT u.id, u.name, u.email, u.picture, m.role, m.daily_limit, m.created_at FROM members m JOIN users u ON u.id = m.user_id WHERE m.ws_id = ? ORDER BY m.created_at', [$wsId]);
    $use = [];
    foreach (qall('SELECT user_id, day, requests, tokens_in, tokens_out FROM usage_daily WHERE ws_id = ? AND day >= ?', [$wsId, $since]) as $r) {
        $x = &$use[(int) $r['user_id']];
        $x = $x ?? ['today' => 0, 'month' => 0, 'tokens' => 0];
        if ($r['day'] === date('Y-m-d')) $x['today'] += (int) $r['requests'];
        $x['month'] += (int) $r['requests'];
        $x['tokens'] += (int) $r['tokens_in'] + (int) $r['tokens_out'];
        unset($x);
    }
    $default = !empty($s['ws']['daily_limit']) ? (int) $s['ws']['daily_limit'] : (int) server_settings()['defaultLimit'];
    $out = [];
    foreach ($rows as $r) {
        $u = $use[(int) $r['id']] ?? ['today' => 0, 'month' => 0, 'tokens' => 0];
        $out[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'email' => is_manager($s) || (int) $r['id'] === (int) $s['user']['id'] ? $r['email'] : '', 'picture' => $r['picture'],
            'role' => $r['role'], 'dailyLimit' => (int) $r['daily_limit'], 'effectiveLimit' => (int) $r['daily_limit'] ?: $default,
            'today' => $u['today'], 'month' => $u['month'], 'tokens' => $u['tokens'], 'me' => (int) $r['id'] === (int) $s['user']['id']];
    }
    return ['members' => $out, 'defaultLimit' => $default, 'serverDefault' => (int) server_settings()['defaultLimit']];
}
function action_member_update(): array {
    $s = require_manager();
    $b = body_json();
    $wsId = (int) $s['ws']['id'];
    $uid = (int) ($b['userId'] ?? 0);
    $m = q1('SELECT * FROM members WHERE ws_id = ? AND user_id = ?', [$wsId, $uid]);
    if (!$m) fail(404, 'not_member', 'Ta osoba nie należy do zespołu.');
    if ($m['role'] === 'owner' && $uid !== (int) $s['user']['id']) fail(403, 'forbidden', 'Nie można zmieniać konta właściciela.');
    if (!empty($b['remove'])) {
        if ($m['role'] === 'owner') fail(400, 'owner', 'Właściciel nie może usunąć siebie z firmy.');
        q('DELETE FROM members WHERE ws_id = ? AND user_id = ?', [$wsId, $uid]);
        q('DELETE FROM kv WHERE ws_id = ? AND user_id = ?', [$wsId, $uid]);
        q('UPDATE sessions SET ws_id = 0 WHERE user_id = ? AND ws_id = ?', [$uid, $wsId]);
        return ['ok' => true];
    }
    $role = $m['role'];
    if (isset($b['role']) && $m['role'] !== 'owner') $role = $b['role'] === 'tech' ? 'tech' : 'employee';
    $limit = array_key_exists('dailyLimit', $b) ? max(0, min(100000, (int) $b['dailyLimit'])) : (int) $m['daily_limit'];
    q('UPDATE members SET role = ?, daily_limit = ? WHERE ws_id = ? AND user_id = ?', [$role, $limit, $wsId, $uid]);
    return ['ok' => true];
}

/* ======================================================================== zaproszenia */
function invite_link(string $code): string { return app_url() . '?invite=' . $code; }
function action_invites(): array {
    $s = require_manager();
    $rows = qall('SELECT code, role, email, created_at, expires_at, used_by, used_at FROM invites WHERE ws_id = ? AND expires_at > ? ORDER BY created_at DESC', [(int) $s['ws']['id'], time()]);
    return ['invites' => array_values(array_filter(array_map(fn($r) => [
        'code' => $r['code'], 'role' => $r['role'], 'email' => $r['email'], 'link' => invite_link($r['code']),
        'expiresAt' => (int) $r['expires_at'] * 1000, 'used' => (int) $r['used_by'] > 0, 'multi' => $r['email'] === '',
    ], $rows), fn($r) => $r['multi'] || !$r['used']))];
}
function action_invite_create(): array {
    $s = require_manager();
    if ($s['ws']['type'] === 'personal') fail(400, 'personal', 'Do konta prywatnego nie można zapraszać innych osób.');
    $b = body_json();
    $role = ($b['role'] ?? '') === 'tech' ? 'tech' : 'employee';
    $email = mb_strtolower(str_in($b, 'email', 190));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail(400, 'bad_email', 'Nieprawidłowy adres e-mail.');
    $code = strtolower(bin2hex(random_bytes(10)));
    $now = time();
    q('INSERT INTO invites (code, ws_id, role, email, created_by, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$code, (int) $s['ws']['id'], $role, $email, (int) $s['user']['id'], $now, $now + ($email ? 14 : 30) * 86400]);
    $sent = false; $mailError = '';
    if ($email !== '' && !empty($b['send'])) {
        try {
            $who = $s['user']['name'] ?: $s['user']['email'];
            send_mail($email, "Zaproszenie do zespołu {$s['ws']['name']} w PaperlyAI", mail_layout(
                'Zaproszenie do PaperlyAI',
                '<p>' . htmlspecialchars($who) . ' zaprasza Cię do zespołu <b>' . htmlspecialchars($s['ws']['name']) . '</b> w PaperlyAI – asystencie AI do pracy biurowej (odpowiedzi na maile, pisma, faktury, umowy).</p><p>Kliknij przycisk i zaloguj się przez Google kontem <b>' . htmlspecialchars($email) . '</b>. Link jest ważny 14 dni.</p>',
                'Dołącz do zespołu', invite_link($code)));
            $sent = true;
        } catch (Throwable $e) { $mailError = $e->getMessage(); }
    }
    return ['ok' => true, 'code' => $code, 'link' => invite_link($code), 'sent' => $sent, 'mailError' => $mailError];
}
function action_invite_delete(): array {
    $s = require_manager();
    q('DELETE FROM invites WHERE code = ? AND ws_id = ?', [str_in(body_json(), 'code', 40), (int) $s['ws']['id']]);
    return ['ok' => true];
}
function invite_row(string $code): array {
    if (!preg_match('/^[a-f0-9]{20}$/', $code)) fail(404, 'invite', 'Nieprawidłowy link zaproszenia.');
    $inv = q1('SELECT i.*, w.name AS ws_name, w.type AS ws_type FROM invites i JOIN workspaces w ON w.id = i.ws_id WHERE i.code = ?', [$code]);
    if (!$inv || (int) $inv['expires_at'] < time()) fail(404, 'invite', 'Zaproszenie wygasło lub zostało usunięte – poproś o nowy link.');
    if ($inv['email'] !== '' && (int) $inv['used_by'] > 0) fail(410, 'invite_used', 'To zaproszenie zostało już wykorzystane.');
    return $inv;
}
function invite_info(string $code): array {
    $inv = invite_row($code);
    return ['workspace' => $inv['ws_name'], 'role' => $inv['role'], 'email' => $inv['email'] !== '' ? preg_replace('/(?<=.).(?=[^@]*@)/u', '•', $inv['email']) : ''];
}
function action_join(array $s, string $code, bool $quiet = false): array {
    $inv = invite_row($code);
    $u = $s['user'];
    if ($inv['email'] !== '' && mb_strtolower($inv['email']) !== mb_strtolower($u['email'])) fail(403, 'invite_email', 'To zaproszenie jest dla innego adresu e-mail – zaloguj się kontem Google, na które przyszło zaproszenie.');
    $wsId = (int) $inv['ws_id'];
    $existing = q1('SELECT role FROM members WHERE ws_id = ? AND user_id = ?', [$wsId, (int) $u['id']]);
    if (!$existing) q('INSERT INTO members (ws_id, user_id, role, daily_limit, created_at) VALUES (?, ?, ?, 0, ?)', [$wsId, (int) $u['id'], $inv['role'], time()]);
    q('UPDATE invites SET used_by = ?, used_at = ? WHERE code = ?', [(int) $u['id'], time(), $inv['code']]);
    set_session_ws($wsId);
    $out = ['ok' => true, 'workspace' => $inv['ws_name']];
    if (!$quiet) $out['me'] = me_payload(fresh_session((int) $u['id'], $wsId));
    return $out;
}

/* ======================================================================== zużycie AI */
function action_usage(): array {
    $s = require_manager();
    $since = date('Y-m-d', strtotime('-29 days'));
    $days = qall('SELECT day, SUM(requests) AS requests, SUM(tokens_in) AS tin, SUM(tokens_out) AS tout FROM usage_daily WHERE ws_id = ? AND day >= ? GROUP BY day ORDER BY day', [(int) $s['ws']['id'], $since]);
    return ['days' => array_map(fn($d) => ['day' => $d['day'], 'requests' => (int) $d['requests'], 'tokens' => (int) $d['tin'] + (int) $d['tout']], $days)];
}

/* ======================================================================== szybkość modeli i efekty AI */
/** Szybkość modeli w całej firmie: mediana czasu do pierwszego słowa z ostatnich godzin (bez treści zapytań). */
function action_ai_speed(): array {
    $s = require_ws();
    $wsId = (int) $s['ws']['id'];
    $out = [];
    foreach ([3 * 3600, 24 * 3600] as $win) {
        $rows = qall('SELECT model, status, ttfb_ms, total_ms, created_at FROM ai_calls WHERE ws_id = ? AND created_at >= ? AND model <> \'\' ORDER BY id DESC LIMIT 400', [$wsId, time() - $win]);
        $by = [];
        foreach ($rows as $r) $by[$r['model']][] = $r;
        foreach ($by as $model => $list) {
            if (isset($out[$model]) || ($win < 86400 && count($list) < 2)) continue;
            $ok = array_values(array_filter($list, fn($r) => (int) $r['status'] === 200 && (int) $r['ttfb_ms'] > 0));
            $t = array_map(fn($r) => (int) $r['ttfb_ms'], $ok); sort($t);
            $slow = array_values(array_filter($list, fn($r) => (int) $r['status'] === 499));
            $fail = array_values(array_filter($list, fn($r) => (int) $r['status'] !== 200 && (int) $r['status'] !== 499 && (int) $r['status'] !== 400));
            $out[$model] = [
                'model' => $model, 'n' => count($list), 'ok' => count($ok), 'slow' => count($slow), 'fail' => count($fail),
                'median' => $t ? $t[intdiv(count($t), 2)] : 0, 'p90' => $t ? $t[min(count($t) - 1, (int) floor(count($t) * 0.9))] : 0,
                'slowMin' => $slow ? (int) round(array_sum(array_map(fn($r) => (int) $r['total_ms'], $slow)) / count($slow)) : 0,
                'last' => (int) $list[0]['created_at'] * 1000, 'windowH' => $win / 3600,
            ];
        }
    }
    return ['models' => array_values($out), 'at' => time() * 1000];
}

/** Efekty AI w miesiącu: udane wyniki według narzędzia (cała firma dla właściciela/technika, własne dla pracownika). */
function action_ai_effects(): array {
    $s = require_ws();
    $wsId = (int) $s['ws']['id']; $uid = (int) $s['user']['id'];
    $month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : date('Y-m');
    $all = is_manager($s) && ($_GET['scope'] ?? '') !== 'me';
    $where = 'ws_id = ? AND day LIKE ? AND status = 200 AND ttfb_ms > 0' . ($all ? '' : ' AND user_id = ?');
    $args = $all ? [$wsId, $month . '-%'] : [$wsId, $month . '-%', $uid];
    $tools = qall("SELECT tool, COUNT(*) AS n FROM ai_calls WHERE $where GROUP BY tool", $args);
    $people = $all ? qall("SELECT a.user_id, u.name, u.email, COUNT(*) AS n FROM ai_calls a LEFT JOIN users u ON u.id = a.user_id WHERE a.ws_id = ? AND a.day LIKE ? AND a.status = 200 AND a.ttfb_ms > 0 GROUP BY a.user_id, u.name, u.email ORDER BY n DESC", [$wsId, $month . '-%']) : [];
    $since = qall("SELECT MIN(day) AS d FROM ai_calls WHERE ws_id = ?", [$wsId]);
    return [
        'month' => $month, 'scope' => $all ? 'company' : 'me', 'since' => (string) ($since[0]['d'] ?? ''),
        'tools' => array_map(fn($r) => ['tool' => (string) $r['tool'], 'n' => (int) $r['n']], $tools),
        'people' => array_map(fn($r) => ['name' => (string) ($r['name'] ?: $r['email'] ?: 'Osoba'), 'n' => (int) $r['n'], 'me' => (int) $r['user_id'] === $uid], $people),
    ];
}

/* ======================================================================== synchronizacja danych */
function sync_key_ok(string $k): bool { return (bool) preg_match('/^[A-Za-z0-9_:.\-]{1,190}$/', $k); }
function action_sync_pull(): array {
    $s = require_ws();
    $wsId = (int) $s['ws']['id']; $uid = (int) $s['user']['id'];
    $since = max(0, (int) ($_GET['since'] ?? 0));
    $len = is_sqlite() ? 'LENGTH(v)' : 'OCTET_LENGTH(v)';
    $heads = qall("SELECT id, user_id, k, deleted, updated_at, $len AS len FROM kv WHERE ws_id = ? AND (user_id = 0 OR user_id = ?) AND id > ? ORDER BY id LIMIT 500", [$wsId, $uid, $since]);
    $items = []; $budget = 0; $rev = $since; $more = count($heads) === 500;
    foreach ($heads as $h) {
        $size = (int) $h['len'];
        if ($items && $budget + $size > SYNC_PULL_BUDGET) { $more = true; break; }
        $budget += $size;
        $v = (int) $h['deleted'] ? null : (q1('SELECT v FROM kv WHERE id = ?', [(int) $h['id']])['v'] ?? null);
        $items[] = ['id' => (int) $h['id'], 'scope' => (int) $h['user_id'] ? 'user' : 'ws', 'k' => $h['k'], 'v' => $v, 'deleted' => (bool) (int) $h['deleted'], 'at' => (int) $h['updated_at']];
        $rev = (int) $h['id'];
    }
    return ['rev' => $rev, 'items' => $items, 'more' => $more, 'wsId' => $wsId, 'userId' => $uid];
}
function action_sync_push(): array {
    $s = require_ws();
    $wsId = (int) $s['ws']['id']; $uid = (int) $s['user']['id'];
    $manager = is_manager($s);
    $items = body_json()['items'] ?? [];
    if (!is_array($items) || count($items) > 300) fail(400, 'bad_items', 'Nieprawidłowe dane synchronizacji.');
    $done = []; $skipped = [];
    $now = time();
    $sql = (is_sqlite() ? 'INSERT OR REPLACE' : 'REPLACE') . ' INTO kv (ws_id, user_id, k, v, deleted, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)';
    foreach ($items as $it) {
        $k = is_array($it) ? (string) ($it['k'] ?? '') : '';
        $scope = ($it['scope'] ?? '') === 'user' ? 'user' : 'ws';
        if (!sync_key_ok($k) || str_starts_with($k, 'srv:')) { $skipped[] = $k; continue; }
        if ($scope === 'ws' && !$manager && !str_starts_with($k, 'idb:contracts:')) { $skipped[] = $k; continue; }
        $deleted = !empty($it['deleted']);
        $v = $deleted ? null : (is_string($it['v'] ?? null) ? $it['v'] : null);
        if (!$deleted && $v === null) { $skipped[] = $k; continue; }
        if ($v !== null && strlen($v) > SYNC_ITEM_MAX) { $skipped[] = $k; continue; }
        q($sql, [$wsId, $scope === 'user' ? $uid : 0, $k, $v, $deleted ? 1 : 0, $now, $uid]);
        $done[$scope . '|' . $k] = (int) db()->lastInsertId();
    }
    return ['ok' => true, 'ids' => $done, 'skipped' => $skipped];
}

/* ======================================================================== Gmail bez ponownego logowania */
function action_google_token(): array {
    $s = require_user();
    $t = google_access_token((int) $s['user']['id']);
    $scopes = preg_split('/\s+/', (string) $t['scope']);
    return ['access_token' => $t['access_token'], 'expires_in' => $t['expires_in'], 'email' => $t['email'], 'gmail' => in_array(SCOPE_GMAIL, $scopes, true), 'calendar' => in_array(SCOPE_CALENDAR, $scopes, true)];
}
function action_google_disconnect(): array {
    $s = require_user();
    $row = google_token_row((int) $s['user']['id']);
    if ($row && ($rt = decrypt_str($row['refresh_enc']))) google_revoke($rt);
    q('DELETE FROM google_tokens WHERE user_id = ?', [(int) $s['user']['id']]);
    q("DELETE FROM kv WHERE user_id = ? AND k LIKE 'srv:gmail%'", [(int) $s['user']['id']]);
    return ['ok' => true];
}

/* ======================================================================== powiadomienia e-mail */
function action_notify_prefs(): array {
    $s = require_user();
    $b = body_json();
    $p = user_prefs($s['user'], $s['role']);
    foreach (['newMail', 'contracts', 'digest', 'pushMail', 'pushInbox', 'pushInvoices', 'pushContracts'] as $k) if (array_key_exists($k, $b)) $p[$k] = (bool) $b[$k];
    if (array_key_exists('digestHour', $b)) $p['digestHour'] = max(5, min(12, (int) $b['digestHour']));
    if (array_key_exists('email', $b)) {
        $e = str_in($b, 'email', 190);
        if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) fail(400, 'bad_email', 'Nieprawidłowy adres e-mail.');
        $p['email'] = $e;
    }
    q('UPDATE users SET prefs = ? WHERE id = ?', [json_encode($p, JSON_UNESCAPED_UNICODE), (int) $s['user']['id']]);
    return ['ok' => true, 'prefs' => $p];
}
/* ======================================================================== powiadomienia na telefon (Web Push) */
function action_push_subscribe(): array {
    $s = require_user();
    $b = body_json();
    $endpoint = str_in($b, 'endpoint', 1000);
    $p256 = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($b['keys']['p256dh'] ?? ''));
    $auth = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($b['keys']['auth'] ?? ''));
    if (!push_endpoint_ok($endpoint)) fail(400, 'bad_endpoint', 'Ta przeglądarka używa nieobsługiwanej usługi powiadomień.');
    if (strlen(b64u_dec($p256)) !== 65 || strlen(b64u_dec($auth)) !== 16) fail(400, 'bad_keys', 'Nieprawidłowe klucze subskrypcji powiadomień.');
    $hash = hash('sha256', $endpoint);
    q('DELETE FROM push_subs WHERE endpoint_hash = ?', [$hash]);
    q('INSERT INTO push_subs (user_id, ws_id, endpoint_hash, endpoint, p256dh, auth, ua, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [(int) $s['user']['id'], (int) ($s['ws']['id'] ?? 0), $hash, $endpoint, $p256, $auth, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190), time()]);
    $n = (int) (q1('SELECT COUNT(*) AS n FROM push_subs WHERE user_id = ?', [(int) $s['user']['id']])['n'] ?? 0);
    return ['ok' => true, 'devices' => $n];
}
function action_push_unsubscribe(): array {
    $s = require_user();
    $endpoint = str_in(body_json(), 'endpoint', 1000);
    q('DELETE FROM push_subs WHERE endpoint_hash = ? AND user_id = ?', [hash('sha256', $endpoint), (int) $s['user']['id']]);
    return ['ok' => true, 'devices' => (int) (q1('SELECT COUNT(*) AS n FROM push_subs WHERE user_id = ?', [(int) $s['user']['id']])['n'] ?? 0)];
}
function action_push_test(): array {
    $s = require_user();
    $n = push_user((int) $s['user']['id'], ['title' => 'PaperlyAI – powiadomienia działają', 'body' => 'Tak będą wyglądać przypomnienia o nowej poczcie, gotowych odpowiedziach i terminach płatności.', 'url' => app_url(), 'tag' => 'test']);
    if (!$n) fail(502, 'push_failed', 'Nie udało się dostarczyć powiadomienia – włącz powiadomienia na tym urządzeniu jeszcze raz.');
    return ['ok' => true, 'delivered' => $n];
}

function action_test_mail(): array {
    $s = require_user();
    $to = user_prefs($s['user'])['email'] ?: $s['user']['email'];
    try {
        send_mail($to, 'Test powiadomień PaperlyAI', mail_layout('Powiadomienia działają', '<p>To jest wiadomość testowa. Jeśli ją widzisz, PaperlyAI może wysyłać Ci przypomnienia o umowach, nowej poczcie i poranne podsumowanie.</p><p>Nadawca: <b>' . htmlspecialchars(mail_from()[0]) . '</b> (' . mail_mode() . ').</p>', 'Otwórz PaperlyAI', app_url()));
    } catch (Throwable $e) { fail(502, 'mail_failed', 'Nie udało się wysłać wiadomości: ' . $e->getMessage()); }
    return ['ok' => true, 'to' => $to];
}

/* ======================================================================== administrator serwera */
function action_server_status(): array {
    $s = require_admin();
    $f = features();
    $missing = [];
    if (!cfg('GEMINI_API_KEY')) $missing[] = ['feature' => 'Klucz AI dla Twoich firm (opcjonalnie – inni użytkownicy płacą własnym kluczem)', 'secret' => 'GEMINI_API_KEY'];
    if (!cfg('GOOGLE_CLIENT_SECRET')) $missing[] = ['feature' => 'Gmail bez ponownego logowania', 'secret' => 'GOOGLE_CLIENT_SECRET'];
    if (!cfg('SMTP_HOST')) $missing[] = ['feature' => 'E-maile z własnej domeny (SMTP)', 'secret' => 'SMTP_HOST, SMTP_USER, SMTP_PASS'];
    if (!cfg('GUS_KEY')) $missing[] = ['feature' => 'Dane z rejestru REGON (bez klucza: wykaz VAT Ministerstwa Finansów)', 'secret' => 'GUS_KEY'];
    if (strlen((string) cfg('APP_SECRET', '')) < 16) $missing[] = ['feature' => 'Własny klucz szyfrowania (zalecane)', 'secret' => 'APP_SECRET'];
    return [
        'features' => $f, 'missing' => $missing, 'settings' => server_settings(),
        'cronUrl' => base_url() . app_path() . '/api/cron.php?key=' . cron_key(),
        'cronLast' => (int) meta_get('cron_last', '0') * 1000,
        'oauthRedirect' => google_redirect_uri(),
        'mail' => ['mode' => mail_mode(), 'from' => mail_from()[0]],
        'php' => PHP_VERSION, 'uploadMax' => upload_max_bytes(),
        'counts' => ['users' => (int) q1('SELECT COUNT(*) AS n FROM users')['n'], 'workspaces' => (int) q1('SELECT COUNT(*) AS n FROM workspaces')['n']],
    ];
}
function action_server_settings(): array {
    require_admin();
    $b = body_json();
    $cur = server_settings();
    if (array_key_exists('signupOpen', $b)) $cur['signupOpen'] = (bool) $b['signupOpen'];
    if (array_key_exists('defaultLimit', $b)) $cur['defaultLimit'] = max(0, min(100000, (int) $b['defaultLimit']));
    meta_set('settings', json_encode($cur));
    return ['ok' => true, 'settings' => $cur];
}

/* ======================================================================== klucze Gemini */
function action_gemini_key(): array {
    $s = require_user();
    $b = body_json();
    $uid = (int) $s['user']['id'];
    if (!empty($b['clear'])) { q('DELETE FROM user_secrets WHERE user_id = ?', [$uid]); return ['ok' => true, 'me' => me_payload(fresh_session($uid, (int) ($s['ws']['id'] ?? 0)))]; }
    $key = preg_replace('/\s+/', '', str_in($b, 'key', 300));
    $err = check_gemini_key($key);
    if ($err !== '') fail(400, 'bad_key', $err);
    q((is_sqlite() ? 'INSERT OR REPLACE' : 'REPLACE') . ' INTO user_secrets (user_id, gemini_enc, updated_at) VALUES (?, ?, ?)', [$uid, encrypt_str($key), time()]);
    return ['ok' => true, 'me' => me_payload(fresh_session($uid, (int) ($s['ws']['id'] ?? 0)))];
}
function action_company_gemini_key(): array {
    $s = require_manager();
    if ($s['ws']['type'] !== 'company') fail(400, 'personal', 'Wspólny klucz jest dostępny dla kont firmowych.');
    $b = body_json();
    $set = ws_settings($s['ws']);
    if (!empty($b['clear'])) unset($set['geminiKey']);
    else {
        $key = preg_replace('/\s+/', '', str_in($b, 'key', 300));
        $err = check_gemini_key($key);
        if ($err !== '') fail(400, 'bad_key', $err);
        $set['geminiKey'] = encrypt_str($key);
    }
    save_ws_settings((int) $s['ws']['id'], $set);
    return ['ok' => true, 'me' => me_payload(fresh_session((int) $s['user']['id'], (int) $s['ws']['id']))];
}

/* ======================================================================== GUS / REGON */
function action_gus(): array {
    require_user();
    require __DIR__ . '/lib/gus.php';
    $nip = preg_replace('/\D/', '', str_in($_GET, 'nip', 20));
    if (!valid_nip($nip)) fail(400, 'bad_nip', 'Nieprawidłowy NIP (zła suma kontrolna).');
    return ['ok' => true, 'company' => company_lookup($nip)];
}

/* ======================================================================== KSeF */
function require_company_ws(bool $manager): array {
    $s = $manager ? require_manager() : require_ws();
    if ($s['ws']['type'] !== 'company') fail(400, 'personal', 'KSeF jest dostępny dla kont firmowych.');
    require_once __DIR__ . '/lib/ksef.php';
    return $s;
}
function action_ksef_settings(): array {
    $s = require_company_ws(true);
    $b = body_json();
    $set = ws_settings($s['ws']);
    if (!empty($b['clear'])) { unset($set['ksefToken'], $set['ksefSession']); save_ws_settings((int) $s['ws']['id'], $set); return ['ok' => true]; }
    $env = str_in($b, 'env', 8);
    $set['ksefEnv'] = isset(KSEF_ENVS[$env]) ? $env : 'prod';
    $nip = preg_replace('/\D/', '', str_in($b, 'nip', 20));
    if (!valid_nip($nip)) fail(400, 'bad_nip', 'Podaj prawidłowy NIP firmy, w imieniu której pobierasz faktury.');
    $set['ksefNip'] = $nip;
    $token = str_in($b, 'token', 600);
    if ($token !== '') $set['ksefToken'] = encrypt_str($token);
    if (empty($set['ksefToken'])) fail(400, 'no_token', 'Wklej token KSeF (wygenerujesz go w Aplikacji Podatnika KSeF z uprawnieniem do przeglądania faktur, a do wystawiania – także do wystawiania faktur).');
    unset($set['ksefSession']);
    save_ws_settings((int) $s['ws']['id'], $set);
    return ['ok' => true];
}
function action_ksef_test(): array {
    $s = require_company_ws(true);
    try { [$env] = ksef_access_token($s['ws'], true); }
    catch (ApiError $e) { throw $e; }
    catch (Throwable $e) { fail(502, 'ksef', $e->getMessage()); }
    return ['ok' => true, 'env' => KSEF_ENVS[$env] ?? $env];
}
function action_ksef_list(): array {
    $s = require_company_ws(false);
    $b = body_json();
    $from = str_in($b, 'from', 10); $to = str_in($b, 'to', 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) fail(400, 'bad_range', 'Podaj daty w formacie RRRR-MM-DD.');
    try { return ['ok' => true] + ksef_purchases($s['ws'], $from, $to); }
    catch (ApiError $e) { throw $e; }
    catch (Throwable $e) { fail(502, 'ksef', $e->getMessage()); }
}
function action_ksef_sales(): array {
    $s = require_company_ws(false);
    $b = body_json();
    $from = str_in($b, 'from', 10); $to = str_in($b, 'to', 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) fail(400, 'bad_range', 'Podaj daty w formacie RRRR-MM-DD.');
    try { return ['ok' => true] + ksef_sales($s['ws'], $from, $to); }
    catch (ApiError $e) { throw $e; }
    catch (Throwable $e) { fail(502, 'ksef', $e->getMessage()); }
}

/* ======================================================================== KSeF – wystawianie faktur */
function require_invoicing(): array {
    $s = require_company_ws(true);   // wystawia właściciel lub technik firmy
    require_once __DIR__ . '/lib/ksef_issue.php';
    return $s;
}
function inv_env_info(array $ws): array {
    $set = ws_settings($ws);
    $env = (string) ($set['ksefEnv'] ?? 'prod');
    return ['env' => $env, 'envName' => KSEF_ENVS[$env] ?? $env, 'configured' => !empty($set['ksefToken']) && valid_nip((string) ($set['ksefNip'] ?? ''))];
}
function action_inv_profile(bool $post): array {
    $s = require_invoicing();
    $p = $post ? inv_profile_save($s['ws'], body_json()) : inv_profile($s['ws']);
    return ['ok' => true, 'profile' => $p, 'nextNumber' => inv_next_number($s['ws'], str_in($_GET, 'date', 10) ?: null)] + inv_env_info($s['ws']);
}
function action_inv_preview(): array {
    $s = require_invoicing();
    $inv = inv_normalize($s['ws'], body_json());
    return ['ok' => true, 'invoice' => $inv, 'xml' => inv_fa3_xml($inv)];
}
function inv_poll(array $ws, array $row, int $tries): array {
    for ($i = 0; $i < $tries; $i++) {
        try { $st = ksef_invoice_state($ws, (string) $row['session_ref'], (string) $row['invoice_ref']); }
        catch (Throwable $e) { $st = ['state' => 'processing', 'ksefNumber' => '', 'message' => $e->getMessage()]; }
        if ($st['state'] !== 'processing') {
            q('UPDATE ksef_issued SET status = ?, ksef_number = ?, error = ?, updated_at = ? WHERE id = ?', [$st['state'], $st['ksefNumber'], $st['state'] === 'rejected' ? $st['message'] : null, time(), (int) $row['id']]);
            break;
        }
        if ($i < $tries - 1) usleep(1500000);
    }
    return q1('SELECT * FROM ksef_issued WHERE id = ?', [(int) $row['id']]);
}
function action_inv_issue(): array {
    $s = require_invoicing();
    $b = body_json();
    $info = inv_env_info($s['ws']);
    if (!$info['configured']) fail(409, 'ksef_not_configured', 'Najpierw połącz KSeF w Panelu technika (NIP i token z uprawnieniem do wystawiania faktur).');
    if (($b['confirmEnv'] ?? '') !== $info['env']) fail(409, 'env_changed', 'Środowisko KSeF zmieniło się w międzyczasie – sprawdź je i potwierdź ponownie.');
    $inv = inv_normalize($s['ws'], $b);
    $xml = inv_fa3_xml($inv);
    $now = time();
    q('INSERT INTO ksef_issued (ws_id, user_id, env, number, buyer, buyer_nip, buyer_email, issue_date, due_date, net, gross, currency, status, xml, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        (int) $s['ws']['id'], (int) $s['user']['id'], $info['env'], $inv['number'], $inv['buyer']['name'], $inv['buyer']['nip'], $inv['buyer']['email'],
        $inv['issueDate'], $inv['dueDate'], inv_money($inv['net']), inv_money($inv['gross']), $inv['currency'], 'sending', $xml, $now, $now,
    ]);
    $id = (int) db()->lastInsertId();
    try {
        [$env, $sessionRef, $invoiceRef] = ksef_send_invoice($s['ws'], $xml);
    } catch (Throwable $e) {
        q("UPDATE ksef_issued SET status = 'failed', error = ?, updated_at = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 1000), time(), $id]);
        $msg = $e instanceof ApiError ? $e->getMessage() : $e->getMessage();
        if (preg_match('/uprawnie|permission|403/i', $msg)) $msg .= ' – sprawdź, czy token KSeF ma uprawnienie do wystawiania faktur.';
        fail(502, 'ksef', 'Faktura NIE została wystawiona: ' . $msg);
    }
    q("UPDATE ksef_issued SET status = 'processing', env = ?, session_ref = ?, invoice_ref = ?, updated_at = ? WHERE id = ?", [$env, $sessionRef, $invoiceRef, time(), $id]);
    $row = inv_poll($s['ws'], q1('SELECT * FROM ksef_issued WHERE id = ?', [$id]), 6);
    return ['ok' => true, 'invoice' => inv_row_public($row)];
}
function inv_row_for(array $s, int $id): array {
    $row = q1('SELECT * FROM ksef_issued WHERE id = ? AND ws_id = ?', [$id, (int) $s['ws']['id']]);
    if (!$row) fail(404, 'not_found', 'Nie znaleziono faktury.');
    return $row;
}
function action_inv_status(): array {
    $s = require_invoicing();
    $row = inv_row_for($s, (int) (body_json()['id'] ?? 0));
    if ($row['status'] === 'processing') $row = inv_poll($s['ws'], $row, 2);
    return ['ok' => true, 'invoice' => inv_row_public($row)];
}
function action_inv_list(): array {
    $s = require_invoicing();
    $rows = qall('SELECT * FROM ksef_issued WHERE ws_id = ? ORDER BY id DESC LIMIT 100', [(int) $s['ws']['id']]);
    return ['ok' => true, 'invoices' => array_map('inv_row_public', $rows)] + inv_env_info($s['ws']);
}
function action_inv_xml(): array {
    $s = require_invoicing();
    $row = inv_row_for($s, (int) ($_GET['id'] ?? 0));
    return ['ok' => true, 'xml' => (string) $row['xml'], 'name' => preg_replace('/[^0-9A-Za-z\-]+/', '_', $row['number']) . '.xml'];
}
function action_inv_paid(): array {
    $s = require_invoicing();
    $b = body_json();
    $row = inv_row_for($s, (int) ($b['id'] ?? 0));
    q('UPDATE ksef_issued SET paid = ?, updated_at = ? WHERE id = ?', [!empty($b['paid']) ? 1 : 0, time(), (int) $row['id']]);
    return ['ok' => true, 'invoice' => inv_row_public(q1('SELECT * FROM ksef_issued WHERE id = ?', [(int) $row['id']]))];
}

/* ======================================================================== plik z linku */
function action_fetch_url(): array {
    require_user();
    require_once __DIR__ . '/lib/fetchurl.php';
    $url = trim(str_in(body_json(), 'url', 2000));
    if ($url === '') fail(400, 'bad_url', 'Wklej link do pliku lub strony.');
    return ['ok' => true] + fetch_url_file($url);
}
function action_ksef_xml(): array {
    $s = require_company_ws(false);
    $n = str_in($_GET, 'n', 80);
    try { return ['ok' => true, 'xml' => ksef_invoice_xml($s['ws'], $n), 'name' => 'KSeF_' . preg_replace('/[^0-9A-Za-z\-]/', '', $n) . '.xml']; }
    catch (ApiError $e) { throw $e; }
    catch (Throwable $e) { fail(502, 'ksef', $e->getMessage()); }
}
