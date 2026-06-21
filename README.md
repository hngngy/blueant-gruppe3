# Blue Ant Portfolio-Dashboard

Prototypisches PHP-Dashboard zur Auswertung von Projekt- und Portfoliodaten aus einer Blue-Ant-Testinstanz.

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
Die Prompts liegen fest in `config/prompts.php`. Für reproduzierbare Ergebnisse werden Temperatur `0` und ein fester Seed verwendet.

## Start mit Docker

```powershell
docker compose up --build
```

Danach im Browser öffnen:

```text
http://localhost:8000/portfolio-dashboard.php
```

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
http://localhost:8000/portfolio-dashboard.php
```

## Test-Endpunkte

- Dashboard: `http://localhost:8000/portfolio-dashboard.php`
- API-Rohantwort: `http://localhost:8000/api-test.php`
- KPI-Beschreibungen: `http://localhost:8000/kpis-test.php`
- Meilensteine: `http://localhost:8000/milestone-test.php`
- Meilensteine mit Projekt-ID: `http://localhost:8000/milestone-test.php?id=358571979`

## Dashboard bedienen

1. Portfolio auswählen.
2. Stichtag im Datumsfeld setzen.
3. `Projekte anzeigen` klicken.
4. Ein oder mehrere Projekte per Checkbox auswählen.
5. `Auswertung starten` klicken.
6. Optional `CSV exportieren` oder `Text exportieren` klicken.
7. Optional den angezeigten KI-Prompt prüfen oder anpassen.
8. Optional `KI-Auswertung starten` klicken, wenn die Gemini-Zusammenfassung erzeugt werden soll.

Die regelbasierte Portfolio-Auswertung funktioniert unabhängig von der KI. Die KI-Anfrage wird erst nach Klick auf `KI-Auswertung starten` ausgeführt.
Die eigentliche Auswertung startet erst, wenn mindestens ein Projekt ausgewählt wurde.
Prompt-Anpassungen im Dashboard gelten nur für den aktuellen KI-Aufruf und ändern nicht die Datei `config/prompts.php`.

Direkte Export-URLs:

```text
http://localhost:8000/portfolio-dashboard.php?portfolioId=PORTFOLIO_ID&reportDate=2026-06-21&analysisStarted=1&projectIds[]=PROJEKT_ID&export=csv
http://localhost:8000/portfolio-dashboard.php?portfolioId=PORTFOLIO_ID&reportDate=2026-06-21&analysisStarted=1&projectIds[]=PROJEKT_ID&projectIds[]=WEITERE_PROJEKT_ID&export=txt
```

## Projektstruktur

```text
config/config.php              Zentrale Konfiguration über .env
config/prompts.php             Feste KI-Prompts für reproduzierbare Reports
src/BlueAntClient.php          HTTP-Client für Blue-Ant-REST-Endpunkte
src/GeminiClient.php           Client für Gemini-KI-Auswertung
src/PortfolioAiAnalyzer.php    Normalisierung und Prompt-Aufruf für Portfolio-Reports
public/portfolio-dashboard.php Dashboard und Auswertungslogik
public/styles.css              Modernes Dashboard-Styling ohne Frontend-Abhängigkeiten
public/api-test.php            Einfacher API-Test-Endpunkt
public/kpis-test.php           Test für KPI-Beschreibungen
public/milestone-test.php      Test für Meilenstein-Daten
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

## Noch offene Punkte laut Pflichtenheft

- Verarbeitung mehrerer Portfolios in einem Lauf
- Vollstaendige Dokumentation der individuellen Blue-Ant-Felder und Berechnungslogik
