# Connect AI Agents to WordPress — RemoteWP Plugin

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.8+-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777bb4.svg)](https://php.net)
[![Security: Read-Only](https://img.shields.io/badge/Security-Read--Only-green.svg)](https://remotewp.dev)

**RemoteWP** is the WordPress plugin that lets you connect AI agents — Claude, ChatGPT, Cursor, Google Gemini, or any HTTP-capable agent — to WordPress and WooCommerce sites via a secure REST API. No SSH. No FTP. No exposed credentials.

🌐 **Website & Pro plans:** [remotewp.dev](https://remotewp.dev)

---

## What is RemoteWP?

RemoteWP turns any self-hosted WordPress site into a secure, API-accessible endpoint for AI agents. Instead of giving your AI assistant full server access via SSH or FTP, RemoteWP provides scoped, token-authenticated REST API endpoints limited to paths you explicitly approve.

**This is the official open-source Free (Core) version** — strictly read-only, designed to pass WordPress.org security review and give you safe AI agent access to audit and inspect any WordPress site.

For full read/write automation (modify files, install plugins, manage WooCommerce, use the Agent Skill Pack), see **[RemoteWP Pro](https://remotewp.dev/#pricing)**.

---

## How to Connect Claude, ChatGPT or Cursor to WordPress

1. Install RemoteWP on your WordPress site (upload ZIP or install via wp-admin)
2. Go to **Settings → RemoteWP** and generate your API token
3. Copy your token and site URL
4. Pass both to your AI agent:
   - **Claude (MCP):** add as an HTTP tool with `X-RemoteWP-Token` header
   - **Cursor:** add to your `.cursorrules` or agent config
   - **ChatGPT / Codex:** create a Custom Action with your site URL
   - **Any agent:** standard `GET` / `POST` over HTTPS with token header

Your AI agent can now read files, list directories, audit plugin versions, and inspect WordPress configuration — all without SSH or FTP.

---

## Key Features — Free Core (Read-Only)

### 🔒 Security First
- **Read-Only Enforcement** — No file-writing or modifying endpoints. Zero remote code execution risk.
- **Token Authentication** — Secure 64-character token on every request via `X-RemoteWP-Token` header.
- **Path Sandboxing** — All reads are restricted to `ABSPATH`. Directory traversal (`../`) is fully blocked.
- **Protected System Files** — `wp-config.php`, `.env`, `.htaccess`, and server configs are permanently blacklisted.
- **Rate Limiting & Lockout** — Built-in brute force and DDoS protection.
- **IP Whitelisting** — Optionally restrict API access to specific IP ranges.

### 📂 Filesystem API (Read-Only)
- **List Directories** (`GET /wp-json/remotewp/v1/list`) — View file listings, sizes, modification dates.
- **Read Files** (`GET /wp-json/remotewp/v1/read`) — Read theme, plugin, and config file contents.
- **Site Status** (`GET /wp-json/remotewp/v1/status`) — WordPress version, active plugins, PHP version.
- **Plugin Audit** (`GET /wp-json/remotewp/v1/plugins`) — List all installed plugins with version info.

---

## Example API Request

Connect any AI agent with a standard HTTP call:

```http
GET /wp-json/remotewp/v1/read?path=wp-content/themes/my-theme/style.css
X-RemoteWP-Token: YOUR-API-TOKEN-HERE
```

```http
GET /wp-json/remotewp/v1/list?path=wp-content/themes/my-theme/
X-RemoteWP-Token: YOUR-API-TOKEN-HERE
```

Works with Claude, Cursor, ChatGPT, Gemini, Antigravity, or any HTTP-capable AI agent.

---

## Compatible AI Agents

| Agent | Integration Method |
|---|---|
| **Claude (Anthropic)** | MCP HTTP tool or direct API call |
| **Cursor** | REST call in `.cursorrules` or agent config |
| **ChatGPT / Codex** | Custom GPT Action with OpenAPI schema |
| **Google Gemini** | Function calling via HTTP |
| **Antigravity AI** | Native HTTP tool |
| **Any HTTP agent** | Standard GET/POST with token header |

---

## Installation

### From WordPress.org (recommended)
1. Search for **RemoteWP** in the WordPress plugin directory
2. Click Install → Activate
3. Go to Settings → RemoteWP → Generate token

### Manual ZIP Install
1. Download the latest release ZIP from this repository
2. Go to wp-admin → Plugins → Add New → Upload Plugin
3. Upload and activate
4. Go to Settings → RemoteWP → Generate token

### Requirements
- WordPress 5.8+
- PHP 7.4+
- HTTPS strongly recommended (required for production use)

---

## Free vs Pro

| Feature | Free (Core) | Pro |
|---|---|---|
| Read files via REST API | ✅ | ✅ |
| List directories | ✅ | ✅ |
| Audit plugins & status | ✅ | ✅ |
| Write / modify files | ❌ | ✅ |
| Install / activate plugins | ❌ | ✅ |
| WooCommerce endpoints | ❌ | ✅ |
| Agent Skill Pack | ❌ | ✅ |
| Unlimited WordPress sites | 1 site | ✅ |
| Priority support | ❌ | ✅ |

👉 **[Get RemoteWP Pro at remotewp.dev](https://remotewp.dev/#pricing)**

---

## Security Model

RemoteWP is built with a zero-trust approach for AI agent access:

- Every request requires a valid token — no token, no access
- Tokens are scoped to your WordPress installation only
- Path whitelist limits what directories the agent can access
- Rate limiting prevents automated abuse
- Full audit log of every read operation (Pro)
- No credentials are ever exposed to the AI agent

This makes RemoteWP significantly safer than sharing SSH keys or FTP credentials with an AI assistant.

---

## Why Not Just Use SSH or FTP?

| | SSH / FTP | RemoteWP |
|---|---|---|
| Credential exposure | Full server access | Scoped to WP paths only |
| Revoke access | Complex | One click in wp-admin |
| Audit log | None | Full log (Pro) |
| Path restriction | Manual / complex | Built-in whitelist |
| Brute force protection | Server-level only | Built-in lockout |
| Works with AI agents natively | ❌ | ✅ |

---

## Contributing

This is the open-source Core version. Contributions welcome:

1. Fork this repo
2. Create a branch: `git checkout -b feature/your-feature`
3. Commit and push
4. Open a Pull Request

Please follow WordPress coding standards.

---

## License

GPL v2 or later — see [LICENSE](LICENSE) for details.

---

## Links

- 🌐 **Website:** [remotewp.dev](https://remotewp.dev)
- 📦 **WordPress.org:** [wordpress.org/plugins/remotewp](https://wordpress.org/plugins/remotewp) *(pending review)*
- 📖 **Documentation:** [remotewp.dev/docs/getting-started](https://remotewp.dev/docs/getting-started.html)
- 💬 **Support:** info@remotewp.dev

---

*Built by [X-HOUSE SRL](https://xhouse.ro) — Arad, Romania*
