<?php
/**
 * Dane firmy po NIP: rejestr REGON (GUS, usługa BIR1.1 – z kluczem GUS_KEY)
 * albo – bez klucza lub gdy GUS nie odpowie – Wykaz podatników VAT Ministerstwa Finansów (biała lista).
 */
declare(strict_types=1);

function company_lookup(string $nip): array {
    $errors = [];
    if (cfg('GUS_KEY')) {
        try { $r = gus_lookup($nip, (string) cfg('GUS_KEY'), svc_url('gus')); if ($r) return $r; }
        catch (Throwable $e) { $errors[] = 'GUS: ' . $e->getMessage(); }
    }
    try { $r = mf_lookup($nip); if ($r) return $r; }
    catch (Throwable $e) { $errors[] = 'MF: ' . $e->getMessage(); }
    if ($errors) fail(502, 'lookup_failed', 'Rejestr nie odpowiedział – spróbuj za chwilę. (' . implode('; ', $errors) . ')');
    fail(404, 'not_found', 'Nie znaleziono firmy o tym numerze NIP.');
}

function gus_soap(string $url, string $action, string $body, string $sid = ''): string {
    $env = '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:ns="http://CIS/BIR/PUBL/2014/07" xmlns:dat="http://CIS/BIR/PUBL/2014/07/DataContract">'
        . '<soap:Header xmlns:wsa="http://www.w3.org/2005/08/addressing"><wsa:To>' . htmlspecialchars($url) . '</wsa:To>'
        . '<wsa:Action>http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/' . $action . '</wsa:Action></soap:Header>'
        . '<soap:Body>' . $body . '</soap:Body></soap:Envelope>';
    $h = ['Content-Type: application/soap+xml; charset=utf-8'];
    if ($sid !== '') $h[] = 'sid: ' . $sid;
    $r = http_request('POST', $url, $h, $env, 20);
    if ($r['status'] !== 200) throw new RuntimeException('HTTP ' . $r['status']);
    if (!preg_match('#<' . $action . 'Result>(.*?)</' . $action . 'Result>#s', $r['body'], $m)) return '';
    return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function gus_lookup(string $nip, string $key, string $url): ?array {
    $sid = trim(gus_soap($url, 'Zaloguj', '<ns:Zaloguj><ns:pKluczUzytkownika>' . htmlspecialchars($key) . '</ns:pKluczUzytkownika></ns:Zaloguj>'));
    if ($sid === '') throw new RuntimeException('nieprawidłowy klucz GUS');
    $xml = gus_soap($url, 'DaneSzukajPodmioty', '<ns:DaneSzukajPodmioty><ns:pParametryWyszukiwania><dat:Nip>' . $nip . '</dat:Nip></ns:pParametryWyszukiwania></ns:DaneSzukajPodmioty>', $sid);
    if (trim($xml) === '') return null;
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    libxml_use_internal_errors($prev);
    if (!$doc || !isset($doc->dane)) return null;
    $d = $doc->dane[0];
    if (isset($d->ErrorCode)) return null;
    $g = fn($k) => trim((string) ($d->$k ?? ''));
    $street = trim($g('Ulica') . ' ' . $g('NrNieruchomosci') . ($g('NrLokalu') !== '' ? '/' . $g('NrLokalu') : ''));
    $city = $g('MiejscowoscPoczty') ?: $g('Miejscowosc');
    if ($street === '') $street = trim($g('Miejscowosc') . ' ' . $g('NrNieruchomosci'));
    return [
        'source' => 'GUS (REGON)', 'name' => $g('Nazwa'), 'nip' => $g('Nip') ?: $nip, 'regon' => $g('Regon'),
        'street' => $street, 'postal' => $g('KodPocztowy'), 'city' => $city,
        'address' => trim($street . ', ' . trim($g('KodPocztowy') . ' ' . $city), ', '),
        'active' => $g('DataZakonczeniaDzialalnosci') === '', 'vat' => '', 'accounts' => [],
    ];
}

function mf_lookup(string $nip): ?array {
    $r = http_json('GET', svc_url('mf') . '/api/search/nip/' . $nip . '?date=' . date('Y-m-d'), [], null, 20);
    if ($r['status'] === 400 || $r['status'] === 404) return null;
    if ($r['status'] !== 200 || !is_array($r['json'])) throw new RuntimeException('HTTP ' . $r['status']);
    $s = $r['json']['result']['subject'] ?? null;
    if (!$s) return null;
    $addr = (string) ($s['workingAddress'] ?? '') ?: (string) ($s['residenceAddress'] ?? '');
    $postal = ''; $city = ''; $street = $addr;
    if (preg_match('/^(.*?),\s*(\d{2}-\d{3})\s+(.+)$/u', $addr, $m)) { $street = trim($m[1]); $postal = $m[2]; $city = trim($m[3]); }
    $fixCase = fn($t) => preg_match('/\p{Ll}/u', $t) ? $t : mb_convert_case(mb_strtolower($t), MB_CASE_TITLE);
    return [
        'source' => 'Wykaz podatników VAT (MF)', 'name' => (string) ($s['name'] ?? ''), 'nip' => (string) ($s['nip'] ?? $nip), 'regon' => (string) ($s['regon'] ?? ''),
        'street' => $fixCase($street), 'postal' => $postal, 'city' => $fixCase($city),
        'address' => $fixCase($street) . ($postal ? ', ' . $postal . ' ' . $fixCase($city) : ''),
        'active' => true, 'vat' => (string) ($s['statusVat'] ?? ''), 'accounts' => array_slice((array) ($s['accountNumbers'] ?? []), 0, 5),
    ];
}
