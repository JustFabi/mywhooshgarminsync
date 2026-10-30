# MyWhooshSync

A Laravel + Vue web application that syncs your **MyWhoosh** indoor-cycling activities to **Garmin Connect** — with clean, fixed FIT files and a selectable spoofed Garmin watch.

This is a web re-implementation of [`jdelrue/mywoosh2garmin`](https://github.com/jdelrue/mywoosh2garmin) (a Go desktop tool): instead of a GUI binary you get a self-hosted webapp where you connect both services once, pick any Garmin watch from a catalog, and sync every ride right from your browser.

---

## What it does

- **Connects to MyWhoosh** using the same API the MyWhoosh Android app uses (login → paginated activity list → FIT download through presigned S3 URLs).
- **Connects to Garmin Connect** by performing the Garmin SSO login an Android Garmin Connect Mobile client would perform (embed → signin → CSRF → ticket → preauthorized → OAuth token exchange) and keeps the resulting **OAuth1/OAuth2 tokens** encrypted at rest.
- **Downloads the original FIT** for every activity and re-encodes it with all fixes below, touching nothing else byte-for-byte.
- **Uploads** the fixed FIT to Garmin Connect via `upload-service`; a `409` duplicate response counts as success *(already uploaded → marked synced)*.
- **Per-user credentials & watch choice**: every user has their own MyWhoosh/Garmin connections and their own spoof watch selection.

### FIT fixes applied (same as the original tool)

| Fix | Detail |
| --- | --- |
| Session averages | Sets **average power (field 20), average HR (16), average cadence (18)** on every session message from computed over the record messages |
| Temperature stripping | Removes the temperature field from `record` messages (native sensors report ambient indoor temps) |
| Device spoofing | Rewrites `file_id` and `device_info` messages so the file looks like it came from a real Garmin watch: **manufacturer = 1 (Garmin), product id = chosen watch, serial = 3420897194** |

Unsupported/unparseable frames are re-emitted verbatim, so parsing is lossless: round-trip tests assert byte-identical output for the bundled fixtures.

---

## Watch spoof catalog

Garmin Connect validates the `product` id inside the FIT, which is what decides the device shown on your activity page. MyWhooshSync ships a catalog of **36 real Garmin watches** (Fenix, Edge, Forerunner, Venu, Epix, Descent, Instinct and friends) — pick whatever you like on the *Watches* page and every subsequent upload claims to come from that watch. The default (as in the original tool) is **Garmin Fenix 6S Pro (product 3288)**.

The catalog lives in `app/Support/GarminDevices.php` and is seeded into the `devices` table by `GarminDeviceSeeder`.

---

## Installation

Requirements: PHP **>= 8.2**, Composer, Node.js >= 20. Any Laravel-supported database works; the app defaults to SQLite.

```bash
git clone <this-repo> mywhooshsync
cd mywhooshsync

composer install
npm install

cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # if using the SQLite default

php artisan migrate --seed       # Garmin device catalog

npm run build                    # build the Vue frontend (or `npm run dev` while developing)
php artisan serve                # http://127.0.0.1:8000
```

Or run everything at once: `composer run dev` (server + queue/logs + Vite dev server).

There is **no authentication**: whoever reaches the app shares one implicit
local workspace (single `LocalUser` row). Because of that, the webapp is
meant for a private server / home network — nothing else guards it.

---

## Usage

1. **Connections** — enter your MyWhoosh credentials and your Garmin Connect email/password. Ticking *remember password* stores the password AES-encrypted (Laravel app key) on this server so tokens can be refreshed automatically later; otherwise the connection is one-shot. If Garmin login fails with an MFA error, disable 2FA for that account (SSO-with-MFA is not supported).
2. **Watches** — pick the Garmin watch that uploads should claim to come from. Defaults to Fenix 6S Pro.
3. **Activities** —
   - *Fetch from MyWhoosh*: imports headers for the last *N* days from MyWhoosh.
   - *Sync all* / the per-row **Sync** action: for each pending activity the app downloads the FIT, applies the fixes, uploads it to Garmin, and marks the activity **Synced**. If a fixed FIT already exists on disk it is reused rather than rebuilt.
   - Per-row extras: **Fix** (build/refresh the fixed FIT without uploading), **Download** (fetch the fixed FIT locally), **Delete**.
   - The frontend drives uploads one-by-one with plain `fetch` calls, so PHP timeouts on long lists are not an issue.

### Command line

The same flow is available headless:

```bash
# Everything for a user (use the account's email), last 14 days, chosen default watch
php artisan mywhoosh:sync --days=14 --watch="fenix 7"

# Only import headers / only upload pending
php artisan mywhoosh:sync --fetch-only
php artisan mywhoosh:sync --upload-only
```

`--watch` matches a catalog entry by name fragment and is remembered per user (same as picking it in the UI).

### Automatic sync from cron (`mywhoosh:cron`)

For unattended runs there is `php artisan mywhoosh:cron`: it reads the
service credentials from environment values, establishes/freshens both
sessions as needed, imports new MyWhoosh activities (already-known ids are
tracked and skipped) and uploads only what is not synced yet. Nothing else
touches the watch selection: pass it with `--watch="<name fragment>"
(default: the watch last picked in the UI → Fenix 6S Pro).

```bash
# one-shot: credentials from Laravel's .env / real environment
php artisan mywhoosh:cron

# credentials from a dedicated file, looks 14 days back, spoofs a Fenix 7
php artisan mywhoosh:cron --env-file=/etc/mywhoosh-sync.env --days=14 --watch="fenix 7"

# import without upload / upload without import
php artisan mywhoosh:cron --fetch-only
php artisan mywhoosh:cron --upload-only
```

Credential values (all optional — set only the side you want to automate):

| Env value | Meaning |
| --- | --- |
| `MYWHOOSH_EMAIL`, `MYWHOOSH_PASSWORD` | MyWhoosh login; used to log in when no session is stored, when the stored account differs, or when a stored session is rejected. Remembered (encrypted) for transparent re-logins. |
| `GARMIN_EMAIL`, `GARMIN_PASSWORD` | Garmin Connect login; establishes DI OAuth2 tokens when needed, refreshes them via the stored refresh token, and re-logs-in once on a rejected session. |

Example `mywhoosh-sync.env` (same syntax as `.env`):

```env
MYWHOOSH_EMAIL=rider@example.com
MYWHOOSH_PASSWORD="secret with spaces"
GARMIN_EMAIL=you@example.com
GARMIN_PASSWORD=garmin-secret
```

Crontab example — every 30 minutes, logging to the app's log directory:

```cron
*/30 * * * * cd /srv/mywhooshsync && php artisan mywhoosh:cron >> storage/logs/cron-sync.log 2>&1
```

Exit code is `0` unless the configuration is incomplete, the MyWhoosh import
fails, or *every* pending upload fails; individual retries happen on the next
run (activities keep their pending/failed status).

Fixed FIT files are kept on local disk at `storage/app/fit/{user_id}/{activity_id}.fit`.

---

## Architecture

```
app/
  Services/
    Fit/            Pure-PHP FIT parser/fixer/encoder (ported from muktihari/fit)
    MyWhoosh/       Login, activity listing, presigned FIT download
    Garmin/         SSO login, OAuth1/2 token store, upload client
    Sync/           ActivitySyncService: download → fix → upload façade
  Support/          GarminDevices catalog, FIT constants
  Http/Controllers/ Dashboard, Connections, Watches, Activities
resources/js/       Inertia (Vue 3) pages: Dashboard, Activities, Watches, Connections
```

- Authentication: the Laravel starter-kit session auth; the app is a standard **Inertia + Vue 3** SPA.
- FIT handling is fully type-scale-safe: definitions are re-emitted, counters/accumulators recomputed, CRCs rewritten; chained `.FIT` segments are parsed as separate segments.
- OAuth1 request signing (SSO + upload) follows RFC 5849 and mirrors the behavior of Go's `dghubble/oauth1` (only `oauth_*` params in the Authorization header, signature still covering query params). Service keys come from Garmin's public `oauth_consumer.json` and never change.

## Tests

```bash
php artisan test
```

Covers the FIT codec round-trips + fixer behavior (against bundled fixtures, including a chained multi-segment file), OAuth1 signature vectors cross-verified with Node's `crypto`, and the device catalog seeding.

A standalone smoke script over the biggest fixture:

```bash
php -d memory_limit=2G tests/scripts/fit_smoke.php
```

## Credits & disclaimer

- [mywoosh2garmin](https://github.com/jdelrue/mywoosh2garmin) — the original Go implementation whose protocol details this app reproduces.
- [muktihari/fit](https://github.com/muktihari/fit) — the Go FIT library the PHP codec was ported from.
- This project is not affiliated with MyWhoosh or Garmin. Credentials you enter are stored only on your own server (passwords optionally, AES-encrypted with your `APP_KEY`). Use at your own risk.
