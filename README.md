# OfficeFlow AI

Jednoplikowa aplikacja webowa (SaaS, model **BYOK – Bring Your Own Key**) do automatyzacji pracy biurowej z wykorzystaniem Google Gemini API (domyślnie `gemini-3.8-flash`, z automatycznym przełączeniem na `gemini-3.6-flash` przy przeciążeniu; model można zmienić w Ustawieniach API).

## Uruchomienie

Wdróż `index.html` jako Static Site (np. na Render) albo otwórz go w przeglądarce. Przy pierwszym uruchomieniu wklej swój klucz API z [Google AI Studio](https://aistudio.google.com/app/apikey) – zostanie zapisany wyłącznie w `localStorage` Twojej przeglądarki.

> Moduł **Skrzynka AI** wymaga strony otwartej przez `http(s)://` (np. adres z Render) – logowanie Google i Microsoft nie działa z pliku `file://`.

## Moduły

1. **Skrzynka AI** – czyta nieprzeczytane maile z Gmaila i/lub Outlooka (prywatnego i Microsoft 365), pomija newslettery, powiadomienia i podejrzane wiadomości, a do pozostałych przygotowuje propozycję odpowiedzi. Każdą odpowiedź możesz edytować, poprawić poleceniem („krócej”, „zaproponuj czwartek”), zapisać jako szkic albo zatwierdzić i wysłać (z 5 s na cofnięcie). Sprawdzanie automatyczne co 3 min działa, gdy karta z aplikacją jest otwarta.
2. **Asystent Korespondencji B2B** – gotowe odpowiedzi e-mail (cel, ton, dodatkowe fakty).
3. **Analizator Dokumentów i Notatek** – podsumowanie + action items, ekstrakcja danych i terminów, ocena ryzyka.
4. **Generator Ofert Handlowych** – spersonalizowana propozycja: Hak → Problem → Rozwiązanie → CTA.

## Konfiguracja skrzynek (jednorazowo)

Instrukcja krok po kroku jest też w aplikacji: **Panel technika → Skrzynki pocztowe → Skonfiguruj**.

### Gmail
1. [Google Cloud Console](https://console.cloud.google.com/projectcreate) → utwórz projekt.
2. Włącz [Gmail API](https://console.cloud.google.com/apis/library/gmail.googleapis.com).
3. [Google Auth Platform](https://console.cloud.google.com/auth/overview) → *Get started*, typ odbiorców **External**; w *Audience* dodaj swój adres jako **Test user**.
4. *Clients* → *Create client* → **Web application**; *Authorized JavaScript origins*: adres strony, np. `https://officeflow-ai.onrender.com`.
5. Skopiuj **Client ID** i wklej w aplikacji.

### Outlook / Microsoft 365
1. [Microsoft Entra → App registrations](https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade) → *New registration*.
2. *Supported account types*: **Accounts in any organizational directory and personal Microsoft accounts**.
3. *Redirect URI*: platforma **Single-page application (SPA)**, adres strony, np. `https://officeflow-ai.onrender.com/`.
4. Skopiuj **Application (client) ID** i wklej w aplikacji. W koncie firmowym administrator może musieć zatwierdzić uprawnienia `Mail.ReadWrite`, `Mail.Send`, `User.Read`.

## Dwa tryby: technik i pracownik

**Panel technika** (dla osoby wdrażającej aplikację) zbiera całą konfigurację w jednym miejscu, z listą kontrolną postępu:
1. **Klucz AI i model** – klucz Gemini, model, poziom rozumowania, informacja o limitach.
2. **Skrzynki pocztowe** – rejestracja aplikacji w Google Cloud (Gmail) i Microsoft Entra (Outlook / Microsoft 365).
3. **Informacje o firmie dla AI** – fakty wykorzystywane w odpowiedziach wszystkich pracowników.
4. **Zaproś pracowników** – link konfiguracyjny i gotowa wiadomość do wysłania (mail, Teams, Slack) oraz lista rzeczy do odhaczenia u Google/Microsoftu.

**Widok pracownika** pokazuje tylko Skrzynkę AI (połącz skrzynkę, podpis, zatwierdzanie odpowiedzi) i narzędzia biurowe – bez kluczy, modeli i konfiguracji. Komunikaty o problemach technicznych kończą się prośbą o kontakt z technikiem.

Pracownik otwiera link od technika, klika **Zastosuj** i **Połącz**, loguje się do swojej poczty i wpisuje podpis. Technik może podejrzeć aplikację oczami pracownika („Zobacz widok pracownika”) i wrócić linkiem **Tryb technika** na dole menu.

Przed wysłaniem linku technik powinien:
- **Gmail (@gmail.com):** dodać adres każdego pracownika jako *Test user* (Google Auth Platform → Audience, do 100 osób). **Google Workspace:** ustawić typ odbiorców *Internal* – wtedy nie trzeba listy.
- **Microsoft 365:** kliknąć *Grant admin consent* w *API permissions* aplikacji.
- **Klucz Gemini:** jeśli jest dołączony do linku, jest wspólny dla wszystkich – warto ograniczyć go do adresu strony i włączyć płatny plan.

## Limity Gemini

Darmowy plan Gemini ma niski dzienny limit (np. 20 zapytań dziennie na model), a każdy przeanalizowany mail to jedno zapytanie. Do codziennej pracy – zwłaszcza kilku osób – włącz płatny plan (Billing) w Google AI Studio. Po wyczerpaniu limitu aplikacja przełącza się na drugi model, a Skrzynka AI wstrzymuje kolejkę do odnowienia limitu.

## Prywatność

Wszystko dzieje się w przeglądarce: klucz Gemini, identyfikatory aplikacji i tokeny logowania są przechowywane lokalnie, a treść maili trafia wyłącznie do Gmail/Microsoft Graph oraz Gemini API – bez serwera pośredniczącego.

## Technologie

Tailwind CSS (CDN), czysty JavaScript, `marked.js` + `DOMPurify`, Google Identity Services, MSAL.js (`@azure/msal-browser` 3.28.1), Gmail API, Microsoft Graph.
