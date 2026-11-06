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
  admin/              index.php (+ teile/), Login per PHP (admin_login in config.php)
  lib/                PHP-Logik, PHPMailer, config.example.php (gesperrt per .htaccess)
asc-data/             tarife.json, verfuegbarkeit.json, buchungen.json (außerhalb des Webroots)
tests/run.php         Tests der Kernlogik
tools/config-erzeugen.php  config.php für Strato aus den GitHub-Secrets
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

## Deployment auf Strato

Automatisch per GitHub Actions (`.github/workflows/deploy.yml`) bei jedem Push auf `main`:
Tests → `config.php` aus Secrets erzeugen (`tools/config-erzeugen.php`) → Upload per SFTP → Prüfung der Live-Seite
(Startseite, API, `lib/config.php` gesperrt, `/admin` verlangt Login). Ohne Secrets wird der Upload übersprungen.

**Einmalig bei Strato:** Subdomain `asc.steinforth.com` auf den Ordner `/asc` zeigen lassen, SSL aktivieren,
PHP 8.2+ einstellen, Postfach `asc@steinforth.com` anlegen, SFTP-Zugang nachsehen.

**Einmalig bei GitHub** (*Settings → Secrets and variables → Actions → Secrets*):

| Secret | Inhalt |
| --- | --- |
| `STRATO_SFTP_HOST` | SFTP-Server, meist `ssh.strato.de` |
| `STRATO_SFTP_USER` | SFTP-Benutzer aus dem Strato-Kundencenter |
| `STRATO_SFTP_PASSWORT` | SFTP-Passwort |
| `ASC_SMTP_PASSWORT` | Passwort des Postfachs asc@steinforth.com |
| `ASC_ADMIN_PASSWORT` | Passwort für `/admin` (mind. 10 Zeichen, Benutzer `admin`) |

Optional als *Variables*: `STRATO_PFAD` (Ordner, in dem `asc/` und `asc-data/` liegen, Standard `.` = Startordner des SFTP-Zugangs)
und `ASC_BASE_URL` (Standard `https://asc.steinforth.com`).

**Was hochgeladen wird:** `asc/` vollständig nach `/asc` (nichts wird gelöscht), `asc-data/` nach `/asc-data`, aber **nur fehlende Dateien** –
Buchungen und im Admin geänderte Tarife/Verfügbarkeiten werden nie überschrieben. Der geheime Schlüssel für die Bestätigungslinks
entsteht beim ersten Aufruf auf dem Server (`asc-data/geheimnis.key`). Der Admin-Login wird von PHP geprüft, eine `.htpasswd` ist nicht nötig.

Backup: `asc-data/` regelmäßig per SFTP herunterladen (oder CSV-Export im Admin).

## Offen vor dem Start (aus dem PRD)

Endgültige Preise, Team-Texte (Lieblingstier und Satz der Kinder), Betreuung „bei uns“ ja/nein, Haftpflicht, Datenschutztext prüfen lassen.
