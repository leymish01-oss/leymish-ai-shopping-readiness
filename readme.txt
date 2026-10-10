=== LeyMish AI Readiness ===
Contributors: leymish
Tags: gtin, chatgpt, ai shopping, product feed, structured data
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Can ChatGPT, Google and Perplexity read your WooCommerce products? A 0–100 audit, one-click fixes and feeds, free.

== Description ==

Shoppers now ask AI assistants what to buy. ChatGPT, Google and Perplexity match products by barcode (GTIN), brand and maker's part number (MPN), and they read your product pages, your store's product API and your feeds. Products they can't read are left out. LeyMish AI Readiness shows what they see and fixes it, from one **LeyMish** menu with eight tabs.

**Free, and it all runs on your site:**

* **Overview:** a 0–100 score with a grade and one plain sentence, four area cards, your next three wins, what changed this week, your score over time and a before/after report you can send to a client.
* **Audit:** every check (robots.txt for each AI crawler, real blocking by firewalls, product structured data, readable without JavaScript, Store API, guest checkout, llms.txt, UCP) with plain-words fixes and a CSV.
* **Products:** published products by default, a status and "what's missing" for each, an inline editor for GTIN, brand and MPN with check-digit validation as you type, and one-click fixes with a preview and an undo: "Set brand for all", "I make these products: use each SKU as the MPN" (only with your explicit tick), and GTINs from your supplier's CSV. A GTIN is never invented.
* **See it the way AI sees it:** pick a product and see what an AI shopping agent can read from its page and its API, missing fields in red, present in green.
* **Feeds:** OpenAI (JSONL) and Google Merchant Center (TSV) product feeds at stable addresses on your domain, a generated llms.txt, a UCP business profile at /.well-known/ucp, and richer product schema (brand, GTIN, MPN, your return policy and plain shipping rates), each switched on by you and built only from your own settings.
* **Weekly re-audit:** alerts when products lose identifiers, AI crawlers get blocked in robots.txt, or a feed fails, and an email from your own site.

**Optional LeyMish Pro services ($12 a month or $99 a year per store; agencies $39 a month for up to 10 stores):**

* **AI visibility:** your shoppers' own questions (drafted from your categories, edited by you) asked to a search-grounded AI model every week: are you cited, which sites are cited instead, and the trend. Every free store gets one check of 3 questions.
* **AI fixes:** attributes, image alt text, a Google category and a clearer description, drafted only from the product's own page, shown as Now vs Proposed and saved only when you approve. Free stores get 20 to try; Pro includes 500 a month.
* **Outside monitoring:** every week LeyMish fetches your store, feeds and profile from outside, as OpenAI and Google do, and alerts you when a firewall or CDN blocks AI crawlers or a feed breaks.
* **Store Team:** a CEO agent writes your store's weekly plan (free); on Pro, the Catalog and Reporting agents do the work and every change waits in the Approvals inbox until you approve it.

Pro is a service: the licence only unlocks calls to LeyMish's service. Nothing that runs on your site is locked. Upgrading takes a minute: the Plan tab opens checkout and switches Pro on by itself when the purchase arrives.

= Getting started (about 15 minutes, free) =

1. Install and activate the plugin. A **LeyMish** menu appears right after WooCommerce. "Start here" in its header opens this guide inside WordPress, with a tick on each step you've done.
2. LeyMish → Overview → **Run the audit** (about a minute).
3. Read your score and "Your next 3 wins": each win shows the points it adds and a Fix it button.
4. LeyMish → Products: **Set brand for all**, use each SKU as the MPN (only if you make the products), or import **GTINs from a supplier CSV**. Open **See it the way AI sees it**: red fields turn green as you fix them.
5. LeyMish → Feeds: switch on the OpenAI and Google feeds, llms.txt, the UCP profile and product schema, and set your real return policy.
6. LeyMish → Overview → **Run the audit again** to see your score change.
7. Keep LeyMish → Plan → **Weekly email** on: your site re-checks itself every week.

The full guide with flowcharts (free, Pro, done for you, the weekly routine): https://www.leymish.com/woocommerce/start/

= Updating from Pro or Store Team =

LeyMish AI Readiness Pro and LeyMish Store Team are now built in. When you update, your licence, settings, Store Team connection, feeds and history move over, the old plugins are switched off, and you can delete them.

== Installation ==

1. Install and activate WooCommerce, then this plugin.
2. Open **LeyMish** in the admin menu and click **Run the audit**.
3. Work through "Your next 3 wins". The Products tab fixes most of them in a few clicks.

Developers can also run `wp lasr audit` (add `--format=json` for machine-readable output).

== Frequently Asked Questions ==

= Does it count my visitors? =

Yes, on your own site and without cookies (Overview → Visitors): page views, unique visitors per day, where they came from (AI assistants such as ChatGPT or Perplexity, search, other sites, direct) and your top landing pages. To count unique visitors it keeps a one-way hash of the visitor's IP address and browser name, made with a random value that changes every day, for at most two days; the IP address and browser name are never stored, and staff and known bots aren't counted. The counts stay on your site, unless you connect Store Team, which can then read them. You can turn counting off on the Overview card. WordPress → Settings → Privacy offers suggested text for your privacy policy.

= Is it really free? =

Yes. The audit, the Products tab and its one-click fixes, the feeds, llms.txt, the UCP profile, product schema, the weekly re-audit and the report are free and never limited. LeyMish Pro adds services that run on our side: AI visibility, AI fixes, outside monitoring and Store Team agents.

= Does it send my store's data anywhere? =

Not unless you use a LeyMish service, and each one says what it sends before you use it. The audit, the editor and the feeds run entirely on your site. See "External services" below for every call, what it sends and when.

= Does this put my products in ChatGPT? =

No plugin can guarantee that. OpenAI, Google and others decide what they show. This plugin fixes what commonly keeps products out, using only facts from your own store.

= Why does a check say "Not tested"? =

Your server couldn't request its own pages (a "loopback" request). Tools → Site Health shows whether loopback requests work. Skipped checks don't count against your score.

= Where does it read GTIN and brand from? =

GTIN: WooCommerce's own "GTIN, UPC, EAN, or ISBN" field (WooCommerce 9.2+) or common plugin fields. Brand: WooCommerce Brands (9.6+), popular brand plugins, a "Brand" attribute, or custom fields. Developers can add sources with the `lasr_gtin_meta_keys`, `lasr_mpn_meta_keys` and `lasr_brand_taxonomies` filters.

= Why does my UCP profile say "no checkout services"? =

WooCommerce core doesn't implement a UCP checkout yet, so the profile honestly declares none. A checkout integration that implements UCP can add its services with the `lasr_ucp_profile` filter. The audit gives a profile without services half the points.

= How many products does it check? =

The first 500 published products. Developers can change this with the `lasr_product_limit` filter.

== Screenshots ==

1. Overview: your score, grade, the three answers (what's wrong, what we fixed, what to do next) and four area cards.
2. Products: "See it the way AI sees it", missing fields in red and present in green, then the one-click fixes.
3. Products: status and what's missing for each product, with GTIN check digits validated as you type.
4. Feeds: OpenAI and Google feeds, llms.txt, the UCP profile and product schema, each switched on by you.
5. AI visibility: your shoppers' questions, whether AI answers cite your store, and who they cite instead.
6. Plan: what's free, what LeyMish Pro adds, and the one-click upgrade.

== External services ==

The audit, the Products tab, the feeds, llms.txt, the UCP profile, product schema, the weekly re-audit and the report run entirely on your own server. They make no requests except to your own store's pages.

Some optional features use the LeyMish service at https://leymish-ai.leymish.workers.dev, run by LeyMish Labs (14 Jenkins St, Rosewater SA 5013, Australia). Terms: https://www.leymish.com/terms.html. Privacy: https://www.leymish.com/privacy.html. Nothing is sent on activation, on an audit or on a plain page view: each call below happens only after you act, and says so on screen.

* **Licence check** (/v1/license/check, /v1/pro/status): when you activate a LeyMish Pro licence, and weekly while one is active. Sends the licence key and your store's address. LeyMish checks the key with its payment provider (Gumroad).
* **One-click upgrade** (/v1/claim/start, /v1/claim/poll): when you click "Upgrade", and while that tab waits for your purchase. Sends your store's address; checkout opens at Gumroad (https://gumroad.com, terms https://gumroad.com/terms, privacy https://gumroad.com/privacy) with a one-time claim ID, so the new licence can be matched to your store.
* **AI visibility** (/v1/visibility/check): when you tick the box and run a check, and weekly on Pro. Sends your questions, your store's name and address. LeyMish asks a search-grounded AI model through OpenRouter (https://openrouter.ai, privacy https://openrouter.ai/privacy) and returns which sites are cited. Questions and answers are not stored by LeyMish; the results are kept on your site.
* **AI fixes** (/v1/fix, /v1/usage): only after you tick the consent box, and only for the product you click. Sends that product's name, descriptions, attributes, categories and main image address. LeyMish asks Anthropic's Claude (https://www.anthropic.com, privacy https://www.anthropic.com/legal/privacy) for a draft. No customer or order data is sent; LeyMish keeps counters, not product text.
* **Outside monitoring** (/v1/monitor): weekly, on Pro only. Sends your store's address; LeyMish then requests your home page, robots.txt, product API, llms.txt, feeds and UCP profile from outside, including as AI crawlers would, at most once a day.
* **Store Team** (/v1/team/...): only after you click "Connect" and approve on WooCommerce's own screen. WooCommerce sends LeyMish a REST API key for your store, stored encrypted; once connected, the agents can also read your visitor counts from this plugin (counts only, never IP addresses); the agents read your catalogue, your completed orders of the last 90 days (to find products bought together and orders ready for a review request) and recent product reviews (so no one who already reviewed is asked), use them for that run without keeping them, and propose changes; nothing is written without your approval. A review request you approve is sent by your own store as a WooCommerce order note, and none is proposed while another plugin already sends review requests. The live demo loads public data about our partner store only when you click "Show the live demo". Disconnect deletes LeyMish's copy of the key.
* **The weekly store tip** (/v1/check/subscribe, optional, off by default): only when you tick the box, enter an email address and submit. Sends that address, your site's domain and your consent. Every email has an unsubscribe link, and unsubscribing deletes the record.

The "try it on a sample store" link opens WordPress Playground (https://playground.wordpress.net), a service run by the WordPress project that builds a throwaway WordPress in your own browser. It is an ordinary link: following it sends nothing about your site.

== Changelog ==

= 2.1.0 =
* New, free: **Visitors** on Overview. Cookieless, first-party counts on your own site: visitors yesterday and in 7 days, how many came from AI assistants, search, other sites or directly, and your top landing pages.
* New: **Start here**. The start guide inside WordPress, trimmed to your plan, with a tick on each step that's really done; a "15-minute start" card on Overview until the first audit and fix.
* New free check: **the store can take an order**: a payment method is on, the cart and checkout pages are published, shipping can be quoted, no payment method is left in test mode, and two card forms don't compete at checkout.
* New: **internal links to your products** (Audit → Store health): products no post or page links to, the posts that already name them, and links to products that are gone.
* Product schema no longer repeats what your theme or another plugin already says: if a return policy or shipping details are already on your product pages, LeyMish doesn't add a second one (the Feeds tab says so).
* Store Team: once a day the plugin sends your visitor counts (counts only) so the store report can include them; choose a weekly (Monday) or daily report on the Team tab.
* Store Team: a monthly sales goal on the Team tab, shown against the sales WooCommerce has recorded this month (no forecasts). New proposals, each waiting for your approval: cross-sells from products really bought together, a link to a product no other product page links to, and one honest review request per order 7 to 30 days after it completes (no reward, any rating welcome).
* AI visibility: a used weekly allowance now shows as a notice ("they reset on Monday"), not an error.

= 2.0.1 =
* Fixed: a valid LeyMish Pro licence could be refused by AI visibility, AI fixes and outside monitoring. When a licence really isn't active, the message now says so plainly and points to Plan.
* Overview starts from your earliest real score (including earlier work by LeyMish agents), and the chart shows those points too.
* "What changed this week" lists what really happened: settings you switched on or off, audit score changes and Store Team approvals.
* An area at 100% always says Good. When a few products still have a gap, the card names them, says what's missing and gives a Fix it button.
* Items nobody can act on yet (UCP checkout for WooCommerce) are shown as "Next to watch", never as your biggest win.
* New free check: WooCommerce's sample "Refund and Returns Policy" page is not published (its 30-day text can contradict your real policy).
* AI visibility questions are drafted from your products (types and key ingredients) with one brand question, mixing "best" and "where to buy". Names with & show correctly.
* Store Team history shows the old value, who approved each change and when, and folds old expired suggestions into one line.
* Pages no longer stall half-drawn on slow hosts: what the page needs from LeyMish is fetched before it starts, with short timeouts.
* The "now built in" notice shows once on Plugins and once on Overview, and goes away when the old plugins are deleted.

= 2.0.0 =
* One plugin, one menu: **LeyMish** sits right after WooCommerce with eight tabs (Overview, Audit, Products, Feeds, AI visibility, AI fixes, Team, Plan). Every tab opens with what's wrong, what we fixed and what to do next.
* Pro and Store Team are built in. Updating moves your licence, settings, Store Team connection, feeds and history over and switches the old plugins off.
* Products tab: published products by default (drafts and private behind a filter), a status and "what's missing" for each product, one-click fixes with a preview and an undo ("Set brand for all", SKU as MPN for private label, GTINs from a supplier CSV), and "See it the way AI sees it".
* Free now: OpenAI and Google feeds (same addresses as Pro 1.x), llms.txt, a UCP business profile, product schema enrichment from your own settings (brand, GTIN, MPN, return policy, shipping), the weekly re-audit with alerts, and the before/after report.
* New LeyMish Pro services: AI visibility (one free check for every store), outside monitoring, and Store Team agents; AI fixes moved into their own tab.
* One-click upgrade from the Plan tab; pasting a key still works. Old Pro keys keep working.
* The UCP check follows the 2026-08-25 specification; a valid profile without services now scores half the points.

= 1.4.1 =
* The menu and page are now called "LeyMish AI Readiness".
* "Open dashboard" is the first link on the plugin's row in the Plugins list.
* After the first activation, one notice on the Plugins screen links straight to the dashboard. It goes away once you open the page or dismiss it.

= 1.4.0 =
* A clearer dashboard: a score gauge with a grade and one plain sentence about where your store stands, your trend, and four cards for Access, Product data, Store API and AI checkout readiness.
* "Your biggest wins": the top three fixes, how many products each one affects, and a link to our free guide for each.
* "Free vs Pro for your store", built from your own numbers, on this screen only. Hide it for 30 days with one click. Nothing in the free plugin is locked.
* The products table now lists only products with gaps, collapsed until you open it; the CSV still has every product.
* Charts have their numbers in text for screen readers; the screen works at phone width.

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
