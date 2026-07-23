import logging
import re
import urllib.parse
import urllib.request
from typing import List, Optional
from playwright.sync_api import sync_playwright, Page
from dataclasses import dataclass, asdict
import pandas as pd
import argparse
import platform
import time
import os

EMAIL_PATTERN = re.compile(
    r"[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}",
    re.IGNORECASE,
)
JUNK_EMAIL_MARKERS = (
    "noreply",
    "no-reply",
    "donotreply",
    "example.com",
    "sentry.io",
    "wixpress",
    "@2x.",
    ".png",
    ".jpg",
    ".webp",
)

@dataclass
class Place:
    name: str = ""
    address: str = ""
    website: str = ""
    email: str = ""
    phone_number: str = ""
    reviews_count: Optional[int] = None
    reviews_average: Optional[float] = None
    store_shopping: str = "No"
    in_store_pickup: str = "No"
    store_delivery: str = "No"
    place_type: str = ""
    opens_at: str = ""
    introduction: str = ""

def setup_logging():
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s - %(levelname)s - %(message)s',
    )

def extract_text(page: Page, xpath: str) -> str:
    try:
        if page.locator(xpath).count() > 0:
            return page.locator(xpath).inner_text()
    except Exception as e:
        logging.warning(f"Failed to extract text for xpath {xpath}: {e}")
    return ""


def is_junk_email(email: str) -> bool:
    lower = email.lower()
    return any(marker in lower for marker in JUNK_EMAIL_MARKERS)


def clean_email(raw: str) -> str:
    if not raw:
        return ""
    value = raw.strip()
    if value.lower().startswith("mailto:"):
        value = value[7:]
    value = value.split("?")[0].strip()
    match = EMAIL_PATTERN.search(value)
    return match.group(0) if match else ""


def extract_email_from_maps(page: Page) -> str:
    checks = [
        ('//a[contains(@href, "mailto:")]', "href"),
        ('//button[contains(@data-item-id, "mailto:")]', "data-item-id"),
        ('//a[contains(@data-item-id, "mailto:")]', "data-item-id"),
        ('//button[contains(@data-item-id, "email")]//div[contains(@class, "fontBodyMedium")]', "text"),
        ('//a[contains(@data-item-id, "email")]//div[contains(@class, "fontBodyMedium")]', "text"),
    ]
    for xpath, attr_type in checks:
        try:
            locator = page.locator(xpath)
            if locator.count() == 0:
                continue
            if attr_type == "href":
                raw = locator.first.get_attribute("href") or ""
            elif attr_type == "data-item-id":
                raw = locator.first.get_attribute("data-item-id") or ""
                raw = raw.replace("email:", "")
            else:
                raw = locator.first.inner_text()
            email = clean_email(raw)
            if email and not is_junk_email(email):
                return email
        except Exception as e:
            logging.debug(f"Email check failed for {xpath}: {e}")
    return ""


def normalize_website_url(website: str) -> str:
    website = website.strip()
    if not website:
        return ""
    if not website.startswith(("http://", "https://")):
        website = "https://" + website
    return website


def fetch_page_html(url: str, timeout: int = 8) -> str:
    request = urllib.request.Request(
        url,
        headers={"User-Agent": "Mozilla/5.0 (compatible; GoogleMapsScraper/1.0)"},
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return response.read().decode("utf-8", errors="ignore")


def extract_emails_from_html(html: str) -> List[str]:
    emails: List[str] = []
    seen = set()
    for mailto in re.findall(r'mailto:([^"\'>\s?]+)', html, re.IGNORECASE):
        email = clean_email(mailto)
        key = email.lower()
        if email and not is_junk_email(email) and key not in seen:
            seen.add(key)
            emails.append(email)
    for match in EMAIL_PATTERN.findall(html):
        email = clean_email(match)
        key = email.lower()
        if email and not is_junk_email(email) and key not in seen:
            seen.add(key)
            emails.append(email)
    return emails


def extract_email_from_website(website: str) -> str:
    base = normalize_website_url(website)
    if not base:
        return ""

    parsed = urllib.parse.urlparse(base)
    origin = f"{parsed.scheme}://{parsed.netloc}"
    paths = ["", "/contact", "/contact-us", "/about", "/about-us"]

    for path in paths:
        url = origin + path if path else base
        try:
            html = fetch_page_html(url)
            emails = extract_emails_from_html(html)
            if emails:
                return emails[0]
        except Exception as e:
            logging.debug(f"Could not fetch email from {url}: {e}")
    return ""


def extract_place(page: Page, fetch_website_email: bool = False) -> Place:
    # XPaths
    name_xpath = '//div[@class="TIHn2 "]//h1[@class="DUwDvf lfPIob"]'
    address_xpath = '//button[@data-item-id="address"]//div[contains(@class, "fontBodyMedium")]'
    website_xpath = '//a[@data-item-id="authority"]//div[contains(@class, "fontBodyMedium")]'
    phone_number_xpath = '//button[contains(@data-item-id, "phone:tel:")]//div[contains(@class, "fontBodyMedium")]'
    reviews_count_xpath = '//div[@class="TIHn2 "]//div[@class="fontBodyMedium dmRWX"]//div//span//span//span[@aria-label]'
    reviews_average_xpath = '//div[@class="TIHn2 "]//div[@class="fontBodyMedium dmRWX"]//div//span[@aria-hidden]'
    info1 = '//div[@class="LTs0Rc"][1]'
    info2 = '//div[@class="LTs0Rc"][2]'
    info3 = '//div[@class="LTs0Rc"][3]'
    opens_at_xpath = '//button[contains(@data-item-id, "oh")]//div[contains(@class, "fontBodyMedium")]'
    opens_at_xpath2 = '//div[@class="MkV9"]//span[@class="ZDu9vd"]//span[2]'
    place_type_xpath = '//div[@class="LBgpqf"]//button[@class="DkEaL "]'
    intro_xpath = '//div[@class="WeS02d fontBodyMedium"]//div[@class="PYvSYb "]'

    place = Place()
    place.name = extract_text(page, name_xpath)
    place.address = extract_text(page, address_xpath)
    place.website = extract_text(page, website_xpath)
    place.email = extract_email_from_maps(page)
    if not place.email and fetch_website_email and place.website:
        place.email = extract_email_from_website(place.website)
        if place.email:
            logging.info(f"Found email from website for {place.name or 'listing'}: {place.email}")
    place.phone_number = extract_text(page, phone_number_xpath)
    place.place_type = extract_text(page, place_type_xpath)
    place.introduction = extract_text(page, intro_xpath) or "None Found"

    # Reviews Count
    reviews_count_raw = extract_text(page, reviews_count_xpath)
    if reviews_count_raw:
        try:
            temp = reviews_count_raw.replace('\xa0', '').replace('(','').replace(')','').replace(',','')
            place.reviews_count = int(temp)
        except Exception as e:
            logging.warning(f"Failed to parse reviews count: {e}")
    # Reviews Average
    reviews_avg_raw = extract_text(page, reviews_average_xpath)
    if reviews_avg_raw:
        try:
            temp = reviews_avg_raw.replace(' ','').replace(',','.')
            place.reviews_average = float(temp)
        except Exception as e:
            logging.warning(f"Failed to parse reviews average: {e}")
    # Store Info
    for idx, info_xpath in enumerate([info1, info2, info3]):
        info_raw = extract_text(page, info_xpath)
        if info_raw:
            temp = info_raw.split('·')
            if len(temp) > 1:
                check = temp[1].replace("\n", "").lower()
                if 'shop' in check:
                    place.store_shopping = "Yes"
                if 'pickup' in check:
                    place.in_store_pickup = "Yes"
                if 'delivery' in check:
                    place.store_delivery = "Yes"
    # Opens At
    opens_at_raw = extract_text(page, opens_at_xpath)
    if opens_at_raw:
        opens = opens_at_raw.split('⋅')
        if len(opens) > 1:
            place.opens_at = opens[1].replace("\u202f","")
        else:
            place.opens_at = opens_at_raw.replace("\u202f","")
    else:
        opens_at2_raw = extract_text(page, opens_at_xpath2)
        if opens_at2_raw:
            opens = opens_at2_raw.split('⋅')
            if len(opens) > 1:
                place.opens_at = opens[1].replace("\u202f","")
            else:
                place.opens_at = opens_at2_raw.replace("\u202f","")
    return place

def scrape_places(search_for: str, total: int, fetch_website_email: bool = False) -> List[Place]:
    setup_logging()
    places: List[Place] = []
    with sync_playwright() as p:
        from browser_launch import launch_browser

        browser = launch_browser(p)
        page = browser.new_page()
        try:
            page.goto("https://www.google.com/maps/@32.9817464,70.1930781,3.67z?", timeout=60000)
            page.wait_for_timeout(1000)
            page.locator("//form[contains(@jsaction,'searchboxFormSubmit')]//input[@name='q']").fill(search_for)
            page.keyboard.press("Enter")
            page.wait_for_selector('//a[contains(@href, "https://www.google.com/maps/place")]')
            page.hover('//a[contains(@href, "https://www.google.com/maps/place")]')
            previously_counted = 0
            while True:
                page.mouse.wheel(0, 10000)
                page.wait_for_selector('//a[contains(@href, "https://www.google.com/maps/place")]')
                found = page.locator('//a[contains(@href, "https://www.google.com/maps/place")]').count()
                logging.info(f"Currently Found: {found}")
                if found >= total:
                    break
                if found == previously_counted:
                    logging.info("Arrived at all available")
                    break
                previously_counted = found
            listings = page.locator('//a[contains(@href, "https://www.google.com/maps/place")]').all()[:total]
            listings = [listing.locator("xpath=..") for listing in listings]
            logging.info(f"Total Found: {len(listings)}")
            for idx, listing in enumerate(listings):
                try:
                    listing.click()
                    page.wait_for_selector('//div[@class="TIHn2 "]//h1[@class="DUwDvf lfPIob"]', timeout=10000)
                    time.sleep(1.5)  # Give time for details to load
                    place = extract_place(page, fetch_website_email=fetch_website_email)
                    if place.name:
                        places.append(place)
                    else:
                        logging.warning(f"No name found for listing {idx+1}, skipping.")
                except Exception as e:
                    logging.warning(f"Failed to extract listing {idx+1}: {e}")
        finally:
            browser.close()
    return places

def save_places_to_csv(places: List[Place], output_path: str = "result.csv", append: bool = False):
    df = pd.DataFrame([asdict(place) for place in places])
    if not df.empty:
        for column in df.columns:
            if df[column].nunique() == 1:
                df.drop(column, axis=1, inplace=True)
        file_exists = os.path.isfile(output_path)
        mode = "a" if append else "w"
        header = not (append and file_exists)
        df.to_csv(output_path, index=False, mode=mode, header=header)
        logging.info(f"Saved {len(df)} places to {output_path} (append={append})")
    else:
        logging.warning("No data to save. DataFrame is empty.")

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("-s", "--search", type=str, help="Search query for Google Maps")
    parser.add_argument("-t", "--total", type=int, help="Total number of results to scrape")
    parser.add_argument("-o", "--output", type=str, default="result.csv", help="Output CSV file path")
    parser.add_argument("--append", action="store_true", help="Append results to the output file instead of overwriting")
    parser.add_argument(
        "--fetch-email",
        action="store_true",
        help="If Maps has no email, check the business website contact pages",
    )
    args = parser.parse_args()
    search_for = args.search or "turkish stores in toronto Canada"
    total = args.total or 1
    output_path = args.output
    append = args.append
    places = scrape_places(search_for, total, fetch_website_email=args.fetch_email)
    save_places_to_csv(places, output_path, append=append)

if __name__ == "__main__":
    main()
