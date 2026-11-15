# OfficeFlow AI

Jednoplikowa aplikacja webowa (SaaS, model **BYOK – Bring Your Own Key**) do automatyzacji pracy biurowej z wykorzystaniem Google Gemini API (domyślnie `gemini-3.8-flash`; model można zmienić w Ustawieniach API).

## Uruchomienie

Otwórz plik `index.html` w przeglądarce (dwuklik lub przeciągnięcie do okna). Przy pierwszym uruchomieniu wklej swój klucz API z [Google AI Studio](https://aistudio.google.com/app/apikey) – zostanie zapisany wyłącznie w `localStorage` Twojej przeglądarki.

## Narzędzia

1. **Asystent Korespondencji B2B** – gotowe odpowiedzi e-mail (cel, ton, dodatkowe fakty).
2. **Analizator Dokumentów i Notatek** – podsumowanie + action items, ekstrakcja danych i terminów, ocena ryzyka.
3. **Generator Ofert Handlowych** – spersonalizowana propozycja: Hak → Problem → Rozwiązanie → CTA.

## Technologie

Tailwind CSS (CDN), czysty JavaScript, `marked.js` + `DOMPurify` do bezpiecznego renderowania Markdown. Zapytania trafiają bezpośrednio do `generativelanguage.googleapis.com` – bez serwera pośredniczącego.
