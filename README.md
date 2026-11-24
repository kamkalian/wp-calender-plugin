# GCal Monatskalender

WordPress-Plugin: liest die Termine eines **öffentlichen** Google-Kalenders alle 10 Minuten per API-Key ein und zeigt sie als Monatsansicht mit Tag-Filter und Suche.

## Einrichtung
1. Google Cloud Console: Projekt anlegen, **Google Calendar API** aktivieren, unter *Anmeldedaten* einen **API-Key** erzeugen (auf die Calendar API beschränken).
2. Google Kalender: Kalender öffentlich freigeben („Alle Termindetails anzeigen“), **Kalender-ID** notieren.
3. Plugin-Ordner als ZIP in WordPress installieren, aktivieren.
4. *Einstellungen → Google-Kalender*: API-Key und Kalender-ID eintragen, speichern.
5. Auf einer Seite den Shortcode `[gcal_kalender]` einfügen.

Der Key kann alternativ in der `wp-config.php` stehen: `define( 'GCALMK_API_KEY', '…' );`

## Shortcode
| Attribut | Wirkung |
|---|---|
| `tag="Jugend"` | Tag beim Laden vorgewählt |
| `suche="nein"` | Suche ausblenden |
| `tags="nein"` | Tag-Leiste ausblenden |
| `breite="inhalt"` | Standard ist die volle Fensterbreite; so nur Breite des Theme-Inhaltsbereichs |
| `oben="70"` | Abstand des fixierten Kopfbereichs zum oberen Fensterrand in Pixel (bei festem Theme-Header) |

## Tags
Tags stehen in der **Beschreibung des Google-Termins in eckigen Klammern**, z. B. `[Angebot]` oder `[Selbsthilfe] [Jugend]`. Ein Termin kann mehrere Tags haben. Die Klammern werden in der Anzeige ausgeblendet.

Unter *Einstellungen → Google-Kalender → Tags* legst du für jeden Tag **Farbe** und **Bereich im Ausdruck** fest. Alle in den Terminen gefundenen Tags erscheinen dort automatisch (mit „neu“, bis du speicherst). Tags ohne Bereich sind erlaubt: Sie filtern nur im Kalender. Änderungen wirken sofort.

Voreingestellt sind `[Angebot]` → „Treffen und Angebote“, `[Selbsthilfe]` → „Selbsthilfegruppen und Beratungen“, `[Jugend]` → „Unsere Jugendangebote“, `[Event]` → „Sonstige Veranstaltungen“ sowie `[Tour]` und `[Urlaub]` → „Tagesfahrten / Wanderungen / Urlaub“.

Im Kalender gibt es einen aufklappbaren Bereich „Tags“ mit den Tags, die mindestens einen Termin ab heute haben, sortiert nach Häufigkeit. Die Tag-Farbe erscheint als Punkt am Tag und als Linie am Termin (bei mehreren Tags die des ersten). Ein gewählter Tag bleibt neben dem Auf-/Zuklapp-Knopf sichtbar (Klick darauf hebt den Filter auf), der Bereich klappt nach der Auswahl wieder ein. Hat ein Tag nur einen Termin, springt der Kalender zu dessen Monat.

## Wiederkehrende Termine
Google liefert bei aufgelösten Serien die Serien-ID (`recurringEventId`) mit. Ein Tag, dessen Termine wiederkehrend sind, trägt in der Tag-Leiste das Symbol ↻ (an den Terminen im Raster steht es nicht).

## Fixierter Kopfbereich
Tags, Monatsnavigation, Suche und Wochentage bleiben beim Scrollen am oberen Rand stehen (zuerst scrollt der Bereich mit der Seite, dann bleibt er haften und nur die Tabelle scrollt weiter). Hat das Theme einen festen Header, mit `oben="70"` (Pixel) den Abstand einstellen.

## Zahnrad und Drucken
Oben rechts im Kalender stehen neben der Suche zwei Symbole:
- **Zahnrad** (Ansicht einstellen): Erste Option „Farbige Balken anzeigen“ blendet die farbigen Linien und Punkte an den Terminen ein oder aus. Die Wahl wird im Cookie `gcalmk_prefs` (1 Jahr, nur diese Anzeige-Einstellung, keine personenbezogenen Daten) gespeichert und beim nächsten Besuch wiederhergestellt.
- **Drucker**: Druckt auf DIN A4 eine einzige Liste aller eindeutigen Termine ab dem heutigen Datum bis zum letzten bekannten Termin (Vergangenes erscheint nicht). Gleiche Termine (nach Titel, ohne Zahl am Anfang, Emojis und Satzzeichen; Titel mit „geöffnet“/„GESCHLOSSEN“ werden über den Namen davor zusammengefasst: „Geschäftsstelle geöffnet“ und „Geschäftsstelle geschlossen“ sind ein Eintrag „Geschäftsstelle“, die geschlossenen Tage stehen darunter als „GESCHLOSSEN“) erscheinen genau einmal; hat ein Termin mehrere Uhrzeiten, stehen diese untereinander, jede mit ihren eigenen Tagen; ein einzelner Tag steht als „Do 05.11.26“; **wiederkehrende Termine werden in Worten zusammengefasst** (eine Regel gilt, wenn sie auf mind. 85 % der Termine zutrifft, Ferien- und Feiertagslücken stören nicht), jeweils mit Zeitraum in einer Zeile: „jeden Montag“, „Mo–Fr“, „Di/Do“, „alle 14 Tage mittwochs“ (auch 21, 28 … Tage), „jeden 1. Mittwoch im Monat“, „jeden letzten Freitag im Monat“. Termine ohne erkennbare Regel zeigen die einzelnen Tage mit kurzem Datum, nach Jahren getrennt („2027: 07.01., 04.02., 04.03.“). Termine mit „entfällt“ im Titel (egal ob groß oder klein) werden nicht gedruckt. Gedruckt werden nur Termine mit einem Tag, der einem Bereich zugeordnet ist (siehe unten); ein Termin mit mehreren Tags steht in jedem seiner Bereiche. Ein gewählter Tag-Filter wird berücksichtigt; die farbigen Balken erscheinen im Ausdruck, wenn sie im Zahnrad eingeschaltet sind.

## Bereiche im Ausdruck
Der Ausdruck ist in Bereiche mit Überschrift gegliedert („Treffen und Angebote“, „Selbsthilfegruppen und Beratungen“, „Unsere Jugendangebote“, „Sonstige Veranstaltungen“, „Tagesfahrten / Wanderungen / Urlaub“). Innerhalb jedes Bereichs bleibt das Layout mit den Spalten Termin, Zeit und Tage.
- **Bereichsliste:** Unter *Einstellungen → Google-Kalender → Bereiche im Ausdruck* steht ein Name pro Zeile; die Reihenfolge ist die Druckreihenfolge.
- **Zuordnung über die Tags:** Jeder Tag hat in der Tag-Tabelle ein Dropdown „Bereich im Ausdruck“. Mehrere Tags können denselben Bereich haben (z. B. `[Tour]` und `[Urlaub]`).
- **Ohne Bereich:** Termine ohne Tag oder nur mit Tags ohne Bereich werden nicht gedruckt (aber im Kalender angezeigt).

## Heute
Der heutige Tag ist im Raster durch Rahmen, Hintergrund und einen roten Balken mit „Heute“ hervorgehoben.

## Technik
- Abruf per WP-Cron (`gcalmk_10min`), Ergebnis liegt in der Option `gcalmk_cache`; Besucher lösen nie einen Google-Abruf aus. Bei Fehlern bleibt der alte Cache bestehen.
- Der Key wird nie an den Browser ausgeliefert; öffentlicher Endpunkt: `/wp-json/gcalmk/v1/events`.
- Für exakte 10-Minuten-Takte einen Server-Cron auf `wp-cron.php` einrichten und `DISABLE_WP_CRON` setzen.
