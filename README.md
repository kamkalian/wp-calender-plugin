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

## Tags
Als Tag dient automatisch das **erste Wort des Termintitels** (z. B. „Jugendtraining Halle 2“ → `Jugendtraining`). Über dem Kalender gibt es einen aufklappbaren Bereich „Tags“ mit den Tags, die mindestens einen Termin ab heute haben, sortiert nach Häufigkeit. Jeder Tag bekommt automatisch eine eigene Farbe (als Punkt am Tag und als Linie am Termin); die Farben lassen sich unter *Einstellungen → Google-Kalender → Tag-Farben* ändern. Ein gewählter Tag bleibt neben dem Auf-/Zuklapp-Knopf sichtbar (Klick darauf hebt den Filter auf), der Bereich klappt nach der Auswahl wieder ein. Hat ein Tag nur einen Termin, springt der Kalender zu dessen Monat.

Unter *Einstellungen → Google-Kalender → Tags ausblenden* lässt sich eine Liste (ein Wort pro Zeile) pflegen; diese Wörter werden nicht als Tag angezeigt, die Termine bleiben sichtbar.

## Technik
- Abruf per WP-Cron (`gcalmk_10min`), Ergebnis liegt in der Option `gcalmk_cache`; Besucher lösen nie einen Google-Abruf aus. Bei Fehlern bleibt der alte Cache bestehen.
- Der Key wird nie an den Browser ausgeliefert; öffentlicher Endpunkt: `/wp-json/gcalmk/v1/events`.
- Für exakte 10-Minuten-Takte einen Server-Cron auf `wp-cron.php` einrichten und `DISABLE_WP_CRON` setzen.
