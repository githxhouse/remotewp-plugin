=== RemoteWP ===
Contributors: xhouse
Tags: ai, api, rest-api, remote management, developer tools
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let AI agents read and inspect your WordPress site remotely through a secure REST API — no SSH or FTP needed.

== Description ==

**RemoteWP** turns your WordPress site into a secure, API-readable endpoint that AI agents can inspect remotely. Whether you use Claude, ChatGPT, Cursor, Windsurf, or any custom automation tool — RemoteWP gives them read-only access to your site's filesystem and WordPress information through a clean REST API.

= Why RemoteWP? =

Traditional WordPress management requires SSH, FTP, or cPanel access. AI agents and automation tools can't use these easily. RemoteWP solves this by providing a secure read-only REST API bridge:

* **Secure REST API** — Token-based authentication over HTTPS
* **Filesystem Reading** — List directories and read files (read-only)
* **WordPress Info** — Site info, plugin list, theme details
* **Audit Logging** — Full activity log with IP tracking
* **Granular Permissions** — Read-only access profile enforced on free version
* **Rate Limiting** — Configurable requests per minute with brute-force lockout

= Key Features =

**Security First**

* Token-based authentication (64-character secure tokens)
* HTTPS enforcement (no plaintext API calls)
* IP whitelist with CIDR notation support
* Rate limiting (configurable requests per minute)
* Brute force lockout (auto-blocks after failed attempts)
* Protected files (wp-config.php, .env, .htaccess cannot be accessed)
* Hidden directories blocked (.git, .github, etc.)
* Path restrictions (limit access to specific directories)
* Directory traversal prevention

**Read-Only Filesystem API (Free)**

* `GET /remotewp/v1/list` — List directory contents with metadata
* `GET /remotewp/v1/read` — Read file content (up to 5MB, read-only)
* `GET /remotewp/v1/status` — Plugin and server status
* `GET /remotewp/v1/skill` — AI agent skill pack (read-only)
* `GET /remotewp/v1/wp/info` — Basic site information

**Modern Admin Dashboard**

* Clean, modern admin interface
* Token management with copy-to-clipboard
* Activity log viewer with filtering
* Permission profile display
* IP whitelist manager
* Rate limit configuration

= Use Cases =

1. **AI-Powered Site Auditing** — Let Claude, GPT, or Cursor read theme files, audit plugin versions, and inspect configurations without write access
2. **Remote Monitoring** — Check site status, plugin versions, and file structure via API
3. **Multi-Site Inspection** — Inspect multiple WordPress sites from a central system

= Quick Start =

1. Install and activate the plugin
2. Go to **RemoteWP** in the admin menu
3. Copy the API token
4. Start making API calls:

`curl -H "X-RemoteWP-Token: YOUR_TOKEN" https://yoursite.com/wp-json/remotewp/v1/status`

== Installation ==

1. Upload the `remotewp` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Navigate to **RemoteWP** in the admin sidebar
4. Copy the auto-generated API token
5. Configure rate limits and IP whitelist as needed

== Frequently Asked Questions ==

= Is RemoteWP secure? =

Yes. RemoteWP enforces HTTPS, uses 64-character cryptographic tokens, implements rate limiting, IP whitelisting, brute force protection, and sandboxes all operations within your WordPress directory. Sensitive files like wp-config.php, .env, and .htaccess are always blocked.

= Can the free version modify files? =

No. The free version is strictly read-only. It can list directories and read file contents, but cannot write, delete, rename, or modify any files. Write operations require a Pro license and are restricted to the wp-content/ directory only.

= What files are protected? =

The following files can never be accessed via the API, regardless of permission level:
wp-config.php, .env (and all .env.* variants), .htaccess, .htpasswd, .user.ini, php.ini, web.config, and all hidden directories (starting with a dot, such as .git).

= Does it work with my caching plugin? =

The plugin itself does not clear caches in the free version. Cache management is a Pro feature.

= What external services does this plugin use? =

See the "External Services" section below for full disclosure.

== External Services ==

This plugin connects to the following external service operated by the plugin author:

**remotewp.dev** (operated by X-HOUSE SRL, Arad, Romania)

1. **License validation** — When a Pro license key is entered in the settings, the plugin sends the license key and site domain to `https://remotewp.dev/wp-json/remotewp-license/v1/validate-key` to verify the license status. This only occurs for Pro users who have entered a license key.

2. **Update checks** — For Pro users with an active license, the plugin checks for available updates by contacting `https://remotewp.dev/wp-json/remotewp-license/v1/update-check`. The request includes the license key, site domain, current plugin version, and plugin slug. This check is cached for 12 hours to minimize requests.

No data is sent to external services for free users who have not entered a license key.

Privacy policy: https://remotewp.dev/privacy-policy.html
Terms of service: https://remotewp.dev/terms-of-service.html

== Screenshots ==

1. Dashboard — Token management and connection info
2. Activity Log — Filterable audit log viewer
3. Settings — Rate limiting and IP whitelist

== Changelog ==

= 3.4.0 =
* Security: Restricted write/modify endpoints to wp-content/ directory to prevent WordPress core modifications.
* Security: Added recursive protection to block API access to hidden directories (.git, .github) and credential files (.env, .htaccess).
* Security: Free version is now strictly read-only — write, delete, rename, mkdir, and restore endpoints require an active Pro license.
* Bugfix: Made transient-based rate limiting fully object-cache-safe for Redis/Memcached environments.
* Bugfix: Normalized backslashes to forward slashes for Windows search path exclusions.

= 3.2.0 =
* Added AI Agent Skill Pack with one-click agent prompt
* Added `/skill` REST endpoint for dynamic agent skill delivery
* Security: fixed path restriction prefix bypass (directory boundary check)
* Security: fixed ABSPATH sibling directory escape
* Security: hardened IP spoofing protection when trust_proxy is enabled
* Security: randomized log/backup storage directory for Nginx/LiteSpeed compatibility
* Security: fixed XSS vector in admin error rendering
* Security: HTTPS localhost check uses REMOTE_ADDR instead of SERVER_NAME

= 3.1.0 =
* Added license management system with tier-based feature gating
* Added modern admin dashboard with dark theme
* Added connection test button

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
* Added full internationalization (i18n) support
* Improved security with HTTPS enforcement and protected files

== Upgrade Notice ==

= 3.4.0 =
Security hardening release. Free version is now strictly read-only. Write operations restricted to wp-content/ only. Protects hidden directories and credential files. Recommended upgrade for all users.
