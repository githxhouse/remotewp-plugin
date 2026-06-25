# RemoteWP — Secure WordPress Audit & Read API for AI Agents (Core Version)

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.8+-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777bb4.svg)](https://php.net)
[![Security: Read-Only](https://img.shields.io/badge/Security-Read--Only-green.svg)](https://remotewp.dev)

**RemoteWP** turns any self-hosted WordPress site into a secure, API-controllable endpoint for AI agents. 

This is the official open-source **Free (Core) version** of the RemoteWP plugin. It is designed to be **strictly read-only** to ensure absolute security and compliance. It allows AI coding assistants (Claude, Cursor, Google Gemini, OpenAI ChatGPT/Codex, and custom HTTP-capable agents) to audit files, inspect configuration, and check site statuses remotely — with zero file-writing capabilities.

For full write operations (modifying files, installing/activating plugins, WooCommerce management, SEO adjustments, and custom agent skills), please upgrade to **[RemoteWP Pro](https://remotewp.dev)**.

---

## Key Features (Free Core)

### 🔒 Security First
- **Read-Only Enforcement** - This Core version contains absolutely no file-writing or modifying endpoints, completely preventing remote code execution (RCE).
- **Token Authentication** - Secure token handshake on every request using `X-RemoteWP-Token` header.
- **Path Sandboxing** - All file reads are strictly sandboxed within the WordPress installation root (`ABSPATH`). Directory traversal (`../`) is blocked.
- **Protected System Files** - Critical system files (like `wp-config.php`, `.env`, `.htaccess`, and server configurations) are blacklisted and cannot be read.
- **Rate Limiting & Lockout** - Built-in protection against brute force and DDoS attempts.

### 📂 Filesystem API (Read-Only)
- **List Directories** (`GET /remotewp/v1/list`) - Safely view file listings, sizes, and modification dates.
- **Read Files** (`GET /remotewp/v1/read`) - Read the contents of theme, plugin, or content files (up to 5MB).

### ⚙️ WordPress Info API
- **Site Status** (`GET /remotewp/v1/status`) - Verify connection and check PHP/WordPress versions.
- **Environment Details** (`GET /remotewp/v1/wp/info`) - Fetch basic site settings, active theme, and activated plugins count.

---

## Free Core vs Pro Comparison

| Capability | Free Core | RemoteWP Pro |
|---|:---:|:---:|
| **Read Theme/Plugin Files** | ✅ | ✅ |
| **List Directories** | ✅ | ✅ |
| **Verify Connection & PHP Info** | ✅ | ✅ |
| **Write/Modify Files** (CSS/PHP/JS) | ❌ | ✅ |
| **Delete Files / Folders** | ❌ | ✅ |
| **Rename Files / Folders** | ❌ | ✅ |
| **Create Directories** | ❌ | ✅ |
| **Restore Backups** | ❌ | ✅ |
| **Grep Search File Contents** | ❌ | ✅ |
| **Manage Plugins** (Toggle Active) | ❌ | ✅ |
| **Read WordPress Options** | ❌ | ✅ |
| **Clear Site/Object Caches** | ❌ | ✅ |
| **WooCommerce & SEO Skills** | ❌ | ✅ |

*Learn more about Pro features and pricing at [remotewp.dev/pricing](https://remotewp.dev#pricing).*

---

## Quick Start

### 1. Installation
1. Download the plugin ZIP from [remotewp.dev](https://remotewp.dev) (or build the Free Core ZIP from source).
2. Go to your WordPress Dashboard → **Plugins** → **Add New** → **Upload Plugin**.
3. Upload `remotewp-free.zip` and click **Activate**.

### 2. Generate API Token
1. In your WordPress admin sidebar, navigate to **Settings** → **RemoteWP**.
2. Set the permission level to **Read Only** (for this Core version).
3. Copy your auto-generated API Token.

### 3. Connection Test
Provide your AI agent or terminal with the site URL and the token:
```bash
curl -H "X-RemoteWP-Token: YOUR_TOKEN_HERE" \
  https://yoursite.com/wp-json/remotewp/v1/status
```

---

## AI Agent Integration

RemoteWP includes a built-in skill endpoint designed to provide AI agents with immediate instructions on how to use the REST bridge. 

When configuring your AI assistant (e.g., Claude, Gemini, or Cursor), direct them to check the following endpoint to load their operational rules automatically:
```
GET https://yoursite.com/wp-json/remotewp/v1/skill
```

---

## Licensing & Credits

- **License:** GPL-2.0-or-later
- **Developed by:** **[X-HOUSE SRL](https://xhouse.ro)** (Arad, Romania)
- **Support & Sales:** [info@remotewp.dev](mailto:info@remotewp.dev)
- **Website:** [remotewp.dev](https://remotewp.dev)
