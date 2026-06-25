=== RemoteWP ===
Contributors: xhouse
Tags: ai, api, remote management, automation, developer tools
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let AI agents manage your WordPress site remotely through a secure REST API — no SSH or FTP needed.

== Description ==

**RemoteWP** turns your WordPress site into a secure, API-controllable endpoint that AI agents can manage remotely. Whether you use Claude, ChatGPT, Cursor, Windsurf, or any custom automation — RemoteWP gives them direct access to your site's filesystem and WordPress operations through a clean REST API.

= Why RemoteWP? =

Traditional WordPress management requires SSH, FTP, or cPanel access. AI agents and automation tools can't use these easily. RemoteWP solves this by providing:

* **Secure REST API** — Token-based authentication over HTTPS
* **Filesystem Operations** — Read, write, delete, rename, search files
* **WordPress Operations** — Site info, plugin management, cache clearing
* **Auto-Backup** — Every destructive operation creates an automatic backup
* **Audit Logging** — Full activity log with IP tracking
* **Granular Permissions** — Read-only, read-write, or full access profiles

= Key Features =

**Security First**

* Token-based authentication (64-character secure tokens)
* HTTPS enforcement (no plaintext API calls)
* IP whitelist with CIDR notation support
* Rate limiting (configurable requests per minute)
* Brute force lockout (auto-blocks after failed attempts)
* Protected files (wp-config.php, .env, .htaccess cannot be accessed)
* Path restrictions (limit access to specific directories)
* Directory traversal prevention

**Filesystem API**

* `GET /list` — List directory contents with metadata
* `GET /read` — Read file content (up to 5MB)
* `POST /write` — Create or update files (with auto-backup)
* `POST /delete` — Delete files or empty directories (with auto-backup)
* `POST /rename` — Rename files or directories (with auto-backup)
* `POST /mkdir` — Create new directories
* `POST /restore` — Restore from automatic backup
* `GET /search` — Search file contents (grep-like)
* `GET /status` — Plugin and server status

**WordPress Operations API**

* `GET /wp/info` — Comprehensive site information
* `GET /wp/plugins` — Full plugin list with update status
* `POST /wp/plugin/toggle` — Activate or deactivate plugins
* `GET /wp/options` — Read whitelisted WordPress options
* `POST /wp/cache-clear` — Clear all caches (supports WP Super Cache, W3TC, WP Rocket, LiteSpeed)

**Modern Admin Dashboard**

* Clean, modern admin interface
* Token management with copy-to-clipboard
* Activity log viewer with filtering
* Permission profile selector
* IP whitelist manager
* Rate limit configuration

= Use Cases =

1. **AI-Powered Site Management** — Let Claude, GPT, or Cursor manage theme files, fix bugs, and optimize code
2. **Automated Deployments** — Push code changes via API from CI/CD pipelines
3. **Remote Auditing** — Scan files for malware, check plugin versions, audit configurations
4. **Multi-Site Management** — Manage multiple WordPress sites from a central system
5. **Backup Automation** — Automated file backup and restore workflows

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
5. Configure permissions, rate limits, and IP whitelist as needed

== Frequently Asked Questions ==

= Is RemoteWP secure? =

Yes. RemoteWP enforces HTTPS, uses 64-character cryptographic tokens, implements rate limiting, IP whitelisting, brute force protection, and sandboxes all operations within your WordPress directory. Sensitive files like wp-config.php are always protected.

= Can I limit what the API can do? =

Yes. RemoteWP offers three permission profiles:
- **Read Only** — Only list and read operations
- **Read & Write** — Read plus write and create
- **Full Access** — All operations including delete and plugin management

You can also restrict access to specific directories using path restrictions.

= Does it work with my caching plugin? =

The cache-clear endpoint supports WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed Cache, and WordPress's built-in object cache.

= What happens if something goes wrong? =

Every write, delete, and rename operation automatically creates a backup of the affected file. You can restore any file through the API or manually from the backup directory.

= Can I use it with multiple AI tools? =

Yes! The API is tool-agnostic. Any HTTP client, AI agent, or automation tool that can send REST requests with headers can use RemoteWP.

= Where are backups stored? =

Backups are stored in a randomized directory inside `wp-content/uploads/`. The directory is protected from web access via .htaccess, index.php, and a non-guessable folder name for compatibility with Nginx and LiteSpeed.

== Screenshots ==

1. Dashboard — Token management and connection info
2. Activity Log — Filterable audit log viewer
3. Settings — Permissions, rate limiting, and IP whitelist

== Changelog ==

= 3.2.0 =
* Added AI Agent Skill Pack with one-click agent prompt
* Added `/skill` REST endpoint for dynamic agent skill delivery
* Security: fixed path restriction prefix bypass (directory boundary check)
* Security: fixed ABSPATH sibling directory escape
* Security: hardened IP spoofing protection when trust_proxy is enabled
* Security: randomized log/backup storage directory for Nginx/LiteSpeed compatibility
* Security: fixed XSS vector in admin error rendering
* Security: HTTPS localhost check uses REMOTE_ADDR instead of SERVER_NAME
* Cleaned up legacy AI Instructions buttons in favor of Skill Pack
* Updated API Access tab with Skill Endpoint actions

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
* Renamed API namespace to `remotewp/v1`
* Changed auth header to `X-RemoteWP-Token`

= 2.0.0 =
* Internal release — class-based architecture
* Token authentication
* Basic filesystem CRUD

= 1.0.0 =
* Initial internal release

== Upgrade Notice ==

= 3.2.0 =
Security hardening release. Fixes path traversal edge cases, IP spoofing, and XSS. Adds AI Agent Skill Pack for one-click agent integration.

= 3.0.0 =
Major update with new security features, WordPress Operations API, and modern admin dashboard. The API namespace changed from `xhouse-api/v1` to `remotewp/v1` and the auth header changed from `X-House-Token` to `X-RemoteWP-Token`. Backward compatibility with the old header is maintained.
