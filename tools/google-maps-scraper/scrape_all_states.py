import argparse
import json
import logging
import os
import platform
from typing import List, Set

from fetch_state_cities import ensure_state_cities, state_slug
from scrape_state import SCRAPE_MODES, parse_businesses, scrape_state, setup_logging
from state_grid import DEFAULT_COUNTRY
from playwright.sync_api import sync_playwright

BUSINESS_TYPE = "smoke and vape shops"
OUTPUT_SUFFIX = "smoke_vape_shops"
OUTPUT_DIR = "output"
PROGRESS_DIR = "data/progress"


def load_all_states_progress(path: str) -> dict:
    if not os.path.isfile(path):
        return {"completed_states": [], "skip_states": ["Alaska"]}
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def save_all_states_progress(path: str, data: dict) -> None:
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2)


def all_states_progress_path(suffix: str) -> str:
    return os.path.join(PROGRESS_DIR, f"all_states_{suffix}_progress.json")


def state_progress_path(state_name: str, suffix: str) -> str:
    return os.path.join(PROGRESS_DIR, f"{state_slug(state_name)}_{suffix}_progress.json")


def state_output_path(state_name: str, output_dir: str, suffix: str) -> str:
    return os.path.join(output_dir, f"{state_slug(state_name)}_{suffix}.csv")


def states_from_start(states: List[str], start_state: str) -> List[str]:
    if not start_state:
        return states
    target = start_state.strip().lower()
    for index, state in enumerate(states):
        if state.lower() == target:
            return states[index:]
    raise ValueError(f"Start state '{start_state}' not found in states list")


def scrape_all_states(
    states: List[str],
    skip_states: Set[str],
    business: str,
    per_search: int,
    output_dir: str,
    cities_dir: str,
    delay_seconds: int,
    all_progress_path: str,
    output_suffix: str,
    fetch_website_email: bool = False,
    mode: str = "both",
    grid_step: float = 0.15,
    grid_zoom: int = 13,
    bounds_file: str = "data/state_bounds.json",
    start_state: str = "",
    businesses: List[str] | None = None,
    country: str = DEFAULT_COUNTRY,
    individual_only: bool = False,
    fast_mode: bool = False,
) -> None:
    setup_logging()
    os.makedirs(output_dir, exist_ok=True)
    os.makedirs(PROGRESS_DIR, exist_ok=True)

    business_list = businesses if businesses is not None else parse_businesses(business)
    states = states_from_start(states, start_state)

    all_progress = load_all_states_progress(all_progress_path)
    completed_states = set(all_progress.get("completed_states", []))

    pending_states = [
        state for state in states if state not in skip_states and state not in completed_states
    ]

    logging.info(
        f"Multi-state scrape: {len(pending_states)} states to run, "
        f"skipping {sorted(skip_states)}, businesses={business_list}, country={country}, "
        f"individual_only={individual_only}, fast_mode={fast_mode}, mode='{mode}'"
    )
    if start_state:
        logging.info(f"Starting from state: {start_state}")

    with sync_playwright() as p:
        from browser_launch import launch_browser

        browser = launch_browser(p)
        page = browser.new_page()
        try:
            for state_index, state_name in enumerate(pending_states, start=1):
                logging.info(f"Starting state {state_index}/{len(pending_states)}: {state_name}")

                cities = []
                if mode in ("city", "both"):
                    try:
                        cities = ensure_state_cities(state_name, cities_dir)
                    except Exception as e:
                        logging.error(f"Could not load cities for {state_name}: {e}")
                        if mode == "city":
                            continue

                try:
                    scrape_state(
                        cities=cities,
                        business=business,
                        businesses=business_list,
                        state=state_name,
                        per_search=per_search,
                        output_path=state_output_path(state_name, output_dir, output_suffix),
                        delay_seconds=delay_seconds,
                        progress_path=state_progress_path(state_name, output_suffix),
                        fetch_website_email=fetch_website_email,
                        mode=mode,
                        grid_step=grid_step,
                        grid_zoom=grid_zoom,
                        bounds_file=bounds_file,
                        page=page,
                        country=country,
                        individual_only=individual_only,
                        fast_mode=fast_mode,
                    )
                except Exception as e:
                    logging.error(f"State scrape failed for {state_name}: {e}")
                    continue

                completed_states.add(state_name)
                all_progress["completed_states"] = sorted(completed_states)
                all_progress["current_state"] = state_name
                all_progress["mode"] = mode
                all_progress["businesses"] = business_list
                save_all_states_progress(all_progress_path, all_progress)
                logging.info(f"Finished state: {state_name}. Moving to next state.")
        finally:
            browser.close()

    logging.info("All states complete.")


def main():
    parser = argparse.ArgumentParser(
        description="Scrape Google Maps for all US states (city, grid, or both; multiple business types supported)"
    )
    parser.add_argument("--states-file", default="data/us_states.json")
    parser.add_argument(
        "--business",
        default=BUSINESS_TYPE,
        help='Business type(s). Comma-separated for mixed data, e.g. "nail salon,hair salon"',
    )
    parser.add_argument("-t", "--per-city", type=int, default=0, help="Max results per city/grid cell (0 = unlimited)")
    parser.add_argument(
        "--mode",
        choices=SCRAPE_MODES,
        default="both",
        help="city = city names only, grid = coordinates only, both = maximum coverage",
    )
    parser.add_argument("--grid-step", type=float, default=0.15, help="Lat/lng step between grid cells")
    parser.add_argument("--grid-zoom", type=int, default=13, help="Google Maps zoom level for grid searches")
    parser.add_argument("--bounds-file", default="data/state_bounds.json", help="State bounding boxes JSON")
    parser.add_argument("--output-dir", default=OUTPUT_DIR)
    parser.add_argument("--output-suffix", default=OUTPUT_SUFFIX, help="CSV filename suffix per state")
    parser.add_argument("--cities-dir", default="data/cities")
    parser.add_argument("--delay", type=int, default=0, help="Seconds to wait between searches (0 = no delay)")
    parser.add_argument(
        "--start-state",
        default="",
        help="Start from this state and continue forward (e.g. Tennessee)",
    )
    parser.add_argument(
        "--progress-file",
        default=None,
        help="Override all-states progress file (default: data/progress/all_states_<suffix>_progress.json)",
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

    progress_file = args.progress_file or all_states_progress_path(args.output_suffix)
    business_list = parse_businesses(args.business)

    with open(args.states_file, encoding="utf-8") as f:
        config = json.load(f)

    scrape_all_states(
        states=config["states"],
        skip_states=set(config.get("skip_states", [])),
        business=args.business,
        businesses=business_list,
        per_search=args.per_city,
        output_dir=args.output_dir,
        cities_dir=args.cities_dir,
        delay_seconds=args.delay,
        all_progress_path=progress_file,
        output_suffix=args.output_suffix,
        fetch_website_email=args.fetch_email,
        mode=args.mode,
        grid_step=args.grid_step,
        grid_zoom=args.grid_zoom,
        bounds_file=args.bounds_file,
        start_state=args.start_state,
        country=args.country,
        individual_only=args.individual_only,
        fast_mode=args.fast,
    )


if __name__ == "__main__":
    main()
