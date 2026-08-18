=== RemoteWP ===
Contributors: xhouse
Tags: ai, api, remote management, wordpress development, developer tools, debugging, seo, woocommerce, automation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.7.10
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

* Inspect templates affecting headings, metadata and schema
* Review canonical and structured data implementations
* Search for duplicated or hardcoded SEO elements
* Assist with approved technical SEO changes

RemoteWP does not replace a crawler, analytics platform, keyword tool or complete SEO suite. Full SEO audits may require browser access and external data.

**Performance**

* Search scripts and styles loaded by themes and plugins
* Inspect inefficient or repetitive code
* Investigate cache and transient-related problems
* Clear supported WordPress caches and transients when permitted

Core Web Vitals analysis requires browser or field-performance data that RemoteWP does not provide by itself.

**Development & WooCommerce**

* Inspect and modify approved theme and plugin files
* Work with hooks, filters, templates and shortcodes
* Create files and directories when permissions allow
* Assist with WooCommerce customizations

**Maintenance**

* Inspect installed plugins and available update information
* Activate or deactivate plugins when permitted
* Review supported WordPress configuration values
* Standardize recurring work across client websites

= Key Features =

**RemoteWP V2**

* Fast AI agent startup through /wp-json/remotewp/v2/connect
* V2 context, health, OpenAPI and operation status endpoints
* Short-lived content handles for safer file reads
* Expected SHA-256 checks for existing-file mutations
* Operation IDs, mutation phases, backup IDs and verification metadata
* Legacy /helper/v1/ namespace retained for compatibility and WAF-friendly fallback

**Security and Control**

* Token-based authentication with 64-character cryptographic tokens
* HTTPS enforcement in production
* IP whitelist with CIDR notation support
* Configurable rate limiting and brute-force lockout
* Protected sensitive files such as wp-config.php, .env, .htaccess, .git and .user.ini
* Dot-files and dot-directories blocked recursively
* Approved writes run through RemoteWP with validation, operation logs and automatic backups
* Sensitive executable file changes such as PHP, shell or Python files require explicit extra approval, an approval note, automatic backup and restore metadata

**Single Package Distribution**

* Customers install remotewp.zip
* No public Pro ZIP, Full ZIP or Master ZIP is distributed
* Pro capabilities are unlocked dynamically through the license server
* Auto-updates use the same public remotewp.zip package

= RemoteWP V2 API =

New AI agents should start with:

GET /wp-json/remotewp/v2/connect

Core V2 endpoints include:

* GET /remotewp/v2/connect - Fast startup payload with site context, endpoints and operating rules
* GET /remotewp/v2/context - Authenticated site, capability and tenant context
* GET /remotewp/v2/health - Storage, backup, rollout and API health checks
* GET /remotewp/v2/openapi.json - Machine-readable V2 contract
* GET /remotewp/v2/skill - Full V2 AI Agent Skill Pack when detailed rules are needed
* GET /remotewp/v2/fs/list - List directory contents with metadata
* GET /remotewp/v2/fs/read - Read metadata and receive a short-lived content handle
* GET /remotewp/v2/content/{handle} - Resolve short-lived file content handles
* GET /remotewp/v2/fs/search - Search file contents
* POST /remotewp/v2/fs/validate - Validate a path before changing it
* POST /remotewp/v2/fs/write - Create or update files with backup, expected hash and approval flow
* POST /remotewp/v2/fs/patch - Apply additive patch operations with expected SHA-256
* POST /remotewp/v2/fs/delete - Delete with automatic backup and approval flow when sensitive
* POST /remotewp/v2/fs/rename - Rename with automatic backup and approval flow when sensitive
* POST /remotewp/v2/fs/restore - Restore from backup with restore metadata
* GET /remotewp/v2/operations/{operation_id} - Inspect mutation phases, backup IDs and verification status
* GET /remotewp/v2/wp/info - Site information, theme and WordPress version
* GET /remotewp/v2/status - Plugin status, permission level and version

The legacy /helper/v1/ namespace remains available for older connectors and WAF-safe fallback routes such as /helper/v1/sync and /helper/v1/process.

= Quick Start =

1. Install and activate remotewp.zip
2. Go to RemoteWP in the WordPress admin menu
3. Copy the API token
4. Copy the RemoteWP V2 fast connection prompt or call the V2 connect endpoint from your AI agent
5. Start with:

curl -H "X-RemoteWP-Token: YOUR_TOKEN" https://yoursite.com/wp-json/remotewp/v2/connect

= Sensitive File Approval Flow =

RemoteWP does not ask users to edit files manually in cPanel for normal approved work. If an AI agent tries to change a sensitive executable site file, RemoteWP returns an approval-required response. The agent must explain the exact file, operation, expected impact and rollback plan, then continue only after explicit user approval. RemoteWP records the approval request, approval confirmation, backup and restore metadata.

== Installation ==

1. Upload remotewp.zip from the official RemoteWP download or GitHub release
2. Activate the plugin through the Plugins menu
3. Navigate to RemoteWP in the admin sidebar
4. Copy the auto-generated API token
5. Configure permissions, rate limits and IP whitelist as needed

== Frequently Asked Questions ==

= Is RemoteWP secure? =

RemoteWP enforces HTTPS, token authentication, rate limiting, IP controls, protected paths, operation logs and automatic backups. Sensitive executable file changes require explicit extra approval before the agent can continue.

= Can I limit what the API can do? =

Yes. RemoteWP provides permission profiles and path controls. V2 operations also include context, health checks, expected hashes and rollout controls.

= Which AI agents can I use? =

Any AI agent or automation tool capable of making authenticated HTTP requests with custom headers. Claude, ChatGPT, Cursor, Windsurf, Codex, Gemini and custom agents can work with RemoteWP when configured with the appropriate tool or script.

= Does RemoteWP include Pro code in the public ZIP? =

No. The public customer package is remotewp.zip. Pro capabilities are delivered dynamically from the license server after license and domain validation.

= What happens if something goes wrong? =

Every write, delete and rename operation creates a backup before changing the file. Restore metadata is returned so the change can be rolled back.

= Where are backups stored? =

Backups are stored in a randomized directory inside wp-content/uploads/. The directory is protected from web access where supported by the server.

== Screenshots ==

1. Dashboard - Token management and connection info
2. Activity Log - Filterable audit log viewer
3. Settings - Connection diagnostics, rate limiting and IP whitelist; license and capability policy are managed centrally by RemoteWP

== Changelog ==

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
* Fix: Clarified central handoff authentication. `Authorization: Bearer` must use the RemoteWP Master/Agency license key from the RemoteWP account/dashboard, not the WordPress site token.
* Fix: Updated the AI Agent Skill Pack so agents ask the user/admin for the Master/Agency key when needed, never search site files for it, and never save it in local handoff reports.
* Docs: Aligned GitHub and plugin readme text with the RemoteWP V2 handoff and single-package distribution model.

= 3.7.3 =
* Fix: Corrected update-check version detection so WordPress sites on 3.7.2 receive the 3.7.3 update.
* Fix: Corrected wp-content path detection on hosting setups where ABSPATH and WP_CONTENT_DIR normalize differently, preventing false core_modification_blocked errors for theme/plugin files such as child-theme functions.php.
* Improvement: Executable site file mutations now return an explicit approval-required response so agents must explain the change, request user approval, then continue through RemoteWP with automatic backup and restore metadata.
* Improvement: V2 connection payload advertises mutation endpoints and approval flow to reduce slow or confused agent startup.

= 3.7.2 =
* Release: Aligned public plugin documentation and metadata with RemoteWP V2.
* Security: Customers install the public Free/Core package; Pro capabilities are delivered as an encrypted, domain-bound module after license validation.
* Security: Master/Full ZIP archives are not distributed through the public download flow.
* Tenant isolation: Agency/domain identity and hand-off data are resolved from the authenticated license and domain assignment.

= 3.7.0 =
* Feature: Added dynamic AI Skill Resolver and stack-based skill pack generation.
* Feature: Added WooCommerce, Elementor, WPBakery and SEO-oriented skill modules.
* Security: Added WAF-compatible Base64 request dispatcher.

= 3.2.0 =
* Added AI Agent Skill Pack and dynamic /skill endpoint.
* Improved path traversal, HTTPS, IP and XSS hardening.

= 3.0.0 =
* Complete rewrite for public release.
* Added permission profiles, rate limiting, file search, WordPress operations, activity logs, path controls and automatic backups.

= 2.0.0 =
* Internal class-based architecture release.

= 1.0.0 =
* Initial internal release.

== Upgrade Notice ==

= 3.7.4 =
Important V2 skill update. Clarifies that central handoff uses the RemoteWP Master/Agency license key, not the WordPress site token, and prevents agents from falling back incorrectly.

= 3.7.3 =
Important V2 update. Fixes public update delivery, improves V2 startup, corrects false path-policy blocks and adds explicit approval flow for sensitive executable site file changes.

= 3.7.2 =
RemoteWP V2 public package alignment. Customers install only remotewp.zip; Pro capabilities are delivered dynamically after license validation.
