/* PaperlyAI – service worker.
   Jedyne zadanie: odebrać pliki i tekst udostępnione z telefonu („Udostępnij → PaperlyAI”)
   i przekazać je aplikacji. Nic nie jest buforowane – zawsze ładuje się aktualna wersja strony. */
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
