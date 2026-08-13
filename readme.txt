=== RemoteWP ===
Contributors: xhouse
Tags: ai, api, remote management, wordpress development, developer tools, debugging, seo, woocommerce, automation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Securely connect compatible AI agents to your WordPress site through a controlled REST API for development, debugging, SEO, WooCommerce and maintenance.

== Description ==

**RemoteWP securely connects compatible AI agents to a real WordPress website through a controlled REST API.**

It allows configured AI agents to inspect approved WordPress files, search themes and plugins, identify problems and perform supported operations for design, debugging, SEO, performance optimization, development, WooCommerce and ongoing maintenance.

RemoteWP reduces the need to share FTP, SSH or hosting-panel credentials while keeping access restricted through authentication, permission profiles, protected paths, backups, rate limiting, IP controls and activity logs.

> RemoteWP provides the WordPress connection and supported API operations. The quality and scope of the analysis also depend on the tools and capabilities available to the connected AI agent.

= What RemoteWP Can Help With =

**Design & Layout**

* Inspect approved theme templates and stylesheets
* Search for CSS related to visual problems
* Investigate responsive layout issues
* Modify approved frontend files
* Assist with WooCommerce template and layout changes

Visual confirmation may require browser access or another rendering tool available to the connected AI agent.

**WordPress Debugging**

* Search approved theme and plugin files
* Investigate PHP, JavaScript and CSS problems
* Locate functions related to WordPress errors
* Analyze likely plugin or theme conflicts
* Apply controlled fixes with automatic backups

**SEO**

* Inspect templates affecting headings and metadata
* Review canonical and schema implementations
* Search for duplicated or hardcoded SEO elements
* Investigate technical SEO issues in themes and plugins

RemoteWP does not replace a crawler, analytics platform or complete SEO suite.

**Performance**

* Search scripts and styles loaded by themes and plugins
* Inspect inefficient or repetitive code
* Investigate cache and transient-related problems
* Clear supported WordPress caches and transients

Core Web Vitals analysis requires browser or field-performance data that RemoteWP does not provide by itself.

**Development & WooCommerce**

* Inspect and modify approved theme and plugin files
* Work with hooks, filters, templates and shortcodes
* Create files and directories when permissions allow
* Assist with WooCommerce customizations
* Apply approved code modifications

**Maintenance**

* Inspect installed plugins and available update information
* Activate or deactivate plugins when permitted
* Review supported WordPress configuration values
* Clear caches and transients
* Standardize recurring work across client websites

= Key Features =

**Security First**

* Token-based authentication (64-character cryptographic tokens)
* HTTPS enforcement (bypassed on localhost only)
* IP whitelist with CIDR notation support
* Rate limiting — configurable requests per minute (default: 60/min)
* Brute force lockout — auto-blocks after failed authentication attempts
* Protected files: wp-config.php, .env, .htaccess and others cannot be accessed
* Path restrictions: limit access to specific directories
* Write operations restricted to wp-content/
* All dot-files and dot-directories blocked recursively
* Dangerous extensions (.php, .sh, .py, .exe etc.) blocked by default

**Filesystem API (Free)**

* GET /status — Plugin and server status, permission level
* GET /list — List directory contents with metadata
* GET /read — Read file content (up to 5MB)
* GET /skill — AI Agent Skill Pack with site variables pre-filled
* GET /instructions — Legacy AI instructions
* GET /wp/info — Basic site information

**Filesystem API (Pro)**

* POST /write — Create or update files (with auto-backup)
* POST /delete — Delete files or directories (with auto-backup)
* POST /rename — Rename files or directories (with auto-backup)
* POST /mkdir — Create new directories
* POST /restore — Restore from automatic backup
* GET /search — Search file contents (grep-like)
* POST /sync — WAF-compatible base64-encoded request dispatcher
* POST /process — Alias for /sync

**WordPress Operations API (Pro)**

* GET /wp/info — Full site information: theme, plugins summary, WP version
* GET /wp/plugins — Full plugin list with update status
* POST /wp/plugin/toggle — Activate or deactivate plugins
* GET /wp/options — Read whitelisted WordPress options
* POST /wp/cache-clear — Clear all caches (WP Super Cache, W3TC, WP Rocket, LiteSpeed)

**Modern Admin Dashboard**

* Clean, modern admin interface
* Token management with copy-to-clipboard
* Activity log viewer with filtering
* Permission profile selector
* IP whitelist manager
* Rate limit configuration

= Use Cases =

1. **AI-Assisted WordPress Work** — Design, debugging, SEO, development and maintenance through compatible AI agents
2. **Agency & Multi-Site Management** — Apply the same controlled workflow across multiple client websites
3. **WooCommerce Support** — Investigate templates, checkout issues and WooCommerce customizations
4. **Performance & SEO Investigation** — Review code, templates and technical SEO issues in approved files
5. **Controlled Code Modifications** — Read, review and apply changes with automatic backups and activity logging

= Quick Start =

1. Install and activate the plugin
2. Go to **RemoteWP** in the admin menu
3. Copy the API token and endpoint
4. Retrieve the AI Agent Skill Pack from /wp-json/helper/v1/skill and paste it into your AI agent
5. Start making API calls:

curl -H "X-RemoteWP-Token: YOUR_TOKEN" https://yoursite.com/wp-json/helper/v1/status

= Limitations =

RemoteWP provides controlled access to supported WordPress files and operations. It does not guarantee that an AI-generated recommendation or code modification is correct. Visual inspection, browser testing, analytics and external SEO data may require additional tools. You remain responsible for configuring permissions and reviewing sensitive operations.

== Installation ==

1. Upload the emotewp folder to /wp-content/plugins/
2. Activate the plugin through the 'Plugins' menu
3. Navigate to **RemoteWP** in the admin sidebar
4. Copy the auto-generated API token
5. Configure permissions, rate limits and IP whitelist as needed

== Frequently Asked Questions ==

= Is RemoteWP secure? =

RemoteWP enforces HTTPS, uses 64-character cryptographic tokens, implements rate limiting, IP whitelisting, brute force protection, path sandboxing and write restrictions. Sensitive files like wp-config.php are always protected. You remain responsible for configuring permissions appropriately.

= Can I limit what the API can do? =

Yes. RemoteWP offers three permission profiles:
- **Read Only** — Only list, read, search and WordPress info operations
- **Read & Write** — Read plus write and create operations
- **Full Access** — All operations including delete and plugin management

You can also restrict access to specific directories using path restrictions.

= Which AI agents can I use? =

Any AI agent or automation tool capable of making authenticated HTTP requests with custom headers. The exact integration method depends on the platform. Claude, ChatGPT, Cursor, Windsurf, Codex, Gemini and custom agents can all work with RemoteWP when configured with the appropriate tool or script.

= Does it work with my caching plugin? =

The cache-clear endpoint supports WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed Cache and WordPress built-in object cache.

= What happens if something goes wrong? =

Every write, delete and rename operation automatically creates a timestamped backup. You can restore any file through the API or from the backup directory.

= Where are backups stored? =

Backups are stored in a randomized directory inside wp-content/uploads/. The directory is protected from web access via .htaccess, index.php and a non-guessable folder name for compatibility with Nginx and LiteSpeed.

== Screenshots ==

1. Dashboard — Token management and connection info
2. Activity Log — Filterable audit log viewer
3. Settings — Permissions, rate limiting and IP whitelist

== Changelog ==

= 3.7.1 =
* Fix: Active 48-hour trials now automatically download and enable the PRO module.
* Fix: Trial detection now uses the decrypted license key and reports consistent license_tier, is_pro and is_trial values.
* Fix: WordPress now detects and displays public RemoteWP updates correctly for existing installations.
* Improvement: Skill delivery and connection handling are more reliable for Free and trial users.

= 3.6.5 =
* Security: Changed REST API namespace to helper/v1 to bypass WAF firewalls and server filters.
* Fix: Resolve class loading issue on activation causing Class 'RemoteWP_License' not found fatal error.
* Security: Disable hex string obfuscation in unified build to prevent antivirus false-positives and quarantines on shared hosting.
* Docs: Update agent Skill Pack instructions to enforce Base64-encoded requests for file writing to prevent WAF connection resets and IP bans.

= 3.4.0 =
* Security: Restricted write/modify endpoints to wp-content/ directory to prevent WordPress core modifications.
* Security: Added recursive protection to block AI access to hidden directories (.git) and critical credentials (.env).
* Bugfix: Made transient-based rate limiting fully object-cache-safe for Redis/Memcached environments.
* Bugfix: Normalized backslashes to forward slashes for Windows search path exclusions.
* Compliance: Split into strictly read-only Free version and secure write-enabled Pro version.

= 3.7.0 =
* Feature: Intelligent Auto-Detection of active site capabilities & plugins (WooCommerce, Elementor, WPBakery, SEO)
* Feature: Dynamic `/skill` API endpoint expansion serving tailored agent skills based on detected active plugins
* Feature: Added 4 specialized skill modules (wordpress-elementor, wordpress-wpbakery, wordpress-seo, wordpress-woocommerce)

= 3.2.0 =
* Added AI Agent Skill Pack with one-click agent prompt
* Added /skill REST endpoint for dynamic agent skill delivery
* Security: fixed path restriction prefix bypass (directory boundary check)
* Security: fixed ABSPATH sibling directory escape
* Security: hardened IP spoofing protection when trust_proxy is enabled
* Security: randomized log/backup storage directory for Nginx/LiteSpeed compatibility
* Security: fixed XSS vector in admin error rendering
* Security: HTTPS localhost check uses REMOTE_ADDR instead of SERVER_NAME
* Cleaned up legacy AI Instructions buttons in favor of Skill Pack
* Updated API Access tab with Skill Endpoint actions

= 3.7.0 =
* Feature: Added Dynamic AI Skill Resolver & Stack-Based Skill Pack Generation
* Feature: Added Ultra-Fast Initial Onboarding Audit (<2s single-call status response)
* Feature: Added AI-Ready WooCommerce Catalog Perfection Loop (Score 90+ Readiness) and /llms.txt generator
* Feature: Added Elementor & WPBakery Page Builder JSON metadata & shortcode inspection skills
* Feature: Added Schema.org JSON-LD and SEO Meta tag audit skills for RankMath, Yoast, and SEOPress
* Feature: Added automatic pre-write file backup (.bak) and instant /restore endpoint
* Security: Hardened Base64 WAF payload dispatcher (/sync) to prevent firewall blocks on live production sites

= 3.1.0 =
* Added license management system with tier-based feature gating
* Added modern admin dashboard with dark theme
* Added connection test button
* Added trust strip and status cards
* Improved admin UI with tabs (Overview, API Access, License, Activity Log, Settings, Docs)

= 3.0.0 =
* Complete rewrite for public release
* Added granular permission profiles (read-only, read-write, full)
* Added rate limiting with configurable thresholds
* Added IP whitelist with CIDR notation support
* Added brute force lockout protection
* Added file search endpoint (grep-like)
* Added WordPress Operations API (site info, plugins, options, cache)
* Added modern admin dashboard with tabs
* Added activity log viewer with filtering
* Added path restrictions for directory-level access control
* Added auto-backup on all destructive operations
* Added full internationalization (i18n) support
* Improved security with HTTPS enforcement and protected files
* Renamed API namespace to emotewp/v1
* Changed auth header to X-RemoteWP-Token

= 2.0.0 =
* Internal release — class-based architecture
* Token authentication
* Basic filesystem CRUD

= 1.0.0 =
* Initial internal release

== Upgrade Notice ==

= 3.4.0 =
Security hardening and bugfix release. Restricts write operations to wp-content/, protects hidden directories, and fixes object-cache transient rate limit issues. Recommends immediate upgrade.

= 3.2.0 =
Security hardening release. Fixes path traversal edge cases, IP spoofing, and XSS. Adds AI Agent Skill Pack for one-click agent integration.

= 3.0.0 =
Major update with new security features, WordPress Operations API, and modern admin dashboard. The API namespace changed from xhouse-api/v1 to emotewp/v1 and the auth header changed from X-House-Token to X-RemoteWP-Token. Backward compatibility with the old header is maintained.
