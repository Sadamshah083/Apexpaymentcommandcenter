import argparse
import json
import logging
import os
import platform
import time
from dataclasses import asdict
from typing import List, Optional, Set, Tuple

import pandas as pd
from playwright.sync_api import sync_playwright

from fetch_state_cities import ensure_state_cities, state_slug
from main import Place, extract_place, setup_logging
from state_grid import (
    DEFAULT_COUNTRY,
    build_city_search_query,
    build_grid_search_url,
    cell_id,
    grid_cells_for_state,
)

STATE_ABBREVS = {
    "Alabama": "AL", "Alaska": "AK", "Arizona": "AZ", "Arkansas": "AR", "California": "CA",
    "Colorado": "CO", "Connecticut": "CT", "Delaware": "DE", "Florida": "FL", "Georgia": "GA",
    "Hawaii": "HI", "Idaho": "ID", "Illinois": "IL", "Indiana": "IN", "Iowa": "IA",
    "Kansas": "KS", "Kentucky": "KY", "Louisiana": "LA", "Maine": "ME", "Maryland": "MD",
    "Massachusetts": "MA", "Michigan": "MI", "Minnesota": "MN", "Mississippi": "MS",
    "Missouri": "MO", "Montana": "MT", "Nebraska": "NE", "Nevada": "NV", "New Hampshire": "NH",
    "New Jersey": "NJ", "New Mexico": "NM", "New York": "NY", "North Carolina": "NC",
    "North Dakota": "ND", "Ohio": "OH", "Oklahoma": "OK", "Oregon": "OR", "Pennsylvania": "PA",
    "Rhode Island": "RI", "South Carolina": "SC", "South Dakota": "SD", "Tennessee": "TN",
    "Texas": "TX", "Utah": "UT", "Vermont": "VT", "Virginia": "VA", "Washington": "WA",
    "West Virginia": "WV", "Wisconsin": "WI", "Wyoming": "WY",
}

BUSINESS_TYPE = "smoke and vape shops"
OUTPUT_SUFFIX = "smoke_vape_shops"
OUTPUT_DIR = "output"
PROGRESS_DIR = "data/progress"
SCRAPE_MODES = ("city", "grid", "both")
TASK_KEY_SEP = "::"

PLACE_LINK_XPATH = '//a[contains(@href, "https://www.google.com/maps/place")]'
PLACE_NAME_XPATH = '//div[@class="TIHn2 "]//h1[@class="DUwDvf lfPIob"]'

CORPORATE_NAME_MARKERS = (
    " llc", " l.l.c.", " inc", " corp", " corporation", " holdings",
    " enterprises", " international", " nationwide", " franchise",
    " company", " co.", " group", " solutions llc", " systems inc",
)
NATIONAL_CHAIN_MARKERS = (
    "pop-a-lock", "pop a lock", "home depot", "lowe's", "lowes ",
    "walmart", "sears", "u-haul", "budget locksmith", "securitas",
    "brinks", "adt security", "amazon hub",
    "zips dry cleaners", "tide dry cleaners", "comet cleaners", "martinizing",
)
CORPORATE_PLACE_TYPES = (
    "corporate office", "headquarters", "manufacturer", "wholesale",
    "distributor", "distribution service", "business center", "holding company",
    "security system supplier", "software company", "corporate campus",
)


def parse_businesses(business: str) -> List[str]:
    items = [part.strip() for part in business.split(",") if part.strip()]
    if not items:
        raise ValueError("At least one business type is required")
    return items


def task_key(location: str, business: str, multi_business: bool) -> str:
    if multi_business:
        return f"{location}{TASK_KEY_SEP}{business}"
    return location


def load_cities(path: str) -> List[str]:
    with open(path, encoding="utf-8") as f:
        cities = []
        seen = set()
        for line in f:
            city = line.strip()
            if city and city.lower() not in seen:
                seen.add(city.lower())
                cities.append(city)
        return cities


def is_usa_address(address: str) -> bool:
    value = address.strip()
    if not value:
        return False
    return "United States" in value or ", USA" in value or value.endswith("USA")


def address_in_state(address: str, state: str) -> bool:
    abbrev = STATE_ABBREVS.get(state)
    if not abbrev:
        return True
    return f", {abbrev} " in address or f", {abbrev}," in address


def place_matches_location(place: Place, state: str) -> bool:
    if not is_usa_address(place.address):
        return False
    return address_in_state(place.address, state)


def is_individual_listing(place: Place) -> bool:
    name = place.name.lower()
    if any(marker in name for marker in CORPORATE_NAME_MARKERS):
        return False
    if any(marker in name for marker in NATIONAL_CHAIN_MARKERS):
        return False
    place_type = place.place_type.lower()
    if place_type and any(marker in place_type for marker in CORPORATE_PLACE_TYPES):
        return False
    return True


def place_key(place: Place) -> Tuple[str, str, str]:
    return (
        place.name.strip().lower(),
        place.address.strip().lower(),
        place.phone_number.strip().lower(),
    )


def load_existing_keys(output_path: str) -> Set[Tuple[str, str, str]]:
    if not os.path.isfile(output_path):
        return set()
    df = pd.read_csv(output_path)
    keys = set()
    for _, row in df.iterrows():
        keys.add(
            (
                str(row.get("name", "")).strip().lower(),
                str(row.get("address", "")).strip().lower(),
                str(row.get("phone_number", "")).strip().lower(),
            )
        )
    return keys


def emails_output_path(main_output_path: str) -> str:
    base, ext = os.path.splitext(main_output_path)
    return f"{base}_emails{ext or '.csv'}"


def load_existing_email_keys(emails_path: str) -> Set[Tuple[str, str]]:
    if not os.path.isfile(emails_path):
        return set()
    df = pd.read_csv(emails_path)
    keys = set()
    for _, row in df.iterrows():
        email = str(row.get("email", "")).strip().lower()
        name = str(row.get("name", "")).strip().lower()
        if email:
            keys.add((email, name))
    return keys


EMAIL_COLUMNS = [
    "name", "email", "phone_number", "address", "website", "place_type",
    "search_state", "search_business", "search_source", "search_city",
]


def save_email_rows(
    rows: List[dict],
    emails_path: str,
    append: bool,
    seen_email_keys: Set[Tuple[str, str]],
) -> int:
    email_rows = []
    for row in rows:
        email = str(row.get("email", "")).strip()
        if not email:
            continue
        key = (email.lower(), str(row.get("name", "")).strip().lower())
        if key in seen_email_keys:
            continue
        seen_email_keys.add(key)
        email_rows.append({col: row.get(col, "") for col in EMAIL_COLUMNS})

    if not email_rows:
        return 0

    df = pd.DataFrame(email_rows, columns=EMAIL_COLUMNS)
    file_exists = os.path.isfile(emails_path)
    mode = "a" if append else "w"
    header = not (append and file_exists)
    df.to_csv(emails_path, index=False, mode=mode, header=header)
    return len(email_rows)


def save_places(
    places: List[Place],
    output_path: str,
    search_city: str,
    append: bool,
    seen_keys: Set[Tuple[str, str, str]],
    search_state: str = "",
    search_source: str = "city",
    search_business: str = "",
    individual_only: bool = False,
    emails_path: str = "",
    seen_email_keys: Optional[Set[Tuple[str, str]]] = None,
) -> int:
    rows = []
    for place in places:
        if not place_matches_location(place, search_state):
            logging.debug(f"Skipped non-USA or out-of-state listing: {place.name} | {place.address}")
            continue
        if individual_only and not is_individual_listing(place):
            logging.debug(f"Skipped corporate/chain listing: {place.name} | {place.place_type}")
            continue
        key = place_key(place)
        if key in seen_keys or not place.name:
            continue
        seen_keys.add(key)
        row = asdict(place)
        row["search_city"] = search_city
        row["search_state"] = search_state
        row["search_source"] = search_source
        row["search_business"] = search_business
        rows.append(row)

    if not rows:
        return 0

    df = pd.DataFrame(rows)
    file_exists = os.path.isfile(output_path)
    mode = "a" if append else "w"
    header = not (append and file_exists)
    df.to_csv(output_path, index=False, mode=mode, header=header)

    if emails_path and seen_email_keys is not None:
        saved_emails = save_email_rows(rows, emails_path, append=True, seen_email_keys=seen_email_keys)
        if saved_emails:
            logging.info(f"Saved {saved_emails} emails to {emails_path}")

    return len(rows)


def scrape_timings(fast_mode: bool) -> dict:
    if fast_mode:
        return {
            "scroll_ms": 400,
            "listing_sleep": 0.4,
            "city_load_ms": 300,
            "grid_load_ms": 500,
            "stale_limit": 2,
        }
    return {
        "scroll_ms": 1500,
        "listing_sleep": 1.5,
        "city_load_ms": 1000,
        "grid_load_ms": 2000,
        "stale_limit": 3,
    }


def scrape_listings(
    page,
    total: int,
    fetch_website_email: bool = False,
    fast_mode: bool = False,
) -> List[Place]:
    places: List[Place] = []
    unlimited = total <= 0
    timings = scrape_timings(fast_mode)

    try:
        page.wait_for_selector(PLACE_LINK_XPATH, timeout=45000)
    except Exception:
        return places

    page.hover(PLACE_LINK_XPATH)
    previously_counted = 0
    stale_rounds = 0
    while True:
        page.mouse.wheel(0, 10000)
        page.wait_for_timeout(timings["scroll_ms"])
        page.wait_for_selector(PLACE_LINK_XPATH)
        found = page.locator(PLACE_LINK_XPATH).count()
        logging.info(f"Currently Found: {found}")
        if not unlimited and found >= total:
            break
        if found == previously_counted:
            stale_rounds += 1
            if stale_rounds >= timings["stale_limit"]:
                logging.info("Arrived at all available")
                break
        else:
            stale_rounds = 0
        previously_counted = found

    all_listings = page.locator(PLACE_LINK_XPATH).all()
    listings = all_listings if unlimited else all_listings[:total]
    listings = [listing.locator("xpath=..") for listing in listings]
    logging.info(f"Scraping {len(listings)} listings (detail mode)")

    for idx, listing in enumerate(listings):
        try:
            listing.click()
            page.wait_for_selector(PLACE_NAME_XPATH, timeout=10000)
            time.sleep(timings["listing_sleep"])
            # Fast mode: Maps email only (website crawl is much slower)
            use_website_email = fetch_website_email and not fast_mode
            place = extract_place(page, fetch_website_email=use_website_email)
            if place.name:
                places.append(place)
            else:
                logging.warning(f"No name found for listing {idx + 1}, skipping.")
        except Exception as e:
            logging.warning(f"Failed to extract listing {idx + 1}: {e}")

    return places


def scrape_city(
    page,
    search_query: str,
    total: int,
    fetch_website_email: bool = False,
    fast_mode: bool = False,
) -> List[Place]:
    timings = scrape_timings(fast_mode)
    page.goto("https://www.google.com/maps", timeout=60000)
    page.wait_for_timeout(timings["city_load_ms"])
    page.locator("//form[contains(@jsaction,'searchboxFormSubmit')]//input[@name='q']").fill(search_query)
    page.keyboard.press("Enter")
    logging.info(f"City search: {search_query}")
    return scrape_listings(page, total, fetch_website_email, fast_mode=fast_mode)


def scrape_grid_cell(
    page,
    business: str,
    state: str,
    lat: float,
    lng: float,
    zoom: int,
    total: int,
    fetch_website_email: bool = False,
    country: str = DEFAULT_COUNTRY,
    fast_mode: bool = False,
) -> List[Place]:
    timings = scrape_timings(fast_mode)
    url = build_grid_search_url(business, state, lat, lng, zoom, country=country)
    page.goto(url, timeout=60000)
    page.wait_for_timeout(timings["grid_load_ms"])
    logging.info(f"Grid search: {cell_id(lat, lng)} @ zoom {zoom} ({business})")
    return scrape_listings(page, total, fetch_website_email, fast_mode=fast_mode)


def load_state_progress(progress_path: str) -> dict:
    if not os.path.isfile(progress_path):
        return {"completed_cities": [], "completed_grid_cells": []}
    with open(progress_path, encoding="utf-8") as f:
        data = json.load(f)
    return {
        "completed_cities": list(data.get("completed_cities", [])),
        "completed_grid_cells": list(data.get("completed_grid_cells", [])),
    }


def load_progress(progress_path: str) -> Set[str]:
    return set(load_state_progress(progress_path)["completed_cities"])


def load_grid_progress(progress_path: str) -> Set[str]:
    return set(load_state_progress(progress_path)["completed_grid_cells"])


def save_progress(
    progress_path: str,
    completed_cities: Optional[Set[str]] = None,
    completed_grid_cells: Optional[Set[str]] = None,
) -> None:
    data = load_state_progress(progress_path)
    if completed_cities is not None:
        data["completed_cities"] = sorted(completed_cities)
    if completed_grid_cells is not None:
        data["completed_grid_cells"] = sorted(completed_grid_cells)
    with open(progress_path, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2)


def scrape_state_cities(
    page,
    cities: List[str],
    businesses: List[str],
    state: str,
    per_search: int,
    output_path: str,
    progress_path: str,
    delay_seconds: int,
    fetch_website_email: bool,
    seen_keys: Set[Tuple[str, str, str]],
    completed_cities: Set[str],
    country: str = DEFAULT_COUNTRY,
    individual_only: bool = False,
    emails_path: str = "",
    seen_email_keys: Optional[Set[Tuple[str, str]]] = None,
    fast_mode: bool = False,
) -> None:
    multi_business = len(businesses) > 1
    pending = []
    for city in cities:
        for business in businesses:
            key = task_key(city, business, multi_business)
            if key not in completed_cities:
                pending.append((city, business, key))

    logging.info(f"{state}: {len(pending)} city searches pending ({len(completed_cities)} done)")

    for index, (city, business, key) in enumerate(pending, start=1):
        search_query = build_city_search_query(city, state, business, country=country)
        logging.info(f"[{state} city {index}/{len(pending)}] {search_query}")

        try:
            places = scrape_city(
                page, search_query, per_search, fetch_website_email, fast_mode=fast_mode
            )
            saved = save_places(
                places,
                output_path,
                search_city=city,
                append=True,
                seen_keys=seen_keys,
                search_state=state,
                search_source="city",
                search_business=business,
                individual_only=individual_only,
                emails_path=emails_path,
                seen_email_keys=seen_email_keys,
            )
            logging.info(f"Saved {saved} new places from {city}, {state} ({business})")
            completed_cities.add(key)
            save_progress(progress_path, completed_cities=completed_cities)
        except Exception as e:
            logging.error(f"Failed on {city}, {state} ({business}): {e}")

        if delay_seconds > 0:
            time.sleep(delay_seconds)


def scrape_state_grid(
    page,
    state: str,
    businesses: List[str],
    per_search: int,
    output_path: str,
    progress_path: str,
    delay_seconds: int,
    fetch_website_email: bool,
    seen_keys: Set[Tuple[str, str, str]],
    completed_grid_cells: Set[str],
    grid_step: float,
    grid_zoom: int,
    bounds_file: str,
    country: str = DEFAULT_COUNTRY,
    individual_only: bool = False,
    emails_path: str = "",
    seen_email_keys: Optional[Set[Tuple[str, str]]] = None,
    fast_mode: bool = False,
) -> None:
    multi_business = len(businesses) > 1
    cells = grid_cells_for_state(state, grid_step, bounds_file)
    pending = []
    for lat, lng in cells:
        label = cell_id(lat, lng)
        for business in businesses:
            key = task_key(label, business, multi_business)
            if key not in completed_grid_cells:
                pending.append((lat, lng, label, business, key))

    logging.info(f"{state}: {len(pending)} grid searches pending ({len(completed_grid_cells)} done)")

    for index, (lat, lng, label, business, key) in enumerate(pending, start=1):
        logging.info(f"[{state} grid {index}/{len(pending)}] {label} ({business})")

        try:
            places = scrape_grid_cell(
                page,
                business=business,
                state=state,
                lat=lat,
                lng=lng,
                zoom=grid_zoom,
                total=per_search,
                fetch_website_email=fetch_website_email,
                country=country,
                fast_mode=fast_mode,
            )
            saved = save_places(
                places,
                output_path,
                search_city=label,
                append=True,
                seen_keys=seen_keys,
                search_state=state,
                search_source="grid",
                search_business=business,
                individual_only=individual_only,
                emails_path=emails_path,
                seen_email_keys=seen_email_keys,
            )
            logging.info(f"Saved {saved} new places from grid {label}, {state} ({business})")
            completed_grid_cells.add(key)
            save_progress(progress_path, completed_grid_cells=completed_grid_cells)
        except Exception as e:
            logging.error(f"Failed on grid {label}, {state} ({business}): {e}")

        if delay_seconds > 0:
            time.sleep(delay_seconds)


def scrape_state(
    cities: List[str],
    business: str,
    state: str,
    per_search: int,
    output_path: str,
    delay_seconds: int,
    progress_path: str,
    fetch_website_email: bool = False,
    mode: str = "both",
    grid_step: float = 0.15,
    grid_zoom: int = 13,
    bounds_file: str = "data/state_bounds.json",
    page=None,
    businesses: Optional[List[str]] = None,
    country: str = DEFAULT_COUNTRY,
    individual_only: bool = False,
    fast_mode: bool = False,
) -> None:
    if mode not in SCRAPE_MODES:
        raise ValueError(f"Invalid mode '{mode}'. Choose from: {', '.join(SCRAPE_MODES)}")

    business_list = businesses if businesses is not None else parse_businesses(business)

    setup_logging()
    progress = load_state_progress(progress_path)
    completed_cities = set(progress["completed_cities"])
    completed_grid_cells = set(progress["completed_grid_cells"])
    seen_keys = load_existing_keys(output_path)
    emails_path = emails_output_path(output_path)
    seen_email_keys = load_existing_email_keys(emails_path)

    logging.info(
        f"Starting {state} scrape (mode={mode}): output={output_path}, emails={emails_path}, "
        f"businesses={business_list}, country={country}, individual_only={individual_only}, "
        f"fast_mode={fast_mode}, email_fetch={fetch_website_email}"
    )

    def run_scrape(active_page) -> None:
        if mode in ("city", "both"):
            scrape_state_cities(
                page=active_page,
                cities=cities,
                businesses=business_list,
                state=state,
                per_search=per_search,
                output_path=output_path,
                progress_path=progress_path,
                delay_seconds=delay_seconds,
                fetch_website_email=fetch_website_email,
                seen_keys=seen_keys,
                completed_cities=completed_cities,
                country=country,
                individual_only=individual_only,
                emails_path=emails_path,
                seen_email_keys=seen_email_keys,
                fast_mode=fast_mode,
            )

        if mode in ("grid", "both"):
            scrape_state_grid(
                page=active_page,
                state=state,
                businesses=business_list,
                per_search=per_search,
                output_path=output_path,
                progress_path=progress_path,
                delay_seconds=delay_seconds,
                fetch_website_email=fetch_website_email,
                seen_keys=seen_keys,
                completed_grid_cells=completed_grid_cells,
                grid_step=grid_step,
                grid_zoom=grid_zoom,
                bounds_file=bounds_file,
                country=country,
                individual_only=individual_only,
                emails_path=emails_path,
                seen_email_keys=seen_email_keys,
                fast_mode=fast_mode,
            )

    if page is not None:
        run_scrape(page)
        logging.info(f"State scrape finished. Results saved to {output_path}")
        return

    with sync_playwright() as p:
        from browser_launch import launch_browser

        browser = launch_browser(p)
        active_page = browser.new_page()
        try:
            run_scrape(active_page)
        finally:
            browser.close()

    logging.info(f"State scrape finished. Results saved to {output_path}")


def main():
    parser = argparse.ArgumentParser(
        description="Scrape Google Maps city/grid into one state CSV (supports multiple business types)"
    )
    parser.add_argument("--state", default="Alabama", help="US state name")
    parser.add_argument(
        "--business",
        default=BUSINESS_TYPE,
        help='Business type(s) to search. Comma-separated for mixed data, e.g. "nail salon,hair salon"',
    )
    parser.add_argument(
        "--cities-file",
        default=None,
        help="Text file with one city per line (default: auto-fetch/cache from Wikipedia)",
    )
    parser.add_argument("--cities-dir", default="data/cities", help="Directory for cached city lists")
    parser.add_argument(
        "-t",
        "--per-city",
        type=int,
        default=0,
        help="Max results per city/grid cell (0 = unlimited)",
    )
    parser.add_argument(
        "--mode",
        choices=SCRAPE_MODES,
        default="both",
        help="city = city names only, grid = coordinates only, both = maximum coverage",
    )
    parser.add_argument("--grid-step", type=float, default=0.15, help="Lat/lng step between grid cells")
    parser.add_argument("--grid-zoom", type=int, default=13, help="Google Maps zoom level for grid searches")
    parser.add_argument("--bounds-file", default="data/state_bounds.json", help="State bounding boxes JSON")
    parser.add_argument("--output-dir", default=OUTPUT_DIR, help="Directory for state CSV output")
    parser.add_argument(
        "--output-suffix",
        default=OUTPUT_SUFFIX,
        help="CSV filename suffix (e.g. alabama_smoke_vape_shops.csv)",
    )
    parser.add_argument("-o", "--output", default=None, help="Override full output CSV path")
    parser.add_argument("--delay", type=int, default=0, help="Seconds to wait between searches (0 = no delay)")
    parser.add_argument(
        "--progress-file",
        default=None,
        help="Progress file for resume support (default: data/progress/<state>_<suffix>_progress.json)",
    )
    parser.add_argument(
        "--fetch-email",
        action="store_true",
        help="If Maps has no email, check the business website contact pages",
    )
    parser.add_argument("--country", default=DEFAULT_COUNTRY, help="Country for search queries and address filter")
    parser.add_argument(
        "--individual-only",
        action="store_true",
        help="Skip corporate/chain listings (LLC, Inc, national brands, corporate offices)",
    )
    parser.add_argument(
        "--fast",
        action="store_true",
        help="Faster scraping (shorter waits; Maps email only, skips slow website email crawl)",
    )
    args = parser.parse_args()

    os.makedirs(args.output_dir, exist_ok=True)
    os.makedirs(PROGRESS_DIR, exist_ok=True)

    business_list = parse_businesses(args.business)

    cities = []
    if args.mode in ("city", "both"):
        if args.cities_file:
            cities = load_cities(args.cities_file)
        else:
            cities = ensure_state_cities(args.state, args.cities_dir)

    output_path = args.output or os.path.join(
        args.output_dir, f"{state_slug(args.state)}_{args.output_suffix}.csv"
    )
    progress_path = args.progress_file or os.path.join(
        PROGRESS_DIR, f"{state_slug(args.state)}_{args.output_suffix}_progress.json"
    )

    scrape_state(
        cities=cities,
        business=args.business,
        businesses=business_list,
        state=args.state,
        per_search=args.per_city,
        output_path=output_path,
        delay_seconds=args.delay,
        progress_path=progress_path,
        fetch_website_email=args.fetch_email,
        mode=args.mode,
        grid_step=args.grid_step,
        grid_zoom=args.grid_zoom,
        bounds_file=args.bounds_file,
        country=args.country,
        individual_only=args.individual_only,
        fast_mode=args.fast,
    )


if __name__ == "__main__":
    main()
