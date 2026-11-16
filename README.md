# OfficeFlow AI

Jednoplikowa aplikacja webowa (SaaS, model **BYOK – Bring Your Own Key**) do automatyzacji pracy biurowej z wykorzystaniem Google Gemini API (domyślnie `gemini-3.8-flash`, z automatycznym przełączeniem na `gemini-3.6-flash` przy przeciążeniu; model można zmienić w Ustawieniach API).

## Uruchomienie

Wdróż `index.html` jako Static Site (np. na Render) albo otwórz go w przeglądarce. Przy pierwszym uruchomieniu wklej swój klucz API z [Google AI Studio](https://aistudio.google.com/app/apikey) – zostanie zapisany wyłącznie w `localStorage` Twojej przeglądarki.

> Moduł **Skrzynka AI** wymaga strony otwartej przez `http(s)://` (np. adres z Render) – logowanie Google i Microsoft nie działa z pliku `file://`.

## Moduły

### Skrzynka AI (Gmail, Outlook, Microsoft 365)
Czyta nieprzeczytane maile, pomija newslettery, powiadomienia i podejrzane wiadomości, a do pozostałych przygotowuje propozycję odpowiedzi. Każdą możesz edytować, poprawić poleceniem („krócej”, „zaproponuj czwartek”), zapisać jako szkic albo zatwierdzić i wysłać w tym samym wątku (z 5 s na cofnięcie).
- **Załączniki** – PDF-y, zdjęcia oraz pliki Word (.docx) i Excel (.xlsx) z maila (do 4 plików, 7 MB każdy) są analizowane razem z treścią; pozostałe formaty (np. stare .doc/.xls) są oznaczone jako nieprzeanalizowane.
- **Automatyczne etykiety** – kategoria AI trafia do skrzynki jako etykieta Gmail `OfficeFlow/…` lub kategoria Outlook `OfficeFlow: …`.
- **Terminy z kalendarza** – przy prośbie o spotkanie AI proponuje wolne terminy z Kalendarza Google lub Outlooka (w Twoich godzinach pracy), a przycisk „Dodaj do kalendarza” tworzy wydarzenie, opcjonalnie z zaproszeniem dla nadawcy.
- **Przypomnienia o braku odpowiedzi** – gdy wysłana oferta lub pytanie zostaną bez reakcji przez 2–7 dni, w skrzynce pojawia się karta z gotowym przypomnieniem (maks. dwa przypomnienia na sprawę).
- **Poranne podsumowanie** – przy pierwszym sprawdzeniu poczty po 6:00: najważniejsze sprawy, kto czeka na odpowiedź, kalendarz na dziś.
- **Biblioteka gotowych odpowiedzi** – fragmenty firmowe (z Panelu technika, trafiają do linku zaproszenia) i osobiste; AI je wykorzystuje, a do odpowiedzi wstawisz je jednym kliknięciem.
- **Powiadomienia przeglądarki** – o nowych propozycjach, braku odpowiedzi i podsumowaniu dnia, gdy karta jest w tle.
- **Filtry i wyszukiwarka** – widoki *Do zatwierdzenia*, *Przypomnienia*, *W trakcie*, *Bez odpowiedzi*, *Obsłużone* z licznikami oraz wyszukiwanie po nadawcy, temacie i streszczeniu (bez względu na polskie znaki).
- **Baza wiedzy firmy** – cenniki, regulaminy i procedury z bazy trafiają do każdej analizy maila (można wyłączyć w Moich ustawieniach).
- Sprawdzanie automatyczne co 3 min działa, gdy karta z aplikacją jest otwarta.

### Narzędzia
| Grupa | Narzędzie | Co robi |
|---|---|---|
| Pisanie | **Korespondencja B2B** | odpowiedź e-mail wg celu, tonu i faktów |
| Pisanie | **Generator ofert** | spersonalizowana propozycja: Hak → Problem → Rozwiązanie → CTA |
| Pisanie | **Pisma firmowe** | wezwanie do zapłaty, odpowiedź na reklamację, reklamacja do dostawcy, wypowiedzenie, aneks, pismo przewodnie |
| Pisanie | **Korekta i zmiana tonu** | korekta, skracanie, rozwijanie, zmiana tonu, prosty język, tłumaczenie PL/EN/DE |
| Pisanie | **Marketing** | posty LinkedIn / Facebook / Instagram, newsletter, opis produktu, artykuł SEO, odpowiedź na opinię Google – w 1–3 wariantach |
| Dokumenty | **Analizator dokumentów** | podsumowanie + zadania, kluczowe dane i terminy, ocena ryzyka – z tekstu lub pliku; **dokładne obliczenia kodem** dla Excela i CSV |
| Dokumenty | **Rozmowa z dokumentem** | pytania o umowy, raporty i arkusze w formie czatu – z odwołaniem do paragrafu, strony lub arkusza |
| Dokumenty | **Porównanie dokumentów** | różnice między wersjami umowy z oceną ryzyka albo porównanie dwóch ofert |
| Dokumenty | **Haczyki w umowie** | umowa prostym językiem: werdykt 🟢🟡🔴, haczyki z cytatami, wszystkie koszty, jak wyjść z umowy, ważne terminy |
| Dokumenty | **Notatki ze spotkań** | z nagrania (z mikrofonu, telefonu, Teams) lub transkrypcji: podsumowanie, ustalenia, zadania, mail do uczestników, protokół |
| Finanse | **Faktury i KSeF** | odczyt zdjęć, PDF-ów i plików XML z KSeF, kategorie kosztów, rejestr zakupów VAT do Excela, biała lista VAT |
| Finanse | **Windykacja** | lista należności z Excela → przypomnienia, wezwania i przedsądowe wezwania z odsetkami i rekompensatą 40/70/100 EUR |
| Kadry i kariera | **Rekrutacja** | ocena dopasowania CV do ogłoszenia, pytania na rozmowę, zaproszenie lub odmowa dla kandydata |
| Kadry i kariera | **CV i list motywacyjny** | CV i list dopasowane do ogłoszenia, słowa kluczowe ATS, klauzula RODO – bez wymyślania doświadczenia |
| Automatyzacja | **Przetwarzanie listy** | to samo polecenie AI dla każdego wiersza Excela/CSV (maile, opisy produktów, kategorie, tłumaczenia), wynik w nowej kolumnie |

#### Szczegóły nowych modułów
- **Dyktowanie** – przy każdym polu tekstowym jest przycisk „Dyktuj”: mówisz, a AI zamienia nagranie na uporządkowany tekst (interpunkcja, polecenia „kropka”, „nowy akapit”, liczby i daty w polskim formacie). Maks. 5 minut.
- **Notatki ze spotkań** – nagrywanie przez mikrofon komputera (z blokadą usypiania ekranu) lub plik MP3/M4A/WAV/OGG/WEBM/AAC/FLAC/MP4 do 1 GB. Nagrania powyżej 12 MB trafiają najpierw do Google przez Files API i są usuwane po analizie. Terminy względne („do piątku”) są zamieniane na daty.
- **Terminy do kalendarza** – pod wynikiem Analizatora, Notatek, Haczyków w umowie, Porównania, Pism i Faktur: AI wyszukuje daty (faktury – bez AI, z odczytanych danych), można je poprawić i dodać do Kalendarza Google, Outlooka albo pobrać plik .ics, z przypomnieniem.
- **Dokładne liczby w Excelu** – opcja „Licz dokładnie (kod Python)”: pełne dane arkuszy (wszystkie wiersze, do 500 kolumn) trafiają do Gemini jako CSV, a obliczenia wykonuje kod w piaskownicy Google. Pod wynikiem są wykresy i rozwijany podgląd kodu z wynikami.
- **Baza wiedzy firmy** – w Panelu technika: pliki (PDF, Word, Excel, TXT, zdjęcia) i notatki, do 30 MB, zapisane w przeglądarce (IndexedDB). Większe pliki są wysyłane do Google raz na 48 godzin i ponownie używane. Pracownicy dostają plik z przycisku „Eksportuj” i wczytują go w Skrzynce AI lub Rozmowie z dokumentem.
- **KSeF** – faktury ustrukturyzowane XML (FA(2), FA(3)) są odczytywane bezpośrednio z pliku: kwoty, stawki VAT, pozycje, termin płatności, rachunek i mechanizm podzielonej płatności są brane dokładnie z XML, AI dobiera tylko kategorię kosztu. Kategorie edytuje technik (można dodać numery kont), trafiają do linku zaproszenia.
- **Rejestr zakupów VAT** – eksport do Excela (.xlsx) i CSV z rozbiciem netto/VAT na stawki 23/8/5/0%/zw/np, kategorią, MPP i źródłem danych. To uniwersalny układ kolumn do zmapowania w programie księgowym (Optima, Symfonia, wFirma i inne mają własne formaty importu).
- **Biała lista VAT** – jednym kliknięciem sprawdza w wykazie Ministerstwa Finansów status VAT sprzedawców i to, czy rachunek z faktury jest zgłoszony (ostrzeżenie przy płatnościach powyżej 15 000 zł).
- **Windykacja** – kolumny z pliku są rozpoznawane automatycznie (można je zmienić), faktury jednego kontrahenta trafiają do jednego pisma. Etap zależy od dni po terminie (domyślnie do 14 dni przypomnienie, do 30 wezwanie, później przedsądowe). Odsetki: kwota × stopa × dni ÷ 365 (stopę wpisuje się z obwieszczenia MF), rekompensata według kursu średniego NBP z ostatniego dnia roboczego miesiąca przed terminem płatności (pobierany automatycznie). Wynik: wszystkie pisma w jednym pliku Word, zestawienie w Excelu, szkice e-maili w Gmailu lub Outlooku (nic nie jest wysyłane automatycznie) i przypomnienia w kalendarzu o sprawdzeniu wpłat.
- **Przetwarzanie listy** – do 2000 wierszy, z testem na pierwszym wierszu, wstawianiem kolumn do polecenia (`{Firma}`), zatrzymywaniem i wznawianiem, ponawianiem błędnych wierszy; przy limicie na minutę aplikacja czeka, po wyczerpaniu dziennego – zatrzymuje się. Wynik: Excel (.xlsx) lub CSV z nową kolumną. Obsługuje CSV zapisane przez polskiego Excela (Windows-1250, średniki).

Narzędzia z polem pliku przyjmują PDF-y, zdjęcia, TXT/CSV oraz **Word (.docx) i Excel (.xlsx)**. Gemini nie czyta plików Office bezpośrednio, więc aplikacja rozpakowuje je w przeglądarce:
- tekst, nagłówki, listy i tabele → tekst z tabelami; arkusze → tabele z datami, procentami i wynikami formuł,
- **wykresy** → tytuł, typ, osie i tabela danych (także gdy plik nie ma zapisanych wartości – odczyt z komórek),
- **obrazy** (zdjęcia, skany, zrzuty ekranu) → przekazywane AI jako osobne obrazy; duże są pomniejszane do 1600 px, drobne ikony pomijane,
- pola tekstowe w arkuszach.

Jeśli coś nie trafi do AI w całości (arkusz ponad 1000 wierszy lub 60 kolumn, ponad 30 arkuszy, bardzo długi dokument, obrazy EMF/WMF, limit 10 obrazów na plik), pracownik widzi to od razu: w powiadomieniu po dodaniu pliku, na liście plików, w uwadze przy wyniku, a w Skrzynce AI – na karcie maila. Stare formaty .doc/.xls trzeba zapisać jako .docx/.xlsx lub PDF.

Praca z wynikiem:
- **Popraw wynik** – pod wynikiem wpisz poprawkę („dodaj zdanie o gwarancji”) lub kliknij gotową (*Krócej*, *Bardziej formalnie*, *Prostszym językiem*, *Więcej szczegółów*, *Przetłumacz na angielski*). AI poprawia poprzednią wersję bez ponownego wysyłania plików; nieudana poprawka nie kasuje wyniku.
- **Historia** – ostatnie 10 wyników każdego narzędzia (także po poprawkach); kliknięcie przywraca wynik i dane formularza.
- **Wersje robocze** – wpisane dane formularzy zapisują się na bieżąco i wracają po odświeżeniu strony; „Wyczyść” usuwa wersję roboczą.

Każdy wynik można **wyeksportować**: Word (.docx), PDF (przez okno drukowania), Markdown, tekst, nowa wiadomość w Gmailu / Outlooku / domyślnym programie pocztowym, a dla faktur – Excel (.xlsx: rejestr VAT, zestawienie, pozycje) i CSV.

### Licznik zużycia
Panel technika pokazuje dzisiejsze i miesięczne zapytania do Gemini, tokeny i szacowany koszt (według cennika Google dla `gemini-3.8-flash`), z paskiem limitu darmowego planu. Licznik działa w danej przeglądarce; pełne zestawienie dla klucza jest w Google AI Studio.

## Konfiguracja skrzynek (jednorazowo)

Instrukcja krok po kroku jest też w aplikacji: **Panel technika → Skrzynki pocztowe → Skonfiguruj**.

### Gmail
1. [Google Cloud Console](https://console.cloud.google.com/projectcreate) → utwórz projekt.
2. Włącz [Gmail API](https://console.cloud.google.com/apis/library/gmail.googleapis.com) oraz [Google Calendar API](https://console.cloud.google.com/apis/library/calendar-json.googleapis.com) (kalendarz – wolne terminy i spotkania).
3. [Google Auth Platform](https://console.cloud.google.com/auth/overview) → *Get started*, typ odbiorców **External**; w *Audience* dodaj swój adres jako **Test user**.
4. *Clients* → *Create client* → **Web application**; *Authorized JavaScript origins*: adres strony, np. `https://officeflow-ai.onrender.com`.
5. Skopiuj **Client ID** i wklej w aplikacji.

### Outlook / Microsoft 365
1. [Microsoft Entra → App registrations](https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade) → *New registration*.
2. *Supported account types*: **Accounts in any organizational directory and personal Microsoft accounts**.
3. *Redirect URI*: platforma **Single-page application (SPA)**, adres strony, np. `https://officeflow-ai.onrender.com/`.
4. Skopiuj **Application (client) ID** i wklej w aplikacji. W koncie firmowym administrator może musieć zatwierdzić uprawnienia `Mail.ReadWrite`, `Mail.Send`, `Calendars.ReadWrite`, `User.Read`.

## Dwa tryby: technik i pracownik

**Panel technika** (dla osoby wdrażającej aplikację) zbiera całą konfigurację w jednym miejscu, z listą kontrolną postępu:
1. **Klucz AI i model** – klucz Gemini, model, poziom rozumowania, informacja o limitach.
2. **Skrzynki pocztowe** – rejestracja aplikacji w Google Cloud (Gmail) i Microsoft Entra (Outlook / Microsoft 365).
3. **Informacje o firmie dla AI** – fakty wykorzystywane w odpowiedziach wszystkich pracowników, oraz kategorie kosztów do faktur.
4. **Baza wiedzy firmy** – dokumenty i notatki, z których AI korzysta w Skrzynce AI i narzędziach; eksport pliku dla pracowników.
5. **Biblioteka gotowych odpowiedzi** – fragmenty firmowe (cennik, dane do przelewu, regulaminy).
6. **Zaproś pracowników** – link konfiguracyjny i gotowa wiadomość do wysłania (mail, Teams, Slack) oraz lista rzeczy do odhaczenia u Google/Microsoftu.

**Widok pracownika** pokazuje tylko Skrzynkę AI (połącz skrzynkę, podpis, zatwierdzanie odpowiedzi) i narzędzia biurowe – bez kluczy, modeli i konfiguracji. Komunikaty o problemach technicznych kończą się prośbą o kontakt z technikiem.

Pracownik otwiera link od technika, klika **Zastosuj** i **Połącz**, loguje się do swojej poczty i wpisuje podpis. Technik może podejrzeć aplikację oczami pracownika („Zobacz widok pracownika”) i wrócić linkiem **Tryb technika** na dole menu.

Przed wysłaniem linku technik powinien:
- **Gmail (@gmail.com):** dodać adres każdego pracownika jako *Test user* (Google Auth Platform → Audience, do 100 osób). **Google Workspace:** ustawić typ odbiorców *Internal* – wtedy nie trzeba listy.
- **Microsoft 365:** kliknąć *Grant admin consent* w *API permissions* aplikacji.
- **Klucz Gemini:** jeśli jest dołączony do linku, jest wspólny dla wszystkich – warto ograniczyć go do adresu strony i włączyć płatny plan.

## Limity Gemini

Darmowy plan Gemini ma niski limit (np. 20 zapytań dziennie i 5 na minutę na model), a każdy przeanalizowany mail, każde dyktowanie i każdy wiersz w Przetwarzaniu listy to jedno zapytanie. Do codziennej pracy – zwłaszcza kilku osób – włącz płatny plan (Billing) w Google AI Studio. Po wyczerpaniu limitu aplikacja przełącza się na drugi model, a Skrzynka AI wstrzymuje kolejkę do odnowienia limitu.

## Prywatność

Wszystko dzieje się w przeglądarce: klucz Gemini, identyfikatory aplikacji i tokeny logowania są przechowywane lokalnie, a treść maili (wraz z analizowanymi załącznikami), nagrania i dołączone pliki trafiają wyłącznie do Gmail/Microsoft Graph, Kalendarza oraz Gemini API – bez serwera pośredniczącego. Biała lista VAT (Ministerstwo Finansów) dostaje tylko numery NIP sprzedawców, a NBP – zapytanie o kurs EUR. Na darmowym planie Gemini Google może wykorzystywać przesłane treści do ulepszania swoich usług – do danych firmowych i osobowych używaj płatnego planu.

Historia wyników i wersje robocze również zostają tylko w tej przeglądarce. Pracownik usuwa je z menu **Historia**, a technik w **Panelu technika → Dane w tej przeglądarce** może jednym przyciskiem usunąć wszystkie dane OfficeFlow (np. na wspólnym komputerze).

## Technologie

Tailwind CSS (CDN) z paletą w stylu Primer (jasny i ciemny motyw – automatycznie według systemu lub przełącznikiem w nagłówku), czysty JavaScript, `marked.js` + `DOMPurify`, Google Identity Services, MSAL.js (`@azure/msal-browser` 3.28.1), Gmail API, Microsoft Graph, Google Calendar API, Gemini Files API i code execution, API NBP, API wykazu podatników VAT (MF). Pliki .docx/.xlsx (odczyt i zapis), .ics i CSV są obsługiwane bez dodatkowych bibliotek.
