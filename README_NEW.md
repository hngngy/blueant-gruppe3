# Blue Ant Portfolio-Dashboard

PHP-Dashboard zur Portfolio- und Projektauswertung aus Blue Ant mit optionaler KI-Analyse.

## Features

- Portfolio- und Projektauswahl mit Bulk Actions
- Visualisierungen: Statusampel, Meilensteine, Fortschritt, Aufwand
- KI-gestützte Management-Summaries mit Gemini (optional)
- CSV und Text-Export
- Prompt-Konfiguration über `.env`

---

## Installation

### Voraussetzungen

- Docker Desktop (empfohlen) ODER PHP 8.x lokal mit cURL-Erweiterung
- Zugriff auf Blue-Ant-REST-API
- Gültiger Blue-Ant-API-Token
- (Optional) Google Gemini API Key für KI-Analysen

### Mit Docker

```powershell
Copy-Item .env.example .env
```

In `.env` eintragen: `BLUEANT_TOKEN` und optional `GEMINI_API_KEY`

```powershell
docker compose up --build
```

Browser: `http://localhost:8000/portfolio-dashboard.php`

### Lokal ohne Docker

```powershell
php -S localhost:8000 -t public
```

Browser: `http://localhost:8000/portfolio-dashboard.php`

---

## Konfiguration

### .env-Datei

**Essenzielle Variablen:**

```env
# Blue Ant API
BLUEANT_BASE_URL=https://dashboard-examples.blueant.cloud/rest
BLUEANT_TOKEN=your_api_token

# KI-Features (optional)
AI_ENABLED=true
GEMINI_API_KEY=your_gemini_key
GEMINI_MODEL=gemini-2.5-flash
AI_TEMPERATURE=0
AI_TIMEOUT_SECONDS=120
```

Die `.env`-Datei enthält sensible Daten und darf **nicht ins Repository**!

### KI-Prompts

Datei: `config/prompts/portfolio-management-summary.txt`

Anleitung:
1. Datei öffnen und bearbeiten
2. Speichern
3. `docker compose restart`

Alternativer Pfad in `.env`:
```env
PROMPT_PORTFOLIO_MANAGEMENT_SUMMARY=/path/to/your-prompt.txt
```

---

## Verwendung

1. Portfolio auswählen
2. "Projekte anzeigen" klicken
3. Projekte auswählen (oder "Alle auswählen" Button)
4. "Auswertung starten"
5. Optional: "KI-Auswertung starten"
6. Export: CSV oder Text

Das Dashboard zeigt: Statusampel, Meilensteine, Fortschritt und Aufwand pro Projekt.

---

## Troubleshooting

### HTTP 401 Fehler

In `.env` URL auf REST-Endpunkt prüfen:
```env
BLUEANT_BASE_URL=https://dashboard-examples.blueant.cloud/rest
```

### Container-Probleme

```powershell
docker compose logs -f
docker compose down
docker compose up --build
```

### KI funktioniert nicht

- `AI_ENABLED=true` in `.env`
- `GEMINI_API_KEY` gültig
- Logs: `docker compose logs -f`

## Debug-Endpunkte

- `http://localhost:8000/api-test.php` - API-Test
- `http://localhost:8000/kpis-test.php?search=arbeit` - KPI-Test
- `http://localhost:8000/milestone-test.php?id=419344634` - Meilenstein-Test

---

## Struktur

```
blueant-gruppe3/
├── public/
│   ├── portfolio-dashboard.php    # Hauptdashboard
│   ├── api-test.php              # API-Test
│   ├── kpis-test.php             # KPI-Test
│   ├── milestone-test.php        # Meilenstein-Test
│   └── styles.css                # CSS
├── src/
│   ├── BlueAntClient.php         # Blue-Ant API
│   ├── AiJsonClient.php          # KI-Interface
│   ├── GeminiClient.php          # Gemini Implementation
│   └── PortfolioAiAnalyzer.php   # KI-Logik
├── config/
│   ├── config.php                # Config
│   ├── prompts.php               # Prompt-Verwaltung
│   └── prompts/
│       └── portfolio-management-summary.txt
├── .env.example                  # Beispiel .env
├── docker-compose.yml            # Docker
├── Dockerfile                    # Docker Image
└── README.md                     # Diese Datei
```

---

## Deployment

### Mit Docker

```powershell
# Lokal
docker compose up --build

# Background (Production)
docker compose up -d

# Stoppen
docker compose down
```

### Lokal ohne Docker

```powershell
php -S 0.0.0.0:8000 -t public
```

---

## Changelog

**v1.0 (2026-07-08)**
- ✅ Portfolio-Dashboard
- ✅ Visualisierungen (Ampel, Meilensteine, Fortschritt, Aufwand)
- ✅ KI-Integration (Gemini)
- ✅ Prompt-Konfiguration (.env)
- ✅ Bulk Actions (Select All)
- ✅ CSV/Text Export

**v1.1 (2026-07-10)**
- ✅ Eigene Statusampel: Eine projektspezifische Ampel wird jetzt zusätzlich zur BlueAnt-Ampel berechnet. Die eigene Ampel basiert auf Meilensteinen (offen / überfällig), To-dos (offen / überfällig) und der Fortschrittsabweichung (Plan vs. Ist). Implementierung: `public/portfolio-dashboard.php`.
- ✅ BlueAnt-Ampel-Verfügbarkeit: Das Dashboard prüft nun für jedes Projekt, ob ein BlueAnt-Ampelwert vorliegt und zeigt getrennte Zählungen (BlueAnt vs. eigene Berechnung).
- ✅ UI: Die Spalte "Status-Text" in der Projekt-Tabelle wurde deutlich verbreitert, damit lange Status-Memos horizontal umbrechen. Außerdem wird die Spalte "EIGENE AMPEL" in der Tabelle "Kritische Projekte" jetzt korrekt gerendert (Farbe + Begründung).
- ✅ Helper-Logik: Neue (lokale) Helferfunktionen zur Auswertung von Planning-Entries und To-dos wurden ergänzt (z. B. Analyse der Meilensteine, eigene Health-Logik, Normalisierung möglicher Ampel-Felder).

Hinweise zur Logik und zum Testen
- Standard-Schwellen (konfigurierbar im Code):
	- Sofort ROT, wenn überfällige Meilensteine vorhanden sind.
	- ROT, wenn mehr als 20% der offenen To-dos überfällig sind.
	- GELB, wenn die Fortschrittsabweichung deutlich negativ ist (Beispiel: <= -20 Prozentpunkte).
	- GRAU, wenn keine Planungsdaten vorhanden sind.
- Dateien, die geändert bzw. ergänzt wurden: `public/portfolio-dashboard.php`, `public/styles.css`.
- Testendpunkte: `public/milestone-test.php` (liefert Planning-Entries), `public/kpis-test.php` und `public/api-test.php` können beim Debug helfen.
- Nach dem Pull / Rebuild: Seite im Browser neu laden (Hard-Refresh), damit neue CSS-Regeln greifen.
