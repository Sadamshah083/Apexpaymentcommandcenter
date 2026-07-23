import json
import logging
import os
import urllib.parse
from typing import List, Tuple

DEFAULT_BOUNDS_FILE = "data/state_bounds.json"
DEFAULT_COUNTRY = "USA"


def load_state_bounds(state_name: str, bounds_file: str = DEFAULT_BOUNDS_FILE) -> dict:
    if not os.path.isfile(bounds_file):
        raise FileNotFoundError(f"State bounds file not found: {bounds_file}")
    with open(bounds_file, encoding="utf-8") as f:
        bounds = json.load(f)
    if state_name not in bounds:
        raise KeyError(f"No bounding box configured for state: {state_name}")
    return bounds[state_name]


def cell_id(lat: float, lng: float) -> str:
    return f"{lat:.4f},{lng:.4f}"


def parse_cell_id(value: str) -> Tuple[float, float]:
    lat_str, lng_str = value.split(",", maxsplit=1)
    return float(lat_str), float(lng_str)


def generate_grid_cells(
    min_lat: float,
    max_lat: float,
    min_lng: float,
    max_lng: float,
    step: float,
) -> List[Tuple[float, float]]:
    if step <= 0:
        raise ValueError("Grid step must be greater than 0")

    cells: List[Tuple[float, float]] = []
    seen = set()
    lat = min_lat
    while lat <= max_lat:
        lng = min_lng
        while lng <= max_lng:
            rounded = (round(lat, 4), round(lng, 4))
            if rounded not in seen:
                seen.add(rounded)
                cells.append(rounded)
            lng += step
        lat += step
    return cells


def grid_cells_for_state(state_name: str, step: float, bounds_file: str = DEFAULT_BOUNDS_FILE) -> List[Tuple[float, float]]:
    box = load_state_bounds(state_name, bounds_file)
    cells = generate_grid_cells(
        box["min_lat"],
        box["max_lat"],
        box["min_lng"],
        box["max_lng"],
        step,
    )
    logging.info(f"{state_name}: generated {len(cells)} grid cells (step={step})")
    return cells


def build_grid_search_url(
    business: str,
    state: str,
    lat: float,
    lng: float,
    zoom: int,
    country: str = DEFAULT_COUNTRY,
) -> str:
    query = f"{business} in {state}, {country}"
    return f"https://www.google.com/maps/search/{urllib.parse.quote(query)}/@{lat},{lng},{zoom}z"


def build_city_search_query(city: str, state: str, business: str, country: str = DEFAULT_COUNTRY) -> str:
    return f"{business} in {city}, {state}, {country}"
