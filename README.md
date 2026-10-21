# ASC – Animal Sitter Club

Webseite für **asc.steinforth.com**: Leistungen und Preise, Gassi-Termine direkt buchen, Wochenend-/Ferienbetreuung anfragen, Bestätigen/Ablehnen per E-Mail-Link und ein kleiner Admin-Bereich.

Technik nach PRD: statisches HTML/CSS, etwas Vanilla-JS, PHP 8 ohne Framework, Daten in JSON-Dateien, E-Mail über PHPMailer/SMTP. Kein Build-Prozess, Deployment per SFTP.

## Design

Das Frontend folgt dem **ASC Design System** (https://claude.ai/artifact/XExqty8sg1mYMzELPj9yqM):
Wiesengrün `brand`, Sonnengelb `accent`, Creme `surface`, Schrift Fredoka, Logo mit der Pfote.

- Tokens stehen als CSS-Variablen oben in `asc/assets/style.css` (Namen wie in `tokens.json`), inkl. dunklem Thema per Systemeinstellung.
- Komponenten mit den Klassen des Design Systems: `asc-btn` (primary/accent/ghost), `asc-badge`, `asc-card` (TarifCard).
- Logo „Hüpfend“ aus dem Design System: `logo.svg` in Kopfzeile (auch am Handy) und Fuß, 56px hoch; `logo-inverse.svg` im dunklen Thema per `<picture>`. Favicon ist die Pfote.
- Schriften (OFL) unter `asc/assets/fonts/`: Fredoka; Patrick Hand nur für kurze Zitate der Kinder (`.hand-note`).
- Tier-Illustrationen (Hund, Katze, Kaninchen) unter `asc/assets/tiere/`, nur in Farben des Design Systems.
- Gemalte Bilder der Kinder unter `asc/assets/team/` (WebP, 640×800, Farben ans Design angeglichen). Wer noch kein Bild hat, zeigt auf der Team-Seite ein Tier auf `brand-soft`/`accent-soft`. Neues Bild: Datei dort ablegen und in `team.html` das `<div class="portrait">` der Person anpassen.
- Kleine Funktions-Icons (Telefon, Ort, Kalender) sind Lucide (ISC), direkt im HTML.
- Abweichung: Text auf `accent` ist in beiden Themen `#2b2420` (`--on-accent`), weil `ink` im dunklen Thema auf Sonnengelb nur 1,8:1 Kontrast hätte.
- `"empfohlen": true` in `tarife.json` markiert einen Tarif als „Beliebt“ (Rahmen + Badge).

## Ordnerstruktur

```
asc/                  Webroot (Subdomain asc.steinforth.com zeigt hierhin)
  index.html … datenschutz.html, danke.html, 404.html
  assets/             style.css, app.js, logo.svg, Tier-Illustrationen
  api/                buchen.php, anfrage.php, bestaetigen.php, tarife.php,
                      verfuegbarkeit.php, preis.php, token.php
  admin/              index.php (+ teile/, .htaccess für Basic Auth)
  lib/                PHP-Logik, PHPMailer, config.example.php (gesperrt per .htaccess)
asc-data/             tarife.json, verfuegbarkeit.json, buchungen.json (außerhalb des Webroots)
tests/run.php         Tests der Kernlogik
tools/pages-build.php statische Vorschau für GitHub Pages
dev-router.php        nur für die lokale Entwicklung
```

## Lokal starten

Voraussetzung: PHP 8.1+ (mit mbstring).

```bash
php -S localhost:8000 -t asc dev-router.php
```

- Seite: http://localhost:8000
- Admin: http://localhost:8000/admin/ – lokal **admin / admin**
- Ohne `asc/lib/config.php` wird lokal automatisch `config.example.php` genutzt.
- E-Mails werden lokal nicht verschickt, sondern als `.eml` in `asc-data/mails/` abgelegt (Transport `file`). Die Bestätigen-/Ablehnen-Links darin funktionieren lokal.

Tests: `php tests/run.php`

## Ablauf einer Buchung

1. Kunde bucht einen Gassi-Termin (Kalender, nur freigegebene Tage/Zeitfenster) oder fragt eine Betreuung mit Zeitraum an.
2. Spam-Schutz: Honeypot-Feld + signiertes Zeit-Token (mind. 3 Sekunden, max. 24 h).
3. Eintrag in `buchungen.json` mit Status `offen`; Mail an die Familie (alle Angaben + Links „Bestätigen“/„Ablehnen“) und Eingangsbestätigung an den Kunden.
4. Klick auf den Link (HMAC-signiert, 7 Tage gültig) → Rückfrageseite → Bestätigen/Ablehnen. Der Kunde bekommt Bestätigung bzw. Absage. Erst bestätigte Gassi-Termine belegen das Zeitfenster.
5. Im Admin-Bereich lässt sich alles auch manuell erledigen (inkl. „erledigt“, Preis anpassen für Stammkunden-Rabatt, interne Notiz).

Personenbezogene Daten werden 12 Monate nach dem Termin automatisch anonymisiert (läuft bei jeder neuen Buchung und jedem Admin-Aufruf mit).

## Pflege ohne Code

- **Preise/Tarife:** Admin → Tarife (Name, Beschreibung, Einheit, Preis, Staffel, Zuschläge, aktiv). Neue Tarife oder Zuschläge direkt in `asc-data/tarife.json` ergänzen – Aufbau ist dort im Feld `hinweis` erklärt.
- **Verfügbarkeit:** Admin → Verfügbarkeit. Tag anklicken → freigeben (max. 3 Zeitfenster) oder sperren. Zeiträume geben Wochentage pauschal frei (z. B. Schulzeit Mo/Mi/Fr) oder sperren Ferien/Prüfungszeiten. Einzeltage haben Vorrang.
- **Texte der Seiten:** direkt in den `.html`-Dateien. Gelb markierte `[Platzhalter]` (Team, Impressum, Datenschutz, Einsatzgebiet) vor dem Start ersetzen.
- **Texte der E-Mails:** `asc/lib/texte.php`.

## Vorschau auf GitHub Pages

https://msteinforth.github.io/asc/ zeigt eine **statische Vorschau** (GitHub Pages kann kein PHP):
Seiten, Preise, Kalender und Live-Preis funktionieren, Formulare werden aber **nicht gesendet**
und ein Hinweisbalken weist darauf hin. Admin-Bereich und API sind nicht enthalten.

- Gebaut von `.github/workflows/pages.yml` bei jedem Push auf `main` und täglich (Kalender bleibt aktuell).
- `tools/pages-build.php` erzeugt die Vorschau: kopiert HTML/Assets, legt `api/tarife.json` und
  `api/verfuegbarkeit.json` aus `asc-data/` an und setzt den Basis-Pfad `/asc` vor alle Links.
- Lokal ausprobieren: `php tools/pages-build.php _site` und `_site` mit einem beliebigen Webserver ausliefern.
- Einmalig nötig: im Repository unter *Settings → Pages* bei *Source* „GitHub Actions“ auswählen.

## Deployment auf Strato (später)

1. Subdomain `asc.steinforth.com` im Kundencenter anlegen und auf den Ordner `/asc` zeigen lassen, SSL aktivieren.
2. `asc/` nach `/asc` und `asc-data/` nach `/asc-data` (neben, nicht in den Webroot) hochladen. `dev-router.php` und `tests/` nicht hochladen.
   **Achtung:** Nach dem Start `asc-data/` nicht mehr überschreiben – dort liegen die echten Buchungen und die im Admin geänderten Tarife.
3. `asc/lib/config.example.php` nach `asc/lib/config.php` kopieren und anpassen:
   - `base_url` → `https://asc.steinforth.com`
   - `data_dir` → absoluter Pfad zu `/asc-data`
   - `secret` → neu erzeugen: `php -r "echo bin2hex(random_bytes(32));"`
   - `mail.transport` → `smtp`, SMTP-Passwort des Postfachs asc@steinforth.com eintragen
   - `kontakt_telefon` eintragen
   - `admin_login` → eigenes Passwort-Hash setzen oder `null`, wenn nur `.htaccess` genutzt wird
4. Admin-Schutz: `.htpasswd` in `asc/admin/` anlegen und in `asc/admin/.htaccess` den absoluten Pfad bei `AuthUserFile` eintragen.
5. Schreibrechte für `asc-data/` prüfen (PHP muss dort schreiben können).
6. Kann `asc-data` nicht außerhalb des Webroots liegen, schützt die mitgelieferte `asc-data/.htaccess` (`Require all denied`) den Ordner.
7. Test: eine Buchung mit eigener Mailadresse durchspielen, Bestätigungslink klicken.

Backup: `asc-data/` wöchentlich per SFTP herunterladen (oder CSV-Export im Admin).

## Offen vor dem Start (aus dem PRD)

Endgültige Preise, Team-Texte (Lieblingstier und Satz der Kinder), Anschrift für Impressum/Datenschutz, Betreuung „bei uns“ ja/nein, Haftpflicht, Datenschutztext prüfen lassen.
