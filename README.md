# Blue Ant Portfolio-Dashboard

PHP-Dashboard zur Auswertung von Projekt- und Portfoliodaten aus einer Blue-Ant-Testinstanz.

## Voraussetzungen

- Docker Desktop oder eine lokale PHP-8.x-Installation
- Zugriff auf die Blue-Ant-REST-API
- Gültiger Blue-Ant-API-Token

## Konfiguration

Die Anwendung wird über Umgebungsvariablen konfiguriert. Dafür wird eine lokale `.env`-Datei genutzt.

```powershell
Copy-Item .env.example .env
```

Danach in `.env` den Token eintragen:

```env
APP_PORT=8000
APP_ENV=local
BLUEANT_BASE_URL=https://dashboard-examples.blueant.cloud/rest
BLUEANT_TOKEN=dein_api_token
AI_ENABLED=false
AI_PROVIDER=gemini
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
GEMINI_API_KEY=dein_gemini_api_key
GEMINI_MODEL=gemini-2.5-flash
AI_TEMPERATURE=0
AI_TIMEOUT_SECONDS=120
```

Die `.env`-Datei enthält sensible Zugangsdaten und darf nicht ins Repository eingecheckt werden.

## KI-Auswertung mit Gemini

Die KI-Auswertung nutzt ausschließlich die Gemini API über `generateContent`.

In `.env` aktivieren:

```env
AI_ENABLED=true
AI_PROVIDER=gemini
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
GEMINI_API_KEY=dein_gemini_api_key
GEMINI_MODEL=gemini-2.5-flash
AI_TEMPERATURE=0
AI_TIMEOUT_SECONDS=120
```

Docker-Container neu starten:

```powershell
docker compose down
docker compose up --build
```

Falls die lokale `.env` noch `gemini-api-key=...` enthält, wird dieser Wert aus Kompatibilitätsgründen ebenfalls gelesen. Empfohlen ist `GEMINI_API_KEY=...`.
Für reproduzierbare Ergebnisse werden Temperatur `0` und ein fester Seed verwendet.

### Prompt und KI-Auswertung im Code

- Der Standardprompt liegt in `config/prompts.php` unter dem Schlüssel `portfolio_management_summary`.
- Das sichtbare Promptkästchen, der Button `KI-Auswertung starten` und die Ausgabe der KI-Ergebnisse befinden sich in `public/portfolio-dashboard.php` im Abschnitt `KI-Management-Auswertung`.
- `public/portfolio-dashboard.php` übernimmt einen im Promptkästchen bearbeiteten Text nur für den unmittelbar folgenden KI-Aufruf. Die Datei `config/prompts.php` wird dadurch nicht verändert.
- `src/PortfolioAiAnalyzer.php` bereitet die ausgewählten Portfolio- und Projektdaten für die KI auf.
- `src/GeminiClient.php` kombiniert Prompt und Eingabedaten und sendet die HTTP-Anfrage an Gemini.

## Start mit Docker

```powershell
docker compose up --build
```

Danach im Browser öffnen:

```text
http://localhost:8000
```

Die Startseite leitet automatisch auf `/portfolio-dashboard.php` weiter.

Stoppen:

```powershell
docker compose down
```

## Start ohne Docker

Voraussetzung: PHP ist lokal installiert und die Extension `curl` ist aktiviert.

```powershell
php -m | findstr curl
php -S localhost:8000 -t public
```

Danach im Browser öffnen:

```text
http://localhost:8000
```

## Test-Endpunkte

- Dashboard: `http://localhost:8000/portfolio-dashboard.php`
- API-Rohantwort: `http://localhost:8000/api-test.php`
- KPI-Beschreibungen: `http://localhost:8000/kpis-test.php`
- Meilensteine: `http://localhost:8000/milestone-test.php`
- Meilensteine mit Projekt-ID: `http://localhost:8000/milestone-test.php?id=358571979`

## Dashboard bedienen

1. Ein oder mehrere Portfolios auswählen.
2. Den gewünschten Stichtag auswählen.
3. `Projekte anzeigen` klicken.
4. Ein oder mehrere Projekte per Checkbox oder über `Alle auswählen` auswählen.
5. `Auswertung starten` klicken.
6. Optional `CSV exportieren` oder `Text exportieren` klicken.
7. Optional den angezeigten KI-Prompt prüfen oder anpassen.
8. Optional `KI-Auswertung starten` klicken, wenn die Gemini-Zusammenfassung erzeugt werden soll.

Die regelbasierte Portfolio-Auswertung funktioniert unabhängig von der KI. Die KI-Anfrage wird erst nach Klick auf `KI-Auswertung starten` ausgeführt.
Die eigentliche Auswertung startet erst, wenn mindestens ein Projekt ausgewählt wurde.
Prompt-Anpassungen im Dashboard gelten nur für den aktuellen KI-Aufruf und ändern nicht die Datei `config/prompts.php`.

Direkte Export-URLs:

```text
http://localhost:8000/portfolio-dashboard.php?portfolioIds[]=PORTFOLIO_ID&reportDate=2026-07-13&analysisStarted=1&projectIds[]=PROJEKT_ID&export=csv
http://localhost:8000/portfolio-dashboard.php?portfolioIds[]=PORTFOLIO_ID&portfolioIds[]=WEITERE_PORTFOLIO_ID&reportDate=2026-07-13&analysisStarted=1&projectIds[]=PROJEKT_ID&projectIds[]=WEITERE_PROJEKT_ID&export=txt
```

## Projektstruktur

```text
config/config.php              Zentrale Konfiguration über .env
config/prompts.php             Feste KI-Prompts für reproduzierbare Reports
src/BlueAntClient.php          HTTP-Client für Blue-Ant-REST-Endpunkte
src/GeminiClient.php           Client für Gemini-KI-Auswertung
src/PortfolioAiAnalyzer.php    Normalisierung und Prompt-Aufruf für Portfolio-Reports
src/PortfolioAnalysis.php      Testbare Fachlogik für Texte, Meilensteine, Prognosen und Kritikalität
public/portfolio-dashboard.php Dashboard und Auswertungslogik
public/index.php               Weiterleitung der Startseite zum Dashboard
public/styles.css              Modernes Dashboard-Styling ohne Frontend-Abhängigkeiten
public/api-test.php            Einfacher API-Test-Endpunkt
public/kpis-test.php           Test für KPI-Beschreibungen
public/milestone-test.php      Test für Meilenstein-Daten
tests/run.php                  Automatisierte Fachlogik- und Feldzuordnungstests
Dockerfile                     PHP-Apache-Container mit curl
docker-compose.yml             Lokale Docker-Ausfuehrung
.env.example                   Beispielkonfiguration
```

## Aktueller Funktionsumfang

- Abruf von Portfolios, Projekten, Projektstatus, KPIs und Planungseinträgen über die REST-API
- Portfolio-Auswahl im Browser
- Auswahl eines oder mehrerer Projekte innerhalb eines Portfolios
- Stichtagsauswahl für Meilensteinbewertung und reproduzierbare Auswertungen
- Darstellung von Plan-/Ist-Aufwand und Plan-/Ist-Fortschritt
- Präzise CSS-Visualisierungen für Statusampel, Meilensteine, Aufwand, Fortschritt und kritische Projekte
- Auswertung der Statusampel über ein individuelles Blue-Ant-Feld
- Zusammenfassung von Projektstatus und Meilensteinen
- Regelbasierte Identifikation kritischer Projekte
- Export der Portfolio-Auswertung als CSV oder Textdatei
- Optionale KI-Management-Summary mit festen Prompts und reproduzierbaren Parametern
- Anzeige und einmalige Anpassung des KI-Prompts direkt vor dem KI-Aufruf
- Auswahl eines oder mehrerer Portfolios; Projekte aus überlappenden Portfolios werden nur einmal ausgewertet
- Bulk-Actions zum Auswählen oder Abwählen aller angezeigten Projekte mit live aktualisiertem Zähler
- Korrekte Trennung der Blue-Ant-Memofelder `subjectMemo` (Gegenstand), `statusMemo` (Status) und `noteMemo` (Notiz)
- Getrennte Zusammenfassungen für Gegenstand und Status pro Projekt sowie portfolioübergreifende KI-Überblicke
- Konfigurierbare individuelle Statusampel (`BLUEANT_TRAFFIC_LIGHT_FIELD_ID`)
- Transparente Termin- und Aufwandsprognose, die ausdrücklich als lineare Schätzung gekennzeichnet wird
- Kritikalitätsprüfung anhand Statusampel, Fortschrittsabweichung, Meilensteinen und Statustext
- Vollständige CSV- und Text-Exporte einschließlich Gegenstand, Status, Prognose und Kritikalitätsgründen

## Fachliche Standardwerte

Die folgenden Werte sind als Standardwerte in `config/config.php` hinterlegt und müssen deshalb nicht in `.env.example` stehen. Falls eine andere Blue-Ant-Instanz andere Feld-IDs oder Grenzwerte verwendet, können sie optional in der lokalen `.env` überschrieben werden:

```env
BLUEANT_TRAFFIC_LIGHT_FIELD_ID=832814142
BLUEANT_TRAFFIC_LIGHT_FIELD_NAME=Status Gesamt
CRITICAL_PROGRESS_DEVIATION=-20
```

Die Feld-ID bestimmt, aus welchem individuellen Blue-Ant-Feld die Statusampel gelesen wird. Der Feldname ist die Beschriftung im Dashboard. Mit `CRITICAL_PROGRESS_DEVIATION=-20` gilt ein Projekt als auffällig, sobald sein Ist-Fortschritt mindestens 20 Prozentpunkte hinter dem Plan liegt.

Der Stichtag ist frei wählbar und wird für Meilensteinbewertung, Prognose, KI-Kontext und Export verwendet. Die Blue-Ant-KPI-API liefert jedoch keine frei wählbaren historischen KPI-Snapshots: Plan-/Ist-Aufwand und Plan-/Ist-Fortschritt entsprechen deshalb weiterhin den beim Abruf gelieferten KPI-Werten und werden nicht rückwirkend für den gewählten Tag rekonstruiert.

Die regelbasierte Zusammenfassung kürzt die gelieferten Texte nachvollziehbar und bleibt ohne KI verfügbar. Die optionale Gemini-Auswertung erstellt darüber hinaus inhaltlich verdichtete Gegenstands- und Statuszusammenfassungen.

## Tests

```powershell
php tests/run.php
```

Die Tests prüfen insbesondere die Meilensteinbewertung, Kritikalitätsregeln, Prognoseberechnung und die korrekte Übergabe von `subjectMemo` als Projektgegenstand.

