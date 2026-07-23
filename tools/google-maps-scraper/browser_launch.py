"""Shared Playwright browser launch for Apexone / CLI scrapers."""

from __future__ import annotations

import os
import platform
from typing import Any


def launch_browser(playwright: Any):
    """Launch Chromium with optional headless mode and Chrome path overrides."""
    headless = os.environ.get("MAPS_SCRAPER_HEADLESS", "").strip().lower() in {"1", "true", "yes"}
    chrome_path = (os.environ.get("MAPS_SCRAPER_CHROME_PATH") or "").strip()

    if not chrome_path and platform.system() == "Windows":
        default_win = r"C:\Program Files\Google\Chrome\Application\chrome.exe"
        if os.path.isfile(default_win):
            chrome_path = default_win

    kwargs = {"headless": headless}
    if chrome_path:
        kwargs["executable_path"] = chrome_path

    return playwright.chromium.launch(**kwargs)
