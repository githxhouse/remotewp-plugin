# Remote WP — Connect Claude, Gemini, Cursor & Codex to WordPress

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.8+-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777bb4.svg)](https://php.net)
[![Version](https://img.shields.io/badge/Version-3.7.10-6366f1.svg)](https://remotewp.dev)

**Remote WP securely connects compatible AI agents like Claude, Gemini, and Codex to a WordPress website through a controlled REST API.**

**It allows you to connect Claude or Gemini inside Cursor directly to WordPress. This lets your AI agents inspect approved files, edit code, debug errors, and optimize SEO safely without sharing raw SSH or FTP credentials.**

**Remote WP reduces the need to share host-level access while keeping control restricted through API authentication, permission profiles, rate limits, and activity logs.**

> Remote WP provides the WordPress connection and supported API operations. The quality and scope of the analysis also depend on the tools and capabilities available to the connected AI agent.

---

## AI Agent Skills & Dynamic Stack Resolution (v3.7.10)

RemoteWP uses the RemoteWP central server as the only source for agent instructions. It dynamically inspects your site's active technology stack (WooCommerce, Elementor, WPBakery, RankMath, Yoast, SEOPress) and delivers pre-formatted, tailored AI Skill Packs directly to connecting AI agents (Claude, ChatGPT, Gemini, Cursor). The plugin package does not contain a local `SKILL.md` fallback.

RemoteWP V2 distributes one public Free/Core package. Pro capabilities are delivered as an encrypted, domain-bound module after the installed plugin validates an active license. Master/Full ZIP archives are not distributed to customers.

### Included Skill Modules:
- **`remotewp-bridge`**: Core AI Agent Operations, Site DNA, Ultra-Fast Onboarding Audit (<2s single-call status response), and Action Menu.
- **`wordpress-woocommerce`**: Score 90+ Catalog Perfection Loop, inventory management, payment gateway diagnostics, and `/llms.txt` generator.
- **`wordpress-elementor`**: Deep `_elementor_data` JSON structure inspection, widget customization, flexbox containers, and template shortcodes.
- **`wordpress-wpbakery`**: WPBakery shortcodes (`[vc_row]`, `[vc_column]`, `[vc_custom_heading]`), inline CSS meta (`_wpb_shortcodes_custom_css`), and layout portability.
- **`wordpress-seo`**: Schema.org JSON-LD structured data injection, RankMath / Yoast / SEOPress meta tag optimization, and heading hierarchy audit.

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

## Key Features

### Security Architecture

- **Token Authentication**: Cryptographically secure 64-character tokens
- **HTTPS Enforcement**: Production connections require HTTPS (bypassed on localhost)
- **Rate Limiting**: Per-IP bucket algorithm with configurable requests-per-minute threshold
- **Brute Force Protection**: Automatic IP lockout after 5 consecutive failed authentication attempts
- **Protected Files**: `wp-config.php`, `.env`, `.htaccess`, `.git`, `.user.ini` always blocked
- **Token TTL**: Configurable (default: `0` = never expires)
- **Path Validation**: `realpath()` + `strpos()` check against ABSPATH
- **Controlled Writes**: Approved site file changes run through RemoteWP with validation and operation logs
- **Sensitive File Approval**: PHP/executable file mutations require an explicit extra approval, an approval note, automatic backup and restore metadata
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

## RemoteWP V2 API Endpoints

The V2 namespace is the canonical API for new AI agent connections. The legacy `/helper/v1/` namespace remains available for backward compatibility and WAF-friendly fallback, but new agents should start with `/remotewp/v2/connect`.

### V2 Startup and Context

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/remotewp/v2/connect` | Fast startup payload with site context, V2 endpoints and operating rules |
| `GET` | `/remotewp/v2/context` | Authenticated site identity, capability and tenant context |
| `GET` | `/remotewp/v2/health` | Storage, backup, rollout and API health checks |
| `GET` | `/remotewp/v2/openapi.json` | Machine-readable V2 contract |
| `GET` | `/remotewp/v2/skill` | Full V2 AI Agent Skill Pack when detailed rules are needed |

### V2 Filesystem Operations

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/remotewp/v2/fs/list` | List directory contents with metadata |
| `GET` | `/remotewp/v2/fs/read` | Read metadata and receive a short-lived content handle |
| `GET` | `/remotewp/v2/content/{handle}` | Resolve short-lived file content handles |
| `GET` | `/remotewp/v2/fs/search` | Search file contents |
| `POST` | `/remotewp/v2/fs/validate` | Validate a path before changing it |
| `POST` | `/remotewp/v2/fs/write` | Write/create file with auto-backup, expected hash and approval flow |
| `POST` | `/remotewp/v2/fs/patch` | Apply additive patch operations with expected SHA-256 |
| `POST` | `/remotewp/v2/fs/delete` | Delete with auto-backup and approval flow when sensitive |
| `POST` | `/remotewp/v2/fs/rename` | Rename with auto-backup and approval flow when sensitive |
| `POST` | `/remotewp/v2/fs/restore` | Restore from backup with restore metadata |
| `GET` | `/remotewp/v2/operations/{operation_id}` | Inspect mutation phases, backup IDs and verification status |

### V2 WordPress Operations

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/remotewp/v2/wp/info` | Site information, theme and WordPress version |
| `GET` | `/remotewp/v2/status` | Plugin status, server-managed capability profile and version |
| `POST` | `/helper/v1/sync` | Legacy WAF-compatible dispatcher retained as fallback |
| `POST` | `/helper/v1/process` | Legacy alias for `/sync` |

---

## Quick Start

```bash
# Fast V2 startup payload for an AI agent
curl -H "X-RemoteWP-Token: YOUR_TOKEN" \
  https://yoursite.com/wp-json/remotewp/v2/connect

# Check V2 health before mutations
curl -H "X-RemoteWP-Token: YOUR_TOKEN" \
  https://yoursite.com/wp-json/remotewp/v2/health

# Read a theme file through V2
curl -H "X-RemoteWP-Token: YOUR_TOKEN" \
  "https://yoursite.com/wp-json/remotewp/v2/fs/read?path=wp-content/themes/mytheme/style.css"

# Write after review, with automatic backup
curl -X POST \
  -H "X-RemoteWP-Token: YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"path":"wp-content/themes/mytheme/custom.css","content":"/* styles */","expected_sha256":"CURRENT_FILE_SHA256"}' \
  https://yoursite.com/wp-json/remotewp/v2/fs/write
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

RemoteWP includes a built-in **AI Agent Skill Pack** and a fast V2 connection payload.

**Start with the compact V2 connection payload:**

```bash
curl -H "X-RemoteWP-Token: YOUR_TOKEN" \
  https://yoursite.com/wp-json/remotewp/v2/connect
```

Use `/wp-json/remotewp/v2/skill` only when the agent needs the full detailed operating rules. The legacy `/wp-json/helper/v1/skill` endpoint remains available for older connectors.

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
|   |-- class-remotewp-pro-loader.php     # Dynamic encrypted Pro module loader
|   `-- class-remotewp-updater.php        # Auto-updater
`-- admin/
    |-- css/admin.css
    `-- js/admin.js
```

The public repository and public ZIP do not distribute a Pro, Full or Master package. Pro capability code is served dynamically by the RemoteWP license server after license and domain validation.

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
