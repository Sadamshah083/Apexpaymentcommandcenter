<?php

namespace App\Services\BusinessResearch;

use App\Models\BusinessResearch;
use App\Models\CrmLead;

class BusinessResearchPrompt
{
    public static function system(): string
    {
        return <<<'SYSTEM'
You are a specialized business intelligence extraction agent operating like Google AI mode with live web search.

Your job: find verified, location-specific facts about ONE target business by searching the public web extensively before writing any answer.

MANDATORY RESEARCH BEHAVIOR:
1. Use Google Search repeatedly — run AT LEAST 8–12 distinct searches covering different angles before producing the final report.
2. Cross-check facts across multiple source types: Google Business Profile/Maps, official website, Yelp, BBB, Facebook, Instagram, LinkedIn, state business filings (SOS), franchise corporate pages, Yellow Pages/Manta, and booking portals (Booksy, Schedulicity, etc.).
3. Always anchor searches to the exact location provided — never confuse franchises or same-name businesses in other cities/states.
4. For owner name: check About pages, LinkedIn profiles, state LLC filings, BBB principal contacts, Facebook page info, press releases, and franchise disclosure documents.
5. For payment processor: inspect website checkout/booking flows, job postings mentioning POS software, review mentions of payment methods, footer badges (Square/Stripe/Clover/Toast), and field-service platforms (ServiceTitan, Housecall Pro, Jobber).
6. Prefer the most recent public information. When sources conflict, cite the most authoritative (official website > state filing > Google Business Profile > directory).
7. Output ONLY the Markdown schema requested — no introduction, no conclusion, no commentary outside the schema.
SYSTEM;
    }

    public static function build(ResearchInput $input, ?string $webContextBlock = null): string
    {
        $businessName = $input->businessName;
        $location = self::resolveLocation($input);
        $websiteNote = $input->website ? "\nKnown website: {$input->website}" : '';

        $contextSection = $webContextBlock
            ? "\n\n--- SUPPLEMENTAL WEB SNIPPETS (cross-reference with your own Google searches) ---\n{$webContextBlock}"
            : '';

        $sheetSection = $input->sheetContext
            ? "\n\n--- DATA FROM UPLOADED SPREADSHEET (use as hints, verify via web search) ---\n{$input->sheetContext}"
            : '';

        return <<<PROMPT
Extract complete business intelligence for the target below. Search Google extensively (maps, directories, social media, SOS filings, reviews, franchise sites) before answering.

Target Business: {$businessName}
Target Location (City/State/Address): {$location}{$websiteNote}

SEARCH CHECKLIST — execute separate Google searches for each before filling the report:
□ "{$businessName}" + location on Google Maps / Business Profile (hours, phone, address, website)
□ Official company website About/Contact/Team pages
□ Yelp, BBB, Yellow Pages, Manta, Chamber of Commerce listings for this exact location
□ Facebook, Instagram, LinkedIn company and owner profiles
□ State Secretary of State / business entity search for LLC officers and registered agent
□ Franchise parent company if applicable (corporate CEO vs local operator)
□ Job postings or careers pages mentioning POS/CRM/field-service software
□ Booking portal (Booksy, Square Appointments, Housecall Pro, ServiceTitan, etc.)
□ Payment processor clues: Square, Stripe, Clover, Toast, ServiceTitan Payments, Worldpay, Fiserv

Present the final output using this EXACT Markdown schema:

### Business Identity & Location
* **Business Name**: [Official trade name or LLC. Include hyperlink to main website domain if found]
* **Physical Address**: [Full street, suite, city, state, ZIP for THIS location]
* **Primary Service**: [Core services offered]
* **Operating Hours**: [Monday–Sunday hours from Google Business Profile or official site]

### Owner & Contact Information
* **Direct Owner Name**: [Exact first and last name of owner, founder, managing member, or franchisee. For chains: local operator OR corporate CEO — specify which]
* **Direct Phone Number**: [Primary operational phone for this location]
* **Direct Email Address**: [Public business email. If no email and they use Facebook/Booksy/contact form only, state that explicitly with the link]

### Payment Processor & Booking Software
* **Payment Processor**: [Backend payment gateway: Square, Stripe, Clover, Toast, ServiceTitan Payments, Worldpay, etc. — or "Not Publicly Available"]
* **System Integration**: [2 sentences: how their POS, booking, or field-service software connects to that payment processor]

STRICT RULES:
1. Search the web first. Do not guess. Use "Not Publicly Available" only after exhaustive search.
2. Match the specific location — not a different branch or state.
3. No filler, no generic text, no preamble or closing remarks.
4. Every filled field must reflect what you found in web sources.{$contextSection}{$sheetSection}
PROMPT;
    }

    public static function systemBulk(): string
    {
        return <<<'SYSTEM'
You are a fast B2B lead enrichment agent with Google Search.

Use 3–5 focused Google searches (Maps, website, Yelp/BBB, LinkedIn) for ONE business at ONE location.
Owner name is the top priority — other fields are optional. Use "Not Publicly Available" for anything you cannot verify.
Output ONLY the Markdown schema — no intro, no extra commentary.
SYSTEM;
    }

    public static function buildBulk(ResearchInput $input, ?string $webContextBlock = null): string
    {
        $businessName = $input->businessName;
        $location = self::resolveLocation($input);
        $websiteNote = $input->website ? "\nWebsite: {$input->website}" : '';

        $contextSection = $webContextBlock
            ? "\n\nWeb snippets:\n{$webContextBlock}"
            : '';

        $sheetSection = $input->sheetContext
            ? "\n\nSpreadsheet hints (verify online):\n{$input->sheetContext}"
            : '';

        return <<<PROMPT
Enrich this lead. Owner name is the most important field — partial answers are OK.

Business: {$businessName}
Location: {$location}{$websiteNote}

Fill what you can find. Leave missing items as "Not Publicly Available".

Use this EXACT Markdown schema:

### Business Identity & Location
* **Business Name**: [name + website link if found]
* **Physical Address**: [full address for this location]
* **Primary Service**: [main services]
* **Operating Hours**: [hours or Not Publicly Available]

### Owner & Contact Information
* **Direct Owner Name**: [owner/franchisee name or Not Publicly Available]
* **Direct Phone Number**: [phone]
* **Direct Email Address**: [email or contact method]

### Payment Processor & Booking Software
* **Payment Processor**: [Square, Stripe, Clover, Toast, etc. or Not Publicly Available]
* **System Integration**: [1 sentence on POS/booking software if found]

Also infer POS/field-service software in your answer when visible (ServiceTitan, Toast, Square, etc.).{$contextSection}{$sheetSection}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    public static function buildFollowUp(ResearchInput $input, array $parsed, ?string $webContextBlock = null): string
    {
        $businessName = $input->businessName;
        $location = self::resolveLocation($input);

        $gaps = [];
        if (empty($parsed['owner_name'])) {
            $gaps[] = 'Direct Owner Name';
        }
        if (empty($parsed['payment_processor'])) {
            $gaps[] = 'Payment Processor';
        }
        if (empty($parsed['direct_phone'])) {
            $gaps[] = 'Direct Phone Number';
        }
        if (empty($parsed['direct_email'])) {
            $gaps[] = 'Direct Email Address';
        }
        if (empty($parsed['operating_hours'])) {
            $gaps[] = 'Operating Hours';
        }

        $gapList = implode(', ', $gaps);
        $contextSection = $webContextBlock ? "\n\n--- WEB SNIPPETS ---\n{$webContextBlock}" : '';

        return <<<PROMPT
Follow-up research for: {$businessName} at {$location}

Your prior report was INCOMPLETE. These fields were missing or "Not Publicly Available": {$gapList}

Run NEW Google searches specifically targeting:
- "{$businessName}" owner site:linkedin.com OR site:facebook.com OR secretary of state
- "{$businessName}" {$location} site:yelp.com OR site:bbb.org principal
- "{$businessName}" ServiceTitan Square Clover Stripe payment processing POS
- "{$businessName}" {$location} franchise operator managing member

Re-output the COMPLETE Markdown schema (all sections), filling in newly found data. Keep verified prior data. Use "Not Publicly Available" only if still unfindable after these targeted searches.{$contextSection}
PROMPT;
    }

    protected static function resolveLocation(ResearchInput $input): string
    {
        if ($input->address) {
            return trim($input->address);
        }

        return 'Not specified — search using business name only but verify location carefully';
    }
}
