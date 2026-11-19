"""Wpisuje dane operatora (z sekretów GitHub) do stron informacyjnych przed wgraniem na hosting.
Puste wartości: elementy z data-req="KLUCZ" są usuwane, a pozostałe pola dostają wartości domyślne."""
import os, re, sys
site = sys.argv[1]
host = (os.environ.get('SITE_HOST') or 'paperlyai.pl').strip()
vals = {
    'OPERATOR_NAME': os.environ.get('OPERATOR_NAME', '').strip() or 'PaperlyAI',
    'OPERATOR_ADDRESS': os.environ.get('OPERATOR_ADDRESS', '').strip(),
    'OPERATOR_NIP': os.environ.get('OPERATOR_NIP', '').strip(),
    'CONTACT_EMAIL': os.environ.get('CONTACT_EMAIL', '').strip() or 'kontakt@' + re.sub(r'^www\.', '', host),
    'SITE_URL': 'https://' + host,
    'SITE_HOST': host,
}
esc = lambda s: s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;').replace('"', '&quot;')
for name in ('prywatnosc.html', 'regulamin.html', 'o-aplikacji.html', 'o-aplikacji-bez-gradientow.html'):
    p = os.path.join(site, name)
    if not os.path.exists(p): continue
    s = open(p, encoding='utf-8').read()
    for k, v in vals.items():
        if not v:
            s = re.sub(r'<(\w+)[^>]*data-req="%s"[^>]*>.*?</\1>' % k, '', s, flags=re.S)
    s = re.sub(r'\{\{([A-Z_]+)\}\}', lambda m: esc(vals.get(m.group(1), '')), s)
    open(p, 'w', encoding='utf-8').write(s)
    print('uzupełniono', name)
