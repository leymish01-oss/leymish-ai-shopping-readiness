# LeyMish AI Readiness (WooCommerce plugin)

[![WordPress.org plugin](https://img.shields.io/wordpress/plugin/v/leymish-ai-shopping-readiness?label=WordPress.org)](https://wordpress.org/plugins/leymish-ai-shopping-readiness/) ![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

Install it from the [WordPress.org plugin directory](https://wordpress.org/plugins/leymish-ai-shopping-readiness/) (Plugins → Add New → search "LeyMish AI Shopping Readiness"), or download the zip from the [latest release](https://github.com/leymish01-oss/leymish-ai-shopping-readiness/releases/latest).

Finds what stops AI shopping agents (ChatGPT, Claude, Perplexity, Google) reading your WooCommerce store, and
tells you what to fix first. Beyond robots.txt, it requests a product page as each AI crawler to catch firewall,
CDN and security-plugin blocks, checks that the Store API answers with clean JSON (no byte-order mark in front),
and lists every product's data gaps. In our [census of 100 WooCommerce stores](https://www.leymish.com/blog/woocommerce-ai-readiness-census.html),
only 4 of 76 product pages had a GTIN or MPN that AI agents can match; on our partner store, working through
the fix list took the score [from 77 to 96](https://www.leymish.com/woocommerce/case-study-mishbio.html).
Free, GPL-2.0-or-later, runs entirely on your site.

One **LeyMish** menu with eight tabs: Overview (a 0–100 score, what's wrong, what was fixed, what to do next),
Audit (every check with its fix, and a CSV), Products (an editor for GTIN, brand and MPN with one-click fixes, a
preview and an undo, and "see it the way AI sees it"), Feeds (OpenAI and Google feeds, llms.txt, a UCP profile and
richer product schema on your own domain), AI visibility, AI fixes, Team and Plan.

Want a quick look before installing? The [free online check](https://www.leymish.com/woocommerce/check/) runs 5 of
these checks from outside your store in about 10 seconds.

## What it checks

| Area | Check |
|---|---|
| Product data | Valid GTIN (check digit) or MPN, brand, price, stock status, image, a real description, attributes, complete variations |
| Crawler access | robots.txt rules for GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User, Google-Extended |
| Real blocking | Requests a product page with each AI crawler's user agent and compares it with a browser request, to catch security plugins and CDN/WAF rules robots.txt doesn't show |
| Structured data | Product JSON-LD on real product pages: required fields, identifier and brand |
| Rendering | Product name and price present without JavaScript |
| Commerce endpoints | Store API reachable, guest checkout on, llms.txt, UCP profile at `/.well-known/ucp` |
| Info only | WooCommerce MCP availability (store-management assistants; not scored) |

Everything above runs on your own site; the audit's only HTTP requests go to your own store's URLs. Optional LeyMish services (AI visibility, AI fixes, outside monitoring, Store Team, the weekly tip) go through one class, `includes/class-lasr-service.php`, only after you act, and are listed under "External services" in `readme.txt`.

## Install

Upload the `leymish-ai-shopping-readiness` folder to `wp-content/plugins/` (or install the zip from
Plugins → Add New → Upload), activate it next to WooCommerce, then open **LeyMish** in the admin menu.

From the command line: `wp lasr audit` or `wp lasr audit --format=json`.

## Develop

- Pure logic (GTIN check digits, robots.txt matching, JSON-LD checks, scoring) lives in separate classes
  under `includes/`, unit-tested without WordPress.
- The full test suite (PHPUnit on PHP 7.4 and 8.3, an end-to-end run against WordPress + WooCommerce in
  wp-env, and Plugin Check) lives in the LeyMish Labs repo that builds this plugin.
- Filters: `lasr_product_limit`, `lasr_gtin_meta_keys`, `lasr_mpn_meta_keys`, `lasr_brand_taxonomies`,
  `lasr_request_url`, `lasr_sslverify`, `lasr_ucp_profile` (a UCP checkout integration adds its services),
  `lasr_service_base`. Action: `lasr_audit_completed`.

## LeyMish Pro

Since 2.0 there is no separate add-on. [LeyMish Pro](https://www.leymish.com/woocommerce/pro.html) ($12 a month or
$99 a year) is a set of services the plugin calls: weekly AI visibility checks, 500 AI fixes a month, outside
monitoring and Store Team agents. Nothing that runs on your site is locked.

## License

GPL-2.0-or-later. See `LICENSE`.
