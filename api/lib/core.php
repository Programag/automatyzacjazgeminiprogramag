<?php
/**
 * PaperlyAI – rdzeń serwera: konfiguracja, baza danych (MySQL lub SQLite), sesje, szyfrowanie, odpowiedzi JSON.
 * Konfiguracja: api/_config.php (tworzony przy wgrywaniu z sekretów GitHub) – zwraca tablicę ustawień.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'app' => 'paperly', 'features' => ['db' => false], 'error' => ['message' => 'PaperlyAI wymaga PHP 8.0 lub nowszego – zmień wersję PHP w panelu hostingu.']]);
    exit;
}

const PAPERLY_VERSION = '2026.11.17';
const SCHEMA_VERSION = 3;

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Warsaw');

function cfg(string $key, $default = null) {
    static $c = null;
    if ($c === null) {
        $file = getenv('PAPERLY_CONFIG') ?: dirname(__DIR__) . '/_config.php';
        $c = is_file($file) ? (include $file) : [];
        if (!is_array($c)) $c = [];
    }
    $v = $c[$key] ?? null;
    if (is_string($v)) $v = trim($v);
    return ($v === null || $v === '') ? $default : $v;
}

/** Sekret aplikacji: z konfiguracji, a gdy go brak – wyprowadzony z danych bazy (stały między wdrożeniami). */
function app_secret(): string {
    $s = (string) cfg('APP_SECRET', '');
    if (strlen($s) >= 16) return $s;
    return hash('sha256', 'paperly|' . cfg('DB_PASS', '') . '|' . cfg('DB_NAME', '') . '|' . cfg('DB_USER', '') . '|' . cfg('GOOGLE_CLIENT_SECRET', ''));
}
function cron_key(): string {
    $k = (string) cfg('CRON_SECRET', '');
    return strlen($k) >= 12 ? $k : substr(hash_hmac('sha256', 'cron', app_secret()), 0, 32);
}

/** Adresy usług zewnętrznych (w testach można je podmienić w konfiguracji). */
function svc_url(string $name): string {
    $defaults = [
        'gemini' => 'https://generativelanguage.googleapis.com',
        'google_tokeninfo' => 'https://oauth2.googleapis.com/tokeninfo',
        'google_userinfo' => 'https://www.googleapis.com/oauth2/v3/userinfo',
        'google_token' => 'https://oauth2.googleapis.com/token',
        'google_revoke' => 'https://oauth2.googleapis.com/revoke',
        'google_auth' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'gmail' => 'https://gmail.googleapis.com/gmail/v1/users/me',
        'gus' => 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc',
        'gus_test' => 'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc',
        'mf' => 'https://wl-api.mf.gov.pl',
        'ksef_test' => 'https://api-test.ksef.mf.gov.pl/v2',
        'ksef_demo' => 'https://api-demo.ksef.mf.gov.pl/v2',
        'ksef_prod' => 'https://api.ksef.mf.gov.pl/v2',
    ];
    $over = cfg('SERVICE_URLS', []);
    return rtrim(is_array($over) && !empty($over[$name]) ? $over[$name] : $defaults[$name], '/');
}

/* ------------------------------------------------------------------ odpowiedzi */
class ApiError extends Exception {
    public int $http; public string $codeName; public array $extra;
    public function __construct(int $http, string $codeName, string $message, array $extra = []) {
        parent::__construct($message);
        $this->http = $http; $this->codeName = $codeName; $this->extra = $extra;
    }
}
function fail(int $http, string $code, string $message, array $extra = []): void { throw new ApiError($http, $code, $message, $extra); }

function json_out($data, int $status = 200): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
function body_json(): array {
    static $b = null;
    if ($b !== null) return $b;
    $raw = file_get_contents('php://input');
    $b = $raw === '' || $raw === false ? [] : json_decode($raw, true);
    if (!is_array($b)) fail(400, 'bad_json', 'Nieprawidłowe dane zapytania.');
    return $b;
}
function str_in(array $a, string $k, int $max = 500): string {
    $v = $a[$k] ?? '';
    if (!is_string($v) && !is_numeric($v)) return '';
    return mb_substr(trim((string) $v), 0, $max);
}

/** Zapytania zmieniające dane muszą pochodzić z tej samej strony (nagłówek X-Paperly + Origin). */
function require_same_origin(): void {
    if (($_SERVER['HTTP_X_PAPERLY'] ?? '') !== '1') fail(403, 'csrf', 'Brak nagłówka aplikacji.');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        $self = $_SERVER['HTTP_HOST'] ?? '';
        if ($host . ($port ? ":$port" : '') !== $self && $host !== preg_replace('/:\d+$/', '', $self)) fail(403, 'csrf', 'Zapytanie z innej strony.');
    }
}

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}
function base_url(): string {
    $fixed = (string) cfg('PUBLIC_URL', '');
    if ($fixed !== '') return rtrim($fixed, '/');
    // Cron uruchomiony z serwera (bez adresu w zapytaniu) – adres zapamiętany przy ostatnim wejściu na stronę
    if (empty($_SERVER['HTTP_HOST'])) return rtrim((string) (db_configured() ? meta_get('public_url', 'http://localhost') : 'http://localhost'), '/');
    return (is_https() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
}
function remember_public_url(): void {
    if (empty($_SERVER['HTTP_HOST']) || cfg('PUBLIC_URL')) return;
    $u = base_url() . app_path();
    if (meta_get('public_url') !== $u) meta_set('public_url', $u);
}
/** Katalog aplikacji w adresie (np. „” albo „/podkatalog”) – api/ leży obok index.html. */
function app_path(): string {
    if (empty($_SERVER['HTTP_HOST'])) return '';   // cron z serwera: adres z remember_public_url() zawiera już ścieżkę
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/index.php';
    return rtrim(str_replace('\\', '/', dirname(dirname($script))), '/');
}
function app_url(): string { return base_url() . app_path() . '/'; }

/* ------------------------------------------------------------------ baza danych */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = (string) cfg('DB_DSN', '');
    try {
        if ($dsn === '') {
            if (!cfg('DB_NAME') || !cfg('DB_USER')) fail(503, 'no_db', 'Serwer nie ma skonfigurowanej bazy danych.');
            $host = (string) cfg('DB_HOST', 'localhost');
            $port = '';
            if (preg_match('/^(.+):(\d+)$/', $host, $m)) { $host = $m[1]; $port = ';port=' . $m[2]; }
            $dsn = "mysql:host={$host}{$port};dbname=" . cfg('DB_NAME') . ';charset=utf8mb4';
        }
        $pdo = new PDO($dsn, (string) cfg('DB_USER', ''), (string) cfg('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    } catch (PDOException $e) {
        error_log('PaperlyAI DB: ' . $e->getMessage());
        $GLOBALS['paperly_db_hint'] = db_error_hint($e);
        fail(503, 'db_error', 'Nie udało się połączyć z bazą danych – ' . $GLOBALS['paperly_db_hint']);
    }
    if (is_sqlite()) { $pdo->exec('PRAGMA foreign_keys = OFF'); $pdo->exec('PRAGMA busy_timeout = 5000'); }
    else $pdo->exec("SET time_zone = '+00:00'");
    migrate($pdo);
    return $pdo;
}
/** Przyczyna błędu połączenia bez ujawniania haseł (kod MySQL → podpowiedź, który sekret poprawić). */
function db_error_hint(PDOException $e): string {
    $m = $e->getMessage();
    $code = preg_match('/\[(\d{4})\]/', $m, $x) ? (int) $x[1] : (int) $e->getCode();
    if ($code === 1045 || stripos($m, 'Access denied') !== false) return 'serwer bazy odrzucił login lub hasło (kod 1045) – sprawdź DB_USER i DB_PASS.';
    if ($code === 1049 || stripos($m, 'Unknown database') !== false) return 'baza o tej nazwie nie istnieje (kod 1049) – sprawdź DB_NAME.';
    if ($code === 1044) return 'użytkownik nie ma dostępu do tej bazy (kod 1044) – sprawdź DB_NAME i DB_USER.';
    if (in_array($code, [2002, 2003, 2005, 2006], true) || stripos($m, 'getaddrinfo') !== false || stripos($m, 'No such file') !== false || stripos($m, 'refused') !== false) return 'nie można połączyć się z serwerem bazy (kod ' . $code . ') – sprawdź DB_HOST (adres serwera bazy z panelu hostingu).';
    return 'błąd bazy (kod ' . $code . ') – sprawdź sekrety DB_HOST, DB_NAME, DB_USER i DB_PASS.';
}
function is_sqlite(): bool { return str_starts_with((string) cfg('DB_DSN', ''), 'sqlite:'); }
function db_configured(): bool { return (bool) (cfg('DB_DSN') || (cfg('DB_NAME') && cfg('DB_USER'))); }

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function q1(string $sql, array $params = []): ?array { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function qall(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }

/** Dodaje liczby do wiersza (lub tworzy go) – działa w MySQL i SQLite. */
function upsert_add(string $table, array $keys, array $adds): void {
    $cols = array_merge(array_keys($keys), array_keys($adds));
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $vals = array_merge(array_values($keys), array_values($adds));
    if (is_sqlite()) {
        $set = implode(',', array_map(fn($c) => "$c = $c + excluded.$c", array_keys($adds)));
        q("INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($ph) ON CONFLICT(" . implode(',', array_keys($keys)) . ") DO UPDATE SET $set", $vals);
    } else {
        $set = implode(',', array_map(fn($c) => "$c = $c + VALUES($c)", array_keys($adds)));
        q("INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($ph) ON DUPLICATE KEY UPDATE $set", $vals);
    }
}

function migrate(PDO $pdo): void {
    try {
        $v = (int) ($pdo->query("SELECT v FROM meta WHERE k = 'schema'")->fetchColumn() ?: 0);
        if ($v >= SCHEMA_VERSION) return;
    } catch (PDOException $e) { $v = 0; }
    $sqlite = is_sqlite();
    $ID = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $BIG = $sqlite ? 'TEXT' : 'LONGTEXT';
    $TAIL = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $tables = [
        "CREATE TABLE IF NOT EXISTS meta (k VARCHAR(64) NOT NULL PRIMARY KEY, v TEXT)$TAIL",
        "CREATE TABLE IF NOT EXISTS users (id $ID, sub VARCHAR(64) NOT NULL, email VARCHAR(190) NOT NULL DEFAULT '', name VARCHAR(190) NOT NULL DEFAULT '', picture VARCHAR(500) NOT NULL DEFAULT '', is_admin TINYINT NOT NULL DEFAULT 0, prefs TEXT, created_at BIGINT NOT NULL DEFAULT 0, last_login BIGINT NOT NULL DEFAULT 0, UNIQUE (sub))$TAIL",
        "CREATE TABLE IF NOT EXISTS workspaces (id $ID, name VARCHAR(190) NOT NULL DEFAULT '', type VARCHAR(16) NOT NULL DEFAULT 'company', nip VARCHAR(16) NOT NULL DEFAULT '', owner_id BIGINT NOT NULL DEFAULT 0, daily_limit INT NOT NULL DEFAULT 0, settings TEXT, created_at BIGINT NOT NULL DEFAULT 0)$TAIL",
        "CREATE TABLE IF NOT EXISTS members (ws_id BIGINT NOT NULL, user_id BIGINT NOT NULL, role VARCHAR(16) NOT NULL DEFAULT 'employee', daily_limit INT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL DEFAULT 0, PRIMARY KEY (ws_id, user_id))$TAIL",
        "CREATE TABLE IF NOT EXISTS invites (code VARCHAR(40) NOT NULL PRIMARY KEY, ws_id BIGINT NOT NULL, role VARCHAR(16) NOT NULL DEFAULT 'employee', email VARCHAR(190) NOT NULL DEFAULT '', created_by BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL DEFAULT 0, used_by BIGINT NOT NULL DEFAULT 0, used_at BIGINT NOT NULL DEFAULT 0)$TAIL",
        "CREATE TABLE IF NOT EXISTS sessions (id CHAR(64) NOT NULL PRIMARY KEY, user_id BIGINT NOT NULL, ws_id BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL DEFAULT 0, last_seen BIGINT NOT NULL DEFAULT 0, ua VARCHAR(190) NOT NULL DEFAULT '')$TAIL",
        "CREATE TABLE IF NOT EXISTS kv (id $ID, ws_id BIGINT NOT NULL, user_id BIGINT NOT NULL DEFAULT 0, k VARCHAR(190) NOT NULL, v $BIG, deleted TINYINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL DEFAULT 0, updated_by BIGINT NOT NULL DEFAULT 0, UNIQUE (ws_id, user_id, k))$TAIL",
        "CREATE TABLE IF NOT EXISTS usage_daily (day CHAR(10) NOT NULL, user_id BIGINT NOT NULL, ws_id BIGINT NOT NULL, requests INT NOT NULL DEFAULT 0, tokens_in BIGINT NOT NULL DEFAULT 0, tokens_out BIGINT NOT NULL DEFAULT 0, PRIMARY KEY (day, user_id, ws_id))$TAIL",
        "CREATE TABLE IF NOT EXISTS google_tokens (user_id BIGINT NOT NULL PRIMARY KEY, email VARCHAR(190) NOT NULL DEFAULT '', scope TEXT, refresh_enc TEXT, created_at BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL DEFAULT 0, last_error VARCHAR(255) NOT NULL DEFAULT '')$TAIL",
        "CREATE TABLE IF NOT EXISTS uploads (id CHAR(32) NOT NULL PRIMARY KEY, user_id BIGINT NOT NULL, url TEXT, created_at BIGINT NOT NULL DEFAULT 0)$TAIL",
        "CREATE TABLE IF NOT EXISTS notices (k VARCHAR(190) NOT NULL PRIMARY KEY, created_at BIGINT NOT NULL DEFAULT 0)$TAIL",
        "CREATE TABLE IF NOT EXISTS rate_limits (k CHAR(64) NOT NULL, win BIGINT NOT NULL, n INT NOT NULL DEFAULT 0, PRIMARY KEY (k, win))$TAIL",
        "CREATE TABLE IF NOT EXISTS user_secrets (user_id BIGINT NOT NULL PRIMARY KEY, gemini_enc TEXT, updated_at BIGINT NOT NULL DEFAULT 0)$TAIL",
    ];
    foreach ($tables as $sql) $pdo->exec($sql);
    if ($sqlite) $pdo->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('schema', '" . SCHEMA_VERSION . "')");
    else $pdo->exec("REPLACE INTO meta (k, v) VALUES ('schema', '" . SCHEMA_VERSION . "')");
}

function meta_get(string $k, $def = null) { $r = q1('SELECT v FROM meta WHERE k = ?', [$k]); return $r ? $r['v'] : $def; }
function meta_set(string $k, ?string $v): void { q((is_sqlite() ? 'INSERT OR REPLACE' : 'REPLACE') . ' INTO meta (k, v) VALUES (?, ?)', [$k, $v]); }

/** Ustawienia serwera zmieniane przez administratora w aplikacji. */
function server_settings(): array {
    $s = json_decode((string) meta_get('settings', '{}'), true) ?: [];
    return array_merge(['signupOpen' => false, 'defaultLimit' => (int) cfg('DAILY_LIMIT', 150)], $s);
}

/* ------------------------------------------------------------------ szyfrowanie */
function enc_key(): string { return hash('sha256', 'paperly-enc|' . app_secret(), true); }
function encrypt_str(string $plain): string {
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, enc_key()));
    }
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', enc_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'g1:' . base64_encode($iv . $tag . $c);
}
function decrypt_str(?string $box): ?string {
    if (!$box) return null;
    $raw = base64_decode(substr($box, 3), true);
    if ($raw === false) return null;
    if (str_starts_with($box, 's1:') && function_exists('sodium_crypto_secretbox_open')) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $p = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), enc_key());
        return $p === false ? null : $p;
    }
    if (str_starts_with($box, 'g1:')) {
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', enc_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? null : $p;
    }
    return null;
}
function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function sign_data(array $data): string { $p = b64url(json_encode($data)); return $p . '.' . b64url(hash_hmac('sha256', $p, app_secret(), true)); }
function verify_signed(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;
    if (!hash_equals(b64url(hash_hmac('sha256', $parts[0], app_secret(), true)), $parts[1])) return null;
    $d = json_decode(b64url_dec($parts[0]), true);
    return is_array($d) ? $d : null;
}

/* ------------------------------------------------------------------ awaryjne odnajdywanie adresów (DNS hostingu chwilowo nie działa) */
// Gdy serwer nazw hostingu nie odpowiada („Could not resolve host”), połączenie ponawiamy z adresem IP:
// ostatnio działającym (zapamiętanym po udanym połączeniu), z systemowego resolvera albo z DNS-over-HTTPS
// (Cloudflare 1.1.1.1, Google 8.8.8.8 – łączymy się z nimi po samym adresie IP, więc nie potrzebują DNS).
const DNS_REMEMBER_SEC = 3600;

function dns_ip_ok(string $ip): bool {
    if (in_array($ip, array_filter(array_map('trim', explode(',', (string) cfg('DNS_TEST_ALLOW', '')))), true)) return true;   // tylko testy
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}
function dns_doh(string $host): array {
    $ips = [];
    foreach (['https://1.1.1.1/dns-query?name=%s&type=A', 'https://8.8.8.8/resolve?name=%s&type=A'] as $tpl) {
        $ch = curl_init(sprintf($tpl, rawurlencode($host)));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['Accept: application/dns-json']]);
        $j = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        foreach (($j['Answer'] ?? []) as $a) if ((int) ($a['type'] ?? 0) === 1 && dns_ip_ok((string) ($a['data'] ?? ''))) $ips[] = (string) $a['data'];
        if ($ips) break;
    }
    return $ips;
}
function dns_fallback_ips(string $host): array {
    $ips = [];
    try { $m = json_decode((string) meta_get('dns:' . $host, ''), true); if (!empty($m['ip']) && dns_ip_ok($m['ip'])) $ips[] = $m['ip']; } catch (Throwable $e) {}
    foreach ((@gethostbynamel($host) ?: []) as $ip) if (dns_ip_ok($ip)) $ips[] = $ip;
    if (!$ips) $ips = dns_doh($host);
    return array_values(array_unique($ips));
}
function dns_remember($ch, string $host): void {
    $ip = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
    if (!dns_ip_ok($ip) || filter_var($host, FILTER_VALIDATE_IP)) return;
    try {
        $m = json_decode((string) meta_get('dns:' . $host, ''), true);
        if (($m['ip'] ?? '') !== $ip || (int) ($m['t'] ?? 0) < time() - DNS_REMEMBER_SEC) meta_set('dns:' . $host, json_encode(['ip' => $ip, 't' => time()]));
    } catch (Throwable $e) {}
}
/** curl_exec z ponowieniem przy awarii DNS hostingu. Wywoływać tylko przed wysłaniem czegokolwiek do przeglądarki. */
function curl_exec_dns($ch, string $url) {
    $out = curl_exec($ch);
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($out === false && curl_errno($ch) === CURLE_COULDNT_RESOLVE_HOST && $host !== '') {
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: (str_starts_with($url, 'http://') ? 80 : 443));
        foreach (array_slice(dns_fallback_ips($host), 0, 3) as $ip) {
            curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $ip]);
            $out = curl_exec($ch);
            if ($out !== false || curl_errno($ch) !== CURLE_COULDNT_CONNECT && curl_errno($ch) !== CURLE_OPERATION_TIMEDOUT) break;
        }
    }
    if ($out !== false) dns_remember($ch, $host);
    return $out;
}

/* ------------------------------------------------------------------ HTTP do usług zewnętrznych */
function http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array {
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $p = strpos($line, ':');
            if ($p !== false) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out = curl_exec_dns($ch, $url);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $out === false ? curl_error($ch) : '';
    curl_close($ch);
    return ['status' => $status, 'body' => $out === false ? '' : (string) $out, 'headers' => $respHeaders, 'error' => $err];
}
function http_json(string $method, string $url, array $headers = [], $payload = null, int $timeout = 30): array {
    $body = null;
    if (is_array($payload)) { $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $headers[] = 'Content-Type: application/json'; }
    elseif (is_string($payload)) $body = $payload;
    $headers[] = 'Accept: application/json';
    $r = http_request($method, $url, $headers, $body, $timeout);
    $r['json'] = json_decode($r['body'], true);
    return $r;
}

/* ------------------------------------------------------------------ sesje i użytkownicy */
const SESSION_COOKIE = 'paperly_s';
const SESSION_DAYS = 60;

function session_token(): string { return (string) ($_COOKIE[SESSION_COOKIE] ?? ''); }
function set_session_cookie(string $value, int $expires): void {
    setcookie(SESSION_COOKIE, $value, ['expires' => $expires, 'path' => app_path() . '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function create_session(int $userId, int $wsId): void {
    $token = bin2hex(random_bytes(32));
    $now = time();
    q('INSERT INTO sessions (id, user_id, ws_id, created_at, expires_at, last_seen, ua) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [hash('sha256', $token), $userId, $wsId, $now, $now + SESSION_DAYS * 86400, $now, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190)]);
    set_session_cookie($token, $now + SESSION_DAYS * 86400);
}
function end_session(): void {
    $t = session_token();
    if ($t !== '') q('DELETE FROM sessions WHERE id = ?', [hash('sha256', $t)]);
    set_session_cookie('', time() - 3600);
}

/** Bieżąca sesja: ['user' => ..., 'ws' => ..., 'role' => ..., 'sid' => ...] albo null. */
function current_session(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    $t = session_token();
    if ($t === '' || !db_configured()) return null;
    $sid = hash('sha256', $t);
    $s = q1('SELECT * FROM sessions WHERE id = ?', [$sid]);
    if (!$s || (int) $s['expires_at'] < time()) return null;
    $user = q1('SELECT * FROM users WHERE id = ?', [(int) $s['user_id']]);
    if (!$user) return null;
    $now = time();
    if ($now - (int) $s['last_seen'] > 3600) {
        q('UPDATE sessions SET last_seen = ?, expires_at = ? WHERE id = ?', [$now, $now + SESSION_DAYS * 86400, $sid]);
        set_session_cookie($t, $now + SESSION_DAYS * 86400);
    }
    $ws = null; $role = null;
    $wsId = (int) $s['ws_id'];
    $m = $wsId ? q1('SELECT * FROM members WHERE ws_id = ? AND user_id = ?', [$wsId, (int) $user['id']]) : null;
    if (!$m) {
        $m = q1('SELECT * FROM members WHERE user_id = ? ORDER BY created_at DESC LIMIT 1', [(int) $user['id']]);
        if ($m) q('UPDATE sessions SET ws_id = ? WHERE id = ?', [(int) $m['ws_id'], $sid]);
    }
    if ($m) { $ws = q1('SELECT * FROM workspaces WHERE id = ?', [(int) $m['ws_id']]); $role = $ws ? $m['role'] : null; }
    $cache = ['user' => $user, 'ws' => $ws, 'member' => $ws ? $m : null, 'role' => $role, 'sid' => $sid];
    return $cache;
}
function require_user(): array {
    $s = current_session();
    if (!$s) fail(401, 'PAPERLY_AUTH', 'Sesja wygasła – zaloguj się ponownie przez Google.');
    return $s;
}
function require_ws(): array {
    $s = require_user();
    if (!$s['ws']) fail(409, 'no_workspace', 'Najpierw załóż konto firmy lub dołącz do zespołu.');
    return $s;
}
function is_manager(array $s): bool { return in_array($s['role'], ['owner', 'tech'], true); }
function require_manager(): array {
    $s = require_ws();
    if (!is_manager($s)) fail(403, 'forbidden', 'Tę zmianę może wprowadzić tylko właściciel lub technik firmy.');
    return $s;
}
function require_admin(): array {
    $s = require_user();
    if (!(int) $s['user']['is_admin']) fail(403, 'forbidden', 'Tylko administrator serwera może to zmienić.');
    return $s;
}
/** Ustawienia powiadomień; przypomnienia o umowach domyślnie tylko dla właściciela i techników. */
function user_prefs(array $user, ?string $role = null): array {
    $p = json_decode((string) ($user['prefs'] ?? ''), true) ?: [];
    return array_merge(['newMail' => false, 'contracts' => in_array($role, ['owner', 'tech'], true), 'digest' => false, 'digestHour' => 7, 'email' => ''], $p);
}
function ws_settings(array $ws): array { return json_decode((string) ($ws['settings'] ?? ''), true) ?: []; }
function save_ws_settings(int $wsId, array $settings): void { q('UPDATE workspaces SET settings = ? WHERE id = ?', [json_encode($settings, JSON_UNESCAPED_UNICODE), $wsId]); }

/** Dzienny limit zapytań AI dla członka zespołu (0 = bez limitu). */
/**
 * Klucz Gemini dla zapytania – każdy płaci za siebie:
 *   konto prywatne: tylko własny klucz osoby,
 *   konto firmowe: wspólny klucz firmy (jeśli właściciel go dodał), inaczej własny klucz osoby,
 *   klucz operatora (sekret GEMINI_API_KEY) – wyłącznie dla firm administratora serwera.
 * Zwraca [klucz, źródło] albo ['', ''] gdy brak.
 */
function user_gemini_key(int $userId): string {
    $r = q1('SELECT gemini_enc FROM user_secrets WHERE user_id = ?', [$userId]);
    return (string) decrypt_str($r['gemini_enc'] ?? null);
}
function resolve_gemini_key(array $s): array {
    $ws = $s['ws'];
    if (!$ws) return ['', ''];
    if ($ws['type'] === 'company') {
        $k = (string) decrypt_str(ws_settings($ws)['geminiKey'] ?? null);
        if ($k !== '') return [$k, 'company'];
    }
    $own = user_gemini_key((int) $s['user']['id']);
    if ($own !== '') return [$own, 'own'];
    $op = (string) cfg('GEMINI_API_KEY', '');
    if ($op !== '') {
        $owner = q1('SELECT is_admin FROM users WHERE id = ?', [(int) $ws['owner_id']]);
        if ($owner && (int) $owner['is_admin']) return [$op, 'operator'];
    }
    return ['', ''];
}
function mask_key(string $k): string { return strlen($k) > 10 ? substr($k, 0, 4) . '…' . substr($k, -4) : '••••'; }
/** Sprawdza klucz Gemini w Google (lista modeli). Zwraca pusty tekst albo opis błędu. */
function check_gemini_key(string $key): string {
    if (!preg_match('/^[\w.\-]{20,200}$/', $key)) return 'To nie wygląda na klucz Gemini – klucze zaczynają się od „AIza” albo „AQ.”.';
    $r = http_json('GET', svc_url('gemini') . '/v1beta/models?pageSize=1', ['x-goog-api-key: ' . $key], null, 20);
    if ($r['status'] === 200) return '';
    if ($r['status'] === 0) return 'Nie udało się połączyć z Google – spróbuj za chwilę.';
    if (in_array($r['status'], [400, 401, 403], true)) return 'Google odrzucił ten klucz – sprawdź, czy skopiowałeś go w całości.';
    if ($r['status'] === 429) return '';   // klucz poprawny, tylko chwilowo bez limitu
    return 'Google odpowiedział błędem (HTTP ' . $r['status'] . ') – spróbuj ponownie.';
}

/* ------------------------------------------------------------------ ochrona przed nadużyciami */
/** Adres IP klienta (za serwerem pośredniczącym hostingu – pierwszy publiczny adres z X-Forwarded-For). */
function client_ip(): string {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $private = !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($private && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']) as $c) {
            $c = trim($c);
            if (filter_var($c, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $c;
        }
    }
    return $ip;
}
/**
 * Limit prób: najwyżej $max zdarzeń w oknie $window sekund dla danej osoby/adresu.
 * Chroni logowanie, zaproszenia, sprawdzanie kluczy i zapytania AI przed zgadywaniem i zalewaniem.
 */
function rate_limit(string $bucket, string $who, int $max, int $window, string $message = ''): void {
    if (!db_configured()) return;
    $k = hash('sha256', $bucket . '|' . $who);
    $win = intdiv(time(), $window) * $window;
    upsert_add('rate_limits', ['k' => $k, 'win' => $win], ['n' => 1]);
    $n = (int) (q1('SELECT n FROM rate_limits WHERE k = ? AND win = ?', [$k, $win])['n'] ?? 0);
    if ($n > $max) {
        $wait = max(1, (int) ceil(($win + $window - time()) / 60));
        header('Retry-After: ' . ($win + $window - time()));
        fail(429, 'rate_limited', $message ?: "Za dużo prób w krótkim czasie – spróbuj ponownie za {$wait} min.");
    }
}

function member_limit(array $s): int {
    if (!empty($s['member']['daily_limit'])) return (int) $s['member']['daily_limit'];
    if (!empty($s['ws']['daily_limit'])) return (int) $s['ws']['daily_limit'];
    return (int) server_settings()['defaultLimit'];
}
function usage_today(int $userId, int $wsId): array {
    $r = q1('SELECT requests, tokens_in, tokens_out FROM usage_daily WHERE day = ? AND user_id = ? AND ws_id = ?', [date('Y-m-d'), $userId, $wsId]);
    return ['requests' => (int) ($r['requests'] ?? 0), 'tokensIn' => (int) ($r['tokens_in'] ?? 0), 'tokensOut' => (int) ($r['tokens_out'] ?? 0)];
}

/** Największe zapytanie, jakie przyjmie PHP na tym hostingu (post_max_size). */
function ini_bytes(string $v): int {
    $v = trim($v); $n = (int) $v;
    switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return $n;
}
function upload_max_bytes(): int {
    $p = ini_bytes((string) ini_get('post_max_size'));
    return $p > 0 ? $p : 64 * 1024 * 1024;
}

function valid_nip(string $nip): bool {
    if (!preg_match('/^\d{10}$/', $nip)) return false;
    $w = [6, 5, 7, 2, 3, 4, 5, 6, 7]; $sum = 0;
    for ($i = 0; $i < 9; $i++) $sum += $w[$i] * (int) $nip[$i];
    return $sum % 11 === (int) $nip[9];
}

/** Obsługa błędów dla punktów wejścia JSON. */
function run_json(callable $fn): void {
    try {
        $fn();
    } catch (ApiError $e) {
        json_out(array_merge(['error' => ['code' => $e->http, 'status' => $e->codeName, 'message' => $e->getMessage()]], $e->extra), $e->http);
    } catch (Throwable $e) {
        error_log('PaperlyAI: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        json_out(['error' => ['code' => 500, 'status' => 'server_error', 'message' => 'Błąd serwera – spróbuj ponownie za chwilę.']], 500);
    }
}
