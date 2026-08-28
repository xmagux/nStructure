# Bare-metal deployment

nStructure runs as a conventional PHP application. Docker, Laravel, and a
production Node.js process are not required.

## Recommended server

- Debian 13 or Ubuntu Server 24.04 LTS
- Nginx
- PHP 8.4 FPM with `curl`, `mbstring`, `openssl`, `pdo_mysql`, `sodium`, and
  `zip`
- MySQL 8.4 LTS
- Composer 2

For a small internal installation, 2 CPU cores, 4 GB RAM, and 20 GB SSD are a
practical starting point. Increase storage according to backup retention and
future attachment requirements.

## Application installation

```bash
sudo mkdir -p /var/www/nstructure
sudo chown "$USER":www-data /var/www/nstructure
cd /var/www/nstructure

# Copy or clone the repository here, then install production dependencies.
composer install --no-dev --classmap-authoritative
cp .env.example .env
```

Set the production URL, `APP_DEMO_MODE=false`, and database credentials in
`.env`. Use a dedicated database account with access only to the nStructure
database.

```bash
php bin/migrate.php
php bin/create-user.php you@example.com "Your Name" "a-strong-password"
```

`php bin/migrate.php` applies every pending migration in
`database/migrations/` in order and is safe to re-run. There is no
self-service registration, so the first login account must be created with
`bin/create-user.php`; anyone already logged in can create further accounts
from the **Account** page afterwards.

Only run `php bin/seed.php` if you want demonstration data — skip it for an
empty production database.

## Nginx and PHP-FPM

Copy `deploy/nginx.conf` to `/etc/nginx/sites-available/nstructure`, replace
the example hostname and adjust the PHP-FPM socket if needed.

```bash
sudo ln -s /etc/nginx/sites-available/nstructure /etc/nginx/sites-enabled/nstructure
sudo nginx -t
sudo systemctl reload nginx
```

The web-server document root must point to `/var/www/nstructure/public`; the
project root must never be exposed directly.

## File permissions

Sessions use PHP's configured session handler, so source files can stay
read-only for the web-server user. The exception is `storage/uploads/`,
which must be writable by the PHP-FPM service account — location, server
room, rack, panel, and cable photos are saved there. Ensure PHP's session
directory is writable by the PHP-FPM service account as well.

## TLS

Expose production installations only over HTTPS. A certificate can be managed
with the organization's reverse proxy or Certbot. After TLS is enabled, set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://nstructure.example.com
```

The session cookie is marked `Secure` automatically whenever the request
arrives over HTTPS (directly, or via `X-Forwarded-Proto` behind a reverse
proxy) — no separate setting is needed.

## Updating

Back up the MySQL database before every update. Then place the application in
the organization's maintenance window, deploy the new files, and run:

```bash
composer install --no-dev --classmap-authoritative
php bin/migrate.php
sudo systemctl reload php8.4-fpm
```

Database migrations are tracked and applied only once.

## Environmental sensors (optional)

nStructure can read temperature, humidity, and reachability from
SNMP-capable sensors (HWgroup HWg-STE, STE2 Lite, and similar devices). The
page lives at `/tools/sensors` and appears in the main navigation once
logged in. It's excluded entirely in demo mode — both the nav link and the
routes — since it lets a logged-in user make the server issue SNMP and ping
traffic to any host they configure.

History is collected by a standalone daemon (`bin/sensors-daemon.php`) and
stored in [VictoriaMetrics](https://victoriametrics.com/), a single-node
time-series database (Apache-2.0). Charts on the page are rendered with
[Apache ECharts](https://echarts.apache.org/) (Apache-2.0, vendored as a
static file under `public/assets/vendor/`). The list/map views' "poll now"
button still talks to sensors directly and does not touch VictoriaMetrics —
only the daemon writes history. Both are optional: without them, the page
still works for live SNMP/ping checks, but the Wykresy (charts) tab has
nothing to show and reports the metrics backend as unreachable.

This module needs three things installed that a bare nStructure checkout
does not require: the `php-snmp` extension, the `fping` binary, and a
running VictoriaMetrics instance. All are free to install and license-clear
for commercial use.

### Automated install

`deploy/install.sh` installs and configures all three in one idempotent
run — safe to re-run any time (e.g. after pulling an update that changes
one of the systemd units). From the deployed application directory, as
root:

```bash
sudo bash deploy/install.sh
```

It installs `php-snmp` and `fping` via `apt`, creates the unprivileged
`victoriametrics` system account and data directory, downloads the latest
VictoriaMetrics release binary if one isn't already installed, deploys both
systemd units from `deploy/`, and enables/restarts both services. The
sections below explain what each step does and how to run them by hand if
you'd rather not run a root script from the repo blind.

### 1. php-snmp and fping

```bash
sudo apt install php8.4-snmp fping   # adjust the PHP version suffix to match your install
sudo systemctl restart php8.4-fpm    # only needed if php-fpm also loads snmp (it doesn't have to)
```

`fping` needs to open raw ICMP sockets. The collector daemon's systemd unit
(`deploy/nstructure-sensors-daemon.service`) already grants this via
`AmbientCapabilities=CAP_NET_RAW`, so **no `setcap` step on the `fping`
binary itself is needed** — and using `setcap` alone would not work anyway,
since the unit also sets `NoNewPrivileges=true`, which makes the kernel
ignore file capabilities on anything the service execs. Ambient
capabilities are the mechanism designed to coexist with
`NoNewPrivileges`, which is why the unit grants it that way instead.

### 2. VictoriaMetrics

Single static Go binary, no Docker required. `deploy/install.sh` downloads
the latest release automatically; to do it by hand instead, get the
appropriate archive for your architecture from the
[VictoriaMetrics releases page](https://github.com/VictoriaMetrics/VictoriaMetrics/releases)
and install it as its own unprivileged user:

```bash
sudo useradd --system --no-create-home --shell /usr/sbin/nologin victoriametrics
sudo mkdir -p /var/lib/victoria-metrics-data
sudo chown victoriametrics:victoriametrics /var/lib/victoria-metrics-data

# extract the downloaded release tarball, then:
sudo install -o root -g root -m 755 victoria-metrics-prod /usr/local/bin/victoria-metrics-prod
```

Deploy `deploy/victoriametrics.service` to `/etc/systemd/system/` and enable it:

```bash
sudo cp deploy/victoriametrics.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now victoriametrics
```

The unit starts VictoriaMetrics with:

- `-httpListenAddr=127.0.0.1:8428` — loopback only, never exposed publicly.
  nStructure's own API proxies chart queries to it (see below); nothing
  external ever talks to VictoriaMetrics directly.
- `-storageDataPath=/var/lib/victoria-metrics-data` — where series data lives.
- `-retentionPeriod=3` — keep 3 months of history, then auto-expire.

Adjust `-retentionPeriod` to taste (e.g. `12` for a year); disk usage below
scales roughly linearly with it.

**Disk usage estimate.** VictoriaMetrics typically costs ~1-2 bytes per
raw sample once compressed. With delta-on-change + hysteresis dedup, a
sensor sitting at a stable reading produces far fewer samples than its
polling interval would suggest — in practice budget for one sample every
few minutes per series even at a 5-minute poll interval, since keepalive
forces at least one point per hour regardless. For 20 sensors, each with 6
series (temperature, temperature probe_up, humidity, humidity probe_up,
ping up, ping latency), at a worst case of one sample/minute/series:

```
20 sensors x 6 series x 1 sample/min x 60 x 24 x 90 days x ~2 bytes
≈ 20 x 6 x 129,600 x 2 bytes ≈ 31 MB for 90 days of retention
```

Even a pessimistic estimate stays in the tens of megabytes for a
few dozen sensors over a few months — this module does not need disk
planning beyond "make sure a few hundred MB are free."

### 3. The collector daemon

`bin/sensors-daemon.php` is a long-running PHP CLI process, not a cron job:
it loops internally with a drift-free one-second tick, checks each sensor's
own due time (300s by default, 5s while someone is actively viewing its
chart), batches all due hosts into a single `fping` call per tick, reads
SNMP via `snmp2_get()`, and writes to VictoriaMetrics as one batched HTTP
POST per tick using the Influx line protocol. It exits cleanly with
`exit(0)` after `SENSOR_DAEMON_MAX_ITERATIONS` ticks (1000 by default,
roughly 15-20 minutes) so systemd's `Restart=always` periodically restarts
it — this bounds any per-process resource growth without needing cron.

`deploy/install.sh` deploys this unit too (see above). By hand:

```bash
sudo cp deploy/nstructure-sensors-daemon.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now nstructure-sensors-daemon
```

After deploying a code update that touches the daemon or either systemd
unit, re-run `sudo bash deploy/install.sh` (or just `daemon-reload` +
`restart` by hand) — the daemon does not pick up code changes to itself
until it restarts, since it's a single long-running process rather than a
per-request script.

Tune the daemon's polling interval, hysteresis thresholds, and keepalive via
the `SENSOR_DAEMON_*` variables in `.env` — see `.env.example` for the full
list and defaults. All are optional; the daemon runs correctly with none of
them set.

To confirm it's running and writing data:

```bash
sudo systemctl status nstructure-sensors-daemon
sudo journalctl -u nstructure-sensors-daemon -f
curl -s 'http://127.0.0.1:8428/api/v1/query?query=up' | head -c 300
```

The `/tools/sensors` page's Wykresy tab shows a warning banner if
VictoriaMetrics is unreachable (checked via `/api/v1/sensors/metrics-status`)
without breaking the rest of the page.

## Verification

```bash
curl --fail https://nstructure.example.com/api/v1/health
```

The endpoint should return a JSON response with `status` set to `ok`.
