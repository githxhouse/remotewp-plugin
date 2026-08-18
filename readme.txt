=== RemoteWP ===
Contributors: xhouse
Tags: ai, api, remote management, wordpress development, developer tools, debugging, seo, woocommerce, automation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.7.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect compatible AI agents to WordPress through RemoteWP V2: a controlled REST API for development, debugging, SEO, WooCommerce and maintenance.

== Description ==

RemoteWP securely connects compatible AI agents such as Claude, ChatGPT, Codex, Cursor, Gemini and custom HTTP-capable agents to a real WordPress website through an authenticated REST API.

RemoteWP V2 uses one public customer package: remotewp.zip. Pro capabilities are not distributed as a separate Pro, Full or Master ZIP. Instead, Pro features are delivered dynamically after license validation as an encrypted, domain-bound module from the RemoteWP license server.

With RemoteWP, an AI agent can inspect approved WordPress files, search themes and plugins, identify problems, propose changes, and perform supported operations for design, debugging, SEO, performance, WooCommerce and ongoing maintenance without sharing raw SSH, FTP or hosting-panel credentials.

RemoteWP provides the WordPress connection and supported API operations. The quality and scope of the analysis also depend on the tools available to the connected AI agent.

= What RemoteWP Can Help With =

**Design & Layout**

* Inspect theme templates and stylesheets
* Search CSS related to layout or responsive issues
* Assist with WooCommerce template and layout changes
* Apply approved frontend modifications with backup

**WordPress Debugging**

* Search theme and plugin files
* Investigate PHP, JavaScript and CSS issues
* Locate functions related to WordPress errors
* Apply approved fixes with automatic backups and restore metadata

**SEO**

* Review schema, canonical tags and Open Graph metadata
* Assist with SEO plugin configuration inspection
* Validate technical SEO and meta tags
* Check sitemap and robots directives

**Performance**

* Locate heavy plugins and active scripts
* Review caching and asset loading configurations
* Check database table sizes and query performance issues
* Assist with Core Web Vitals optimization

**WooCommerce**

* Read products, orders, customers and settings
* Audit store configuration and payment gateway status
* Safe product creation and stock management (Pro)
* Assist with WooCommerce-specific fixes and customizations

== Installation ==

1. Upload the `remotewp` folder to the `/wp-content/plugins/` directory, or install the ZIP file via Plugins > Add New > Upload Plugin.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to RemoteWP in the admin sidebar.
4. Copy the API endpoint and Token to connect your AI agent.
5. If you have a Pro license, enter your license key to unlock full access.

== Frequently Asked Questions ==

= Is RemoteWP safe to use? =
Yes. RemoteWP includes rate limiting, IP whitelisting, permission levels, path restrictions, automatic backups before any file edit, and an immutable audit log. Only authorized AI agents with a valid token can communicate with your site.

= What AI agents are supported? =
Any AI agent that supports custom HTTP/REST API tools, including Claude (Anthropic), ChatGPT (OpenAI), Codex, Cursor, Gemini, and custom agents built with LangChain, LlamaIndex, or AutoGen.

= What is the difference between Free and Pro? =
The Free version provides read-only inspection, file search, and environment diagnostics. The Pro version adds safe file modifications with backups, WooCommerce management, database tools, plugin/theme management, and priority support.

= How do I connect an AI agent? =
Go to RemoteWP in the WordPress admin to copy your API endpoint and Token. Provide these to your AI agent along with the included system prompt or OpenAPI schema.

= Does RemoteWP work with caching plugins and Cloudflare? =
Yes. RemoteWP uses the standard WordPress REST API namespace (`/wp-json/helper/v1/` and `/wp-json/remotewp/v2/`), which is excluded from aggressive page caching by default.

== Screenshots ==

1. Dashboard - Token management and connection info
2. Activity Log - Filterable audit log viewer
3. Settings - Connection diagnostics, rate limiting and IP whitelist; license and capability policy are managed centrally by RemoteWP

== Changelog ==

= 3.7.11 =

* Fix: Eliminated blocking synchronous network requests on normal WordPress frontend and admin pageviews.
* Performance: Pro loader now decrypts cached local module with zero network latency; remote module fetches are guarded by a 12h cooldown and explicit activation events.
* Stability: Prevented PHP worker exhaustion and site timeouts on newly connected or Free sites.

= 3.7.10 =

* Central server is the authority for license, trial, Pro/Lifetime entitlement and capabilities.
* Connected sites can receive the encrypted Pro module through the site token without manual keys or WordPress settings.
* Removed editable local permission/path/mutation controls from the plugin workflow.
* Sensitive mutations continue through automatic backup and operator approval.

= 3.7.9 =

* Pro/Lifetime/trial entitlements automatically recover the encrypted Pro module when it is missing or invalid.
* Pro module payloads are removed automatically on deactivation, downgrade to Free, or expiry.
* Added bounded module refresh and preserved the backup/approval workflow for sensitive mutations.

= 3.7.8 =

* Removed the legacy External Handoff Consent gate. Authenticated Pro/trial sites now submit redacted handoff logs automatically through the central service.

= 3.7.7 =

* Agent skill instructions are now resolved exclusively from the RemoteWP central server; the plugin package no longer ships a local SKILL.md or skill reference files.

= 3.7.6 =

* Added authenticated V2 handoff relay. Agents submit handoff logs with the site token; the plugin forwards them through the encrypted license connection without requesting a Master/Agency key.

= 3.7.5 =
* Improvement: Added an embedded Quick Start tutorial video directly below the connection status.
* Improvement: Moved capability details out of the first page into Docs & Support for a clearer onboarding flow.
* Fix: Handoff delivery now waits for and verifies the central HTTP response instead of reporting success after dispatch.
* Security: Removed embedded internal license and shared encryption material from the public package.

= 3.7.4 =
* Release RemoteWP plugin 3.7.4.
