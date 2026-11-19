<?php
/**
 * KSeF 2.0 – wystawianie faktur sprzedaży (FA(3)) z aplikacji.
 * Kwoty liczone są na serwerze (nie ufamy sumom z przeglądarki), XML zgodny ze schematem FA(3) 1-0E
 * (http://crd.gov.pl/wzor/2025/06/25/13775/), wysyłka w sesji interaktywnej: klucz AES-256 zaszyfrowany
 * kluczem publicznym MF (RSA-OAEP SHA-256), faktura zaszyfrowana AES-256-CBC, potem status i numer KSeF.
 */
declare(strict_types=1);
require_once __DIR__ . '/ksef.php';

const FA3_NS = 'http://crd.gov.pl/wzor/2025/06/25/13775/';
// Obsługiwane stawki: stawka => [mnożnik VAT, pole netto, pole VAT]
const INV_RATES = [
    '23' => [0.23, 'P_13_1', 'P_14_1'],
    '8' => [0.08, 'P_13_2', 'P_14_2'],
    '5' => [0.05, 'P_13_3', 'P_14_3'],
    '0 KR' => [0.0, 'P_13_6_1', null],
    'zw' => [0.0, 'P_13_7', null],
];
const INV_PAY_FORMS = ['przelew' => 6, 'gotowka' => 1, 'karta' => 2];

function inv_money(float $v): string { return number_format(round($v + 0.0, 2), 2, '.', ''); }
function inv_qty(float $v): string { return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') ?: '0'; }
function inv_text($v, int $max): string { return trim(mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v), 0, $max)); }
function inv_date($v): string { $v = (string) $v; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : ''; }

/** Dane sprzedawcy (profil faktur firmy) – zapisane w ustawieniach firmy. */
function inv_profile(array $ws): array {
    $set = ws_settings($ws);
    $p = is_array($set['invoiceProfile'] ?? null) ? $set['invoiceProfile'] : [];
    return array_merge([
        'name' => (string) $ws['name'], 'nip' => (string) ($set['ksefNip'] ?? $ws['nip']), 'l1' => '', 'l2' => '', 'email' => '', 'phone' => '',
        'account' => '', 'bank' => '', 'place' => '', 'series' => 'FV/{n}/{MM}/{RRRR}', 'payDays' => 14, 'zwBasis' => '',
    ], $p);
}
function inv_profile_save(array $ws, array $b): array {
    $p = inv_profile($ws);
    foreach (['name' => 250, 'l1' => 250, 'l2' => 250, 'email' => 190, 'phone' => 40, 'bank' => 120, 'place' => 80, 'series' => 60, 'zwBasis' => 250] as $k => $max)
        if (array_key_exists($k, $b)) $p[$k] = inv_text($b[$k], $max);
    if (array_key_exists('nip', $b)) { $n = preg_replace('/\D/', '', (string) $b['nip']); if ($n !== '' && !valid_nip($n)) fail(400, 'bad_nip', 'Nieprawidłowy NIP sprzedawcy.'); $p['nip'] = $n; }
    if (array_key_exists('account', $b)) { $a = preg_replace('/[^0-9A-Z]/', '', strtoupper((string) $b['account'])); if ($a !== '' && !preg_match('/^(PL)?\d{26}$/', $a)) fail(400, 'bad_account', 'Numer konta powinien mieć 26 cyfr.'); $p['account'] = preg_replace('/^PL/', '', $a); }
    if (array_key_exists('payDays', $b)) $p['payDays'] = max(0, min(120, (int) $b['payDays']));
    if ($p['email'] !== '' && !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) fail(400, 'bad_email', 'Nieprawidłowy e-mail sprzedawcy.');
    $set = ws_settings($ws); $set['invoiceProfile'] = $p; save_ws_settings((int) $ws['id'], $set);
    return $p;
}

/** Następny numer faktury według wzoru serii, np. FV/{n}/{MM}/{RRRR} – licznik w miesiącu (albo w roku, gdy wzór nie ma {MM}). */
function inv_next_number(array $ws, ?string $date = null): string {
    $p = inv_profile($ws);
    $t = strtotime(($date ?: date('Y-m-d')) . ' 12:00:00') ?: time();
    $series = $p['series'] ?: 'FV/{n}/{MM}/{RRRR}';
    $perMonth = str_contains($series, '{MM}');
    $prefix = $perMonth ? date('Y-m', $t) : date('Y', $t);
    $used = array_column(qall("SELECT number FROM ksef_issued WHERE ws_id = ? AND issue_date LIKE ? AND status <> 'rejected'", [(int) $ws['id'], $prefix . '%']), 'number');
    for ($n = count($used) + 1; $n < 100000; $n++) {
        $num = strtr($series, ['{n}' => (string) $n, '{MM}' => date('m', $t), '{RRRR}' => date('Y', $t), '{RR}' => date('y', $t)]);
        if (!in_array($num, $used, true)) return $num;
    }
    return 'FV/' . time();
}

/** Sprawdza i przelicza fakturę z przeglądarki. Zwraca komplet danych do XML i zapisu. */
function inv_normalize(array $ws, array $b): array {
    $seller = inv_profile($ws);
    if (!valid_nip((string) $seller['nip'])) fail(400, 'seller_nip', 'Uzupełnij NIP sprzedawcy w danych do faktur.');
    if ($seller['name'] === '' || $seller['l1'] === '') fail(400, 'seller_data', 'Uzupełnij nazwę i adres sprzedawcy w danych do faktur.');
    $buyer = is_array($b['buyer'] ?? null) ? $b['buyer'] : [];
    $bNip = preg_replace('/\D/', '', (string) ($buyer['nip'] ?? ''));
    $consumer = !empty($buyer['consumer']);
    if (!$consumer && !valid_nip($bNip)) fail(400, 'buyer_nip', 'Podaj prawidłowy NIP nabywcy (albo zaznacz „osoba prywatna”).');
    $b2 = ['nip' => $consumer ? '' : $bNip, 'name' => inv_text($buyer['name'] ?? '', 250), 'l1' => inv_text($buyer['l1'] ?? '', 250), 'l2' => inv_text($buyer['l2'] ?? '', 250), 'email' => inv_text($buyer['email'] ?? '', 190), 'consumer' => $consumer];
    if ($b2['name'] === '') fail(400, 'buyer_name', 'Podaj nazwę nabywcy.');
    if ($b2['email'] !== '' && !filter_var($b2['email'], FILTER_VALIDATE_EMAIL)) fail(400, 'buyer_email', 'Nieprawidłowy e-mail nabywcy.');
    $number = inv_text($b['number'] ?? '', 60);
    if ($number === '') fail(400, 'number', 'Podaj numer faktury.');
    if (q1("SELECT 1 AS x FROM ksef_issued WHERE ws_id = ? AND number = ? AND status IN ('accepted', 'processing', 'sent')", [(int) $ws['id'], $number])) fail(409, 'duplicate', "Faktura o numerze $number została już wystawiona.");
    $issue = inv_date($b['issueDate'] ?? '') ?: date('Y-m-d');
    if ($issue > date('Y-m-d')) fail(400, 'issue_date', 'Data wystawienia nie może być z przyszłości.');
    $sale = inv_date($b['saleDate'] ?? '') ?: $issue;
    $due = inv_date($b['dueDate'] ?? '');
    $pay = INV_PAY_FORMS[(string) ($b['payment'] ?? 'przelew')] ?? 6;
    $items = []; $sums = [];
    foreach (array_slice((array) ($b['items'] ?? []), 0, 200) as $it) {
        $name = inv_text($it['name'] ?? '', 512);
        $qty = round((float) str_replace(',', '.', (string) ($it['qty'] ?? 0)), 6);
        $price = round((float) str_replace(',', '.', (string) ($it['price'] ?? 0)), 2);
        $rate = (string) ($it['vat'] ?? '23');
        if ($name === '' && !$qty) continue;
        if ($name === '') fail(400, 'item_name', 'Każda pozycja musi mieć nazwę.');
        if ($qty <= 0 || $price < 0) fail(400, 'item_qty', "Pozycja „{$name}”: ilość musi być większa od zera, a cena nie może być ujemna.");
        if (!isset(INV_RATES[$rate])) fail(400, 'item_vat', "Pozycja „{$name}”: nieobsługiwana stawka VAT ($rate).");
        $net = round($qty * $price, 2);
        $items[] = ['name' => $name, 'unit' => inv_text($it['unit'] ?? 'szt.', 20) ?: 'szt.', 'qty' => $qty, 'price' => $price, 'net' => $net, 'vat' => $rate];
        $sums[$rate] = ($sums[$rate] ?? 0) + $net;
    }
    if (!$items) fail(400, 'items', 'Dodaj co najmniej jedną pozycję faktury.');
    if (isset($sums['zw']) && $seller['zwBasis'] === '' && inv_text($b['zwBasis'] ?? '', 250) === '') fail(400, 'zw_basis', 'Przy stawce „zw” podaj podstawę zwolnienia (np. art. 43 ust. 1 pkt 37 ustawy o VAT).');
    $totals = []; $net = 0.0; $vat = 0.0;
    foreach ($sums as $rate => $n) {
        $v = round($n * INV_RATES[$rate][0], 2);              // VAT od sumy wartości netto w danej stawce
        $totals[$rate] = ['net' => round($n, 2), 'vat' => $v];
        $net += $n; $vat += $v;
    }
    return [
        'seller' => $seller, 'buyer' => $b2, 'number' => $number, 'issueDate' => $issue, 'saleDate' => $sale, 'dueDate' => $due,
        'place' => inv_text($b['place'] ?? $seller['place'], 80), 'payment' => $pay, 'account' => $seller['account'], 'bank' => $seller['bank'],
        'items' => $items, 'totals' => $totals, 'net' => round($net, 2), 'vat' => round($vat, 2), 'gross' => round($net + $vat, 2),
        'zwBasis' => inv_text($b['zwBasis'] ?? '', 250) ?: $seller['zwBasis'], 'note' => inv_text($b['note'] ?? '', 500), 'currency' => 'PLN',
    ];
}

/** XML faktury FA(3). */
function inv_fa3_xml(array $inv): string {
    $x = new XMLWriter();
    $x->openMemory(); $x->setIndent(true); $x->setIndentString('  ');
    $x->startDocument('1.0', 'UTF-8');
    $x->startElement('Faktura');
    $x->writeAttribute('xmlns', FA3_NS);
    $el = function (string $n, $v) use ($x) { $x->writeElement($n, (string) $v); };
    $x->startElement('Naglowek');
        $x->startElement('KodFormularza'); $x->writeAttribute('kodSystemowy', 'FA (3)'); $x->writeAttribute('wersjaSchemy', '1-0E'); $x->text('FA'); $x->endElement();
        $el('WariantFormularza', 3);
        $el('DataWytworzeniaFa', gmdate('Y-m-d\TH:i:s\Z'));
        $el('SystemInfo', 'PaperlyAI');
    $x->endElement();
    $party = function (string $tag, array $p, bool $buyer) use ($x, $el) {
        $x->startElement($tag);
        $x->startElement('DaneIdentyfikacyjne');
        if ($buyer && $p['consumer']) $el('BrakID', 1); else $el('NIP', $p['nip']);
        $el('Nazwa', $p['name']);
        $x->endElement();
        if ($p['l1'] !== '') { $x->startElement('Adres'); $el('KodKraju', 'PL'); $el('AdresL1', $p['l1']); if ($p['l2'] !== '') $el('AdresL2', $p['l2']); $x->endElement(); }
        $email = (string) ($p['email'] ?? ''); $phone = (string) ($p['phone'] ?? '');
        if ($email !== '' || $phone !== '') { $x->startElement('DaneKontaktowe'); if ($email !== '') $el('Email', $email); if ($phone !== '') $el('Telefon', $phone); $x->endElement(); }
        if ($buyer) { $el('JST', 2); $el('GV', 2); }
        $x->endElement();
    };
    $party('Podmiot1', $inv['seller'], false);
    $party('Podmiot2', $inv['buyer'], true);
    $x->startElement('Fa');
        $el('KodWaluty', $inv['currency']);
        $el('P_1', $inv['issueDate']);
        if ($inv['place'] !== '') $el('P_1M', $inv['place']);
        $el('P_2', $inv['number']);
        if ($inv['saleDate'] !== $inv['issueDate']) $el('P_6', $inv['saleDate']);
        foreach (INV_RATES as $rate => [$mul, $netField, $vatField]) {
            if (!isset($inv['totals'][$rate])) continue;
            $el($netField, inv_money($inv['totals'][$rate]['net']));
            if ($vatField) $el($vatField, inv_money($inv['totals'][$rate]['vat']));
        }
        $el('P_15', inv_money($inv['gross']));
        $x->startElement('Adnotacje');
            $el('P_16', 2); $el('P_17', 2); $el('P_18', 2); $el('P_18A', 2);
            $x->startElement('Zwolnienie');
            if (isset($inv['totals']['zw'])) { $el('P_19', 1); $el('P_19A', $inv['zwBasis']); } else $el('P_19N', 1);
            $x->endElement();
            $x->startElement('NoweSrodkiTransportu'); $el('P_22N', 1); $x->endElement();
            $el('P_23', 2);
            $x->startElement('PMarzy'); $el('P_PMarzyN', 1); $x->endElement();
        $x->endElement();
        $el('RodzajFaktury', 'VAT');
        if ($inv['note'] !== '') { $x->startElement('DodatkowyOpis'); $el('Klucz', 'Uwagi'); $el('Wartosc', $inv['note']); $x->endElement(); }
        foreach ($inv['items'] as $i => $it) {
            $x->startElement('FaWiersz');
            $el('NrWierszaFa', $i + 1);
            $el('P_7', $it['name']);
            $el('P_8A', $it['unit']);
            $el('P_8B', inv_qty($it['qty']));
            $el('P_9A', inv_money($it['price']));
            $el('P_11', inv_money($it['net']));
            $el('P_12', $it['vat']);
            $x->endElement();
        }
        if ($inv['dueDate'] !== '' || $inv['account'] !== '') {
            $x->startElement('Platnosc');
            if ($inv['dueDate'] !== '') { $x->startElement('TerminPlatnosci'); $el('Termin', $inv['dueDate']); $x->endElement(); }
            $el('FormaPlatnosci', $inv['payment']);
            if ($inv['account'] !== '' && $inv['payment'] === 6) { $x->startElement('RachunekBankowy'); $el('NrRB', $inv['account']); if ($inv['bank'] !== '') $el('NazwaBanku', $inv['bank']); $x->endElement(); }
            $x->endElement();
        }
    $x->endElement();
    $x->endElement();
    $x->endDocument();
    return $x->outputMemory();
}

/** Klucz publiczny MF do szyfrowania klucza sesji (SymmetricKeyEncryption). */
function ksef_symmetric_cert(string $env): string {
    $r = ksef_call($env, 'GET', '/security/public-key-certificates', null);
    if ($r['status'] !== 200 || !is_array($r['json'])) throw ksef_error($r, 'klucz publiczny');
    foreach ($r['json'] as $c) {
        if (in_array('SymmetricKeyEncryption', (array) ($c['usage'] ?? []), true) && strtotime((string) $c['validTo']) > time()) {
            return "-----BEGIN CERTIFICATE-----\n" . chunk_split((string) $c['certificate'], 64, "\n") . "-----END CERTIFICATE-----\n";
        }
    }
    throw new RuntimeException('KSeF nie udostępnił klucza do szyfrowania faktur.');
}

/** Wysyła fakturę w sesji interaktywnej. Zwraca [env, sessionRef, invoiceRef]. */
function ksef_send_invoice(array $ws, string $xml): array {
    [$env, $access] = ksef_access_token($ws);
    $key = random_bytes(32); $iv = random_bytes(16);
    $encKey = base64_encode(rsa_oaep_sha256_encrypt($key, ksef_symmetric_cert($env)));
    $open = function () use (&$env, &$access, $encKey, $iv, $ws) {
        return ksef_call($env, 'POST', '/sessions/online', $access, [
            'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'],
            'encryption' => ['encryptedSymmetricKey' => $encKey, 'initializationVector' => base64_encode($iv)],
        ]);
    };
    $s = $open();
    if ($s['status'] === 401) { [$env, $access] = ksef_access_token($ws, true); $s = $open(); }
    if (!in_array($s['status'], [200, 201], true) || empty($s['json']['referenceNumber'])) throw ksef_error($s, 'otwarcie sesji');
    $sessionRef = (string) $s['json']['referenceNumber'];
    $enc = openssl_encrypt($xml, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($enc === false) throw new RuntimeException('Szyfrowanie faktury nie powiodło się.');
    $r = ksef_call($env, 'POST', '/sessions/online/' . rawurlencode($sessionRef) . '/invoices', $access, [
        'invoiceHash' => base64_encode(hash('sha256', $xml, true)), 'invoiceSize' => strlen($xml),
        'encryptedInvoiceHash' => base64_encode(hash('sha256', $enc, true)), 'encryptedInvoiceSize' => strlen($enc),
        'encryptedInvoiceContent' => base64_encode($enc), 'offlineMode' => false,
    ]);
    if (!in_array($r['status'], [200, 201, 202], true) || empty($r['json']['referenceNumber'])) throw ksef_error($r, 'wysłanie faktury');
    $invoiceRef = (string) $r['json']['referenceNumber'];
    ksef_call($env, 'POST', '/sessions/online/' . rawurlencode($sessionRef) . '/close', $access);   // zamknięcie sesji (UPO); błąd nie cofa wysłanej faktury
    return [$env, $sessionRef, $invoiceRef];
}

/** Stan faktury w KSeF: ['state' => processing|accepted|rejected, 'ksefNumber' => ..., 'message' => ...]. */
function ksef_invoice_state(array $ws, string $sessionRef, string $invoiceRef): array {
    [$env, $access] = ksef_access_token($ws);
    $path = '/sessions/' . rawurlencode($sessionRef) . '/invoices/' . rawurlencode($invoiceRef);
    $r = ksef_call($env, 'GET', $path, $access);
    if ($r['status'] === 401) { [$env, $access] = ksef_access_token($ws, true); $r = ksef_call($env, 'GET', $path, $access); }
    if ($r['status'] !== 200 || !is_array($r['json'])) throw ksef_error($r, 'stan faktury');
    $code = (int) ($r['json']['status']['code'] ?? 0);
    $msg = trim((string) ($r['json']['status']['description'] ?? '') . ' ' . implode(' ', array_map('strval', (array) ($r['json']['status']['details'] ?? []))));
    if ($code === 200 && !empty($r['json']['ksefNumber'])) return ['state' => 'accepted', 'ksefNumber' => (string) $r['json']['ksefNumber'], 'message' => $msg];
    if ($code >= 300) return ['state' => 'rejected', 'ksefNumber' => '', 'message' => $msg ?: "Kod $code"];
    return ['state' => 'processing', 'ksefNumber' => '', 'message' => $msg];
}

function inv_row_public(array $r): array {
    return [
        'id' => (int) $r['id'], 'env' => $r['env'], 'number' => $r['number'], 'buyer' => $r['buyer'], 'buyerNip' => $r['buyer_nip'], 'buyerEmail' => $r['buyer_email'],
        'issueDate' => $r['issue_date'], 'dueDate' => $r['due_date'], 'net' => $r['net'], 'gross' => $r['gross'], 'currency' => $r['currency'],
        'status' => $r['status'], 'ksefNumber' => $r['ksef_number'], 'error' => (string) ($r['error'] ?? ''), 'paid' => (bool) (int) $r['paid'],
        'createdAt' => (int) $r['created_at'] * 1000,
    ];
}
