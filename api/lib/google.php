<?php
/** Google: weryfikacja logowania, połączenie Gmaila bez ponownego logowania (token odświeżania na serwerze). */
declare(strict_types=1);

const SCOPE_GMAIL = 'https://www.googleapis.com/auth/gmail.modify';
const SCOPE_CALENDAR = 'https://www.googleapis.com/auth/calendar.events';

/** Sprawdza token dostępu Google: musi być wydany dla NASZEJ aplikacji (client ID z konfiguracji). */
function google_verify_access_token(string $token): array {
    $cid = (string) cfg('GOOGLE_CLIENT_ID', '');
    if ($cid === '') fail(503, 'no_google', 'Serwer nie ma ustawionego identyfikatora klienta Google (GOOGLE_CLIENT_ID).');
    if (!preg_match('/^[\w.\-~+\/]{20,4096}$/', $token)) fail(400, 'bad_token', 'Nieprawidłowy token Google.');
    $r = http_json('GET', svc_url('google_tokeninfo') . '?access_token=' . rawurlencode($token));
    $t = $r['json'];
    if ($r['status'] !== 200 || !is_array($t)) fail(401, 'google_token', 'Google nie potwierdził logowania – spróbuj zalogować się ponownie.');
    $aud = (string) ($t['aud'] ?? $t['azp'] ?? '');
    if (!hash_equals($cid, $aud) && !hash_equals($cid, (string) ($t['azp'] ?? ''))) fail(401, 'google_aud', 'Token Google został wydany dla innej aplikacji.');
    if ((int) ($t['expires_in'] ?? 0) <= 0) fail(401, 'google_expired', 'Logowanie Google wygasło – spróbuj ponownie.');
    if (empty($t['sub'])) fail(401, 'google_sub', 'Google nie przekazał identyfikatora konta (zakres „openid”).');
    $u = http_json('GET', svc_url('google_userinfo'), ['Authorization: Bearer ' . $token]);
    $info = is_array($u['json']) ? $u['json'] : [];
    if (($info['sub'] ?? $t['sub']) !== $t['sub']) fail(401, 'google_sub', 'Niespójne dane konta Google.');
    $email = (string) ($info['email'] ?? $t['email'] ?? '');
    $verified = filter_var($info['email_verified'] ?? $t['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($email === '' || !$verified) fail(401, 'google_email', 'Konto Google nie ma potwierdzonego adresu e-mail.');
    return ['sub' => (string) $t['sub'], 'email' => $email, 'name' => (string) ($info['name'] ?? $email), 'givenName' => (string) ($info['given_name'] ?? ''), 'picture' => (string) ($info['picture'] ?? '')];
}

function google_redirect_uri(): string { return base_url() . app_path() . '/api/oauth.php'; }
function google_offline_ready(): bool { return cfg('GOOGLE_CLIENT_ID') && cfg('GOOGLE_CLIENT_SECRET'); }

function google_token_row(int $userId): ?array { return q1('SELECT * FROM google_tokens WHERE user_id = ?', [$userId]); }
function google_has_scope(?array $row, string $scope): bool { return $row && in_array($scope, preg_split('/\s+/', (string) $row['scope']), true); }

/** Nowy token dostępu z zapisanego tokena odświeżania. */
function google_access_token(int $userId): array {
    $row = google_token_row($userId);
    if (!$row) fail(404, 'google_not_connected', 'Gmail nie jest połączony z serwerem.');
    $refresh = decrypt_str($row['refresh_enc']);
    if (!$refresh) fail(409, 'google_reconnect', 'Połączenie z Gmailem trzeba odnowić – kliknij „Połącz Gmail”.');
    $r = http_json('POST', svc_url('google_token'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id' => cfg('GOOGLE_CLIENT_ID'), 'client_secret' => cfg('GOOGLE_CLIENT_SECRET'),
        'refresh_token' => $refresh, 'grant_type' => 'refresh_token',
    ]));
    $j = $r['json'];
    if ($r['status'] !== 200 || empty($j['access_token'])) {
        $err = (string) ($j['error'] ?? ('HTTP ' . $r['status']));
        q('UPDATE google_tokens SET last_error = ?, updated_at = ? WHERE user_id = ?', [mb_substr($err, 0, 250), time(), $userId]);
        if ($err === 'invalid_grant') {
            q('UPDATE google_tokens SET refresh_enc = NULL WHERE user_id = ?', [$userId]);
            fail(409, 'google_reconnect', 'Google cofnął dostęp do Gmaila (zmiana hasła, odebranie uprawnień lub tryb testowy aplikacji – 7 dni). Kliknij „Połącz Gmail” jeszcze raz.');
        }
        fail(502, 'google_refresh', 'Google nie odświeżył dostępu do Gmaila – spróbuj za chwilę.');
    }
    if (!empty($j['scope']) && $j['scope'] !== $row['scope']) q('UPDATE google_tokens SET scope = ? WHERE user_id = ?', [$j['scope'], $userId]);
    if ($row['last_error'] !== '') q("UPDATE google_tokens SET last_error = '' WHERE user_id = ?", [$userId]);
    return ['access_token' => $j['access_token'], 'expires_in' => (int) ($j['expires_in'] ?? 3600), 'email' => $row['email'], 'scope' => $j['scope'] ?? $row['scope']];
}

/** Wymiana kodu z Google na tokeny (logowanie na stronie Google → powrót do api/oauth.php). */
function google_exchange_code(string $code): array {
    $r = http_json('POST', svc_url('google_token'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'code' => $code, 'client_id' => cfg('GOOGLE_CLIENT_ID'), 'client_secret' => cfg('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => google_redirect_uri(), 'grant_type' => 'authorization_code',
    ]));
    if ($r['status'] !== 200 || empty($r['json']['access_token'])) {
        $e = $r['json']['error_description'] ?? $r['json']['error'] ?? ('HTTP ' . $r['status']);
        throw new RuntimeException('Google: ' . $e);
    }
    return $r['json'];
}

/** E-mail z id_token (otrzymanego bezpośrednio od Google przez HTTPS – bez dodatkowej weryfikacji podpisu). */
function google_id_token_claims(string $idToken): array {
    $p = explode('.', $idToken);
    return count($p) === 3 ? (json_decode(b64url_dec($p[1]), true) ?: []) : [];
}

function google_revoke(string $token): void {
    http_request('POST', svc_url('google_revoke'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $token]), 10);
}

/** Zapytanie do Gmail API w imieniu użytkownika (cron). */
function gmail_get(string $accessToken, string $path): array {
    $r = http_json('GET', svc_url('gmail') . $path, ['Authorization: Bearer ' . $accessToken], null, 25);
    if ($r['status'] !== 200) throw new RuntimeException('Gmail HTTP ' . $r['status']);
    return is_array($r['json']) ? $r['json'] : [];
}
