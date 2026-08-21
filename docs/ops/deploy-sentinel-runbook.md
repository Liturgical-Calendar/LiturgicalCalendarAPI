# Deploy sentinel: php-fpm reload and WebSocket restart

How a deploy to `catholicdigitalcommons.org` takes effect, why it needs a root-owned
helper, and the trap that made an entire protocol change land on disk and do nothing.

## The problem it solves

The deploy user is chrooted and cannot `sudo`. But two things have to happen at root
after files land:

- **php-fpm must be reloaded.** Workers cache compiled PHP and, more stubbornly, gettext
  `.mo` translations. Without a reload, new translations do not appear.
- **The WebSocket server must be restarted.** `public/LitCalTestServer.php` is a
  long-running ReactPHP process. It loads `src/` into memory once, at start, and never
  re-reads it.

So the deploy drops a sentinel file and a root-owned systemd path unit does the rest.

## The pieces

| file                                       | installed at                                    | role                                    |
|--------------------------------------------|-------------------------------------------------|-----------------------------------------|
| `deploy/systemd/litcal-fpm-reload.path`    | `/etc/systemd/system/litcal-fpm-reload.path`    | watches the four sentinels              |
| `deploy/systemd/litcal-fpm-reload.service` | `/etc/systemd/system/litcal-fpm-reload.service` | oneshot, runs the script                |
| `deploy/sbin/litcal-fpm-reload.sh`         | `/usr/local/sbin/litcal-fpm-reload.sh`          | reloads php-fpm, restarts the WS server |
| `deploy/systemd/litcal-websocket.service`  | `/etc/systemd/system/litcal-websocket.service`  | the WebSocket server itself             |

The watched sentinels, one per deployed app:

```text
${FRONTEND_STAGING_ROOT}/tmp/restart.txt   frontend, staging
${FRONTEND_ROOT}/tmp/restart.txt           frontend, production
${API_ROOT}/tmp/restart.txt                the API  ← also restarts the WebSocket server
${TESTS_ROOT}/tmp/restart.txt              the test interface
```

Those variables come from `/etc/litcal-deploy.env`, which is **not** in this repository.
See "Configuration" below.

## The trap: a deploy used to update the API and not the WebSocket endpoint

Until 2026-08-21 the script only reloaded php-fpm. Nothing restarted
`litcal-websocket.service` — not the deploy, not anything else — so it kept serving
whatever it loaded at boot.

Measured on 2026-08-21, before the fix:

|                                  |                                             |
|----------------------------------|---------------------------------------------|
| `api/dev/src/Health.php` on disk | modified 07:33 UTC that morning             |
| `litcal-websocket` process       | started 2026-08-20 06:31 UTC, `NRestarts=0` |

The REST API was current and the WebSocket endpoint was **25 hours stale**. All of
API#806 section G — a new message contract, schema validation, typed error frames — was
on disk and inert. `git pull` genuinely deploys only half of any change that touches
`src/Health.php`.

The same shape bites locally: `composer start` re-reads source per request, `composer
ws:start` does not. After changing `Health.php`, restart the WebSocket server or you are
testing the old one.

## What the script does now

1. Records whether the **api/dev** sentinel is the one that fired, before doing anything
   that clears sentinels.
2. `systemctl reload plesk-php84-fpm` — for any sentinel. One reload covers all pools.
3. If api/dev deployed: `systemctl restart litcal-websocket.service`.
4. **Waits 8 seconds and compares `NRestarts`.** The unit is `Restart=always`, so a
   process that dies at startup does not report failure — it respawns every 5 seconds
   until somebody reads the journal. Comparing the counter turns a silent crash-loop
   into a logged error at deploy time.
5. Clears the sentinels only on success, so a failure retries on the next deploy.

Step 4 exists because of a real near-miss. PR #828 introduced a fatal in
`Health::__construct()` — the WebSocket server could not start at all. It stayed up only
because nothing ever restarted it. Had a restart happened in that window, the unit would
have crash-looped every 5 seconds indefinitely, with no failed state to alert on. #829
fixed the fatal; this check is what would have caught it.

## Configuration

**This repository is public, so it contains no real path, account or unit name.** The
units are templates (`deploy/systemd/*.in`) with `@PLACEHOLDER@` tokens, and the reload
script reads everything from a config file that lives only on the host:

```bash
sudo install -m 0640 -o root -g root deploy/litcal-deploy.env.example /etc/litcal-deploy.env
sudo editor /etc/litcal-deploy.env
```

It defines `API_ROOT`, the other app roots (any of which may be empty to drop it from the
watch list), `PHP_BIN`, `RUN_USER`, `RUN_GROUP`, `FPM_UNIT` and `WS_UNIT`. The script
refuses to run if the file is missing rather than guessing.

Keeping the values out of the repository is the same rule the WebSocket frames now
follow: #827 stripped the server's filesystem root from every frame precisely so an
unauthenticated client could not learn the deployment layout. Committing that layout to a
public repo would have handed it over by another route.

## Installing or updating

```bash
sudo deploy/install.sh
```

`install.sh` renders `deploy/systemd/*.in` against `/etc/litcal-deploy.env`, writes the
units, installs the script, and enables both units.

## Verifying

```bash
systemctl status litcal-fpm-reload.path        # expect: active (waiting)
systemctl status litcal-websocket.service      # expect: active (running)

# Is the running process older than the code it is supposed to be running?
systemctl show litcal-websocket -p ActiveEnterTimestamp -p NRestarts
stat -c '%y %n' "$API_ROOT/src/Health.php"

# Exercise the deploy path end to end
touch "$API_ROOT/tmp/restart.txt"
journalctl -t litcal-fpm-reload -n 20
```

A healthy API deploy logs: `sentinel seen … (api=1)`, `restarting
litcal-websocket.service`, `restarted and stable`, `reload OK; sentinels cleared`.

## Troubleshooting

**`… is CRASH-LOOPING after deploy`** — the newly deployed code cannot construct a
`Health`. `journalctl -u litcal-websocket -n 50` shows the fatal. The sentinel is kept,
so the next deploy retries. Roll back or fix forward; the endpoint is down until then.

**`reload FAILED; sentinels kept for retry`** — php-fpm did not reload; the WebSocket
restart is skipped deliberately, since a broken deploy should not also drop connections.

**Sentinel present but nothing in the journal** — the path unit is not running
(`systemctl status litcal-fpm-reload.path`), or the deploy wrote to a path not in the
watch list. `PathChanged` only fires on modification, so a sentinel left behind by an
earlier failure does not retrigger until it is touched again.

**The WebSocket endpoint answers, but with the old protocol** — the process is stale.
Confirm with the timestamp check above, then `sudo systemctl restart litcal-websocket`.
If a deploy should have done it, check `api=1` appeared in the log line.
