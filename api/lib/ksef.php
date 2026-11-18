<?php
/**
 * KSeF 2.0 – pobieranie faktur zakupowych (firma jako nabywca, „Podmiot 2”).
 * Uwierzytelnianie tokenem KSeF: wyzwanie → token|znacznik czasu zaszyfrowany kluczem publicznym MF (RSA-OAEP SHA-256)
 * → status → tokeny dostępu. Specyfikacja: https://api.ksef.mf.gov.pl/docs/v2 (środowiska: test, demo, prod).
 */
declare(strict_types=1);

const KSEF_ENVS = ['test' => 'testowe', 'demo' => 'przedprodukcyjne (demo)', 'prod' => 'produkcyjne'];

function ksef_base(string $env): string { return svc_url('ksef_' . (isset(KSEF_ENVS[$env]) ? $env : 'test')); }

function ksef_call(string $env, string $method, string $path, ?string $bearer, $payload = null, string $accept = 'application/json'): array {
    $h = ['Accept: ' . $accept, 'X-Error-Format: problem-details'];
    if ($bearer) $h[] = 'Authorization: Bearer ' . $bearer;
    $body = null;
    if ($payload !== null) { $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; }
    elseif ($method === 'POST') { $body = ''; }
    $r = http_request($method, ksef_base($env) . $path, $h, $body, 40);
    $r['json'] = json_decode($r['body'], true);
    return $r;
}
function ksef_error(array $r, string $what): RuntimeException {
    $j = is_array($r['json']) ? $r['json'] : [];
    $msg = $j['detail'] ?? $j['title'] ?? ($j['exception']['exceptionDetailList'][0]['exceptionDescription'] ?? '') ?: ($r['error'] ?: 'HTTP ' . $r['status']);
    if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
    return new RuntimeException("KSeF ($what): " . mb_substr((string) $msg, 0, 300));
}

/** RSA-OAEP z SHA-256 i MGF1-SHA-256 (openssl_public_encrypt w PHP 8.3 obsługuje tylko SHA-1, więc kodowanie robimy sami). */
function rsa_oaep_sha256_encrypt(string $plain, string $pem): string {
    $key = openssl_pkey_get_public($pem);
    if (!$key) throw new RuntimeException('Nieprawidłowy klucz publiczny KSeF.');
    $k = intdiv(openssl_pkey_get_details($key)['bits'] + 7, 8);
    $hLen = 32;
    if (strlen($plain) > $k - 2 * $hLen - 2) throw new RuntimeException('Token KSeF jest za długi.');
    $lHash = hash('sha256', '', true);
    $db = $lHash . str_repeat("\0", $k - strlen($plain) - 2 * $hLen - 2) . "\x01" . $plain;
    $seed = random_bytes($hLen);
    $mgf = function (string $seed, int $len): string {
        $out = '';
        for ($i = 0; strlen($out) < $len; $i++) $out .= hash('sha256', $seed . pack('N', $i), true);
        return substr($out, 0, $len);
    };
    $maskedDb = $db ^ $mgf($seed, $k - $hLen - 1);
    $maskedSeed = $seed ^ $mgf($maskedDb, $hLen);
    $em = "\0" . $maskedSeed . $maskedDb;
    if (!openssl_public_encrypt($em, $out, $key, OPENSSL_NO_PADDING)) throw new RuntimeException('Szyfrowanie tokena KSeF nie powiodło się.');
    return $out;
}

function ksef_public_key(string $env): string {
    $r = ksef_call($env, 'GET', '/security/public-key-certificates', null);
    if ($r['status'] !== 200 || !is_array($r['json'])) throw ksef_error($r, 'klucz publiczny');
    foreach ($r['json'] as $c) {
        if (in_array('KsefTokenEncryption', (array) ($c['usage'] ?? []), true) && strtotime((string) $c['validTo']) > time()) {
            return "-----BEGIN CERTIFICATE-----\n" . chunk_split((string) $c['certificate'], 64, "\n") . "-----END CERTIFICATE-----\n";
        }
    }
    throw new RuntimeException('KSeF nie udostępnił klucza do szyfrowania tokena.');
}

/** Token dostępu (zapamiętany w ustawieniach firmy do wygaśnięcia). */
function ksef_access_token(array $ws, bool $fresh = false): array {
    $set = ws_settings($ws);
    $env = (string) ($set['ksefEnv'] ?? 'prod');
    $nip = (string) ($set['ksefNip'] ?? $ws['nip']);
    $token = decrypt_str($set['ksefToken'] ?? null);
    if (!$token || !valid_nip($nip)) fail(409, 'ksef_not_configured', 'KSeF nie jest skonfigurowany – technik firmy musi wpisać NIP i token KSeF w Panelu technika.');
    $cache = json_decode((string) decrypt_str($set['ksefSession'] ?? null), true) ?: [];
    if (!$fresh && !empty($cache['access']) && ($cache['accessUntil'] ?? 0) > time() + 60 && ($cache['env'] ?? '') === $env) return [$env, $cache['access']];
    if (!$fresh && !empty($cache['refresh']) && ($cache['refreshUntil'] ?? 0) > time() + 60 && ($cache['env'] ?? '') === $env) {
        $r = ksef_call($env, 'POST', '/auth/token/refresh', $cache['refresh']);
        if ($r['status'] === 200 && !empty($r['json']['accessToken']['token'])) {
            $cache['access'] = $r['json']['accessToken']['token'];
            $cache['accessUntil'] = strtotime((string) $r['json']['accessToken']['validUntil']) ?: time() + 600;
            $set['ksefSession'] = encrypt_str(json_encode($cache)); save_ws_settings((int) $ws['id'], $set);
            return [$env, $cache['access']];
        }
    }
    $ch = ksef_call($env, 'POST', '/auth/challenge', null);
    if ($ch['status'] !== 200 || empty($ch['json']['challenge'])) throw ksef_error($ch, 'wyzwanie');
    $ts = (string) ($ch['json']['timestampMs'] ?? (int) round((strtotime((string) $ch['json']['timestamp']) ?: time()) * 1000));
    $enc = base64_encode(rsa_oaep_sha256_encrypt($token . '|' . $ts, ksef_public_key($env)));
    $init = ksef_call($env, 'POST', '/auth/ksef-token', null, ['challenge' => $ch['json']['challenge'], 'contextIdentifier' => ['type' => 'Nip', 'value' => $nip], 'encryptedToken' => $enc]);
    if (!in_array($init['status'], [200, 202], true) || empty($init['json']['authenticationToken']['token'])) throw ksef_error($init, 'logowanie tokenem');
    $authToken = $init['json']['authenticationToken']['token'];
    $ref = (string) $init['json']['referenceNumber'];
    for ($i = 0; $i < 20; $i++) {
        $st = ksef_call($env, 'GET', '/auth/' . rawurlencode($ref), $authToken);
        $code = (int) ($st['json']['status']['code'] ?? 0);
        if ($code === 200) break;
        if ($st['status'] >= 400 || $code >= 300) {
            $desc = ($st['json']['status']['description'] ?? '') . ' ' . implode(' ', (array) ($st['json']['status']['details'] ?? []));
            throw new RuntimeException('KSeF odrzucił token: ' . trim($desc ?: ('HTTP ' . $st['status'])) . ' – sprawdź token, NIP i wybrane środowisko.');
        }
        usleep(700000);
    }
    if (($code ?? 0) !== 200) throw new RuntimeException('KSeF nie potwierdził logowania w czasie 15 s – spróbuj ponownie.');
    $red = ksef_call($env, 'POST', '/auth/token/redeem', $authToken);
    if ($red['status'] !== 200 || empty($red['json']['accessToken']['token'])) throw ksef_error($red, 'tokeny dostępu');
    $cache = [
        'env' => $env,
        'access' => $red['json']['accessToken']['token'], 'accessUntil' => strtotime((string) $red['json']['accessToken']['validUntil']) ?: time() + 600,
        'refresh' => $red['json']['refreshToken']['token'] ?? '', 'refreshUntil' => strtotime((string) ($red['json']['refreshToken']['validUntil'] ?? '')) ?: 0,
    ];
    $set['ksefSession'] = encrypt_str(json_encode($cache));
    save_ws_settings((int) $ws['id'], $set);
    return [$env, $cache['access']];
}

/** Lista faktur zakupowych (metadane) w zakresie dat przyjęcia do KSeF. */
function ksef_purchases(array $ws, string $from, string $to): array {
    [$env, $access] = ksef_access_token($ws);
    $tz = new DateTimeZone('Europe/Warsaw');
    $f = new DateTime($from . ' 00:00:00', $tz); $t = new DateTime($to . ' 23:59:59', $tz);
    if ($t > new DateTime('now', $tz)) $t = new DateTime('now', $tz);
    if ($f > $t) fail(400, 'bad_range', 'Data „od” jest późniejsza niż „do”.');
    if ($t->getTimestamp() - $f->getTimestamp() > 92 * 86400) fail(400, 'bad_range', 'KSeF pozwala pobrać najwyżej 3 miesiące naraz – zawęź zakres dat.');
    $out = []; $hasMore = false;
    for ($page = 0; $page < 8; $page++) {
        $r = ksef_call($env, 'POST', '/invoices/query/metadata?sortOrder=Desc&pageSize=100&pageOffset=' . $page, $access, [
            'subjectType' => 'Subject2',
            'dateRange' => ['dateType' => 'Invoicing', 'from' => $f->format(DATE_ATOM), 'to' => $t->format(DATE_ATOM)],
        ]);
        if ($r['status'] === 401 && $page === 0) { [$env, $access] = ksef_access_token($ws, true); $page = -1; continue; }
        if ($r['status'] !== 200 || !is_array($r['json'])) throw ksef_error($r, 'lista faktur');
        foreach ((array) ($r['json']['invoices'] ?? []) as $i) {
            $out[] = [
                'ksefNumber' => (string) ($i['ksefNumber'] ?? ''), 'number' => (string) ($i['invoiceNumber'] ?? ''),
                'issueDate' => (string) ($i['issueDate'] ?? ''), 'received' => substr((string) ($i['invoicingDate'] ?? ''), 0, 10),
                'seller' => (string) ($i['seller']['name'] ?? ''), 'sellerNip' => (string) ($i['seller']['nip'] ?? ''),
                'net' => $i['netAmount'] ?? null, 'vat' => $i['vatAmount'] ?? null, 'gross' => $i['grossAmount'] ?? null, 'currency' => (string) ($i['currency'] ?? 'PLN'),
                'type' => (string) ($i['invoiceType'] ?? ''),
            ];
        }
        $hasMore = !empty($r['json']['hasMore']);
        if (!$hasMore || !empty($r['json']['isTruncated'])) break;
    }
    return ['env' => $env, 'invoices' => $out, 'more' => $hasMore];
}

/** Faktury sprzedażowe (Subject1) z terminem płatności i e-mailem nabywcy odczytanymi z XML – dla windykacji. */
function ksef_sales(array $ws, string $from, string $to, bool $retried = false): array {
    [$env, $access] = ksef_access_token($ws, $retried);
    $tz = new DateTimeZone('Europe/Warsaw');
    $f = new DateTime($from . ' 00:00:00', $tz); $t = new DateTime($to . ' 23:59:59', $tz);
    if ($t > new DateTime('now', $tz)) $t = new DateTime('now', $tz);
    if ($f > $t) fail(400, 'bad_range', 'Data „od” jest późniejsza niż „do”.');
    if ($t->getTimestamp() - $f->getTimestamp() > 92 * 86400) fail(400, 'bad_range', 'KSeF pozwala pobrać najwyżej 3 miesiące naraz – zawęź zakres dat.');
    $r = ksef_call($env, 'POST', '/invoices/query/metadata?sortOrder=Desc&pageSize=100&pageOffset=0', $access, [
        'subjectType' => 'Subject1',
        'dateRange' => ['dateType' => 'Issue', 'from' => $f->format(DATE_ATOM), 'to' => $t->format(DATE_ATOM)],
    ]);
    if ($r['status'] === 401 && !$retried) return ksef_sales($ws, $from, $to, true);
    if ($r['status'] !== 200 || !is_array($r['json'])) throw ksef_error($r, 'lista faktur sprzedaży');
    $out = []; $n = 0;
    foreach ((array) ($r['json']['invoices'] ?? []) as $i) {
        $type = (string) ($i['invoiceType'] ?? '');
        if (preg_match('/^Kor/i', $type)) continue;                       // korekty pomijamy
        $row = [
            'ksefNumber' => (string) ($i['ksefNumber'] ?? ''), 'number' => (string) ($i['invoiceNumber'] ?? ''),
            'issueDate' => (string) ($i['issueDate'] ?? ''),
            'buyer' => (string) ($i['buyer']['name'] ?? ''), 'buyerNip' => (string) ($i['buyer']['identifier']['value'] ?? ''),
            'gross' => $i['grossAmount'] ?? null, 'currency' => (string) ($i['currency'] ?? 'PLN'),
            'due' => '', 'email' => '', 'paid' => false,
        ];
        if ($n++ < 60 && $row['ksefNumber'] !== '') {                    // szczegóły z XML (termin, e-mail, zapłacono)
            try {
                $x = ksef_invoice_xml($ws, $row['ksefNumber']);
                if (preg_match('#<(?:\w+:)?TerminPlatnosci>.*?<(?:\w+:)?Termin>\s*(\d{4}-\d{2}-\d{2})#s', $x, $mm) || preg_match('#<(?:\w+:)?TerminPlatnosci>\s*(\d{4}-\d{2}-\d{2})#', $x, $mm)) $row['due'] = $mm[1];
                if (preg_match('#<(?:\w+:)?Podmiot2>.*?<(?:\w+:)?Email>\s*([^<\s]+)#s', $x, $mm)) $row['email'] = html_entity_decode($mm[1], ENT_XML1);
                if ($row['buyer'] === '' && preg_match('#<(?:\w+:)?Podmiot2>.*?<(?:\w+:)?Nazwa>([^<]+)#s', $x, $mm)) $row['buyer'] = html_entity_decode(trim($mm[1]), ENT_XML1);
                if (preg_match('#<(?:\w+:)?Zaplacono>\s*1\s*<#', $x)) $row['paid'] = true;
            } catch (Throwable $e) { /* bez szczegółów */ }
        }
        $out[] = $row;
    }
    return ['env' => $env, 'invoices' => $out, 'more' => !empty($r['json']['hasMore'])];
}

function ksef_invoice_xml(array $ws, string $ksefNumber): string {
    if (!preg_match('/^[0-9A-Za-z\-]{20,60}$/', $ksefNumber)) fail(400, 'bad_number', 'Nieprawidłowy numer KSeF.');
    [$env, $access] = ksef_access_token($ws);
    $r = ksef_call($env, 'GET', '/invoices/ksef/' . rawurlencode($ksefNumber), $access, null, 'application/xml');
    if ($r['status'] === 401) { [$env, $access] = ksef_access_token($ws, true); $r = ksef_call($env, 'GET', '/invoices/ksef/' . rawurlencode($ksefNumber), $access, null, 'application/xml'); }
    if ($r['status'] !== 200) throw ksef_error($r, 'pobranie faktury');
    return $r['body'];
}
