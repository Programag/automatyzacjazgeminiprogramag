<?php
/**
 * Powiadomienia Web Push (telefon i komputer) bez zewnętrznych bibliotek.
 * - klucze VAPID (P-256) tworzone przy pierwszym użyciu i zapisane zaszyfrowane w bazie (meta),
 * - treść szyfrowana zgodnie z RFC 8291 (aes128gcm) – serwer usługi push nie widzi treści powiadomienia,
 * - nagłówek Authorization: vapid (RFC 8292, podpis ES256).
 * Wysyłamy tylko do usług push przeglądarek (lista dozwolonych adresów), nigdzie indziej.
 */
declare(strict_types=1);

const PUSH_HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'web.push.apple.com', 'notify.windows.com'];

function push_supported(): bool { return function_exists('openssl_pkey_derive') && function_exists('openssl_pkey_new') && in_array('aes-128-gcm', openssl_get_cipher_methods(), true); }

function b64u_enc(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** Punkt publiczny P-256 (65 bajtów, 0x04||X||Y) z klucza OpenSSL. */
function ec_point(OpenSSLAsymmetricKey $k): string {
    $d = openssl_pkey_get_details($k)['ec'];
    return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}
/** Klucz publiczny OpenSSL z surowego punktu P-256 (SubjectPublicKeyInfo). */
function ec_public_from_point(string $point): OpenSSLAsymmetricKey {
    if (strlen($point) !== 65 || $point[0] !== "\x04") throw new RuntimeException('Nieprawidłowy klucz subskrypcji.');
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    $k = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
    if (!$k) throw new RuntimeException('Nieprawidłowy klucz subskrypcji.');
    return $k;
}
function ec_new(): OpenSSLAsymmetricKey {
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if (!$k) throw new RuntimeException('Nie udało się utworzyć klucza EC.');
    return $k;
}

/** Klucze VAPID serwera: ['key' => prywatny, 'pub' => surowy punkt publiczny]. */
function vapid_keys(): array {
    static $cache = null;
    if ($cache) return $cache;
    $pem = decrypt_str(meta_get('vapid_private'));
    if (!$pem) {
        $k = ec_new();
        openssl_pkey_export($k, $pem);
        meta_set('vapid_private', encrypt_str($pem));
    }
    $key = openssl_pkey_get_private($pem);
    if (!$key) throw new RuntimeException('Uszkodzony klucz VAPID.');
    return $cache = ['key' => $key, 'pub' => ec_point($key)];
}
function vapid_public_b64(): string { return b64u_enc(vapid_keys()['pub']); }

/** Podpis ES256: DER (OpenSSL) → 64 bajty R||S (JWT). */
function es256_sign(string $data, OpenSSLAsymmetricKey $key): string {
    if (!openssl_sign($data, $der, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Podpis VAPID nie powiódł się.');
    $pos = 2;
    if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = ltrim(substr($der, $pos + 2, $len), "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}
function vapid_header(string $endpoint): string {
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $contact = (string) (cfg('CONTACT_EMAIL') ?: cfg('MAIL_FROM') ?: '');
    $sub = filter_var($contact, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $contact : (app_url() ?: 'https://paperlyai.pl');
    $h = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
    $jwt = "$h.$c." . b64u_enc(es256_sign("$h.$c", vapid_keys()['key']));
    return 'vapid t=' . $jwt . ', k=' . vapid_public_b64();
}

function hkdf_sha256(string $salt, string $ikm, string $info, int $len): string {
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $len);
}
/** Szyfrowanie treści (RFC 8291 / RFC 8188, jeden rekord). */
function push_encrypt(string $payload, string $uaPublic, string $authSecret): string {
    $as = ec_new();
    $asPublic = ec_point($as);
    $shared = openssl_pkey_derive(ec_public_from_point($uaPublic), $as, 32);
    if ($shared === false) throw new RuntimeException('ECDH nie powiódł się.');
    $ikm = hkdf_sha256($authSecret, $shared, "WebPush: info\0" . $uaPublic . $asPublic, 32);
    $salt = random_bytes(16);
    $cek = hkdf_sha256($salt, $ikm, "Content-Encoding: aes128gcm\0", 16);
    $nonce = hkdf_sha256($salt, $ikm, "Content-Encoding: nonce\0", 12);
    $tag = '';
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) throw new RuntimeException('Szyfrowanie powiadomienia nie powiodło się.');
    return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $ct . $tag;
}

function push_endpoint_ok(string $endpoint): bool {
    $p = parse_url($endpoint);
    if (!$p || empty($p['host'])) return false;
    $host = strtolower($p['host']);
    $test = array_filter(array_map('trim', explode(',', (string) cfg('PUSH_TEST_ALLOW', ''))));
    if (in_array($host, $test, true)) return in_array($p['scheme'] ?? '', ['http', 'https'], true);
    if (($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['port']) && (int) $p['port'] !== 443) return false;
    foreach (PUSH_HOSTS as $h) if ($host === $h || str_ends_with($host, '.' . $h)) return true;
    return false;
}

/** Wysyła jedno powiadomienie. Zwraca kod HTTP usługi push (0 = brak połączenia). Nieaktualne subskrypcje są usuwane. */
function push_send(array $sub, array $msg, int $ttl = 86400, string $urgency = 'normal'): int {
    if (!push_endpoint_ok((string) $sub['endpoint'])) { q('DELETE FROM push_subs WHERE id = ?', [(int) $sub['id']]); return 400; }
    $payload = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($payload) > 3000) $payload = json_encode(array_merge($msg, ['body' => mb_substr((string) ($msg['body'] ?? ''), 0, 300)]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $body = push_encrypt($payload, b64u_dec((string) $sub['p256dh']), b64u_dec((string) $sub['auth']));
    $r = http_request('POST', (string) $sub['endpoint'], [
        'TTL: ' . $ttl, 'Urgency: ' . $urgency, 'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream',
        'Authorization: ' . vapid_header((string) $sub['endpoint']),
    ], $body, 15);
    $code = (int) $r['status'];
    if ($code === 404 || $code === 410) q('DELETE FROM push_subs WHERE id = ?', [(int) $sub['id']]);
    elseif ($code >= 200 && $code < 300) q('UPDATE push_subs SET last_ok = ?, fails = 0 WHERE id = ?', [time(), (int) $sub['id']]);
    else q('UPDATE push_subs SET fails = fails + 1 WHERE id = ?', [(int) $sub['id']]);
    return $code;
}
/** Powiadomienie na wszystkie urządzenia osoby. Zwraca liczbę dostarczonych. */
function push_user(int $userId, array $msg, int $ttl = 86400): int {
    $n = 0;
    foreach (qall('SELECT * FROM push_subs WHERE user_id = ? AND fails < 20', [$userId]) as $sub) {
        try { $c = push_send($sub, $msg, $ttl); if ($c >= 200 && $c < 300) $n++; }
        catch (Throwable $e) { error_log('PaperlyAI push: ' . $e->getMessage()); }
    }
    return $n;
}
function user_has_push(int $userId): bool { return (bool) q1('SELECT 1 AS x FROM push_subs WHERE user_id = ? AND fails < 20 LIMIT 1', [$userId]); }
