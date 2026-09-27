=== LeyMish AI Shopping Readiness ===
Contributors: leymishlabs
Tags: woocommerce, chatgpt, ai, gtin, structured data
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Can ChatGPT, Claude, Perplexity and Google shopping agents find and read your WooCommerce products? Get a 0–100 score and a prioritised fix list.

== Description ==

Shopify stores became visible in ChatGPT and other AI shopping assistants by default in March 2026. WooCommerce stores don't get that automatically. Whichever route you take (product feeds, a third-party catalog, or future WooCommerce features), the same basics decide whether AI agents can read and trust your products. This plugin checks them in one place.

**WooCommerce → AI Readiness** runs the audit and shows:

* A **0–100 score** and a **fix list** ordered by how many points each fix is worth.
* A table of every check with what was found.
* The **products with the most gaps**, plus a **CSV export** of every product's gaps.

= What it checks =

* **Product data:** valid GTIN (with check digit) or MPN, brand, price, stock status, main image, a real description, key attributes, and complete variations (each with its own price and attribute values).
* **robots.txt** rules for GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User and Google-Extended.
* **Real blocking:** requests one of your product pages with each AI crawler's user agent and compares the result with a normal browser request. This catches security plugins and CDN or firewall rules that robots.txt doesn't show. (A CDN that verifies bots by IP address may block this test while allowing the real crawler, so results are labelled "possible block" for you to confirm.)
* **Product structured data (JSON-LD)** on your real product pages: required fields, plus identifier and brand.
* **Name and price readable without JavaScript**, since most AI crawlers don't run JavaScript.
* **WooCommerce Store API** reachable, **guest checkout** enabled, **llms.txt** present, and a **UCP profile** at /.well-known/ucp.
* WooCommerce **MCP** availability (shown for information; it's for store-management assistants and doesn't affect the score).

= Private by design =

Everything runs on your own site. The plugin does not connect to any external service and sends no data anywhere: its only HTTP requests go to your own store's URLs (for example your robots.txt and a few product pages). No account, no sign-up, no tracking.

= Optional Pro add-on =

A separately sold Pro add-on adds a bulk editor for GTIN, brand and MPN, OpenAI and Google Merchant Center product feeds on your own domain, an llms.txt generator, a weekly re-audit email and score history. This free plugin is complete on its own and never limits the audit.

== Installation ==

1. Install and activate WooCommerce, then this plugin.
2. Go to **WooCommerce → AI Readiness** and click **Run the audit**.
3. Work through the fix list from the top. Re-run the audit to see your score change.

Developers can also run `wp lasr audit` (add `--format=json` for machine-readable output).

== Frequently Asked Questions ==

= Does this put my products in ChatGPT? =

No plugin can guarantee that. OpenAI, Google and others decide what they show. This plugin tells you what stops AI agents reading and trusting your products, and how to fix it.

= Why does a check say "Not tested"? =

Your server couldn't request its own pages (a "loopback" request). Tools → Site Health shows whether loopback requests work. Skipped checks don't count against your score.

= Where does it read GTIN and brand from? =

GTIN: WooCommerce's own "GTIN, UPC, EAN, or ISBN" field (WooCommerce 9.2+) or common plugin fields. Brand: WooCommerce Brands (9.6+), popular brand plugins, a "Brand" attribute, or custom fields. Developers can add sources with the `lasr_gtin_meta_keys`, `lasr_mpn_meta_keys` and `lasr_brand_taxonomies` filters.

= How many products does it check? =

The first 500 published products. Developers can change this with the `lasr_product_limit` filter.

== Screenshots ==

1. The score and the fix list, ordered by points.
2. Every check with what was found.
3. Products with the most gaps, and the CSV export.
4. Pro add-on: bulk editor for GTIN, brand and MPN.
5. Pro add-on: product feed URLs, llms.txt, weekly email and score history.

== Changelog ==

= 1.0.1 =
* The Store API check now spots a byte-order mark (BOM) before the JSON, which strict parsers reject, and says how to find the file. It used to report "Fail: HTTP 200" and blame a security plugin. Found on a real store.
* Clearer message when the Store API answers without JSON.

= 1.0.0 =
* First release.
