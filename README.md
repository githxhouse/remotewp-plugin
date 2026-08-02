# Remote WP — Connect Claude, Gemini, Cursor & Codex to WordPress

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.8+-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777bb4.svg)](https://php.net)
[![Version](https://img.shields.io/badge/Version-3.6.5-6366f1.svg)](https://remotewp.dev)

**Remote WP securely connects compatible AI agents like Claude, Gemini, and Codex to a WordPress website through a controlled REST API.**

**It allows you to connect Claude or Gemini inside Cursor directly to WordPress. This lets your AI agents inspect approved files, edit code, debug errors, and optimize SEO safely without sharing raw SSH or FTP credentials.**

**Remote WP reduces the need to share host-level access while keeping control restricted through API authentication, permission profiles, rate limits, and activity logs.**

> Remote WP provides the WordPress connection and supported API operations. The quality and scope of the analysis also depend on the tools and capabilities available to the connected AI agent.

---

## What RemoteWP Can Help With

### Design & Layout

- Inspect approved theme templates and stylesheets
- Search for CSS related to visual problems
- Investigate responsive layout issues
- Modify approved frontend files
- Assist with WooCommerce template and layout changes
- Apply controlled design modifications

> Visual confirmation may require browser access, screenshots or another rendering tool available to the connected AI agent.

### WordPress Debugging

- Search approved theme and plugin files
- Investigate PHP, JavaScript and CSS problems
- Locate functions related to WordPress errors
- Analyze likely plugin or theme conflicts
- Review broken functionality
- Apply controlled fixes with automatic backups

### SEO

- Inspect templates affecting headings and metadata
- Review canonical and schema implementations
- Search for duplicated or hardcoded SEO elements
- Investigate technical SEO issues in themes and plugins
- Review approved WordPress content and template structure
- Assist with approved SEO modifications

> RemoteWP does not replace a crawler, analytics platform, keyword tool or complete SEO suite. A complete SEO audit may require browser access and external data.

### Performance

- Search scripts and styles loaded by themes and plugins
- Inspect inefficient or repetitive code
- Investigate cache and transient-related problems
- Review possible plugin overhead
- Clear supported WordPress caches and transients
- Assist with approved code-level optimizations

> Core Web Vitals analysis requires browser or field-performance data that RemoteWP does not provide by itself.

### Development & WooCommerce

- Inspect and modify approved theme and plugin files
- Work with hooks, filters, templates and shortcodes
- Create files and directories when permissions allow
- Assist with WooCommerce customizations
- Search the codebase before making changes
- Apply approved code modifications

### Maintenance

- Inspect installed plugins and available update information
- Activate or deactivate plugins when permitted
- Review supported WordPress configuration values
- Search approved WordPress directories
- Clear caches and transients
- Standardize recurring work across client websites

---

## Start Using RemoteWP in Four Steps

### 1. Download RemoteWP

Choose the Free plan or purchase a paid license, then download the RemoteWP plugin package from [remotewp.dev](https://remotewp.dev).

### 2. Install and Activate

Upload the plugin through the WordPress dashboard and activate it like any standard WordPress plugin.

```
Plugins -> Add New -> Upload Plugin -> Activate RemoteWP
```

### 3. Copy the Connection Instructions

Open the RemoteWP settings page and copy the endpoint, authentication token, permission profile and connection instructions.

The plugin also serves a complete, site-specific AI Agent Skill Pack via:

```
GET https://yoursite.com/wp-json/helper/v1/skill
```

This endpoint returns the full SKILL.md with your site URL, API base and tier pre-filled. Paste the content directly into your AI agent as a system prompt or custom instruction.

### 4. Paste It into Your AI Agent

Paste the connection instructions into Claude, ChatGPT, Cursor, Codex, Gemini, Windsurf or another compatible AI agent.

> The AI platform must be configured with a tool, script, connector or integration capable of authenticated HTTP requests with custom headers.

---

## Key Features

### Security First
- **Token Authentication** -- 64-character cryptographic tokens via `X-RemoteWP-Token` header
- **HTTPS Enforcement** -- All API calls require SSL (except localhost)
- **Rate Limiting** -- Configurable requests per minute (default: 60/min per IP)
- **IP Whitelist** -- Optional IP restriction with CIDR notation support
- **Brute Force Protection** -- Auto-lockout after configurable failed auth attempts (default: 5 attempts, 15 min lockout)
- **Path Sandboxing** -- All operations restricted to WordPress ABSPATH
- **Protected Files** -- `wp-config.php`, `.env*`, `.htaccess`, `.htpasswd`, `.user.ini`, `php.ini`, `web.config` always blocked
- **Hidden Directory Block** -- All dot-files and dot-directories blocked recursively
- **Write Restriction** -- Write operations restricted to `wp-content/`
- **Auto-Backup** -- Every write/delete/rename creates a timestamped backup
- **Audit Logging** -- JSON activity log (500 entries, auto-rotated) with IP, action, path, timestamp

---

## How It Works

```
AI Agent
    |
    | Authenticated HTTP request (X-RemoteWP-Token)
    v
RemoteWP REST API
    |
    |-- Authentication
    |-- Permission validation
    |-- Path protection
    |-- Rate limiting
    |-- Backups
    |-- Activity logging
    v
Supported WordPress files and operations
```

1. RemoteWP is installed on the WordPress website.
2. A secure token is generated automatically on activation.
3. The permission profile and path restrictions are configured in the admin dashboard.
4. The AI agent sends authenticated REST API requests using the token.
5. RemoteWP validates authentication, permissions and requested paths.
6. Supported operations are executed.
7. Relevant operations are logged to the audit log.
8. Supported file modifications are backed up automatically before changes are applied.

---

## Security

- **Token Generation**: `bin2hex(random_bytes(32))` -- 64 hex characters
- **Token Comparison**: `hash_equals()` -- timing-safe
- **Token TTL**: Configurable (default: `0` = never expires)
- **Path Validation**: `realpath()` + `strpos()` check against ABSPATH
- **Write Restriction**: Filesystem write operations restricted to `wp-content/`
- **Dangerous Extensions**: `php`, `phtml`, `php5-8`, `phar`, `cgi`, `sh`, `py`, `rb`, `exe` blocked by default
- **Auto-Backup**: Timestamped backup created before every write/delete/rename
- **Backup Storage**: Randomized directory inside `wp-content/uploads/`, protected by `.htaccess` and `index.php`
- **Audit Log**: JSON-based, 500 entries max, auto-rotated
- **HTTPS Check**: Uses `REMOTE_ADDR` for localhost detection (prevents spoofing)
- **IP Whitelist**: CIDR notation supported; whitelisted IPs bypass rate limiting

---

## Permission Profiles

| Profile | Allowed Operations |
|---------|-------------------|
| **Read Only** | list, read, status, search, wp_info, wp_plugins, wp_options, instructions |
| **Read & Write** | All read operations + write, mkdir, wp_cache_clear |
| **Full Access** | All operations including delete, rename, restore, plugin toggle |

Default at activation: **Full Access**. Configure in the RemoteWP admin dashboard.

---

## API Endpoints

### Free Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/helper/v1/status` | Plugin status, permission level, PHP/WP versions |
| `GET` | `/helper/v1/list` | List directory contents with metadata |
| `GET` | `/helper/v1/read` | Read file content (up to 5MB) |
| `GET` | `/helper/v1/skill` | Site-specific AI Agent Skill Pack (SKILL.md with site vars pre-filled) |
| `GET` | `/helper/v1/instructions` | Legacy AI instructions |
| `GET` | `/helper/v1/wp/info` | Basic site info (theme, WP version) |

### Pro Endpoints -- Filesystem

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/helper/v1/write` | Write/create file with auto-backup |
| `POST` | `/helper/v1/delete` | Delete file or directory with auto-backup |
| `POST` | `/helper/v1/rename` | Rename file or directory with auto-backup |
| `POST` | `/helper/v1/mkdir` | Create directory recursively |
| `POST` | `/helper/v1/restore` | Restore from backup |
| `GET` | `/helper/v1/search` | Search file contents (grep-like) |
| `POST` | `/helper/v1/sync` | WAF-compatible base64-encoded request dispatcher |
| `POST` | `/helper/v1/process` | Alias for `/sync` |

### Pro Endpoints -- WordPress Operations

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/helper/v1/wp/info` | Full site info: theme, plugins summary, WP version, multisite |
| `GET` | `/helper/v1/wp/plugins` | Full plugin list with activation status |
| `POST` | `/helper/v1/wp/plugin/toggle` | Activate or deactivate plugins |
| `GET` | `/helper/v1/wp/options` | Read whitelisted WordPress options |
| `POST` | `/helper/v1/wp/cache-clear` | Clear all supported caches and transients |

---

## Quick Start

```bash
# Check plugin status
curl -H "X-RemoteWP-Token: YOUR_TOKEN" \
  https://yoursite.com/wp-json/helper/v1/status

# List theme files
curl -H "X-RemoteWP-Token: YOUR_TOKEN" \
  "https://yoursite.com/wp-json/helper/v1/list?path=wp-content/themes/mytheme"

# Read a file
curl -H "X-RemoteWP-Token: YOUR_TOKEN" \
  "https://yoursite.com/wp-json/helper/v1/read?path=wp-content/themes/mytheme/style.css"

# Write a file (Pro)
curl -X POST \
  -H "X-RemoteWP-Token: YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"path":"wp-content/themes/mytheme/custom.css","content":"/* styles */"}' \
  https://yoursite.com/wp-json/helper/v1/write
```

---

## Example AI Tasks

**Design**
> "Search the approved active-theme files for CSS related to the mobile product-card spacing problem. Explain the most likely cause and show the proposed modification before writing the file."

**Debugging**
> "Search approved plugin and theme files for code related to the checkout validation error. Do not modify anything until the likely cause is identified."

**SEO**
> "Inspect approved theme and SEO-plugin files for heading, canonical and schema implementation problems. Prepare a report before applying changes."

**Performance**
> "Search approved theme and plugin files for scripts and styles that may be loaded globally. Recommend the lowest-risk code-level optimizations."

**WooCommerce Development**
> "Create a backup and add the approved WooCommerce customization to the child theme. Do not modify WordPress core or the parent theme."

**Maintenance**
> "Review installed plugins and available update information. Prepare a maintenance summary before performing any write operation."

---

## AI Agent Integration

RemoteWP can be used with AI agents and automation tools that support authenticated HTTP requests with custom headers.

The exact integration method depends on the AI platform:

- **Claude** -- may require an HTTP-capable tool or integration configured with the RemoteWP endpoint and token
- **ChatGPT** -- may require a compatible action, connector or external tool
- **Cursor and Windsurf** -- may use scripts, terminal tools or configured agent instructions
- **Custom agents** -- can call the REST API directly using any HTTP client

RemoteWP does not include a native plugin or connector for any specific AI platform.

---

## AI Agent Skill Pack

RemoteWP includes a built-in **AI Agent Skill Pack** at `skills/remotewp-bridge/SKILL.md`.

**Retrieve with your site details pre-filled:**

```bash
curl -H "X-RemoteWP-Token: YOUR_TOKEN" \
  https://yoursite.com/wp-json/helper/v1/skill
```

Feed the returned content to your AI agent as a system prompt or custom instruction set.

---

## Architecture

```text
remotewp/
|-- remotewp.php                          # Main plugin loader, constants, activation, cron
|-- uninstall.php                         # Clean uninstall
|-- readme.txt                            # WordPress.org standard readme
|-- includes/
|   |-- class-remotewp-auth.php           # Token auth + HTTPS enforcement + IP whitelist
|   |-- class-remotewp-rate-limiter.php   # Per-IP rate limiting + brute force lockout
|   |-- class-remotewp-permissions.php    # Permission profiles + path security + protected files
|   |-- class-remotewp-fs-api.php         # Free filesystem REST endpoints
|   |-- class-remotewp-license.php        # License management + tier gating
|   |-- class-remotewp-logger.php         # Audit logging (JSON, 500 entries) + auto-backup
|   |-- class-remotewp-admin.php          # Admin dashboard
|   |-- class-remotewp-pro-loader.php     # Pro module loader
|   `-- class-remotewp-updater.php        # Auto-updater
|-- pro/
|   |-- class-remotewp-fs-api-pro.php     # Pro filesystem endpoints
|   |-- class-remotewp-wp-api.php         # Pro WordPress operations endpoints
|   `-- class-remotewp-admin-pro.php      # Pro admin enhancements
|-- skills/
|   `-- remotewp-bridge/
|       `-- SKILL.md                      # AI Agent Skill Pack
`-- admin/
    |-- css/admin.css
    `-- js/admin.js
```

---

## Requirements

- WordPress 5.8+
- PHP 7.4+
- HTTPS (required in production, bypassed on localhost)

---

## Limitations and Responsible Use

RemoteWP provides controlled access to supported WordPress files and operations. It does not guarantee that an AI-generated recommendation or code modification is correct.

Review sensitive changes before execution and test important modifications in a staging environment whenever possible.

Visual inspection, browser testing, database analysis, analytics, external SEO data and performance measurements may require additional tools beyond RemoteWP.

- Do not grant broader permissions than required for the current task.
- Avoid modifying WordPress core files. Prefer child themes or custom plugins for maintainable changes.
- Maintain independent backups before major production modifications.
- You remain responsible for configuring permissions and reviewing sensitive operations.

---

## License

GPL-2.0-or-later -- [Full License](LICENSE)

---

## Built By

**[X-HOUSE SRL](https://xhouse.ro)** -- Arad, Romania

- [remotewp.dev](https://remotewp.dev)
- info@remotewp.dev