"""Tworzy wersję PaperlyAI bez gradientów (płaskie kolory) z index.html, o-aplikacji.html i site.css.

Użycie: python3 flatten.py <katalog_źródłowy> <katalog_wyjściowy>
Uruchamiane przy każdym wgrywaniu na hosting, więc druga wersja zawsze ma te same funkcje co główna.
Wynik: index-bez-gradientow.html, o-aplikacji-bez-gradientow.html, site-bez-gradientow.css.
Kończy się błędem, gdy któryś fragment do zamiany zniknął z index.html albo został jakiś gradient.
"""
import re, sys
root, out = sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else sys.argv[1]
src = open(f'{root}/index.html', encoding='utf-8').read()

def rep(s, old, new, count=1):
    n = s.count(old)
    if n != count:
        raise SystemExit(f'Oczekiwano {count}x, jest {n}x: {old[:90]!r}')
    return s.replace(old, new)

R = [
 # tło strony
 ("""      background:
        radial-gradient(1100px 620px at 105% -12%, rgb(var(--fx-brand-a) / var(--fx-aurora-a)), transparent 62%),
        radial-gradient(900px 560px at -8% 108%, rgb(var(--fx-brand-b) / var(--fx-aurora-b)), transparent 60%),
        rgb(var(--c-canvas));
      background-attachment: fixed;""", "      background: rgb(var(--c-canvas));"),
 ("background: linear-gradient(90deg, transparent, rgb(var(--fx-brand-a) / .7) 30%, rgb(var(--fx-brand-b) / .7) 70%, transparent); background-size: 200% 100%; transition",
  "background: rgb(var(--fx-brand-a) / .7); transition"),
 ("body.ai-busy .app-header::after { opacity: 1; height: 2px; animation: fxFlow 1.4s linear infinite; }",
  "body.ai-busy .app-header::after { opacity: 1; height: 2px; animation: fxBlinkFlat 1.4s ease-in-out infinite; }"),
 ("#sidebar { background-image: linear-gradient(180deg, transparent 50%, rgb(var(--fx-brand-a) / .05)); }", ""),
 ("background: linear-gradient(135deg, rgb(var(--fx-brand-a)), rgb(var(--fx-brand-b))) !important;\n", "background: rgb(var(--fx-brand-a)) !important;\n"),
 ("""    .brand-tile::after { content: ""; position: absolute; inset: 0; background: linear-gradient(115deg, transparent 30%, rgb(255 255 255 / .5) 50%, transparent 70%); transform: translateX(-130%); animation: fxBrandShine 7s var(--ease-out) 1.5s infinite; }\n""", ""),
 (".rounded-t-2xl.bg-slate-50 { background-image: linear-gradient(180deg, rgb(var(--c-canvas) / .65), rgb(var(--c-canvas) / 0)); }", ""),
 ("#formIcon { background: linear-gradient(135deg, rgb(var(--fx-brand-a) / .18), rgb(var(--fx-brand-b) / .16)) !important;", "#formIcon { background: rgb(var(--fx-brand-a) / .16) !important;"),
 (".h-14.w-14.rounded-2xl.bg-indigo-50 { background: linear-gradient(135deg, rgb(var(--fx-brand-a) / .18), rgb(var(--fx-brand-b) / .16));", ".h-14.w-14.rounded-2xl.bg-indigo-50 { background: rgb(var(--fx-brand-a) / .16);"),
 # pierwsza definicja kółka ładowania
 ("""      background: conic-gradient(from 90deg, rgb(var(--fx-brand-a) / 0), rgb(var(--fx-brand-a)) 60%, rgb(var(--fx-brand-b)));
      -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 4px), #000 calc(100% - 4px)); mask: radial-gradient(farthest-side, transparent calc(100% - 4px), #000 calc(100% - 4px));
      filter: drop-shadow(0 0 8px rgb(var(--fx-brand-a) / .45)); animation: spin .9s linear infinite; }""",
  """      border: 4px solid rgb(var(--c-slate-300)); border-top-color: rgb(var(--fx-brand-a)); animation: spin .9s linear infinite; }"""),
 ("""#resultLoading::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, transparent, rgb(var(--fx-brand-a)), rgb(var(--fx-brand-b)), transparent) no-repeat; background-size: 45% 100%; animation: fxIndeterminate 1.3s var(--ease-out) infinite; }""",
  """#resultLoading::before { content: ""; position: absolute; top: 0; left: 0; right: 100%; height: 3px; background: rgb(var(--brand, var(--fx-brand-a))); animation: fxIndeterminateFlat 1.3s var(--ease-out) infinite; }"""),
 ("background: linear-gradient(90deg, rgb(var(--fx-skel-a)) 25%, rgb(var(--fx-skel-b)) 50%, rgb(var(--fx-skel-a)) 75%); background-size: 200% 100%; animation: fxShimmer 1.4s linear infinite;",
  "background: rgb(var(--fx-skel-a)); animation: fxSkelFlat 1.4s ease-in-out infinite;"),
 ("border-radius: 2px; background: linear-gradient(180deg, rgb(var(--fx-brand-a)), rgb(var(--fx-brand-b)));", "border-radius: 2px; background: rgb(var(--brand, var(--fx-brand-a)));"),
 (".md-output h1, .md-output h2 { border-bottom: 0; background: linear-gradient(90deg, rgb(var(--fx-brand-a) / .55), rgb(var(--fx-brand-b) / .35) 40%, transparent 75%) left bottom / 100% 1px no-repeat; }",
  ".md-output h1, .md-output h2 { border-bottom: 1px solid rgb(var(--brand, var(--fx-brand-a)) / .35); }"),
 (".md-output hr { height: 1px; background: linear-gradient(90deg, transparent, rgb(var(--c-slate-300)), rgb(var(--fx-brand-a) / .45), rgb(var(--c-slate-300)), transparent); }",
  ".md-output hr { height: 1px; background: rgb(var(--c-slate-300)); }"),
 ("#chatMessages .rounded-br-md.bg-slate-100 { background: linear-gradient(135deg, rgb(var(--fx-brand-a) / .14), rgb(var(--fx-brand-b) / .1));",
  "#chatMessages .rounded-br-md.bg-slate-100 { background: rgb(var(--brand, var(--fx-brand-a)) / .1);"),
 ("#chatMessages .rounded-lg.bg-slate-900 { background: linear-gradient(135deg, rgb(var(--fx-brand-a)), rgb(var(--fx-brand-b)));",
  "#chatMessages .rounded-lg.bg-slate-900 { background: rgb(var(--brand, var(--fx-brand-a)));"),
 # pierścień wyniku – rysowany w SVG
 ("""      background: radial-gradient(closest-side, rgb(var(--c-canvas)) 80%, transparent 81% 100%), conic-gradient(var(--ring) calc(var(--fx-p) * 1%), rgb(var(--c-slate-200)) 0);
      animation: fxRing 1.2s var(--ease-out) both; filter: drop-shadow(0 8px 16px rgb(var(--shadow-color) / .12)); }""",
  """      position: relative; background: rgb(var(--c-canvas)); }
    .fx-ring > div { position: relative; }
    .fx-ring svg { position: absolute; inset: 0; width: 100%; height: 100%; transform: rotate(-90deg); }
    .fx-ring .fx-ring-val { stroke-dasharray: 289; stroke-dashoffset: calc(289 - 289 * var(--target) / 100); animation: fxRingFlat 1.2s var(--ease-out) both; }
    @keyframes fxRingFlat { from { stroke-dashoffset: 289; } }"""),
 ("      box-shadow: 0 18px 38px -16px rgb(0 0 0 / .5), inset 0 1px 0 rgb(255 255 255 / .2) !important; animation: fxToastIn",
  "      box-shadow: none !important; border: 1px solid rgb(0 0 0 / .12); animation: fxToastIn"),
 (".fx-toast-bar { position: absolute; left: 0; bottom: 0; height: 3px; width: 100%; background: rgb(255 255 255 / .55);",
  ".fx-toast-bar { position: absolute; left: 0; bottom: 0; height: 3px; width: 100%; background: rgb(255 255 255 / .6);"),
 (".fx-toast.bg-emerald-600 { background-image: linear-gradient(135deg, rgb(var(--fx-g1)), rgb(var(--fx-g3))); }\n", ""),
 ("    .fx-toast.bg-red-600 { background-image: linear-gradient(135deg, rgb(var(--fx-d1)), rgb(var(--fx-d3))); }\n", ""),
 ("    .fx-toast.bg-amber-600 { background-image: linear-gradient(135deg, rgb(214 150 6), rgb(166 102 0)); }\n", ""),
 ("    .fx-toast.bg-navy-800 { background-image: linear-gradient(135deg, rgb(58 66 76), rgb(28 32 37)); }\n", ""),
 (".grad-text { background: linear-gradient(90deg, rgb(var(--ai-1)), rgb(var(--ai-2)) 30%, rgb(var(--ai-3)) 55%, rgb(var(--ai-5)) 80%, rgb(var(--ai-1))); background-size: 200% auto; -webkit-background-clip: text; background-clip: text; color: transparent; animation: fxFlow 7s linear infinite; }",
  ".grad-text { color: rgb(var(--brand)); }"),
 ("background: conic-gradient(rgb(var(--ai-1)), rgb(var(--ai-2)), rgb(var(--ai-3)), rgb(var(--ai-4)), rgb(var(--ai-5)), rgb(var(--ai-1))); animation: spin 7s linear infinite; box-shadow: 0 0 0 3px rgb(var(--ai-1) / .1); }",
  "background: rgb(var(--brand)); box-shadow: 0 0 0 3px rgb(var(--brand) / .12); }"),
 ("""    .ai-dot::after { content: ""; position: absolute; inset: 3px; border-radius: 50%; background: radial-gradient(circle at 35% 30%, rgb(255 255 255 / .95), rgb(255 255 255 / .2) 45%, transparent 70%); }""",
  """    .ai-dot::after { content: ""; position: absolute; inset: 6px; border-radius: 50%; background: rgb(var(--brand-peach)); }"""),
 ("body.ai-busy .ai-dot { animation-duration: 1s; box-shadow: 0 0 0 3px rgb(var(--ai-1) / .15), 0 0 16px rgb(var(--ai-2) / .8); }",
  "body.ai-busy .ai-dot { animation: fxBlinkFlat 1s ease-in-out infinite; box-shadow: 0 0 0 3px rgb(var(--brand) / .25); }"),
 # druga definicja kółka ładowania
 ("""    .spinner { position: relative; width: 56px; height: 56px; border: 0 !important; border-radius: 50%; -webkit-mask: none !important; mask: none !important; filter: none !important;
      background: conic-gradient(rgb(var(--ai-1)), rgb(var(--ai-2)), rgb(var(--ai-3)), rgb(var(--ai-4)), rgb(var(--ai-5)), rgb(var(--ai-1))) !important;
      animation: spin 2.4s linear infinite, fxOrbGlow 2.4s ease-in-out infinite !important; }""",
  """    .spinner { position: relative; width: 52px; height: 52px; border-radius: 50%; border: 5px solid rgb(var(--brand) / .15) !important; border-top-color: rgb(var(--brand)) !important; border-right-color: rgb(var(--brand-peach)) !important;
      background: transparent !important; animation: spin .9s linear infinite !important; }"""),
 ("""    .spinner::after { content: ""; position: absolute; inset: 0; border-radius: 50%; background: radial-gradient(circle at 32% 28%, rgb(255 255 255 / .9), transparent 34%), radial-gradient(circle, transparent 55%, rgb(0 0 0 / .18) 100%); }\n""", ""),
 ("""    .ai-core::before { content: ""; position: absolute; inset: -22%; z-index: -1; border-radius: 50%; background: radial-gradient(closest-side, rgb(164 0 227 / .30), rgb(249 170 99 / .14) 55%, transparent 75%); filter: blur(10px); animation: fxBreath 5s ease-in-out infinite; }
    body.ai-busy .ai-core::before { animation-duration: 1.3s; background: radial-gradient(closest-side, rgb(164 0 227 / .42), rgb(249 170 99 / .24) 55%, transparent 75%); }\n""", ""),
 ("""background: radial-gradient(closest-side, rgb(var(--ai-1) / .5), rgb(var(--ai-2) / .28) 50%, transparent 74%); filter: blur(14px); animation: fxBreath 5s ease-in-out infinite; }""",
  """background: rgb(var(--brand) / .12); animation: fxBreath 5s ease-in-out infinite; }"""),
 ("-webkit-mask-image: radial-gradient(circle, #000 69%, transparent 70.5%); mask-image: radial-gradient(circle, #000 69%, transparent 70.5%); ", ""),
 ("will-change: transform; background: conic-gradient(rgb(var(--ai-1)), rgb(var(--ai-2)), rgb(var(--ai-3)), rgb(var(--ai-4)), rgb(var(--ai-5)), rgb(var(--ai-1))); animation: spin 10s linear infinite; }", "will-change: transform; background: rgb(var(--brand)); animation: spin 10s linear infinite; }"),
 (".ai-orb-core i { position: absolute; border-radius: 50%; filter: blur(12px); mix-blend-mode: screen; opacity: .85; }",
  ".ai-orb-core i { position: absolute; border-radius: 50%; opacity: .9; }"),
 ("""background: radial-gradient(circle at 34% 28%, rgb(255 255 255 / .85), transparent 30%), radial-gradient(circle at 50% 50%, transparent 58%, rgb(0 0 0 / .22) 100%); box-shadow""",
  """background: transparent; box-shadow"""),
 ("""    .hero-aurora { position: absolute; inset: -25%; z-index: -2; filter: blur(34px); opacity: .9; transform: translate(calc(var(--px, 0) * -18px), calc(var(--py, 0) * -14px)); transition: transform 1.2s var(--ease-out);
      background: radial-gradient(38% 48% at 22% 30%, rgb(var(--ai-1) / .32), transparent 70%), radial-gradient(34% 44% at 80% 24%, rgb(var(--ai-2) / .3), transparent 70%), radial-gradient(36% 46% at 62% 88%, rgb(var(--ai-3) / .22), transparent 70%), radial-gradient(28% 38% at 30% 86%, rgb(var(--ai-5) / .22), transparent 70%); }
    .hero-aurora::after { content: ""; position: absolute; inset: 0; background: inherit; animation: fxAurora 16s ease-in-out infinite alternate; opacity: .6; }""",
  """    .hero-aurora { display: none; }"""),
 ("""    .hero-ask::before { content: ""; position: absolute; inset: -2px; border-radius: inherit; padding: 2px; pointer-events: none; opacity: 0; transition: opacity .35s;
      background: conic-gradient(from var(--fx-a), rgb(var(--ai-1)), rgb(var(--ai-2)), rgb(var(--ai-3)), rgb(var(--ai-4)), rgb(var(--ai-5)), rgb(var(--ai-1)));
      -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor; mask-composite: exclude; animation: fxAngle 4s linear infinite; }
    .hero-ask:focus-within::before { opacity: 1; }""",
  """    .hero-ask { transition: box-shadow .3s; }
    .hero-ask:focus-within { box-shadow: 0 24px 50px -28px rgb(var(--shadow-color) / .55), 0 0 0 2px rgb(var(--brand)); }"""),
 ("""    .tool-card::after { content: ""; position: absolute; inset: 0; z-index: 2; border-radius: inherit; padding: 1.5px; pointer-events: none; opacity: 0; transition: opacity .35s;
      background: radial-gradient(280px circle at var(--mx, 50%) var(--my, 50%), rgb(var(--ai-2)), rgb(var(--ai-1) / .7) 30%, rgb(var(--ai-5) / .25) 50%, transparent 65%);
      -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor; mask-composite: exclude; }
    .tool-card:hover::after { opacity: 1; }""",
  """    .tool-card::after { content: ""; position: absolute; inset: 0; z-index: 2; border-radius: inherit; pointer-events: none; opacity: 0; transition: opacity .35s; box-shadow: inset 0 0 0 2px rgb(var(--brand)); }
    .tool-card:hover::after { opacity: 1; }"""),
 (".tool-art-mesh { position: absolute; inset: -18%; background: var(--art-mesh); transform:", ".tool-art-mesh { position: absolute; inset: -18%; width: 136%; height: 136%; transform:"),
 ("""background: linear-gradient(90deg, rgb(var(--ai-1)), rgb(var(--ai-2)), rgb(var(--ai-3)), rgb(var(--ai-5))); background-size: 200% 100%; animation: fxFlow 3s linear infinite; }""",
  """background: rgb(var(--brand)); }"""),
 ("background: linear-gradient(90deg, #7a2cf0, #a400e3 55%, #f9aa63); transition: width .3s linear; }", "background: #a400e3; transition: width .3s linear; }"),
 ("""      background-image: radial-gradient(120% 60% at 0% 0%, rgb(164 0 227 / .35), transparent 60%), radial-gradient(90% 50% at 100% 100%, rgb(249 170 99 / .14), transparent 65%) !important;\n""",
  """      background-image: none !important;\n"""),
 (""".app-header::after { background: linear-gradient(90deg, rgb(var(--brand)), rgb(var(--brand-peach)), rgb(var(--brand))) !important; background-size: 200% 100% !important; }""",
  """.app-header::after { background: rgb(var(--brand)) !important; }"""),
 (""".grad-text { background-image: linear-gradient(90deg, rgb(164 0 227), rgb(214 64 190) 35%, rgb(249 170 99) 65%, rgb(164 0 227)) !important; background-size: 200% auto !important; }""",
  """.grad-text { background: none !important; color: rgb(var(--brand)) !important; }
    @keyframes fxIndeterminateFlat { 0% { left: 0; right: 100%; } 50% { left: 25%; right: 30%; } 100% { left: 100%; right: 0; } }
    @keyframes fxSkelFlat { 0%, 100% { opacity: 1; } 50% { opacity: .55; } }
    @keyframes fxBlinkFlat { 0%, 100% { opacity: 1; } 50% { opacity: .45; } }"""),
 # pierścień wyniku: SVG zamiast conic-gradient
 ("""<div class="fx-ring ${tone}" style="--target:${score};--ring:${ringColor}"><div""",
  """<div class="fx-ring ${tone}" style="--target:${score};--ring:${ringColor}"><svg viewBox="0 0 104 104" aria-hidden="true"><circle cx="52" cy="52" r="46" fill="none" stroke="rgb(var(--c-slate-200))" stroke-width="8"/><circle class="fx-ring-val" cx="52" cy="52" r="46" fill="none" stroke="${ringColor}" stroke-width="8" stroke-linecap="round"/></svg><div"""),
 # grafiki narzędzi: płaskie koła zamiast siatki gradientów
 ("""      const spots = [0, 1, 2, 0].map((ci, i) => `radial-gradient(${Math.round(38 + rnd() * 30)}% ${Math.round(50 + rnd() * 40)}% at ${Math.round(rnd() * 100)}% ${Math.round(rnd() * 100)}%, ${pal[(ci + i) % 3]} 0%, transparent 70%)`);
      return { mesh: spots.join(', '), base: pal[3], solid: GROUP_SOLID[t.group] || GROUP_SOLID.Start, gradient: `linear-gradient(135deg, ${pal[0]}, ${pal[1]} 55%, ${pal[2]})` };""",
  """      const shapes = [0, 1, 2].map(i => `<circle cx="${Math.round(20 + rnd() * 260)}" cy="${Math.round(10 + rnd() * 100)}" r="${Math.round(34 + rnd() * 40)}" fill="${pal[i]}" opacity=".9"/>`).join('');
      return { shapes, base: pal[3], solid: GROUP_SOLID[t.group] || GROUP_SOLID.Start };"""),
 ("""<div class="tool-art" style="--art-base:${a.base};--art-mesh:${a.mesh}" aria-hidden="true"><div class="tool-art-mesh"></div>""",
  """<div class="tool-art" style="--art-base:${a.base}" aria-hidden="true"><svg class="tool-art-mesh" viewBox="0 0 300 120" preserveAspectRatio="xMidYMid slice">${a.shapes}</svg>"""),
 # animowana kula na stronie startowej – płaskie wypełnienia
 ("""      let g = ctx.createRadialGradient(cx, cy, 0, cx, cy, R * 1.3);
      g.addColorStop(0, `rgba(249,170,99,${(dark ? .42 : .34) + pulse * (busy ? .25 : .1)})`);
      g.addColorStop(.45, `rgba(164,0,227,${dark ? .26 : .18})`);
      g.addColorStop(1, 'rgba(164,0,227,0)');
      ctx.fillStyle = g; ctx.beginPath(); ctx.arc(cx, cy, R * 1.3, 0, Math.PI * 2); ctx.fill();""",
  """      ctx.fillStyle = `rgba(164,0,227,${(dark ? .16 : .08) + pulse * (busy ? .08 : .03)})`; ctx.beginPath(); ctx.arc(cx, cy, R * 1.3, 0, Math.PI * 2); ctx.fill();"""),
 ("""        const gg = ctx.createRadialGradient(x, y, 0, x, y, 7 * k);
        gg.addColorStop(0, `rgba(255,236,210,${al})`); gg.addColorStop(.35, `rgba(249,170,99,${al * .7})`); gg.addColorStop(1, 'rgba(249,170,99,0)');
        ctx.fillStyle = gg; ctx.beginPath(); ctx.arc(x, y, 7 * k, 0, Math.PI * 2); ctx.fill();""",
  """        ctx.fillStyle = `rgba(249,170,99,${al})`; ctx.beginPath(); ctx.arc(x, y, 3 * k, 0, Math.PI * 2); ctx.fill();"""),
 ("""        const sg = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, 9 * k);
        sg.addColorStop(0, 'rgba(255,255,255,.95)'); sg.addColorStop(.3, `rgba(249,170,99,${.6 + d * .4})`); sg.addColorStop(1, 'rgba(249,170,99,0)');
        ctx.fillStyle = sg; ctx.beginPath(); ctx.arc(p.x, p.y, 9 * k, 0, Math.PI * 2); ctx.fill();""",
  """        ctx.fillStyle = `rgba(249,170,99,${.6 + d * .4})`; ctx.beginPath(); ctx.arc(p.x, p.y, 4 * k, 0, Math.PI * 2); ctx.fill();"""),
 ("""      let sg2 = ctx.createRadialGradient(cx, cy, 0, cx, cy, sz * 1.5);
      sg2.addColorStop(0, 'rgba(255,255,255,.95)'); sg2.addColorStop(.35, 'rgba(255,214,170,.6)'); sg2.addColorStop(1, 'rgba(249,170,99,0)');
      ctx.fillStyle = sg2; ctx.beginPath(); ctx.arc(cx, cy, sz * 1.5, 0, Math.PI * 2); ctx.fill();""",
  """      ctx.fillStyle = '#a400e3'; ctx.beginPath(); ctx.arc(cx, cy, sz * 1.25, 0, Math.PI * 2); ctx.fill();"""),
]
for old, new in R:
    src = rep(src, old, new)

# poświata wokół bliższych punktów kuli – usunięta (zostaje płaski punkt)
src, n = re.subn(r"\n        if \(d > \.62\) \{ const hg = ctx\.createRadialGradient[^\n]*", "", src)
assert n == 1, n
# znacznik wersji (przełącznik wyglądu go czyta, a skrypt przekierowania go pomija) i linki do płaskiej strony „O aplikacji”
src = rep(src, '<!DOCTYPE html>\n<html lang="pl">', '<!DOCTYPE html>\n<html lang="pl" data-look="flat">')
src = src.replace('href="o-aplikacji.html', 'href="o-aplikacji-bez-gradientow.html')

left = [l for l in src.split('\n') if re.search(r'gradient\(|createRadialGradient|createLinearGradient|createConicGradient', l)]
if left:
    print('POZOSTAŁE GRADIENTY:'); [print('  ', l.strip()[:160]) for l in left]; sys.exit(1)
open(f'{out}/index-bez-gradientow.html', 'w', encoding='utf-8').write(src)

css = open(f'{root}/site.css', encoding='utf-8').read()
css = re.sub(r'\n\.hero::before \{[^}]*\}', '\n.hero::before { content: none; }', css)
css = rep(css, '.hero h1 .accent { background: linear-gradient(90deg, var(--pri), #d640be 45%, var(--peach)); -webkit-background-clip: text; background-clip: text; color: transparent; }', '.hero h1 .accent { color: var(--pri); }')
css = re.sub(r'background-image: radial-gradient\(60% 80%[^;]*;', '', css)
assert 'gradient' not in css, [l for l in css.split('\n') if 'gradient' in l]
open(f'{out}/site-bez-gradientow.css', 'w', encoding='utf-8').write(css)
for p in ['o-aplikacji']:
    h = open(f'{root}/{p}.html', encoding='utf-8').read()
    h = rep(h, 'href="site.css"', 'href="site-bez-gradientow.css"')
    h = rep(h, '<html lang="pl">', '<html lang="pl" data-look="flat">')
    open(f'{out}/{p}-bez-gradientow.html', 'w', encoding='utf-8').write(h)
print('OK')
