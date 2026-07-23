#!/usr/bin/env python3
"""Apexone bridge: run Maps scrape jobs and write machine-readable progress JSON."""

from __future__ import annotations

import argparse
import json
import os
import sys
import traceback
from pathlib import Path


def write_progress(path: str, payload: dict) -> None:
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    tmp = f"{path}.tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, indent=2)
    os.replace(tmp, path)


def run_quick(args: argparse.Namespace) -> None:
    from main import scrape_places, save_places_to_csv
    from scrape_state import is_individual_listing

    write_progress(args.progress_file, {
        "status": "running",
        "message": "Starting quick Maps search",
        "percent": 5,
        "rows": 0,
    })

    places = scrape_places(args.search, total=max(1, args.total if args.total > 0 else 1_000_000), fetch_website_email=False)
    if args.individual_only:
        places = [place for place in places if is_individual_listing(place)]

    save_places_to_csv(places, args.output, append=False)
    write_progress(args.progress_file, {
        "status": "completed",
        "message": f"Scraped {len(places)} small businesses",
        "percent": 100,
        "rows": len(places),
        "output": args.output,
    })


def run_state(args: argparse.Namespace) -> None:
    from fetch_state_cities import ensure_state_cities, state_slug
    from scrape_state import parse_businesses, scrape_state

    write_progress(args.progress_file, {
        "status": "running",
        "message": f"Starting state scrape for {args.state}",
        "percent": 5,
        "rows": 0,
    })

    root = Path(__file__).resolve().parent
    cities_dir = args.cities_dir or str(root / "data" / "cities")
    bounds_file = args.bounds_file or str(root / "data" / "state_bounds.json")

    cities = []
    scrape_mode = args.scrape_mode
    if scrape_mode in ("city", "both"):
        try:
            cities = ensure_state_cities(args.state, cities_dir)
        except Exception as exc:
            logging_msg = f"City list unavailable for {args.state} ({exc}); falling back to grid mode"
            write_progress(args.progress_file, {
                "status": "running",
                "message": logging_msg,
                "percent": 8,
                "rows": 0,
            })
            if scrape_mode == "city":
                scrape_mode = "grid"
            cities = []

    if scrape_mode in ("city", "both") and not cities:
        scrape_mode = "grid"

    businesses = parse_businesses(args.business)
    scrape_progress = args.scrape_progress_file or args.progress_file.replace(".json", "_scrape.json")

    scrape_state(
        cities=cities,
        business=args.business,
        businesses=businesses,
        state=args.state,
        per_search=max(1, args.per_city if args.per_city > 0 else 1_000_000),
        output_path=args.output,
        delay_seconds=args.delay,
        progress_path=scrape_progress,
        fetch_website_email=False,
        mode=scrape_mode,
        grid_step=args.grid_step,
        grid_zoom=args.grid_zoom,
        bounds_file=bounds_file,
        country="USA",
        individual_only=True,
        fast_mode=True,
    )

    rows = 0
    if os.path.isfile(args.output):
        import pandas as pd

        rows = len(pd.read_csv(args.output))

    write_progress(args.progress_file, {
        "status": "completed",
        "message": f"State scrape finished ({rows} rows)",
        "percent": 100,
        "rows": rows,
        "output": args.output,
        "state_slug": state_slug(args.state),
    })


def main() -> int:
    parser = argparse.ArgumentParser(description="Apexone Google Maps scrape bridge")
    parser.add_argument("--job-mode", choices=("quick", "state"), required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--progress-file", required=True)
    parser.add_argument("--search", default="")
    parser.add_argument("--total", type=int, default=25)
    parser.add_argument("--state", default="Alabama")
    parser.add_argument("--business", default="locksmith shop")
    parser.add_argument("--per-city", type=int, default=20)
    parser.add_argument("--scrape-mode", choices=("city", "grid", "both"), default="city")
    parser.add_argument("--delay", type=int, default=0)
    parser.add_argument("--grid-step", type=float, default=0.25)
    parser.add_argument("--grid-zoom", type=int, default=13)
    parser.add_argument("--cities-dir", default=None)
    parser.add_argument("--bounds-file", default=None)
    parser.add_argument("--scrape-progress-file", default=None)
    parser.add_argument("--individual-only", action="store_true", default=True)
    parser.add_argument("--no-individual-only", action="store_false", dest="individual_only")
    args = parser.parse_args()

    try:
        os.makedirs(os.path.dirname(args.output) or ".", exist_ok=True)
        if args.job_mode == "quick":
            if not str(args.search).strip():
                raise ValueError("Quick mode requires --search")
            run_quick(args)
        else:
            run_state(args)
        return 0
    except Exception as exc:
        write_progress(args.progress_file, {
            "status": "failed",
            "message": str(exc),
            "percent": 0,
            "rows": 0,
            "error": traceback.format_exc(),
        })
        print(traceback.format_exc(), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
