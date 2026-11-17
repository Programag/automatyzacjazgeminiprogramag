<?php
/**
 * Wysyłka e-maili z własnej domeny (np. powiadomienia@paperlyai.pl) przez SMTP hostingu.
 * Bez SMTP_HOST używa funkcji mail() serwera.
 */
declare(strict_types=1);

function mail_from(): array {
    $host = preg_replace('/^www\./', '', (string) parse_url(base_url(), PHP_URL_HOST));
    return [(string) cfg('MAIL_FROM', cfg('SMTP_USER', 'powiadomienia@' . ($host ?: 'localhost'))), (string) cfg('MAIL_FROM_NAME', 'PaperlyAI')];
}
function mail_configured(): bool { return (bool) cfg('SMTP_HOST') || (bool) cfg('MAIL_ALLOW_PHPMAIL', true); }
function mail_mode(): string { return cfg('SMTP_HOST') ? 'smtp' : 'mail()'; }

function mime_word(string $s): string { return preg_match('/^[\x20-\x7e]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?='; }
function mime_addr(string $email, string $name = ''): string { return $name === '' ? "<$email>" : mime_word($name) . " <$email>"; }

/** Prosty szablon wiadomości: nagłówek, akapity/HTML, przycisk. */
function mail_layout(string $title, string $html, string $buttonText = '', string $buttonUrl = ''): string {
    $btn = $buttonText ? '<p style="margin:24px 0"><a href="' . htmlspecialchars($buttonUrl) . '" style="background:#a400e3;color:#fff;text-decoration:none;padding:11px 22px;border-radius:999px;font-weight:600;display:inline-block">' . htmlspecialchars($buttonText) . '</a></p>' : '';
    $logo = base_url() . app_path() . '/img/logo.png';
    return '<!doctype html><html lang="pl"><body style="margin:0;background:#f6f2fc;font-family:Segoe UI,Arial,sans-serif;color:#27005f">'
        . '<div style="max-width:600px;margin:0 auto;padding:24px 16px">'
        . '<div style="background:#27005f;border-radius:14px 14px 0 0;padding:14px 20px;border-bottom:3px solid #f9aa63"><img src="' . htmlspecialchars($logo) . '" alt="" width="28" height="28" style="vertical-align:middle;background:#fff;border-radius:8px;padding:3px"> <span style="vertical-align:middle;font-weight:700;font-size:17px;color:#fff;margin-left:6px">PaperlyAI</span></div>'
        . '<div style="background:#fff;border:1px solid #e4def2;border-top:0;border-radius:0 0 14px 14px;padding:24px">'
        . '<h1 style="font-size:20px;margin:0 0 12px;color:#27005f">' . htmlspecialchars($title) . '</h1>' . $html . $btn . '</div>'
        . '<p style="font-size:12px;color:#645880;margin-top:16px">Wiadomość wysłana automatycznie przez PaperlyAI (' . htmlspecialchars(base_url()) . '). Powiadomienia wyłączysz w aplikacji: Profil → Powiadomienia e-mail.</p>'
        . '</div></body></html>';
}

function send_mail(string $to, string $subject, string $html, string $text = ''): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Nieprawidłowy adres odbiorcy.');
    [$from, $fromName] = mail_from();
    if ($text === '') $text = trim(html_entity_decode(strip_tags(preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|h1|h2|li|tr)>/i'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $boundary = 'pb_' . bin2hex(random_bytes(8));
    $headers = [
        'Date: ' . date('r'),
        'From: ' . mime_addr($from, $fromName),
        'To: ' . mime_addr($to),
        'Subject: ' . mime_word($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Auto-Submitted: auto-generated',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
        . "--$boundary--\r\n";
    if (!cfg('SMTP_HOST')) {
        $extra = array_values(array_filter($headers, fn($h) => !preg_match('/^(To|Subject):/i', $h)));
        if (!mail($to, mime_word($subject), $body, implode("\r\n", $extra), '-f' . $from)) throw new RuntimeException('Serwer nie wysłał wiadomości (mail()). Ustaw SMTP_HOST, SMTP_USER i SMTP_PASS.');
        return;
    }
    smtp_send($from, $to, implode("\r\n", $headers) . "\r\n\r\n" . $body);
}

function smtp_send(string $from, string $to, string $message): void {
    $host = (string) cfg('SMTP_HOST');
    $port = (int) cfg('SMTP_PORT', 465);
    $secure = strtolower((string) cfg('SMTP_SECURE', $port === 465 ? 'ssl' : 'tls'));
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("SMTP: brak połączenia z $host:$port ($errstr)");
    stream_set_timeout($fp, 30);
    $read = function () use ($fp): array {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) { $data .= $line; if (strlen($line) < 4 || $line[3] !== '-') break; }
        return [(int) substr($data, 0, 3), trim($data)];
    };
    $cmd = function (string $c, array $ok) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        [$code, $resp] = $read();
        if (!in_array($code, $ok, true)) {
            $shown = preg_match('/^(AUTH|[A-Za-z0-9+\/=]{8,}$)/', $c) ? '(logowanie)' : $c;
            throw new RuntimeException("SMTP: serwer odrzucił $shown – $resp");
        }
        return $resp;
    };
    try {
        [$code, $greet] = $read();
        if ($code !== 220) throw new RuntimeException('SMTP: ' . $greet);
        $ehlo = preg_replace('/[^a-z0-9.\-]/i', '', (string) parse_url(base_url(), PHP_URL_HOST)) ?: 'localhost';
        $cmd("EHLO $ehlo", [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0))) throw new RuntimeException('SMTP: nie udało się włączyć szyfrowania (STARTTLS).');
            $cmd("EHLO $ehlo", [250]);
        }
        if (cfg('SMTP_USER')) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string) cfg('SMTP_USER')), [334]);
            $cmd(base64_encode((string) cfg('SMTP_PASS', '')), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message));
        $cmd(str_replace("\n", "\r\n", $data) . "\r\n.", [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}
