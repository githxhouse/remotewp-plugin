# RemoteWP V2 Current Handoff

Updated: 2026-08-18 (Release 3.7.11)
Snapshots:
- `C:\Users\Admin\WEB\remotewp-V2`
- `C:\Users\Admin\WEB\remotewp-plugin` (branch: `main`)
- `C:\Users\Admin\Documents\remotewp\private-v2-worktree` (branch: `remotewp-v2`)
- Live Server: `89.42.42.148` (`/srv/nodex/projects/remotewp-license-server`, `/srv/nodex/projects/remotewp`)

---

## 1. Summary of Changes (2026-08-18)

### A. Core / Pro Architecture Synchronization (v3.7.10)
- Fully synchronized all changes made on the live production server (RemoteWP release 3.7.10):
  - Agent memory & skills delivered dynamically from central license server (`skills/remotewp-bridge.md`, `wordpress-woocommerce.md`, `wordpress-elementor.md`, `wordpress-wpbakery.md`, `wordpress-seo.md`).
  - Handoff relay API (`/wp-json/remotewp/v2/handoff-relay`) forwarding agent execution logs securely to central tenant system without exposing master keys.
  - Server-delivered encrypted Pro module (`wp-content/remotewp-pro/module.enc`) via `/wp-json/remotewp-license/v1/pro-module/site-token`.
  - Fix in `server.js` for `licenseId` reference on `/api/free-download` and `/api/license/free-signup`.

### B. WordPress Site Freeze / Worker Pool Starvation Fix (Release v3.7.11)
- **Problem**: When `remotewp.zip` (3.7.10) was activated on WordPress (`drbalas.ro`), `plugins_loaded` triggered synchronous HTTP `wp_remote_post()` requests to `https://remotewp.dev` on every page visit/hit. For non-Pro sites, this caused up to 60 seconds of blocking HTTP requests per request, exhausting PHP-FPM workers within 2 minutes and freezing the site (504 Gateway Timeout).
- **Fix**:
  1. `remotewp.php`: `remotewp_init()` on `plugins_loaded` now only decrypts the cached module in memory (`$loader->has_module()`), with zero blocking HTTP calls on page loads.
  2. `class-remotewp-pro-loader.php`: Added a 12-hour transient cooldown (`remotewp_pro_fetch_cooldown`) preventing repeated network retries on Free/unactivated sites; reduced HTTP timeout from 30s to 8s; blocked background module fetches on public visitor page views (`!is_admin() && !defined('REST_REQUEST')`).
  3. Module fetching is restricted to explicit administrative events (license key activation in WP Admin), background WP Cron, or explicit authenticated REST requests.
  4. Bounded execution to 1 attempt per cycle (removed duplicate consecutive calls).

---

## 2. Repositories & Deployment Status

- **`remotewp-plugin` (`main`)**:
  - Commit: `Release RemoteWP plugin 3.7.11 (fix: non-blocking Pro loader on frontend/pageviews)`
  - Pushed to `https://github.com/githxhouse/remotewp-plugin.git`
- **`private-v2-worktree` (`remotewp-v2`)**:
  - Commit: `fix(plugin): release 3.7.11 to eliminate blocking network requests on plugins_loaded`
  - Pushed to `https://github.com/githxhouse/remotewp.git`
- **Live Production Server (`89.42.42.148`)**:
  - Pushed updated `remotewp.zip` to:
    - `/srv/nodex/projects/remotewp-license-server/dist/remotewp.zip`
    - `/srv/nodex/projects/remotewp/remotewp.zip`
    - `/srv/nodex/projects/remotewp/remotewp-live.zip`
    - `/var/www/remotewp/remotewp.zip`
  - Verified live public download: `https://remotewp.dev/remotewp.zip` delivers v3.7.11 with SHA256 `ca8c2292bae6a3dba7aa2df62f7010dc0c3235234d035e0bc02be344397f830d`.

---

## 3. Test & Verification Matrix

- **PHP 8.3 Syntax Lint**: 100% PASS across all plugin PHP files.
- **`plugin-contract-test.js`**: PASS.
- **`tenant-schema-test.js`**: PASS.
- **`tenant-flow-test.js`**: PASS.
- **`tenant-isolation-test.js`**: PASS.
- **`report-tenant-contract-test.js`**: PASS.
- **`staging-http-test.js`**: PASS.

---

## 4. Operational Guidelines for Future Sessions

- Follow `DEPLOY-RULES.md` for any remote operations on `89.42.42.148` (single SSH connection with reuse, fail2ban awareness, TOTP authentication from `.env.deploy`).
- Do not add synchronous external HTTP requests to `plugins_loaded` or frontend hooks in WordPress.
- Keep `remotewp.zip` as the sole public distribution package; Pro features must remain server-delivered as encrypted modules to domain-bound sites.
