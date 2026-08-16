# RemoteWP - AI-Powered WordPress Development, Debugging and SEO

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://php.net)
[![Version](https://img.shields.io/badge/Version-3.7.2-6366f1.svg)](https://remotewp.dev)

RemoteWP is a controlled WordPress REST API for AI agents and development tools such as Claude, ChatGPT, Gemini, Cursor, Windsurf and Codex. It helps teams inspect, troubleshoot, optimize and maintain WordPress websites without sharing raw SSH, FTP or hosting-panel credentials.

With RemoteWP, an authorized AI agent can assist with WordPress development, website debugging, SEO improvements, performance investigation, WooCommerce maintenance and controlled file operations. Every action remains subject to authentication, permission profiles, path restrictions, backups and activity logging.

RemoteWP provides the WordPress connection and supported operations. The quality of an analysis or code change also depends on the capabilities of the connected AI agent and the review process used by the site owner.

## RemoteWP V2: Free/Core Plugin and Licensed Pro Module

RemoteWP V2 uses one public Free/Core plugin package. Pro capabilities are delivered as an encrypted module bound to the validated license and domain. Customers do not receive public Master or Full ZIP archives.

This distribution model separates:

- **RemoteWP platform** - the software and license infrastructure operated by RemoteWP.
- **Free/Core plugin** - the public WordPress package installed on a client website.
- **Pro module** - encrypted capabilities delivered only after license and domain validation.
- **Permission profile** - the site-level access policy that limits what the connected agent can do.

An active Pro license does not automatically grant unrestricted access. The selected permission profile, protected paths and operation safety rules still apply.

## AI Agent Skills and Dynamic WordPress Stack Detection

RemoteWP includes an AI Agent Skill Pack that gives compatible agents site-specific instructions and safe operating context. The Cloud Skill Resolver can detect common WordPress technologies, including WooCommerce, Elementor, WPBakery, Rank Math, Yoast SEO and SEOPress, then return a tailored skill pack.

Available skill areas include:

- WordPress file inspection and controlled maintenance
- Elementor and WPBakery layout work
- WooCommerce catalog, checkout and integration diagnostics
- Technical SEO, metadata, headings and Schema.org review
- WordPress debugging, performance investigation and cache maintenance

The resolver endpoint is:

`GET /wp-json/remotewp-license/v1/skills/resolve`

## What RemoteWP Can Help With

### WordPress Development and Design

- Inspect approved theme, plugin and content files
- Investigate responsive layout and CSS issues
- Review templates, hooks, filters and shortcodes
- Prepare controlled changes for an approved child theme or plugin
- Apply supported changes with automatic backups when permitted

### WordPress Debugging and Maintenance

- Investigate PHP, JavaScript and CSS errors
- Search approved code for likely causes of a problem
- Review plugin and theme conflicts
- Inspect installed plugins and update information
- Clear supported caches and transients

### Technical SEO for WordPress

- Review heading structure, canonical tags and metadata
- Inspect Schema.org and JSON-LD implementations
- Identify duplicated or hardcoded SEO elements
- Review SEO plugin and theme integration
- Prepare technical SEO recommendations before making changes

RemoteWP does not replace a crawler, analytics platform, keyword research tool or complete SEO suite. Browser access and external performance or search data may still be required for a full SEO audit and Core Web Vitals analysis.

### WooCommerce Support

- Inspect WooCommerce templates and approved customizations
- Investigate catalog, checkout and payment integration issues
- Review product and order-related code paths
- Assist with controlled performance and maintenance work

## Security and Access Controls

- Cryptographically generated API token authentication
- HTTPS enforcement in production
- Configurable rate limiting and brute-force lockout
- Protected files and directories, including `.env`, `.git`, `.htaccess`, `.user.ini` and `wp-config.php`
- Path validation and write restrictions
- Dangerous file-extension blocking
- Automatic backups before supported write, delete and rename operations
- Randomized backup storage protected from direct web access
- JSON activity logs with automatic rotation
- Optional IP allowlisting

The `/sync` and `/process` routes are compatibility fallbacks for a narrowly scoped server or WAF routing problem. They are not security bypasses and must not be used to circumvent Wordfence, cPanel, ModSecurity or another access-control policy. Authentication, authorization, path restrictions, backups and logging remain enforced.

## Permission Profiles

Permission profiles are independent from the Free/Core versus Pro module distinction.

| Profile | Allowed operations |
| --- | --- |
| **Read Only** | Read-only operations such as list, read, status, search and approved WordPress information |
| **Read and Write** | Read-only operations plus approved write and directory-creation operations |
| **Full Access** | All supported operations, including delete, rename, restore and permitted plugin management |

For compatibility with existing installations, the current plugin activation default is **Full Access**. Site owners should review the setting immediately after installation and select the narrowest profile required for the job.

## API Endpoints

The legacy compatibility namespace is `/wp-json/helper/v1`. The modern V2 contract is also exposed by the plugin for clients that use the V2 API.

### Free/Core Endpoints

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/helper/v1/status` | Plugin status, permission level and WordPress/PHP versions |
| `GET` | `/helper/v1/list` | List approved directory contents with metadata |
| `GET` | `/helper/v1/read` | Read approved file content up to 5 MB |
| `GET` | `/helper/v1/skill` | Retrieve the site-specific AI Agent Skill Pack |
| `GET` | `/helper/v1/instructions` | Retrieve compatibility instructions |
| `GET` | `/helper/v1/wp/info` | Basic WordPress site information |

### Licensed Pro Module - Filesystem Operations

These operations require the encrypted Pro module, an active license/domain validation and a permission profile that allows the requested action.

| Method | Endpoint | Description |
| --- | --- | --- |
| `POST` | `/helper/v1/write` | Create or update an approved file with automatic backup |
| `POST` | `/helper/v1/delete` | Delete an approved file or directory with backup |
| `POST` | `/helper/v1/rename` | Rename an approved file or directory with backup |
| `POST` | `/helper/v1/mkdir` | Create an approved directory |
| `POST` | `/helper/v1/restore` | Restore from an available backup |
| `GET` | `/helper/v1/search` | Search approved file contents |
| `POST` | `/helper/v1/sync` | Last-resort encoded compatibility dispatcher |
| `POST` | `/helper/v1/process` | Compatibility alias for `/sync` |

### Licensed Pro Module - WordPress Operations

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/helper/v1/wp/info` | Extended site information when the Pro module is active |
| `GET` | `/helper/v1/wp/plugins` | Plugin list and activation status |
| `POST` | `/helper/v1/wp/plugin/toggle` | Activate or deactivate a plugin when permitted |
| `GET` | `/helper/v1/wp/options` | Read approved WordPress options |
| `POST` | `/helper/v1/wp/cache-clear` | Clear supported caches and transients |

## Quick Start

1. Install the public `remotewp.zip` Free/Core package in WordPress.
2. Activate RemoteWP and open the RemoteWP settings page.
3. Copy the generated API token and configure the narrowest permission profile required.
4. Connect an AI agent or development tool that can send authenticated HTTP requests.
5. Validate the site status before requesting any write operation.

### Check Plugin Status

```bash
curl -H "X-RemoteWP-Token: YOUR_TOKEN" \
  https://yoursite.com/wp-json/helper/v1/status
```

### List Approved Theme Files

```bash
curl -H "X-RemoteWP-Token: YOUR_TOKEN" \
  "https://yoursite.com/wp-json/helper/v1/list?path=wp-content/themes/mytheme"
```

### Read a Theme File

```bash
curl -H "X-RemoteWP-Token: YOUR_TOKEN" \
  "https://yoursite.com/wp-json/helper/v1/read?path=wp-content/themes/mytheme/style.css"
```

## Video Tutorial: Connect AI Agents to WordPress

Watch the official RemoteWP how-to video to see how Claude, Cursor, Codex and Gemini connect to WordPress through the RemoteWP plugin:

[![How to Connect AI Agents to WordPress with RemoteWP](https://img.youtube.com/vi/98sJw7tmmWQ/hqdefault.jpg)](https://www.youtube.com/watch?v=98sJw7tmmWQ)

[Watch the RemoteWP WordPress AI agent setup tutorial on YouTube](https://www.youtube.com/watch?v=98sJw7tmmWQ)

After watching the tutorial, visit the [RemoteWP GitHub repository](https://github.com/githxhouse/remotewp-plugin) to review the code, open an issue or contribute.

## Example AI Agent Tasks

**WordPress design**

> Inspect the approved active-theme files for CSS related to the mobile product-card spacing problem. Explain the likely cause and show the proposed modification before writing any file.

**WordPress debugging**

> Search approved plugin and theme files for code related to the checkout validation error. Identify the likely cause and wait for approval before changing anything.

**Technical SEO**

> Inspect approved theme and SEO-plugin files for heading, canonical and Schema.org implementation problems. Prepare a report before applying changes.

**Performance**

> Search approved theme and plugin files for scripts and styles that may load globally. Recommend the lowest-risk code-level optimization.

**WooCommerce maintenance**

> Review the approved WooCommerce customization, create a backup and propose the smallest safe change. Do not modify WordPress core or the parent theme.

## AI Agent Integrations

RemoteWP works with AI agents and automation tools that support authenticated HTTP requests with custom headers.

- **Claude, ChatGPT, Gemini and Codex** - use an HTTP-capable connector, action or tool.
- **Cursor and Windsurf** - use a configured script, terminal tool or agent instruction.
- **Custom agents** - call the REST API with any compatible HTTP client.

RemoteWP does not include a native connector for a specific AI platform. The integration method depends on the agent and hosting environment.

## AI Agent Skill Pack

Retrieve the site-specific skill pack with:

```bash
curl -H "X-RemoteWP-Token: YOUR_TOKEN" \
  https://yoursite.com/wp-json/helper/v1/skill
```

Provide the returned instructions to the connected AI agent as a system prompt or custom instruction set.

## Requirements

- WordPress 5.8 or newer
- PHP 7.4 or newer
- HTTPS in production
- A compatible AI agent or HTTP client

## Responsible Use

RemoteWP provides controlled access to supported WordPress files and operations. It does not guarantee that an AI-generated recommendation or code modification is correct.

- Review sensitive changes before execution.
- Use the narrowest permission profile required.
- Keep independent backups before major production changes.
- Prefer staging environments for significant modifications.
- Do not modify WordPress core when a child theme or custom plugin is a safer option.
- Use browser testing, analytics and external SEO data when the task requires them.

## License

GPL-2.0-or-later - [Full License](LICENSE)

## Built By

**[X-HOUSE SRL](https://xhouse.ro)** - Arad, Romania

- [remotewp.dev](https://remotewp.dev)
- info@remotewp.dev
