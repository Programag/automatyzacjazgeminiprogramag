<?php
/**
 * PaperlyAI – połączenie Gmaila (i Kalendarza) na stałe: logowanie na stronie Google → powrót tutaj.
 * Serwer zapisuje zaszyfrowany token odświeżania, więc po odświeżeniu strony nie trzeba logować się ponownie.
 * Adres powrotu do wpisania w Google Cloud (Identyfikatory klienta → Autoryzowane identyfikatory URI przekierowania):
 *   https://<twoja-domena>/api/oauth.php
 */
declare(strict_types=1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/google.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function back_to_app(string $status, string $message = ''): void {
    $q = ['google' => $status];
    if ($message !== '') $q['msg'] = mb_substr($message, 0, 300);
    header('Location: ' . app_url() . '?' . http_build_query($q));
    exit;
}

try {
    if (!google_offline_ready()) back_to_app('error', 'Serwer nie ma ustawionych sekretów GOOGLE_CLIENT_ID i GOOGLE_CLIENT_SECRET.');
    $s = current_session();

    /* ---- start: przejście na stronę zgody Google ---- */
    if (isset($_GET['start'])) {
        if (!$s) back_to_app('error', 'Najpierw zaloguj się w PaperlyAI przez Google.');
        try { rate_limit('oauth', 'u' . $s['user']['id'], 20, 600); } catch (ApiError $e) { back_to_app('error', $e->getMessage()); }
        $want = array_filter(explode(',', (string) ($_GET['scopes'] ?? 'gmail,calendar')));
        $scopes = ['openid', 'email'];
        if (in_array('gmail', $want, true)) $scopes[] = SCOPE_GMAIL;
        if (in_array('calendar', $want, true)) $scopes[] = SCOPE_CALENDAR;
        $nonce = bin2hex(random_bytes(16));
        setcookie('paperly_oauth', $nonce, ['expires' => time() + 900, 'path' => app_path() . '/api/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        $state = sign_data(['u' => (int) $s['user']['id'], 'n' => $nonce, 't' => time()]);
        $q = [
            'client_id' => cfg('GOOGLE_CLIENT_ID'), 'redirect_uri' => google_redirect_uri(), 'response_type' => 'code',
            'scope' => implode(' ', $scopes), 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
        ];
        $hint = (string) ($_GET['hint'] ?? '');
        if (filter_var($hint, FILTER_VALIDATE_EMAIL)) $q['login_hint'] = $hint;
        header('Location: ' . svc_url('google_auth') . '?' . http_build_query($q));
        exit;
    }

    /* ---- powrót z Google ---- */
    if (isset($_GET['error'])) back_to_app('error', $_GET['error'] === 'access_denied' ? 'Nie udzielono zgody w Google.' : 'Google: ' . preg_replace('/[^\w \-]/', '', (string) $_GET['error']));
    $state = verify_signed((string) ($_GET['state'] ?? ''));
    $nonce = (string) ($_COOKIE['paperly_oauth'] ?? '');
    setcookie('paperly_oauth', '', ['expires' => time() - 3600, 'path' => app_path() . '/api/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    if (!$state || $nonce === '' || !hash_equals((string) ($state['n'] ?? ''), $nonce) || time() - (int) ($state['t'] ?? 0) > 900) back_to_app('error', 'Logowanie Google wygasło lub zostało przerwane – spróbuj jeszcze raz.');
    if (!$s || (int) $s['user']['id'] !== (int) $state['u']) back_to_app('error', 'Sesja PaperlyAI wygasła – zaloguj się i połącz Gmail ponownie.');
    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') back_to_app('error', 'Google nie przekazał kodu logowania.');

    $tok = google_exchange_code($code);
    $scope = (string) ($tok['scope'] ?? '');
    $claims = !empty($tok['id_token']) ? google_id_token_claims((string) $tok['id_token']) : [];
    $email = (string) ($claims['email'] ?? '');
    if ($email === '' && str_contains($scope, SCOPE_GMAIL)) {
        try { $email = (string) (gmail_get((string) $tok['access_token'], '/profile')['emailAddress'] ?? ''); } catch (Throwable $e) { $email = ''; }
    }
    $uid = (int) $s['user']['id'];
    $row = google_token_row($uid);
    $refresh = (string) ($tok['refresh_token'] ?? '');
    if ($refresh === '' && !($row && $row['refresh_enc'])) back_to_app('error', 'Google nie przekazał stałego dostępu – w ustawieniach konta Google usuń dostęp dla PaperlyAI i połącz ponownie.');
    $now = time();
    if ($row) {
        q('UPDATE google_tokens SET email = ?, scope = ?, refresh_enc = ?, updated_at = ?, last_error = ? WHERE user_id = ?',
            [$email ?: $row['email'], $scope, $refresh !== '' ? encrypt_str($refresh) : $row['refresh_enc'], $now, '', $uid]);
    } else {
        q('INSERT INTO google_tokens (user_id, email, scope, refresh_enc, created_at, updated_at, last_error) VALUES (?, ?, ?, ?, ?, ?, ?)', [$uid, $email, $scope, encrypt_str($refresh), $now, $now, '']);
    }
    if (!str_contains($scope, SCOPE_GMAIL)) back_to_app('partial', 'Połączono bez dostępu do Gmaila – zaznacz zgodę na odczyt i wysyłanie poczty.');
    back_to_app('connected');
} catch (Throwable $e) {
    error_log('PaperlyAI oauth: ' . $e->getMessage());
    back_to_app('error', $e instanceof ApiError || $e instanceof RuntimeException ? $e->getMessage() : 'Nie udało się połączyć z Google.');
}
