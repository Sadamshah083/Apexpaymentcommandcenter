import argparse
import json
import logging
import os
import re
import urllib.parse
import urllib.request

WIKI_SPECIAL_TITLES = {
    "Georgia": "List_of_municipalities_in_Georgia_(U.S._state)",
    "New York": "List_of_municipalities_in_New_York_(state)",
    "Washington": "List_of_municipalities_in_Washington_(state)",
}

CITY_ROW_RE = re.compile(r"^\| ([^|]+?)†?\s*\| (City|Town|Village|Borough) \|")


def state_slug(state_name: str) -> str:
    return state_name.lower().replace(" ", "_")


def wiki_titles(state_name: str) -> list[str]:
    slug = state_name.replace(" ", "_")
    if state_name in WIKI_SPECIAL_TITLES:
        return [WIKI_SPECIAL_TITLES[state_name]]
    return [
        f"List_of_municipalities_in_{slug}",
        f"List_of_cities_and_towns_in_{slug}",
        f"List_of_cities_in_{slug}",
        f"List_of_towns_in_{slug}",
    ]


def fetch_wikipedia_text(title: str) -> str:
    params = urllib.parse.urlencode(
        {
            "action": "parse",
            "page": title.replace("_", " "),
            "prop": "wikitext",
            "format": "json",
        }
    )
    url = f"https://en.wikipedia.org/w/api.php?{params}"
    request = urllib.request.Request(url, headers={"User-Agent": "GoogleMapsScraper/1.0"})
    with urllib.request.urlopen(request, timeout=30) as response:
        payload = json.loads(response.read().decode("utf-8", errors="ignore"))
    return payload["parse"]["wikitext"]["*"]


def parse_cities_from_wikitext(text: str) -> list[str]:
    cities = []
    seen = set()
    for line in text.splitlines():
        match = CITY_ROW_RE.match(line)
        if not match:
            continue
        name = match.group(1).strip()
        if not name or name in {"Name", "sq mi"} or name.startswith("---"):
            continue
        key = name.lower()
        if key in seen:
            continue
        seen.add(key)
        cities.append(name)
    return cities


def fetch_cities_for_state(state_name: str) -> list[str]:
    for title in wiki_titles(state_name):
        try:
            text = fetch_wikipedia_text(title)
            cities = parse_cities_from_wikitext(text)
            if cities:
                logging.info(f"{state_name}: found {len(cities)} cities from {title}")
                return cities
        except Exception as e:
            logging.warning(f"{state_name}: failed {title}: {e}")
    return []


def cities_file_path(state_name: str, cities_dir: str) -> str:
    return os.path.join(cities_dir, f"{state_slug(state_name)}_cities.txt")


def ensure_state_cities(state_name: str, cities_dir: str) -> list[str]:
    os.makedirs(cities_dir, exist_ok=True)
    path = cities_file_path(state_name, cities_dir)
    legacy_path = os.path.join("data", f"{state_slug(state_name)}_cities.txt")
    if not os.path.isfile(path) and os.path.isfile(legacy_path):
        with open(legacy_path, encoding="utf-8") as src, open(path, "w", encoding="utf-8") as dst:
            dst.write(src.read())
    if os.path.isfile(path):
        with open(path, encoding="utf-8") as f:
            cities = [line.strip() for line in f if line.strip()]
        if cities:
            return cities

    cities = fetch_cities_for_state(state_name)
    if not cities:
        raise RuntimeError(f"Could not fetch cities for {state_name}")

    with open(path, "w", encoding="utf-8") as f:
        f.write("\n".join(cities) + "\n")
    return cities


def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s - %(levelname)s - %(message)s")
    parser = argparse.ArgumentParser(description="Fetch and cache Wikipedia city lists for US states")
    parser.add_argument("--states-file", default="data/us_states.json")
    parser.add_argument("--cities-dir", default="data/cities")
    parser.add_argument("--state", help="Fetch a single state only")
    args = parser.parse_args()

    with open(args.states_file, encoding="utf-8") as f:
        config = json.load(f)

    states = [args.state] if args.state else config["states"]
    skip_states = set(config.get("skip_states", []))

    for state_name in states:
        if state_name in skip_states:
            logging.info(f"Skipping {state_name}")
            continue
        ensure_state_cities(state_name, args.cities_dir)


if __name__ == "__main__":
    main()
