# Findings — Installing & Running the Audit Logging Docker Package

> **Date:** 2026-06-22
> **Tested on:** Windows 11 + Docker Desktop (Engine 29.5.3, Compose v5.1.4)
> **Question asked:** "Can this Docker package be installed and run?"
> **Short answer:** **Yes — it builds and runs**, but it shipped with **4 bugs** that
> had to be fixed first, and then hit **2 server-side issues** on the shared
> OpenSearch host (`10.0.2.30`) that are **not** the package's fault.

---

## TL;DR

- ✅ Images build, containers start.
- ✅ After 4 fixes, the **dev** app serves a CakePHP 4.6.4 page at `http://localhost:8081`,
  and Fluent Bit tails → parses → enriches → ships the log toward `10.0.2.30`.
- ⛔ Audit logs do **not** land in OpenSearch — blocked entirely on the server side:
  the OpenSearch node is **out of disk (95.71% used)** so the whole cluster is in
  read-only mode, and `action.auto_create_index` does not permit `audit-*` indices.

---

## Environment / what was set up

Followed the **manual** install path from `INSTALL_GUIDE.md` (the bundled
`install.sh` is an interactive Linux script and does not run cleanly on Windows):

1. Created `.env` with the documented defaults (DB + OpenSearch at `10.0.2.30`).
2. Created `logs/{dev,staging,prod}` and `config/{dev,staging,prod}`.
3. `docker compose build app-dev`
4. `docker compose up -d app-dev fluentbit-dev`

Only the **dev** environment was started (staging/prod on 8082/8083 were left down).

Network precheck: `10.0.2.30` is reachable from this machine and OpenSearch there
responds `HTTP 200`, so the network dependency is satisfied.

---

## Bugs found in the package (fixed)

| # | Symptom | Root cause | File changed | Fix applied |
|---|---------|-----------|--------------|-------------|
| 1 | App returns **HTTP 404** | Apache `DocumentRoot=/var/www/html/webroot`, but compose mounted `./app`; the real CakePHP root (with `webroot/`, `vendor/`, `composer.json`) is one level deeper in `./app/sample-logging-app/`. So `/var/www/html/webroot` did not exist. | `docker-compose.yml` (all 3 app services) | Changed mount `./app:/var/www/html` → `./app/sample-logging-app:/var/www/html` |
| 2 | Fluent Bit: *"parser 'json' is not registered"*, input init fails | `fluent-bit.conf` references `Parser json` / `apache_error` but the `[SERVICE]` block never loads `Parsers_File parsers.conf` (the parsers are defined in `parsers.conf` but not loaded). | `fluent-bit/fluent-bit.conf` | Added `Parsers_File parsers.conf` to `[SERVICE]` |
| 3 | Fluent Bit: *"cannot open database /logs/..db"*, input init fails | Each `tail` input writes its position DB to `/logs/...db`, but the `./logs/<env>:/logs` volume is mounted **read-only** (`:ro`), so the SQLite DB cannot be created. | `fluent-bit/fluent-bit.conf` | Pointed the 3 `DB` paths to a writable location: `/logs/...db` → `/tmp/...db` |
| 4 | CakePHP **fatal error** (HTTP 500): *"Composer dependencies require PHP >= 8.2.0. You are running 8.1.34"* | `Dockerfile` base image is `php:8.1-apache`, but the app's `composer.lock` requires **PHP ≥ 8.2**. | `Dockerfile` | `FROM php:8.1-apache` → `FROM php:8.2-apache` (image rebuilt) |

**Result after fixes:** `http://localhost:8081` returns **HTTP 200** (CakePHP 4.6.4
welcome page), and Fluent Bit starts cleanly — all 3 tail inputs and all `es`
outputs initialize, and processed records are correctly enriched, e.g.:

```json
{"action":"student.import","entity":"student","actor_id":456,
 "environment":"dev","system":"cakephp-audit","log_type":"audit", ...}
```

---

## Server-side blockers (NOT the package — on `10.0.2.30`)

The host is `rgs-node-1` / cluster `rgs-logging-cluster`, OpenSearch reporting
Elasticsearch-compat **v7.10.2**.

### A. Disk full → cluster read-only (hard blocker)

Fluent Bit's `es` output kept failing to flush. With `Trace_Error On`, OpenSearch's
actual response was:

```
status 429 cluster_block_exception:
index [audit-dev-2026.06.22] blocked by:
[TOO_MANY_REQUESTS/12/disk usage exceeded flood-stage watermark,
 index has read-only-allow-delete block]
```

Confirmed via `_cat/allocation` / `_cat/nodes`:

```
node         disk.used_percent   disk.avail
rgs-node-1   95.71               4gb   (of 94.9gb total)
```

At the ~95% **flood-stage watermark**, OpenSearch sets
`index.blocks.read_only_allow_delete: true` on indices cluster-wide
(`logs-rgs`, `logs-remote`, `audit-dev-2026.06.22`, system indices, …).
**No writes from any client succeed** until disk is freed. This is a live
problem for everything that writes to this server, not just this package.

### B. `action.auto_create_index` does not allow `audit-*`

Before the disk issue surfaced, a direct write returned:

```
index_not_found_exception ... and [action.auto_create_index]
([.monitoring*,.watches,.triggered_watches,.watcher-history*,.ml*,logs-*])
doesn't match
```

So even with disk free, dated audit indices (`audit-dev-YYYY.MM.DD`) won't be
auto-created. `logs-*` is allowed, but `audit-*` and `app-*` are not.
(An empty `audit-dev-2026.06.22` index was created manually during testing to
isolate this from issue A.)

---

## What the server admin needs to do (their call — shared infra)

1. **Free disk space** on `10.0.2.30` (delete old indices or add disk) — get below ~85%.
2. **Clear the read-only block** once space is freed:
   ```
   PUT _all/_settings
   { "index.blocks.read_only_allow_delete": null }
   ```
3. **Permit audit indices to auto-create:**
   ```
   PUT _cluster/settings
   { "persistent": { "action.auto_create_index":
     "audit-*,app-*,logs-*,.monitoring*,.watches,.triggered_watches,.watcher-history*,.ml*" } }
   ```

After that, **no further package changes are needed** — Fluent Bit (running with
retry/backoff) will flush the queued records automatically.

---

## Bug #5 — App could not connect to a database (fixed locally via XAMPP MySQL)

**Symptom:** the dev app's home page showed *"CakePHP is NOT able to connect to
the database — SQLSTATE[HY000] [2002] No such file or directory"*, and the
data pages `/products` and `/users` returned **HTTP 500**.

**Root cause:** the app's `config/app_local.php` has the database connection
**hardcoded to `host => 'localhost'`** and does **not** read the `DB_HOST` env
var that `docker-compose.yml` passes in. Inside the container, `localhost` means
the container itself (no MySQL there) → the `[2002]` socket error. (The compose
mounts `./config/dev` into a non-standard `config/local` folder, not CakePHP's
`config/app_local.php`, so the env-based config never takes effect.)

**Fix (local, using the host's XAMPP MySQL/MariaDB):**

1. Started XAMPP **Apache + MySQL** on the host. Verified MariaDB 12.2.2 listens
   on `0.0.0.0:3306` (its `bind-address` is commented out), so a container can reach it.
2. Created the database and a **container-reachable** user:
   ```sql
   CREATE DATABASE IF NOT EXISTS sample_logging_db
     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER IF NOT EXISTS 'cakeuser'@'%' IDENTIFIED BY 'cakepass';
   GRANT ALL PRIVILEGES ON sample_logging_db.* TO 'cakeuser'@'%';
   FLUSH PRIVILEGES;
   ```
   (The `@'%'` host is required — the connection comes from the Docker container,
   not `localhost`.)
3. Pointed the app at the host's MySQL — in
   `app/sample-logging-app/config/app_local.php`, changed the **default**
   datasource only:
   ```
   'host' => 'localhost',   →   'host' => 'host.docker.internal',
   ```
   (`host.docker.internal` resolves to the host from a Docker Desktop container;
   confirmed it maps to `192.168.65.254`.)
4. Ran the migrations inside the container to build the tables:
   ```bash
   docker compose exec app-dev bash -c "cd /var/www/html && bin/cake migrations migrate"
   ```
   Created `users`, `products`, `audit_logs` (+ `phinxlog`).

**Result:** home page now reports *"CakePHP is able to connect to the database"*,
and **`/`, `/products`, `/users` all return HTTP 200**. CRUD actions work and
generate audit records locally.

> Note: this connects the app to a **local** MySQL for development/demo. To use
> the central MySQL at `10.0.2.30` instead, set the default `host` accordingly
> (and ensure that server is reachable with the `cakeuser`/`cakepass` credentials).
> Audit events generated by CRUD actions still won't reach OpenSearch until the
> server-side disk/block issue above is resolved.

---

## How to reproduce / verify locally

```bash
# from the package root
docker compose up -d app-dev fluentbit-dev

# app should return HTTP 200
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8081/

# generate a test audit event (LF-terminated JSON line)
printf '{"action":"test","entity":"student","actor_id":1}\n' >> logs/dev/audit.json

# watch Fluent Bit process & attempt to ship it
docker compose logs -f fluentbit-dev
```

Until the server-side disk/block issue is resolved, the last step will show
`429 cluster_block_exception` retries — expected, and not a package fault.

---

## Notes

- Changes in this report were applied to: `docker-compose.yml`, `Dockerfile`,
  `fluent-bit/fluent-bit.conf`, and `app/sample-logging-app/config/app_local.php`
  (default DB host → `host.docker.internal`). Temporary debug tracing was reverted; configs are clean.
- The shared OpenSearch cluster was **not** modified, except creating one empty
  `audit-dev-2026.06.22` index (with prior approval) to isolate issues A vs B.
- Tested on Windows; the `:ro` log mount + `/tmp` DB fix (#3) and the parser fix
  (#2) are platform-independent and would have failed on Linux too.
