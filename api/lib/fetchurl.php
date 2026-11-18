<?php
/**
 * Pobieranie pliku z linku podanego przez użytkownika („Dodaj z linku”).
 * Ochrona przed SSRF: tylko http/https na portach 80/443, adres docelowy musi być publiczny
 * (każdy adres IP z DNS jest sprawdzany, połączenie jest przypięte do sprawdzonego IP),
 * każde przekierowanie jest sprawdzane od nowa, limit rozmiaru i czasu.
 */
const FETCH_MAX_BYTES = 10 * 1024 * 1024;
const FETCH_MAX_REDIRECTS = 4;

function fetch_ip_public(string $ip): bool {
    if (stripos($ip, '::ffff:') === 0) $ip = substr($ip, 7);           // IPv4 zapisany jako IPv6
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($ip);
        foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['0.0.0.0', 8], ['127.0.0.0', 8], ['169.254.0.0', 16], ['224.0.0.0', 3]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($n & $mask) === (ip2long($net) & $mask)) return false;
        }
        return true;
    }
    $l = strtolower($ip);
    return !preg_match('/^(::1?$|fe[89ab]|f[cd]|ff|64:ff9b|2001:db8|::)/', $l);
}

/** Zwraca [ip, czy_v6] dla hosta albo kończy błędem. */
function fetch_resolve(string $host): array {
    $test = array_filter(array_map('trim', explode(',', (string) cfg('FETCH_TEST_ALLOW', ''))));
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!in_array($host, $test, true) && !fetch_ip_public($host)) fail(400, 'blocked_host', 'Ten adres prowadzi do sieci wewnętrznej – podaj publiczny link.');
        return [$host, (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)];
    }
    if (!preg_match('/^[a-z0-9.-]{1,253}$/i', $host) || !str_contains($host, '.') && !in_array($host, $test, true)) fail(400, 'bad_url', 'Nieprawidłowy adres strony.');
    $v4 = @gethostbynamel($host) ?: [];
    $v6 = [];
    foreach ((@dns_get_record($host, DNS_AAAA) ?: []) as $r) if (!empty($r['ipv6'])) $v6[] = $r['ipv6'];
    $all = array_merge($v4, $v6);
    if (!$all) fail(400, 'dns', 'Nie znaleziono takiej strony – sprawdź link.');
    if (!in_array($host, $test, true)) foreach ($all as $ip) if (!fetch_ip_public($ip)) fail(400, 'blocked_host', 'Ten adres prowadzi do sieci wewnętrznej – podaj publiczny link.');
    return $v4 ? [$v4[0], false] : [$v6[0], true];
}

function fetch_url_file(string $url): array {
    $test = array_filter(array_map('trim', explode(',', (string) cfg('FETCH_TEST_ALLOW', ''))));
    for ($hop = 0; $hop <= FETCH_MAX_REDIRECTS; $hop++) {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        $host = strtolower(trim($p['host'] ?? '', '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user']) || isset($p['pass'])) fail(400, 'bad_url', 'Podaj link zaczynający się od https:// (bez loginu i hasła w adresie).');
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true) && !in_array($host, $test, true)) fail(400, 'bad_port', 'Obsługiwane są tylko zwykłe adresy stron (port 80 lub 443).');
        [$ip, $v6] = fetch_resolve($host);
        $buf = ''; $tooBig = false; $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . ($v6 ? '[' . $ip . ']' : $ip)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PaperlyAI-Link/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: */*', 'Accept-Language: pl,en;q=0.8'],
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
                $len = strlen($line);
                if (preg_match('/^HTTP\//', $line)) $headers = [];
                elseif (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$buf, &$tooBig) {
                if (strlen($buf) + strlen($chunk) > FETCH_MAX_BYTES) { $tooBig = true; return 0; }
                $buf .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($tooBig) fail(413, 'too_large', 'Plik pod tym linkiem jest za duży (maks. 10 MB).');
        if ($ok === false && !$code) fail(502, 'fetch', 'Nie udało się połączyć ze stroną' . ($err ? ' (' . $err . ')' : '') . '.');
        if ($code >= 300 && $code < 400 && !empty($headers['location'])) { $url = fetch_abs_url($url, $headers['location']); continue; }
        if ($code === 401 || $code === 403) fail(400, 'private', 'Strona wymaga zalogowania – udostępnij plik „każdemu, kto ma link” albo pobierz go i dodaj z dysku.');
        if ($code === 404) fail(400, 'not_found', 'Pod tym linkiem nie ma pliku (błąd 404).');
        if ($code < 200 || $code >= 300) fail(502, 'fetch', "Strona odpowiedziała błędem (HTTP $code).");
        $mime = strtolower(trim(explode(';', $headers['content-type'] ?? 'application/octet-stream')[0]));
        $name = '';
        if (!empty($headers['content-disposition']) && preg_match('/filename\*=UTF-8\'\'([^;]+)|filename="?([^";]+)"?/i', $headers['content-disposition'], $mm)) $name = rawurldecode($mm[1] ?: $mm[2]);
        if ($name === '') $name = rawurldecode(basename(parse_url($url, PHP_URL_PATH) ?: '')) ?: 'plik';
        $name = mb_substr(preg_replace('/[\x00-\x1f\/\\\\]+/', '_', $name), 0, 120);
        return ['name' => $name, 'mime' => $mime, 'size' => strlen($buf), 'data' => base64_encode($buf), 'finalUrl' => $url];
    }
    fail(400, 'redirects', 'Za dużo przekierowań pod tym linkiem.');
}

function fetch_abs_url(string $base, string $loc): string {
    if (preg_match('#^https?://#i', $loc)) return $loc;
    $b = parse_url($base);
    $root = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($loc, '//')) return $b['scheme'] . ':' . $loc;
    if (str_starts_with($loc, '/')) return $root . $loc;
    $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    return $root . $dir . $loc;
}
