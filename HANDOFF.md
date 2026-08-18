# RemoteWP V2 Current Handoff

**Updated:** 2026-08-18 (Production Release & Server-Delivered Security Fix)  
**Snapshots & Repositories:**
- `C:\Users\Admin\WEB\remotewp-V2`
- `C:\Users\Admin\WEB\remotewp-plugin` (branch: `main`, latest tag: `v3.7.11`)
- `C:\Users\Admin\Documents\remotewp\private-v2-worktree` (branch: `remotewp-v2`, latest tag: `v3.7.11`)
- Live Production Server: `89.42.42.148` (`/srv/nodex/projects/remotewp-license-server`, `/srv/nodex/projects/remotewp`)

---

## 1. Accomplishments & Architecture Evolution (2026-08-18)

### A. Core / Pro Architecture & Live Server Sync
- Synchronized live production server changes:
  - Dynamic skill pack resolution from central license server (`skills/remotewp-bridge.md`, `wordpress-woocommerce.md`, `wordpress-elementor.md`, `wordpress-wpbakery.md`, `wordpress-seo.md`).
  - Handoff relay API (`/wp-json/remotewp/v2/handoff-relay`) forwarding agent execution logs securely to central tenant system without exposing master keys.
  - Server-delivered encrypted Pro module (`wp-content/remotewp-pro/module.enc`) via `/wp-json/remotewp-license/v1/pro-module/site-token`.
  - Fix in `server.js` for `licenseId` reference on `/api/free-download` and `/api/license/free-signup`.

### B. WordPress Site Freeze / Worker Pool Starvation Fix (v3.7.11)
- **Problem**: When `remotewp.zip` was activated on WordPress (`drbalas.ro`), `plugins_loaded` triggered synchronous HTTP `wp_remote_post()` requests to `https://remotewp.dev` on every page visit. For Free/unconnected sites, this caused 30-60s of blocking HTTP calls per hit, exhausting PHP-FPM workers within 2 minutes and causing 504 Gateway Timeouts.
- **Resolution**:
  1. `remotewp.php`: `remotewp_init()` on `plugins_loaded` now only decrypts the cached module in memory (`$loader->has_module()`), with zero blocking HTTP calls on visitor page loads.
  2. `class-remotewp-pro-loader.php`: Added a 12-hour transient cooldown (`remotewp_pro_fetch_cooldown`) preventing repeated network retries on Free/unactivated sites; reduced HTTP timeout from 30s to 8s; blocked background module fetches on public visitor page views (`!is_admin() && !defined('REST_REQUEST')`).
  3. Module fetching is restricted to explicit administrative events (license key activation in WP Admin), background WP Cron, or explicit authenticated REST requests.

### C. Server-Delivered Non-Core Root File Operations (Approval & Backup Workflow)
- **Problem**: When site owners/agents attempted to clean up leftover backup archives in the site root (such as `homedir.tar`, `backup.tar.gz`, `dump.sql`, `.zip`, `.tar`), the local path policy previously returned `403 core_modification_blocked`.
- **Resolution**:
  1. Updated the central server Pro class `server/pro/class-remotewp-fs-api-pro.php` with self-contained `sanitize_pro_path`.
  2. Enabled modification and deletion of non-core root archives and files (e.g. `homedir.tar`, `dump.sql`, `.zip`, `.tar`, `.bak`, `.log`).
  3. **Strict Safety & Audit Flow Enforced**:
     - Operations on sensitive/root files require explicit operator approval (`dangerous_operation_approved: true` + `approval_note`).
     - An automatic pre-operation backup is created before file deletion or modification.
     - An immutable audit log entry is saved (`DELETE_DANGEROUS_APPROVED`, `WRITE_DANGEROUS_APPROVED`).
  4. WordPress core files (`wp-config.php`, `wp-load.php`, `wp-admin/`, `wp-includes/`, and RemoteWP internals) remain strictly protected.
  5. **Server-Authoritative Deployment**: Deployed dynamically to `remotewp-license-server` on `89.42.42.148` (PM2 `remotewp-api` reloaded); connected sites receive the updated Pro module automatically without requiring manual plugin ZIP reinstalls. Verified working on `drbalas.ro`.

---

## 2. Repositories & Deployment Status

- **`remotewp-plugin` (`main`)**:
  - Pushed to `https://github.com/githxhouse/remotewp-plugin.git`
  - GitHub Release published: `v3.7.11` (includes `remotewp.zip`)
- **`private-v2-worktree` (`remotewp-v2`)**:
  - Pushed to `https://github.com/githxhouse/remotewp.git`
  - Tag `v3.7.11` pushed
- **Live Production Server (`89.42.42.148`)**:
  - Pushed updated `remotewp.zip` (v3.7.11) to all server distribution paths:
    - `/srv/nodex/projects/remotewp-license-server/dist/remotewp.zip`
    - `/srv/nodex/projects/remotewp/remotewp.zip`
    - `/srv/nodex/projects/remotewp/remotewp-live.zip`
    - `/var/www/remotewp/remotewp.zip`
  - Verified public download URL `https://remotewp.dev/remotewp.zip` (SHA256: `CA8C2292BAE6A3DBA7AA2DF62F7010DC0C3235234D035E0BC02BE344397F830D`).
  - Updated server-side Pro class `/srv/nodex/projects/remotewp-license-server/pro/class-remotewp-fs-api-pro.php`.

---

## 3. Test & Verification Matrix

- **PHP 8.3 Syntax Lint**: 100% PASS across all plugin and server PHP files.
- **`plugin-contract-test.js`**: ✅ PASS.
- **`tenant-schema-test.js`**: ✅ PASS.
- **`tenant-flow-test.js`**: ✅ PASS.
- **`tenant-isolation-test.js`**: ✅ PASS.
- **`report-tenant-contract-test.js`**: ✅ PASS.
- **`staging-http-test.js`**: ✅ PASS.
- **Live Site Smoke Verification**:
  - `drbalas.ro`: Pro module refresh and non-core root file deletion verified.
  - `remotewp.dev/remotewp.zip`: Delivers clean 3.7.11 package.

---

## 4. Operational Guidelines for Future Sessions

- Follow `DEPLOY-RULES.md` for any remote operations on `89.42.42.148` (single SSH connection with reuse, fail2ban awareness, TOTP authentication from `.env.deploy`).
- Never perform synchronous external HTTP requests on `plugins_loaded` or visitor pageviews in WordPress.
- Keep `remotewp.zip` as the sole public distribution package; Pro features, path policies, and agent instructions must remain server-delivered as encrypted modules to domain-bound sites.
