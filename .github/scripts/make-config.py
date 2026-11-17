"""Tworzy api/_config.php z sekretów GitHub (zmienne środowiskowe) – plik trafia tylko na hosting, nie do repozytorium.
Wypisuje wyłącznie nazwy ustawionych sekretów i włączone funkcje (bez wartości)."""
import os, sys

KEYS = [
    'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
    'APP_SECRET', 'CRON_SECRET', 'PUBLIC_URL',
    'GEMINI_API_KEY', 'DAILY_LIMIT',
    'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET',
    'SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURE', 'SMTP_USER', 'SMTP_PASS', 'MAIL_FROM', 'MAIL_FROM_NAME',
    'GUS_KEY', 'MS_CLIENT_ID',
]
INT_KEYS = {'DAILY_LIMIT', 'SMTP_PORT'}

def php_str(v):
    return "'" + v.replace('\\', '\\\\').replace("'", "\\'") + "'"

out = sys.argv[1]
vals = {}
for k in KEYS:
    v = os.environ.get(k, '').strip().replace('\r', '').replace('\n', '')
    if v:
        vals[k] = v
lines = ['<?php', '// PaperlyAI – konfiguracja serwera wygenerowana z sekretów GitHub przy wgrywaniu. Nie edytuj ręcznie.', 'return [']
for k, v in vals.items():
    if k in INT_KEYS and v.isdigit():
        lines.append(f"    '{k}' => {int(v)},")
    else:
        lines.append(f"    '{k}' => {php_str(v)},")
lines.append('];')
with open(out, 'w', encoding='utf-8') as f:
    f.write('\n'.join(lines) + '\n')

has = lambda *ks: all(k in vals for k in ks)
print('Ustawione sekrety serwera:', ', '.join(vals.keys()) or 'brak')
print('Baza danych (konta, zespół, synchronizacja):', 'TAK' if has('DB_NAME', 'DB_USER', 'DB_PASS') else 'NIE – dodaj DB_HOST, DB_NAME, DB_USER, DB_PASS')
print('Logowanie Google na serwerze:', 'TAK' if has('GOOGLE_CLIENT_ID') else 'NIE – dodaj GOOGLE_CLIENT_ID')
print('Klucz AI firmy:', 'TAK' if has('GEMINI_API_KEY') else 'NIE (każdy używa własnego klucza) – GEMINI_API_KEY')
print('Gmail bez ponownego logowania:', 'TAK' if has('GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET') else 'NIE – GOOGLE_CLIENT_SECRET')
print('E-maile:', 'SMTP' if has('SMTP_HOST') else 'funkcja mail() serwera (zalecane SMTP_HOST, SMTP_USER, SMTP_PASS)')
print('Rejestr REGON (GUS):', 'TAK' if has('GUS_KEY') else 'wykaz VAT Ministerstwa Finansów (bez klucza)')
if not has('APP_SECRET'):
    print('::warning::Brak sekretu APP_SECRET – klucz szyfrowania zostanie wyprowadzony z hasła bazy. Zalecane: losowy ciąg min. 32 znaków.')
