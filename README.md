# RemoteWP - The AI-Ready WordPress Bridge

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.8+-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777bb4.svg)](https://php.net)
[![Version](https://img.shields.io/badge/Version-3.0.0-6366f1.svg)](https://remotewp.dev)

**RemoteWP** turns any WordPress site into a secure, API-controllable endpoint for AI agents. Let Claude, ChatGPT, Cursor, Windsurf, or any automation tool manage your WordPress files, plugins, and configuration through a clean REST API - no SSH or FTP needed.

---

## Features

### Security First
- **Token Authentication** - 64-character cryptographic tokens via `X-RemoteWP-Token` header
- **HTTPS Enforcement** - All API calls require SSL (except localhost)
- **Rate Limiting** - Configurable requests per minute (default: 60/min)
- **IP Whitelist** - Optional IP restriction with CIDR notation support
- **Brute Force Protection** - Auto-lockout after failed authentication attempts
- **Path Sandboxing** - All operations are restricted to WordPress ABSPATH
- **Protected Files** - `wp-config.php`, `.env`, `.htaccess` are always protected

### Filesystem API (9 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/remotewp/v1/list` | List directory contents with metadata |
| `GET` | `/remotewp/v1/read` | Read file content (up to 5MB) |
| `POST` | `/remotewp/v1/write` | Write/create file (auto-backup) |
| `POST` | `/remotewp/v1/delete` | Delete file or directory (auto-backup) |
| `POST` | `/remotewp/v1/rename` | Rename file or directory (auto-backup) |
| `POST` | `/remotewp/v1/mkdir` | Create directory |
| `POST` | `/remotewp/v1/restore` | Restore from backup |
| `GET` | `/remotewp/v1/search` | Search file contents (grep-like) |
| `GET` | `/remotewp/v1/status` | Plugin and server status |

### WordPress Operations API (5 endpoints)
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/remotewp/v1/wp/info` | Site info, theme, plugins summary |
| `GET` | `/remotewp/v1/wp/plugins` | Full plugin list with update status |
| `POST` | `/remotewp/v1/wp/plugin/toggle` | Activate/deactivate plugins |
| `GET` | `/remotewp/v1/wp/options` | Read whitelisted WordPress options |
| `POST` | `/remotewp/v1/wp/cache-clear` | Clear all caches and transients |

### Granular Permissions
| Profile | Operations |
|---------|-----------|
| **Read Only** | list, read, status, search, wp_info, wp_plugins, wp_options |
| **Read & Write** | All read + write, mkdir, wp_cache_clear |
| **Full Access** | All operations including delete, rename, plugin toggle |

---

## Quick Start

### 1. Install
```bash
# Upload to WordPress plugins directory
wp plugin install remotewp.zip --activate
```

### 2. Get your token
Navigate to **RemoteWP** in the WordPress admin sidebar and copy the auto-generated API token.

### 3. Make your first API call
```bash
curl -H "X-RemoteWP-Token: YOUR_TOKEN_HERE" \
  https://yoursite.com/wp-json/remotewp/v1/status
```

### 4. List files
```bash
curl -H "X-RemoteWP-Token: YOUR_TOKEN_HERE" \
  "https://yoursite.com/wp-json/remotewp/v1/list?path=wp-content/themes"
```

### 5. Read a file
```bash
curl -H "X-RemoteWP-Token: YOUR_TOKEN_HERE" \
  "https://yoursite.com/wp-json/remotewp/v1/read?path=wp-content/themes/mytheme/style.css"
```

### 6. Write a file
```bash
curl -X POST \
  -H "X-RemoteWP-Token: YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{"path":"wp-content/test.txt","content":"Hello from AI!"}' \
  https://yoursite.com/wp-json/remotewp/v1/write
```

---

## AI Agent Integration

RemoteWP is designed to be consumed by AI agents. Here is how to configure popular tools:

### Claude / Anthropic
```python
import requests

SITE_URL = "https://yoursite.com/wp-json/remotewp/v1"
TOKEN = "your_token_here"
headers = {"X-RemoteWP-Token": TOKEN}

# Read a file
r = requests.get(
    f"{SITE_URL}/read",
    params={"path": "wp-content/themes/mytheme/functions.php"},
    headers=headers,
)
print(r.json()["content"])
```

### Any HTTP Client
The API accepts standard REST requests with JSON bodies. Any tool that can send HTTP requests with custom headers can use RemoteWP.

---

## Architecture

```text
remotewp/
|-- remotewp.php              # Main plugin loader
|-- uninstall.php             # Clean uninstall
|-- readme.txt                # WordPress.org standard
|-- includes/
|   |-- class-remotewp-auth.php          # Token auth + HTTPS + IP whitelist
|   |-- class-remotewp-rate-limiter.php  # Per-IP rate limiting + lockout
|   |-- class-remotewp-permissions.php   # Granular permission profiles
|   |-- class-remotewp-fs-api.php        # Filesystem REST endpoints
|   |-- class-remotewp-wp-api.php        # WordPress operations endpoints
|   |-- class-remotewp-admin.php         # Admin dashboard
|   `-- class-remotewp-logger.php        # Audit logging + backups
`-- admin/
    |-- css/admin.css                    # Modern admin styles
    `-- js/admin.js                      # Clipboard + interactions
```

---

## Security Details

- **Token Generation**: `bin2hex(random_bytes(32))` - 64 hex characters
- **Token Comparison**: `hash_equals()` - timing-safe
- **Path Validation**: `realpath()` + `strpos()` check against ABSPATH
- **Protected Files**: wp-config.php, .env, .htaccess, .htpasswd, .user.ini, php.ini
- **Auto-Backup**: Every write/delete/rename creates a timestamped backup
- **Audit Log**: JSON-based log with IP, action, path, timestamp (500 entries, auto-rotated)
- **Rate Limit**: Transient-based, per-IP, configurable (0 = disabled)
- **Lockout**: After N failed auth attempts, IP is blocked for M minutes

---

## Requirements

- WordPress 5.8+
- PHP 7.4+
- HTTPS (required in production, bypassed on localhost)

---

## License

GPL-2.0-or-later - [Full License](LICENSE)

---

## Built By

**[X-HOUSE SRL](https://xhouse.ro)** - Arad, Romania

- xander@xhouse.ro
- 0735 785 335
- [remotewp.dev](https://remotewp.dev)
