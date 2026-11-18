<?php
/**
 * PaperlyAI – pośrednik do Gemini z kluczem firmy zapisanym na serwerze.
 * Klucz nigdy nie trafia do przeglądarki. Każde zapytanie AI jest liczone (dzienny limit na osobę, zużycie firmy).
 *   gemini.php?p=v1beta/models/<model>:streamGenerateContent&alt=sse   (POST, strumień SSE)
 *   gemini.php?p=v1beta/models/<model>                                (GET, test modelu)
 *   gemini.php?p=upload/v1beta/files                                  (POST, start wysyłki pliku)
 *   gemini.php?u=<id>                                                 (POST, treść pliku)
 *   gemini.php?p=v1beta/files/<id>                                    (GET stan / DELETE)
 */
declare(strict_types=1);
require __DIR__ . '/lib/core.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function gem_fail(int $http, string $status, string $message): void {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => $http, 'status' => $status, 'message' => $message]], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) gem_fail(405, 'PAPERLY_METHOD', 'Niedozwolona metoda.');
    if ($method !== 'GET') {
        try { require_same_origin(); } catch (ApiError $e) { gem_fail(403, 'PAPERLY_CSRF', $e->getMessage()); }
    }
    $s = current_session();
    if (!$s) gem_fail(401, 'PAPERLY_AUTH', 'Sesja wygasła – zaloguj się ponownie przez Google.');
    if (!$s['ws']) gem_fail(409, 'PAPERLY_NO_WORKSPACE', 'Najpierw załóż konto firmy lub dołącz do zespołu.');
    [$key, $keySource] = resolve_gemini_key($s);
    if ($key === '') gem_fail(412, 'PAPERLY_NO_KEY', 'Dodaj swój klucz Gemini – zajmie to minutę, a za korzystanie z AI płacisz bezpośrednio Google (albo korzystasz z darmowego planu).');
    $uid = (int) $s['user']['id']; $wsId = (int) $s['ws']['id'];
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > upload_max_bytes()) {
        gem_fail(413, 'PAPERLY_TOO_LARGE', 'Pliki są za duże dla serwera (' . round($len / 1048576, 1) . ' MB, limit hostingu: ' . round(upload_max_bytes() / 1048576) . ' MB). Nic nie zostało wysłane.');
    }

    /* ---- treść pliku do wcześniej rozpoczętej wysyłki ---- */
    if (isset($_GET['u'])) {
        if ($method !== 'POST') gem_fail(405, 'PAPERLY_METHOD', 'Niedozwolona metoda.');
        $up = q1('SELECT * FROM uploads WHERE id = ? AND user_id = ?', [preg_replace('/[^a-f0-9]/', '', (string) $_GET['u']), $uid]);
        if (!$up || (int) $up['created_at'] < time() - 86400) gem_fail(404, 'PAPERLY_UPLOAD', 'Wysyłka pliku wygasła – spróbuj ponownie.');
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $in = fopen('php://input', 'rb');
        $headers = [];
        foreach (['HTTP_X_GOOG_UPLOAD_OFFSET' => 'X-Goog-Upload-Offset', 'HTTP_X_GOOG_UPLOAD_COMMAND' => 'X-Goog-Upload-Command'] as $sv => $h) {
            if (isset($_SERVER[$sv])) $headers[] = $h . ': ' . preg_replace('/[^\w ,.\-]/', '', (string) $_SERVER[$sv]);
        }
        // Treść pliku przekazujemy strumieniowo (bez wczytywania całego nagrania do pamięci)
        $headers[] = 'Content-Type: ' . preg_replace('/[^\w.+\/\-]/', '', (string) ($_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream'));
        $headers[] = 'Expect:';
        $ch = curl_init((string) $up['url']);
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_INFILESIZE => $len,
            CURLOPT_READFUNCTION => fn($ch, $fd, $n) => (string) fread($in, $n),
        ]);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (preg_match('/"x-goog-upload-status"|"file"\s*:/', (string) $out) || $status >= 200 && $status < 300) q('DELETE FROM uploads WHERE id = ?', [$up['id']]);
        http_response_code($status ?: 502);
        header('Content-Type: application/json; charset=utf-8');
        echo $out === false ? json_encode(['error' => ['code' => 502, 'message' => 'Brak odpowiedzi Google przy wysyłce pliku.']]) : $out;
        exit;
    }

    /* ---- dozwolone ścieżki Gemini API ---- */
    $p = (string) ($_GET['p'] ?? '');
    $isGenerate = (bool) preg_match('#^v1beta/models/[A-Za-z0-9.\-_]+:(streamGenerateContent|generateContent)$#', $p);
    $allowed = $isGenerate
        || ($method === 'POST' && preg_match('#^v1beta/models/[A-Za-z0-9.\-_]+:countTokens$#', $p))
        || ($method === 'GET' && preg_match('#^v1beta/models/[A-Za-z0-9.\-_]+$#', $p))
        || ($method === 'POST' && $p === 'upload/v1beta/files')
        || (in_array($method, ['GET', 'DELETE'], true) && preg_match('#^v1beta/files/[a-z0-9\-]{1,64}$#', $p));
    if (!$allowed) gem_fail(400, 'PAPERLY_PATH', 'Niedozwolone zapytanie do Gemini.');

    if ($isGenerate) {
        // Dzienny limit dotyczy tylko wspólnego klucza (firmy lub operatora) – z własnym kluczem płacisz sam
        $limit = in_array($keySource, ['company', 'operator'], true) ? member_limit($s) : 0;
        $used = usage_today($uid, $wsId)['requests'];
        if ($limit > 0 && $used >= $limit) {
            gem_fail(429, 'PAPERLY_LIMIT', "Wykorzystano dzienny limit AI w firmie ({$limit} zapytań na osobę). Limit odnowi się o północy – jeśli to pilne, poproś technika firmy o zwiększenie limitu. Nic nie zostało wysłane.");
        }
        upsert_add('usage_daily', ['day' => date('Y-m-d'), 'user_id' => $uid, 'ws_id' => $wsId], ['requests' => 1, 'tokens_in' => 0, 'tokens_out' => 0]);
    }

    $url = svc_url('gemini') . '/' . $p . (($_GET['alt'] ?? '') === 'sse' ? '?alt=sse' : '');
    $headers = ['x-goog-api-key: ' . $key];
    foreach (['CONTENT_TYPE' => 'Content-Type', 'HTTP_X_GOOG_UPLOAD_PROTOCOL' => 'X-Goog-Upload-Protocol', 'HTTP_X_GOOG_UPLOAD_COMMAND' => 'X-Goog-Upload-Command',
              'HTTP_X_GOOG_UPLOAD_HEADER_CONTENT_LENGTH' => 'X-Goog-Upload-Header-Content-Length', 'HTTP_X_GOOG_UPLOAD_HEADER_CONTENT_TYPE' => 'X-Goog-Upload-Header-Content-Type'] as $sv => $h) {
        if (isset($_SERVER[$sv])) $headers[] = $h . ': ' . preg_replace('/[^\w ,.;=\/+\-]/', '', (string) $_SERVER[$sv]);
    }
    $body = $method === 'POST' ? (string) file_get_contents('php://input') : null;

    // Strumień: wysyłamy fragmenty od razu, bez buforowania
    @ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    while (ob_get_level() > 0) ob_end_flush();
    ignore_user_abort(false);
    set_time_limit(600);

    $statusSent = false; $collected = ''; $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 600, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $q = strpos($line, ':');
            if ($q !== false) $respHeaders[strtolower(trim(substr($line, 0, $q)))] = trim(substr($line, $q + 1));
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$statusSent, &$collected, &$respHeaders, $isGenerate, $uid) {
            if (!$statusSent) {
                $statusSent = true;
                http_response_code((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 502);
                header('Content-Type: ' . ($respHeaders['content-type'] ?? 'application/json'));
                header('X-Accel-Buffering: no');
                if (!empty($respHeaders['x-goog-upload-url'])) {
                    $id = bin2hex(random_bytes(16));
                    q('INSERT INTO uploads (id, user_id, url, created_at) VALUES (?, ?, ?, ?)', [$id, $uid, $respHeaders['x-goog-upload-url'], time()]);
                    header('X-Goog-Upload-URL: ' . base_url() . app_path() . '/api/gemini.php?u=' . $id);
                    header('Access-Control-Expose-Headers: X-Goog-Upload-URL');
                }
            }
            if ($isGenerate && strlen($collected) < 4 * 1024 * 1024) $collected .= $chunk;
            echo $chunk;
            @flush();
            return connection_aborted() ? 0 : strlen($chunk);
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!$statusSent) {
        if ($ok === false) gem_fail(502, 'PAPERLY_UPSTREAM', 'Serwer nie połączył się z Google Gemini: ' . $err);
        http_response_code($status ?: 502);
        if (!empty($respHeaders['x-goog-upload-url'])) {
            $id = bin2hex(random_bytes(16));
            q('INSERT INTO uploads (id, user_id, url, created_at) VALUES (?, ?, ?, ?)', [$id, $uid, $respHeaders['x-goog-upload-url'], time()]);
            header('X-Goog-Upload-URL: ' . base_url() . app_path() . '/api/gemini.php?u=' . $id);
        }
    }
    // Zużycie tokenów z ostatniego fragmentu odpowiedzi (usageMetadata)
    if ($isGenerate) {
        if ($status < 200 || $status >= 300) {
            // Zapytanie odrzucone przez Google nie zużywa limitu firmy
            q('UPDATE usage_daily SET requests = CASE WHEN requests > 0 THEN requests - 1 ELSE 0 END WHERE day = ? AND user_id = ? AND ws_id = ?', [date('Y-m-d'), $uid, $wsId]);
        } elseif (preg_match_all('/"promptTokenCount"\s*:\s*(\d+)/', $collected, $pm) && $pm[1]) {
            $in = (int) end($pm[1]);
            preg_match_all('/"candidatesTokenCount"\s*:\s*(\d+)/', $collected, $cm);
            preg_match_all('/"thoughtsTokenCount"\s*:\s*(\d+)/', $collected, $tm);
            $out = (int) ($cm[1] ? end($cm[1]) : 0) + (int) ($tm[1] ? end($tm[1]) : 0);
            upsert_add('usage_daily', ['day' => date('Y-m-d'), 'user_id' => $uid, 'ws_id' => $wsId], ['requests' => 0, 'tokens_in' => $in, 'tokens_out' => $out]);
        }
    }
} catch (ApiError $e) {
    gem_fail($e->http, $e->codeName === 'PAPERLY_AUTH' ? 'PAPERLY_AUTH' : 'PAPERLY_' . strtoupper($e->codeName), $e->getMessage());
} catch (Throwable $e) {
    error_log('PaperlyAI gemini: ' . $e->getMessage());
    if (!headers_sent()) gem_fail(500, 'PAPERLY_SERVER', 'Błąd serwera przy zapytaniu do AI – spróbuj ponownie.');
}
