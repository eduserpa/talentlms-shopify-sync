# TalentLMS to Shopify Sync

Single-file PHP script that syncs a TalentLMS course's structure into a
Shopify product's metafield, so a storefront can render a course
curriculum (list of units/lessons) without hitting the TalentLMS API on
every page load.

## How it works

1. Fetches every **active** Shopify product and maps it by its main
   variant's SKU.
2. Fetches every course from TalentLMS.
3. Matches a TalentLMS course to a Shopify product when the course's
   `code` equals the product's SKU (case-insensitive).
4. For each match, fetches the course's full unit list and writes it as
   a JSON metafield: `custom.course_units_structure` on the matched
   product (creating it if missing, updating it if present).

This is meant to run as a scheduled job (cron) to keep Shopify's
course-curriculum display in sync with what's actually configured in
TalentLMS.

## Requirements

- PHP 7.4+ with cURL
- A TalentLMS API key
- A Shopify Admin API access token with `read_products` and
  `write_products` scopes

## Setup

```bash
export TALENTLMS_DOMAIN="your-domain.talentlms.com"
export TALENTLMS_API_KEY="your-talentlms-api-key"
export SHOPIFY_DOMAIN="your-store.myshopify.com"
export SHOPIFY_ACCESS_TOKEN="shpat_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
export SHOPIFY_API_VERSION="2025-07"   # optional, defaults to 2025-07
```

## Usage

```bash
php talentlms-shopify-sync.php
```

Progress is logged verbosely to `sync_log.txt` next to the script (the
log is truncated on every run). Wire it to cron for a recurring sync:

```cron
0 3 * * * php /path/to/talentlms-shopify-sync.php
```

## License

MIT
