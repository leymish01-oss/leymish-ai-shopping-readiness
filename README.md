# LeyMish AI Shopping Readiness (WooCommerce plugin)

[![WordPress.org plugin](https://img.shields.io/wordpress/plugin/v/leymish-ai-shopping-readiness?label=WordPress.org)](https://wordpress.org/plugins/leymish-ai-shopping-readiness/) ![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

Install it from the [WordPress.org plugin directory](https://wordpress.org/plugins/leymish-ai-shopping-readiness/) (Plugins → Add New → search "LeyMish AI Shopping Readiness"), or download the zip from the [latest release](https://github.com/leymish01-oss/leymish-ai-shopping-readiness/releases/latest).

Finds what stops AI shopping agents (ChatGPT, Claude, Perplexity, Google) reading your WooCommerce store, and
tells you what to fix first. Beyond robots.txt, it requests a product page as each AI crawler to catch firewall,
CDN and security-plugin blocks, checks that the Store API answers with clean JSON (no byte-order mark in front),
and lists every product's data gaps. In our [census of 100 WooCommerce stores](https://www.leymish.com/blog/woocommerce-ai-readiness-census.html),
only 4 of 76 product pages had a GTIN or MPN that AI agents can match; on our partner store, working through
the fix list took the score [from 77 to 96](https://www.leymish.com/woocommerce/case-study-mishbio.html).
Free, GPL-2.0-or-later, runs entirely on your site.

**WooCommerce → AI Readiness** gives you a 0–100 score, a fix list ordered by points, and a CSV of every
product's gaps.

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

No outside services, no account, no tracking: the only HTTP requests go to your own store's URLs.

## Install

Upload the `leymish-ai-shopping-readiness` folder to `wp-content/plugins/` (or install the zip from
Plugins → Add New → Upload), activate it next to WooCommerce, then open **WooCommerce → AI Readiness**.

From the command line: `wp lasr audit` or `wp lasr audit --format=json`.

## Develop

- Pure logic (GTIN check digits, robots.txt matching, JSON-LD checks, scoring) lives in separate classes
  under `includes/`, unit-tested without WordPress.
- The full test suite (PHPUnit on PHP 7.4 and 8.3, an end-to-end run against WordPress + WooCommerce in
  wp-env, and Plugin Check) lives in the LeyMish Labs repo that builds this plugin.
- Filters: `lasr_product_limit`, `lasr_gtin_meta_keys`, `lasr_mpn_meta_keys`, `lasr_brand_taxonomies`,
  `lasr_request_url`, `lasr_sslverify`. Action: `lasr_audit_completed`.

## Pro add-on

An optional, separately sold [Pro add-on](https://www.leymish.com/woocommerce/pro.html) adds a bulk GTIN/brand/MPN
editor, OpenAI and Google feeds on your own domain, an llms.txt generator, a weekly re-audit email and
score history. This free plugin is complete on its own.

## License

GPL-2.0-or-later. See `LICENSE`.
