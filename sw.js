/* PaperlyAI – service worker.
   Zadania: odebrać pliki i tekst udostępnione z telefonu („Udostępnij → PaperlyAI”) i przekazać je aplikacji
   oraz pokazywać powiadomienia (Web Push). Nic nie jest buforowane – zawsze ładuje się aktualna wersja strony. */
const SHARE_CACHE = 'paperly-share';
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'POST' || url.origin !== self.location.origin || !url.searchParams.has('share-target')) return;
  e.respondWith((async () => {
    try {
      const form = await e.request.formData();
      const cache = await caches.open(SHARE_CACHE);
      const files = form.getAll('files').filter(f => f && typeof f === 'object' && f.size > 0).slice(0, 10);
      await Promise.all(files.map((f, i) => cache.put(`/__share/${i}`, new Response(f, { headers: { 'Content-Type': f.type || 'application/octet-stream', 'X-Name': encodeURIComponent(f.name || `plik-${i + 1}`) } }))));
      const meta = { count: files.length, title: String(form.get('title') || ''), text: String(form.get('text') || ''), url: String(form.get('url') || '') };
      await cache.put('/__share/meta', new Response(JSON.stringify(meta), { headers: { 'Content-Type': 'application/json' } }));
    } catch (err) { /* aplikacja otworzy się bez plików */ }
    return Response.redirect(new URL('./?shared=1', self.registration.scope).href, 303);
  })());
});

/* Powiadomienia na telefon i komputer (Web Push): nowa poczta, odpowiedzi czekające w Skrzynce AI,
   terminy płatności i umów. Treść przychodzi zaszyfrowana – odszyfrowuje ją przeglądarka. */
self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { title: 'PaperlyAI', body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'PaperlyAI', {
    body: d.body || '', tag: d.tag || undefined, renotify: !!d.tag,
    icon: new URL('img/icon-192.png', self.registration.scope).href, badge: new URL('img/icon-192.png', self.registration.scope).href,
    data: { url: d.url || self.registration.scope }
  }));
});
self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const target = new URL((e.notification.data && e.notification.data.url) || self.registration.scope, self.registration.scope);
  if (target.origin !== self.location.origin) return;
  e.waitUntil((async () => {
    const list = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const open = list.find(c => new URL(c.url).origin === target.origin);
    if (open) { await open.focus(); try { await open.navigate(target.href); } catch (err) { /* karta z innej wersji */ } return; }
    await self.clients.openWindow(target.href);
  })());
});
