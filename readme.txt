=== LeyMish AI Shopping Readiness ===
Contributors: leymish
Tags: gtin, chatgpt, ai shopping, structured data, woocommerce seo
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See if ChatGPT and AI shopping agents can read your WooCommerce store: a 0–100 score, a fix list, and what improved since you started.

== Description ==

Shopify stores became visible in ChatGPT and other AI shopping assistants by default in March 2026. WooCommerce stores don't get that automatically. Whichever route you take (product feeds, a third-party catalog, or future WooCommerce features), the same basics decide whether AI agents can read and trust your products. This plugin checks them in one place.

**WooCommerce → AI Readiness** runs the audit and shows:

* A **0–100 score** and a **fix list** ordered by how many points each fix is worth.
* A table of every check with what was found.
* The **products with the most gaps**, plus a **CSV export** of every product's gaps.
* An **Impact** tab: your score week by week, the checks you've fixed since you started, how many products now have a GTIN or MPN, a brand and image alt text compared with your first audit, and what to fix next. Stored on your site only.

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

= Is it really free? =

Yes. The audit, the fix list, the Impact tab and the CSV export are free and never limited. An optional, separately sold Pro add-on adds tools that do some of the fixing for you.

= Does it send my store's data anywhere? =

No. Everything runs on your own site. Its only web requests go to your own store's pages (for example robots.txt and a few product pages), to see what AI crawlers receive.

= What does the Impact tab show? =

Your score week by week, the checks you've fixed since your first audit, and how many products have a GTIN or MPN, a brand and image alt text compared with when you started. It's stored on your site only.

= Will it slow down my store? =

No. The audit runs only when you click the button in your admin, and the plugin adds nothing to your shop's pages.

= Does this put my products in ChatGPT? =

No plugin can guarantee that. OpenAI, Google and others decide what they show. This plugin tells you what stops AI agents reading and trusting your products, and how to fix it.

= Why does a check say "Not tested"? =

Your server couldn't request its own pages (a "loopback" request). Tools → Site Health shows whether loopback requests work. Skipped checks don't count against your score.

= Where does it read GTIN and brand from? =

GTIN: WooCommerce's own "GTIN, UPC, EAN, or ISBN" field (WooCommerce 9.2+) or common plugin fields. Brand: WooCommerce Brands (9.6+), popular brand plugins, a "Brand" attribute, or custom fields. Developers can add sources with the `lasr_gtin_meta_keys`, `lasr_mpn_meta_keys` and `lasr_brand_taxonomies` filters.

= How many products does it check? =

The first 500 published products. Developers can change this with the `lasr_product_limit` filter.

== Screenshots ==

1. The Impact tab: your score week by week, the checks you fixed and your product data then vs now.
2. The score and the fix list, ordered by points.
3. Every check with what was found.
4. Products with the most gaps, and the CSV export.
5. Pro add-on: bulk editor for GTIN, brand and MPN.
6. Pro add-on: product feed URLs, llms.txt, weekly email and score history.

== External services ==

The audit itself runs entirely on your own server: it requests your own pages the way an AI crawler would and
scores what it finds. Nothing about your store is sent anywhere, and the plugin works with no internet
connection beyond your own site.

There is exactly one optional feature that contacts us, and only after you tick a box and press a button:

**The weekly store tip (optional, off by default)**

* What it is: on the AI Readiness screen there is an unticked checkbox offering a weekly email with one thing
  to fix on a WooCommerce store, plus your latest readiness score.
* When data is sent: only when you tick that box, enter an email address and submit the form. Never on
  activation, never on an audit, never in the background.
* What is sent: the email address you type, your site's domain name, and the fact that you consented (with the
  exact wording you agreed to and the time). Nothing else. Your products, scores and audit results are not sent.
* Where it goes: the LeyMish Labs service at https://leymish-ai.leymish.workers.dev/v1/check/subscribe, run by
  LeyMish Labs (14 Jenkins St, Rosewater SA 5013, Australia).
* What we do with it: send you those emails. The score in them comes from our free outside check of your
  public pages (at most 6 requests a week, obeying your robots.txt), not from the plugin. We do not sell or
  share your address. Every email has an unsubscribe link, and unsubscribing deletes the record.
* Terms and privacy: https://www.leymish.com/terms.html and https://www.leymish.com/privacy.html

The "try it on a sample store" link opens WordPress Playground (https://playground.wordpress.net), a service
run by the WordPress project that builds a throwaway WordPress in your own browser. It is an ordinary link:
following it sends nothing about your site, and nothing is installed here.

== Changelog ==

= 1.3.0 =
* A three-step checklist on first run: run the audit, fix your top three, see Impact. It disappears once you have run an audit, and you can hide it at any time.
* An optional weekly email with one store tip and your score. The checkbox is unticked; nothing is sent unless you tick it and submit. Every email has an unsubscribe link, and unsubscribing deletes the record. Disclosed in full under "External services" above.
* A link to try the plugin on a throwaway sample store in your browser, via WordPress Playground.
* The UCP check now validates the whole business profile — ucp.version, ucp.services and ucp.payment_handlers, reverse-domain capability names, https endpoints — and names the specification version it checked against.
* New information-only line for ACP (the protocol behind ChatGPT checkout): whether this site has a checkout-session route, and which specification version is current. It is not scored, and we never probe your checkout from outside.


= 1.2.0 =
* After your score has gone up by 10 points or more, the Impact tab asks once whether you'd leave a review on WordPress.org. Either answer hides it for good. It never appears anywhere else in your admin.

= 1.1.0 =
* New **Impact** tab (the first thing you see): score over time as a weekly chart, checks fixed since your first audit, products with a GTIN or MPN, a brand and image alt text then vs now, and the next three fixes. Snapshots are kept in your site's options only; nothing is sent anywhere. Sites updating from 1.0.x start from their last audit.
* The audit tab is unchanged. "Run the audit" now confirms when it's finished.
* Deleting the plugin also removes the Impact history.
* Accessibility: darker green and amber text in badges and the fix list, so they meet WCAG AA contrast.

= 1.0.5 =
* Accessibility: darker green and amber text in badges and the fix list (WCAG AA contrast), and the score, tables and share box fit a phone screen. Styling only; no code changes.

= 1.0.4 =
* Readme: contributor is now the plugin owner's WordPress.org account (leymish). No code changes.

= 1.0.3 =
* The note about the optional Pro add-on now says how many of your products lack a valid GTIN, MPN or brand, instead of a feature list. No new requests, nothing sent anywhere.

= 1.0.2 =
* "Share your score": a copyable summary of your score and the two biggest gaps. Nothing is sent anywhere.
* The fix list links to the optional Pro add-on only next to the checks Pro has a tool for (product identifiers, structured data identifiers, llms.txt).

= 1.0.1 =
* The Store API check now spots a byte-order mark (BOM) before the JSON, which strict parsers reject, and says how to find the file. It used to report "Fail: HTTP 200" and blame a security plugin. Found on a real store.
* Clearer message when the Store API answers without JSON.

= 1.0.0 =
* First release.
