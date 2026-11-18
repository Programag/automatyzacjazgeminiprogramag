# PaperlyAI

Jednoplikowa aplikacja webowa (SaaS, model **BYOK – Bring Your Own Key**) do automatyzacji pracy biurowej z wykorzystaniem Google Gemini API (domyślnie `gemini-3.8-flash`, z automatycznym przełączeniem na `gemini-3.6-flash` przy przeciążeniu; model można zmienić w Ustawieniach API).

Aplikacja działa w samej przeglądarce. Na hostingu z PHP i MySQL (np. home.pl) dochodzi opcjonalny **serwer PaperlyAI** (`api/`): konta firm z zespołem, wspólne dane na każdym komputerze, klucz AI firmy z dziennymi limitami, Gmail bez ponownego logowania, powiadomienia e-mail z własnej domeny, dane firm po NIP i faktury z KSeF – opis w sekcji [Serwer PaperlyAI](#serwer-paperlyai). Strony informacyjne: `o-aplikacji.html`, `prywatnosc.html`, `regulamin.html`.

## Uruchomienie

Wdróż `index.html` jako Static Site (np. na Render) albo otwórz go w przeglądarce. Przy pierwszym uruchomieniu wklej swój klucz API z [Google AI Studio](https://aistudio.google.com/app/apikey) – zostanie zapisany wyłącznie w `localStorage` Twojej przeglądarki.

> Moduł **Skrzynka AI** wymaga strony otwartej przez `http(s)://` (np. adres z Render) – logowanie Google i Microsoft nie działa z pliku `file://`.

## Moduły

### Konto (bez serwera – wersja testowa)
- **Zakładanie konta** przy pierwszym uruchomieniu – wyłącznie przez standardowy przycisk **Zaloguj się przez Google** (wymaga tego samego Client ID co Gmail, z adresem strony w *Authorized JavaScript origins* – bez niego kliknięcie pokazuje, co uzupełnić). Logowania bez Google nie ma; dawne konta lokalne przy następnym otwarciu logują się przez Google, a typ konta i dane firmy podpowiadają się same. Aplikacja odczytuje tylko imię, e-mail i zdjęcie, a dostęp od Google od razu wycofuje.
- **Firma albo osoba prywatna**: konto firmowe ma wszystkie narzędzia, a nazwa firmy i NIP (sprawdzana suma kontrolna) trafiają do papieru firmowego. Konto prywatne pokazuje narzędzia do spraw osobistych: Skrzynka AI, pisma, korekta, analizator, rozmowa z dokumentem, porównanie dokumentów, haczyki w umowie, rejestr umów, notatki, CV i symulator rozmowy – bez windykacji, faktur, ofert, marketingu, raportów, rekrutacji, przetwarzania list i zapraszania pracowników.
- **Moje konto** (na dole menu albo Ctrl+K): zmiana typu konta, dane firmy, wylogowanie (klucz, historia i dokumenty zostają).
- Konto jest zapisane tylko w tej przeglądarce; token z Google jest jedynie odczytywany lokalnie (bez serwera, który by go weryfikował), więc to rozwiązanie do testów, a nie zabezpieczenie dostępu. Na stronie z serwerem PaperlyAI logowanie Google tworzy prawdziwe konto weryfikowane przez serwer (sekcja [Serwer PaperlyAI](#serwer-paperlyai)).

### Strona startowa
- **Opisz zadanie** – wpisz po swojemu, np. „przygotuj raport sprzedaży dla zarządu” albo „odpisz klientowi na reklamację”, a aplikacja dobierze narzędzie (podpowiedzi pojawiają się w trakcie pisania). Dopasowanie działa w przeglądarce, bez zapytań do Gemini. W Marketingu, Raporcie dla zarządu i Porównaniu ofert dłuższy opis trafia od razu do formularza.
- **Karty narzędzi** z abstrakcyjnymi grafikami w kolorach grup (generowanymi w przeglądarce), filtrem grup, przechyleniem 3D i światłem podążającym za kursorem.
- **Kula AI i sieć cząsteczek** reagują na kursor i przyspieszają, gdy AI pracuje; liczniki: czeka w Skrzynce AI, umowy wymagające uwagi, zapytania dziś, dokumenty w bazie wiedzy.
- **Ostatnie wyniki** – jedno kliknięcie przywraca wynik razem z danymi formularza.
- **Wyszukiwarka Ctrl+K** (⌘K na Macu) – z każdego miejsca: narzędzia i akcje (motyw, Panel technika, klucz Gemini), obsługa strzałkami i Enterem.

### Skrzynka AI (Gmail, Outlook, Microsoft 365)
- **Ty decydujesz, co trafia do AI** – „Sprawdź teraz” tylko pobiera listę nowych maili (bez zużywania limitu Gemini). Zaznaczasz, dla których maili przygotować odpowiedź („Przygotuj odpowiedzi” albo „Przygotuj odpowiedź” na karcie) – każdy mail to 1 zapytanie. Lista i przygotowane odpowiedzi zostają po odświeżeniu strony i nie są analizowane ponownie.
Czyta nieprzeczytane maile, pomija newslettery, powiadomienia i podejrzane wiadomości, a do pozostałych przygotowuje propozycję odpowiedzi. Każdą możesz edytować, poprawić poleceniem („krócej”, „zaproponuj czwartek”), zapisać jako szkic albo zatwierdzić i wysłać w tym samym wątku (z 5 s na cofnięcie).
- **Załączniki** – PDF-y, zdjęcia oraz pliki Word (.docx) i Excel (.xlsx) z maila (do 4 plików, 7 MB każdy) są analizowane razem z treścią; pozostałe formaty (np. stare .doc/.xls) są oznaczone jako nieprzeanalizowane.
- **Automatyczne etykiety** – kategoria AI trafia do skrzynki jako etykieta Gmail `OfficeFlow/…` (nazwa etykiet pozostała z dawnej nazwy aplikacji, żeby nie dublować istniejących) lub kategoria Outlook `Paperly: …`.
- **Terminy z kalendarza** – przy prośbie o spotkanie AI proponuje wolne terminy z Kalendarza Google lub Outlooka (w Twoich godzinach pracy), a przycisk „Dodaj do kalendarza” tworzy wydarzenie, opcjonalnie z zaproszeniem dla nadawcy.
- **Przypomnienia o braku odpowiedzi** – gdy wysłana oferta lub pytanie zostaną bez reakcji przez 2–7 dni, w skrzynce pojawia się karta z gotowym przypomnieniem (maks. dwa przypomnienia na sprawę).
- **Poranne podsumowanie** (domyślnie wyłączone, 1 zapytanie dziennie po włączeniu w Moich ustawieniach) – przy pierwszym sprawdzeniu poczty po 6:00: najważniejsze sprawy, kto czeka na odpowiedź, kalendarz na dziś.
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
| Dokumenty | **Porównanie ofert dostawców** | kilka ofert obok siebie: koszt całkowity, termin, gwarancja, płatność, wymagania obowiązkowe, ryzyka i ranking według Twoich wag; argumenty do negocjacji i pytania do dostawców |
| Dokumenty | **Haczyki w umowie** | umowa prostym językiem: werdykt 🟢🟡🔴, haczyki z cytatami, wszystkie koszty, jak wyjść z umowy, ważne terminy |
| Dokumenty | **Rejestr umów** | wszystkie umowy firmy z terminami wypowiedzenia, końcami umów i waloryzacjami – przypomnienia, kalendarz, Excel |
| Dokumenty | **Notatki ze spotkań** | z nagrania (z mikrofonu, telefonu, Teams) lub transkrypcji: podsumowanie, ustalenia, zadania, mail do uczestników, protokół |
| Finanse | **Faktury i KSeF** | odczyt zdjęć, PDF-ów i plików XML z KSeF, kategorie kosztów, rejestr zakupów VAT do Excela, biała lista VAT |
| Finanse | **Windykacja** | lista należności z Excela → przypomnienia, wezwania i przedsądowe wezwania z odsetkami i rekompensatą 40/70/100 EUR |
| Finanse | **Raport dla zarządu** | z arkusza Excel/CSV: najważniejsze w 30 sekund, wskaźniki z porównaniem do poprzedniego okresu lub planu, przyczyny zmian, wykresy, decyzje do podjęcia |
| Kadry i kariera | **Rekrutacja** | ocena dopasowania CV do ogłoszenia, pytania na rozmowę, zaproszenie lub odmowa dla kandydata |
| Kadry i kariera | **CV i list motywacyjny** | CV i list dopasowane do ogłoszenia, słowa kluczowe ATS, klauzula RODO – bez wymyślania doświadczenia |
| Kadry i kariera | **Symulator rozmowy** | próbna rozmowa kwalifikacyjna z AI-rekruterem pod ogłoszenie i CV, odpowiedzi pisemne lub głosowe, ocena każdej odpowiedzi i przykłady lepszych |
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
- **Papier firmowy** – w Panelu technika: logo (PNG, JPG, SVG – pomniejszane), nazwa firmy, adres i kontakt, stopka (NIP, KRS, konto) i kolor firmowy. Trafia do eksportów Word (nagłówek z logo, stopka z numerami stron „Strona X z Y”) i PDF – pism, ofert, wezwań, raportów, rozmowy z dokumentem. Można go wyłączyć dla pojedynczego eksportu w menu „Eksportuj”; dokumenty osobiste (CV, symulator rozmowy) są zawsze bez papieru. Pracownicy dostają go w pliku z przycisku „Eksportuj dla pracowników” (razem z bazą wiedzy).
- **Rejestr umów** – umowę dodasz z pliku (AI odczytuje strony, daty, okres i formę wypowiedzenia, automatyczne przedłużenie, wartość, waloryzację i ryzyka – 1 zapytanie, dane do sprawdzenia przed zapisem) albo ręcznie. Gdy umowa nie podaje ostatniego dnia na wypowiedzenie, aplikacja wylicza go z daty końca i okresu wypowiedzenia (oznaczenie „wyliczony”). Status: czerwony do 14 dni, żółty w oknie przypomnienia (14–90 dni, ustawiane dla każdej umowy). Pilne terminy – powiadomienie raz dziennie po otwarciu aplikacji, licznik przy pozycji w menu, a wszystkie terminy jednym kliknięciem do Kalendarza Google, Outlooka lub pliku .ics. Rejestr jest w przeglądarce (IndexedDB); eksport do Excela, kopia zapasowa i wczytanie kopii na innym komputerze.
- **Zadania do Microsoft To Do** – pod Notatkami ze spotkań, Analizatorem i Raportem dla zarządu: zadania z tabeli w wyniku (bez zapytania do AI; gdy tabeli brak – 1 zapytanie), z osobą, terminem i priorytetem do poprawienia. Trafiają na wybraną listę To Do z terminem i przypomnieniem o 9:00. W Skrzynce AI przycisk „Do zadań” zamienia maila w zadanie (z podsumowaniem i nadawcą). Uprawnienie `Tasks.ReadWrite` jest dodawane przy pierwszym użyciu – logowanie do poczty nie prosi o nie z góry.
- **Porównanie ofert dostawców** – oferty jako osobne pliki (PDF, Word, Excel, zdjęcia) albo wklejone i oddzielone „---”. AI odczytuje dane i ocenia jakość oraz wiarygodność; punkty za cenę (koszt całkowity z dostawą i montażem), termin, gwarancję i termin płatności liczy aplikacja względem najlepszej oferty, więc wynik jest powtarzalny. Oferty niespełniające wymagań obowiązkowych spadają na koniec. Wagi zmienisz suwakami pod wynikiem – ranking przelicza się bez nowego zapytania. Eksport porównania do Excela.
- **Raport dla zarządu** – arkusz bieżącego okresu i opcjonalnie poprzedniego okresu lub budżetu; liczby liczone kodem na wszystkich wierszach, 2–3 wykresy (trafiają też do pliku Word), wskaźniki z oceną ✅/⚠️/❌, ryzyka i tabela decyzji, którą od razu wyślesz do Microsoft To Do. Przycisk „Wstaw przykład” dodaje przykładowy arkusz sprzedaży.
- **Symulator rozmowy kwalifikacyjnej** – rodzaj rozmowy (HR, merytoryczna, z przełożonym, mieszana), 5–12 pytań, poziom i język (polski, angielski, niemiecki). Pytania mogą być czytane na głos (synteza mowy przeglądarki). Odpowiedzi nagrane głosem zostają w przeglądarce do końca rozmowy – cała rozmowa to 2 zapytania do Gemini (pytania i ocena). Ocena: wynik 0–100, mocne strony, co poprawić, transkrypcja nagrań, przykład lepszej odpowiedzi (tylko z faktów z CV), pytania do rekrutera i plan ćwiczeń. „Ćwicz słabsze odpowiedzi” powtarza tylko pytania z oceną do 3/5 (1 zapytanie). Eksport oceny do Worda.
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

Każdy wynik można **wyeksportować**: Word (.docx, z papierem firmowym i wykresami), PDF (przez okno drukowania, z papierem firmowym), Markdown, tekst, nowa wiadomość w Gmailu / Outlooku / domyślnym programie pocztowym, a dla faktur i porównania ofert – Excel (.xlsx) i CSV.

### Licznik zużycia
Panel technika pokazuje dzisiejsze i miesięczne zapytania do Gemini, tokeny i szacowany koszt (według cennika Google dla `gemini-3.8-flash`), z paskiem limitu darmowego planu. Licznik działa w danej przeglądarce; pełne zestawienie dla klucza jest w Google AI Studio.

## Konfiguracja skrzynek (jednorazowo)

Instrukcja krok po kroku jest też w aplikacji: **Panel technika → Skrzynki pocztowe → Skonfiguruj**.

### Gmail
Logowanie (konto i Gmail) odbywa się przez przekierowanie na stronę Google i powrót – działa w każdej przeglądarce. Dodaj w kliencie OAuth także **Autoryzowany identyfikator URI przekierowania** – adres strony z ukośnikiem na końcu, np. `https://officeflow-ai.onrender.com/`.

1. [Google Cloud Console](https://console.cloud.google.com/projectcreate) → utwórz projekt.
2. Włącz [Gmail API](https://console.cloud.google.com/apis/library/gmail.googleapis.com) oraz [Google Calendar API](https://console.cloud.google.com/apis/library/calendar-json.googleapis.com) (kalendarz – wolne terminy i spotkania).
3. [Google Auth Platform](https://console.cloud.google.com/auth/overview) → *Get started*, typ odbiorców **External**; w *Audience* dodaj swój adres jako **Test user**.
4. *Clients* → *Create client* → **Web application**; *Authorized JavaScript origins*: adres strony, np. `https://officeflow-ai.onrender.com`.
5. Skopiuj **Client ID** i wklej w aplikacji.

### Outlook / Microsoft 365
1. [Microsoft Entra → App registrations](https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade) → *New registration*.
2. *Supported account types*: **Accounts in any organizational directory and personal Microsoft accounts**.
3. *Redirect URI*: platforma **Single-page application (SPA)**, adres strony, np. `https://officeflow-ai.onrender.com/`.
4. Skopiuj **Application (client) ID** i wklej w aplikacji. W koncie firmowym administrator może musieć zatwierdzić uprawnienia `Mail.ReadWrite`, `Mail.Send`, `Calendars.ReadWrite`, `User.Read`, a do zadań w Microsoft To Do – `Tasks.ReadWrite`.

## Dwa tryby: technik i pracownik

**Panel technika** (dla osoby wdrażającej aplikację) zbiera całą konfigurację w jednym miejscu, z listą kontrolną postępu:
1. **Klucz AI i model** – klucz Gemini, model, poziom rozumowania, informacja o limitach.
2. **Skrzynki pocztowe** – rejestracja aplikacji w Google Cloud (Gmail) i Microsoft Entra (Outlook / Microsoft 365).
3. **Informacje o firmie dla AI** – fakty wykorzystywane w odpowiedziach wszystkich pracowników, oraz kategorie kosztów do faktur.
4. **Baza wiedzy firmy** – dokumenty i notatki, z których AI korzysta w Skrzynce AI i narzędziach; eksport pliku dla pracowników.
5. **Papier firmowy** – logo, dane firmy, stopka i kolor do eksportów Word i PDF (z podglądem).
6. **Biblioteka gotowych odpowiedzi** – fragmenty firmowe (cennik, dane do przelewu, regulaminy).
7. **Zaproś pracowników** – link konfiguracyjny i gotowa wiadomość do wysłania (mail, Teams, Slack) oraz lista rzeczy do odhaczenia u Google/Microsoftu.

**Widok pracownika** pokazuje tylko Skrzynkę AI (połącz skrzynkę, podpis, zatwierdzanie odpowiedzi) i narzędzia biurowe – bez kluczy, modeli i konfiguracji. Komunikaty o problemach technicznych kończą się prośbą o kontakt z technikiem.

Pracownik otwiera link od technika, klika **Zastosuj** i **Połącz**, loguje się do swojej poczty i wpisuje podpis. Technik może podejrzeć aplikację oczami pracownika („Zobacz widok pracownika”) i wrócić linkiem **Tryb technika** na dole menu.

Przed wysłaniem linku technik powinien:
- **Gmail (@gmail.com):** dodać adres każdego pracownika jako *Test user* (Google Auth Platform → Audience, do 100 osób). **Google Workspace:** ustawić typ odbiorców *Internal* – wtedy nie trzeba listy.
- **Microsoft 365:** kliknąć *Grant admin consent* w *API permissions* aplikacji.
- **Klucz Gemini:** jeśli jest dołączony do linku, jest wspólny dla wszystkich – warto ograniczyć go do adresu strony i włączyć płatny plan.

## Limity Gemini

Darmowy plan Gemini ma niski limit (np. 20 zapytań dziennie i 5 na minutę na model), a każdy przeanalizowany mail, każde dyktowanie i każdy wiersz w Przetwarzaniu listy to jedno zapytanie. Do codziennej pracy – zwłaszcza kilku osób – włącz płatny plan (Billing) w Google AI Studio.

**Oszczędzanie limitu** – aplikacja nie wysyła zapytań, które Google na pewno odrzuci:
- Każdy model jest pytany najwyżej raz na jedno polecenie. Przy przeciążeniu (błąd 503) albo braku odpowiedzi aplikacja przechodzi na model zapasowy z osobnym limitem (`gemini-3.7-flash`, potem `gemini-3.6-flash`) i nie ponawia w kółko tego samego modelu.
- Przeciążony model „odpoczywa” 2, 4, 8, 16, a potem 30 minut, a model z wyczerpanym limitem dziennym jest pomijany do jego odnowienia. Ten stan jest zapamiętany w przeglądarce, także po odświeżeniu strony.
- Gdy wszystkie modele są przeciążone lub bez limitu, nic nie jest wysyłane. Pracownik widzi godzinę, o której warto spróbować, i przycisk „Spróbuj mimo to” (wysyła jedno zapytanie).
- Skrzynka AI, Przetwarzanie listy i Windykacja przy przeciążeniu zatrzymują całą kolejkę, zamiast próbować każdego kolejnego maila czy wiersza.
- Ponowne wysłanie identycznych danych pokazuje wynik z historii bez zapytania do Gemini (z przyciskiem „Wygeneruj od nowa”).
- Gdy przepisanie dyktowania się nie uda, nagranie zostaje zachowane i przycisk „Ponów przepisanie” wysyła je ponownie, bez ponownego dyktowania.
- Panel technika pokazuje osobno zapytania udane i odrzucone przez Google.

## Prywatność

Pełna treść: `prywatnosc.html` (z zasadą ograniczonego użycia danych z Google API) i `regulamin.html`. Opis poniżej dotyczy pracy bez serwera; z serwerem PaperlyAI dane zespołu, zaszyfrowane tokeny Google i KSeF oraz liczniki zużycia AI są przechowywane na serwerze firmy, a zapytania z kluczem firmy przechodzą przez serwer (bez zapisywania treści).

Wszystko dzieje się w przeglądarce: klucz Gemini, identyfikatory aplikacji i tokeny logowania są przechowywane lokalnie, a treść maili (wraz z analizowanymi załącznikami), nagrania i dołączone pliki trafiają wyłącznie do Gmail/Microsoft Graph, Kalendarza oraz Gemini API – bez serwera pośredniczącego. Biała lista VAT (Ministerstwo Finansów) dostaje tylko numery NIP sprzedawców, a NBP – zapytanie o kurs EUR. Na darmowym planie Gemini Google może wykorzystywać przesłane treści do ulepszania swoich usług – do danych firmowych i osobowych używaj płatnego planu.

Historia wyników, wersje robocze, baza wiedzy, rejestr umów i papier firmowy również zostają tylko w tej przeglądarce. Nagrania z symulatora rozmowy trafiają do Gemini dopiero przy ocenie, a większe nagrania przesłane przez Files API są usuwane z serwerów Google zaraz po niej. Pracownik usuwa je z menu **Historia**, a technik w **Panelu technika → Dane w tej przeglądarce** może jednym przyciskiem usunąć wszystkie dane Paperly (np. na wspólnym komputerze).

## Serwer PaperlyAI

Katalog `api/` (PHP 8 + MySQL, bez dodatkowych bibliotek) jest wykrywany automatycznie (`api/?a=health`). Bez niego – albo bez skonfigurowanej bazy – aplikacja działa w przeglądarce jak dotąd (np. na Render).

| Funkcja | Jak działa |
|---|---|
| **Konta i zespół** | Logowanie Google weryfikowane przez serwer (token musi być wydany dla Twojego Client ID). Pierwsza osoba zostaje administratorem serwera; kolejne zakładają konto tylko z zaproszenia (można to zmienić). Role: właściciel, technik, pracownik. Zaproszenia imienne (jednorazowe, wysyłane e-mailem) albo link dla wielu osób. |
| **Tryb prosty** | Konto prywatne na serwerze z kluczem AI firmy nie widzi żadnych ustawień technicznych (Panel technika, klucze, modele, Google Cloud): AI działa od razu, Gmail łączy się jednym kliknięciem, podpis w odpowiedziach to imię i nazwisko z konta Google. |
| **Wspólne dane** | Baza wiedzy, rejestr umów, papier firmowy, biblioteka odpowiedzi, informacje o firmie, kategorie kosztów i ustawienia windykacji synchronizują się w zespole; historia wyników i ustawienia – między komputerami danej osoby. Pracownik nie zmieni danych firmy (serwer odrzuca), ale dopisze umowy do rejestru. Przy pierwszym logowaniu dane z przeglądarki trafiają na konto. |
| **Kto płaci za AI** | Każdy płaci za siebie. **Konto prywatne** – własny klucz Gemini dodany w prostym kreatorze (3 kroki z linkiem do Google AI Studio, klucz sprawdzany w Google i zapisany zaszyfrowany przy koncie, działa na każdym urządzeniu). **Konto firmowe** – opcjonalny wspólny klucz firmy (Panel technika → Konto firmy na serwerze; płaci firma, obowiązują dzienne limity na osobę), a bez niego każdy pracownik dodaje własny klucz. Sekret `GEMINI_API_KEY` (klucz operatora) obsługuje tylko firmy administratora serwera. Klucze nie trafiają do przeglądarek – `gemini.php` przekazuje zapytania i pliki. |
| **Gmail na stałe** | Łączenie przez `api/oauth.php` (dostęp offline); zaszyfrowany token odświeżania zostaje na serwerze, więc po odświeżeniu strony nie trzeba logować się ponownie. Treść poczty nadal pobiera przeglądarka – serwer jej nie zapisuje. |
| **Zadania w tle** | `api/cron.php` (harmonogram hostingu co 15–30 min): przypomnienia o terminach umów e-mailem, powiadomienie o nowej poczcie (nadawca i temat) i poranne podsumowanie – według ustawień w **Moje konto → Powiadomienia e-mail**. Bez AI. |
| **E-maile z domeny** | SMTP hostingu (np. `powiadomienia@paperlyai.pl`), a bez SMTP – funkcja `mail()`. |
| **Dane firm po NIP** | Rejestr REGON (GUS, z kluczem `GUS_KEY`) albo wykaz podatników VAT MF – przyciski „Uzupełnij z rejestru” przy zakładaniu konta, w profilu i w papierze firmowym. |
| **KSeF 2.0** | Technik wpisuje token KSeF (zaszyfrowany na serwerze) i środowisko; w narzędziu **Faktury** pojawia się lista faktur zakupowych z wybranego okresu – zaznaczone trafiają do analizy jako pliki XML. Uwierzytelnianie tokenem (RSA-OAEP SHA-256) zgodnie ze specyfikacją MF. |

### Konfiguracja serwera (jednorazowo)

1. **Baza MySQL** – w panelu hostingu utwórz bazę i użytkownika; dane wpisz jako sekrety `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`. Tabele tworzą się same.
2. **Sekrety** w GitHub (Settings → Secrets and variables → Actions) – wgrywanie tworzy z nich `api/_config.php` (nigdy w repozytorium):

| Sekret | Do czego |
|---|---|
| `APP_SECRET` | losowy ciąg min. 32 znaków – szyfrowanie tokenów (zalecane) |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | logowanie Google i Gmail bez ponownego logowania (ten sam klient OAuth co dotąd) |
| `GEMINI_API_KEY`, `DAILY_LIMIT` | opcjonalnie: klucz AI tylko dla Twoich firm (administratora serwera) i domyślny limit zapytań na osobę przy kluczach wspólnych (domyślnie 150); pozostali użytkownicy płacą własnym kluczem |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `MAIL_FROM`, `MAIL_FROM_NAME` | e-maile z własnej domeny (port 465 = SSL, 587 = STARTTLS) |
| `GUS_KEY` | opcjonalnie: klucz do API rejestru REGON |
| `MS_CLIENT_ID` | opcjonalnie: identyfikator aplikacji Microsoft (Entra) – Outlook łączy się jednym kliknięciem, bez konfiguracji u użytkownika |
| `OPERATOR_NAME`, `OPERATOR_ADDRESS`, `OPERATOR_NIP`, `CONTACT_EMAIL`, `SITE_HOST` | dane administratora w polityce prywatności i regulaminie |
| `CRON_SECRET`, `PUBLIC_URL` | opcjonalnie: własny klucz adresu crona, stały adres strony |

3. **Google Cloud** – w kliencie OAuth dodaj *Authorized redirect URI* `https://twoja-domena/api/oauth.php` (oprócz dotychczasowego `https://twoja-domena/`). Adres pokazuje też Panel technika → Konto firmy na serwerze. Aplikacja w trybie testowym Google wydaje tokeny odświeżania ważne 7 dni – na stałe działają po publikacji aplikacji (zakres Gmail wymaga weryfikacji Google).
4. **Harmonogram zadań** (cron) w panelu hostingu – adres z Panelu technika (widoczny dla administratora serwera) albo `php api/cron.php`, co 15–30 minut.
5. Zaloguj się w aplikacji przez Google – zostaniesz administratorem serwera i założysz konto firmy.

Testy serwera: `api` sprawdzono na PHP 8.3 z SQLite i atrapami usług Google, Gmail, Gemini, MF, KSeF i SMTP (logowanie, role, zaproszenia, limity, wysyłka plików, synchronizacja, OAuth offline, cron, szyfrowanie tokenów, RSA-OAEP KSeF).

## Wgrywanie na własny hosting (FTP)

Aplikacja to jeden plik `index.html` – wystarczy hosting ze zwykłymi plikami i certyfikatem HTTPS (bez niego nie działa logowanie Google ani mikrofon). Repozytorium ma workflow **Wgraj na FTP** (`.github/workflows/deploy-ftp.yml`), który po każdej zmianie aplikacji, stron informacyjnych lub serwera (`api/`) na gałęzi `main` lub `claude/sleepy-galileo-wyv13q` wgrywa pliki na serwer, wpisuje dane operatora do polityki prywatności i regulaminu oraz tworzy `api/_config.php` z sekretów (sekcja [Serwer PaperlyAI](#serwer-paperlyai)); można też uruchomić go ręcznie w zakładce **Actions**.

Jednorazowo ustaw w repozytorium (**Settings → Secrets and variables → Actions → New repository secret**):

| Sekret | Wartość |
|---|---|
| `FTP_SERVER` | adres serwera FTP, np. `ftp.paperlyai.pl` (bez `ftp://`) |
| `FTP_USERNAME` | login konta FTP |
| `FTP_PASSWORD` | hasło konta FTP |
| `FTP_DIR` | opcjonalnie: folder strony, np. `/public_html/` (domyślnie `/`) |
| `FTP_PROTOCOL` | opcjonalnie: `ftps` (domyślnie), `ftp` albo `ftps-legacy` |

**Przeniesienie danych na nowy adres:** na starym adresie wejdź w Panel technika → Dane w tej przeglądarce → **Pobierz wszystkie dane**, a na nowym wczytaj plik (link „Wczytaj plik z danymi” na ekranie zakładania konta albo przycisk w tej samej sekcji). Przenosi klucz Gemini, konto, ustawienia, bazę wiedzy, rejestr umów, papier firmowy i historię; skrzynki pocztowe łączy się ponownie. Strona otwarta przez `http://` sama przechodzi na `https://`.

Po zmianie domeny dodaj nowy adres w Google Cloud w *Authorized JavaScript origins* i *Authorized redirect URIs* (`https://twoja-domena/`). Dane (konto, klucz Gemini, historia) są zapisane osobno dla każdego adresu strony.

## Technologie

Tailwind CSS (CDN) z paletą w stylu Primer (jasny i ciemny motyw – automatycznie według systemu lub przełącznikiem w nagłówku, z kolistym przejściem między motywami), warstwa efektów w czystym CSS (przyciski w jednolitych kolorach jednej palety – niebieski akcent, zielony dla wysyłki, czerwony dla usuwania – z falą po kliknięciu i delikatnym uniesieniem, płynne wejścia widoków, animowane ładowanie, linia „AI pracuje” w nagłówku, powiadomienia z paskiem czasu) – wyłączana automatycznie, gdy system ma ustawione ograniczenie ruchu, czysty JavaScript, `marked.js` + `DOMPurify`, Google Identity Services, MSAL.js (`@azure/msal-browser` 3.28.1), Gmail API, Microsoft Graph, Google Calendar API, Gemini Files API i code execution, API NBP, API wykazu podatników VAT (MF). Pliki .docx/.xlsx (odczyt i zapis), .ics i CSV są obsługiwane bez dodatkowych bibliotek.
