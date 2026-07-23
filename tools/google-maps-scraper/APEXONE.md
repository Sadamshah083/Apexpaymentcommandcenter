# Maps Lead Scraper (vendored from Google-Maps-Scrapper)

Scrapes Google Maps for **small / independent businesses**, then Apexone exports
one Excel (`.xlsx`) file per phone **area code** (first 3 digits) inside a ZIP.

## Setup

```bash
cd tools/google-maps-scraper
pip install -r requirements.txt
playwright install chromium
```

Optional env (Laravel `.env`):

```
MAPS_SCRAPER_ENABLED=true
MAPS_SCRAPER_PYTHON=python
MAPS_SCRAPER_PATH=
MAPS_SCRAPER_HEADLESS=true
MAPS_SCRAPER_CHROME_PATH=
```

## CLI bridge

```bash
python apex_bridge.py --job-mode quick --search "locksmith in Birmingham, Alabama, USA" --total 20 --output out.csv --progress-file progress.json
```

State scrape always uses `--individual-only` (small businesses).
