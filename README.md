# NYS — Network Management System

**English** · [Türkçe](README.tr.md)

A panel that inventories and measures Juniper (JunOS) switches and **writes to
the device over NETCONF**. It also carries a server inventory, IP address
management (IPAM), prefix announcement requests, remote sessions (SSH/RDP) and
an audit log.

Sibling projects: EYS (password vault) and the Identity Management Center
(identity provider).

What is supported, and **to what extent**: [`DESTEK.md`](DESTEK.md) (Turkish).

## The order of a device write

```
request → validation (synchronous)
     → is there a second write to the same record?   → if so, rejected
     → write job record (queued)
     ──────────────────────────────────────────────
     session 1: lock-configuration
                compare rollback 0   → must be EMPTY
                load-configuration   (merge / override / set)
                compare rollback 0   → if the diff is EMPTY no commit is made
                commit confirmed <N minutes>
                unlock
     session 2: lock; commit; unlock          ← confirmation
     ──────────────────────────────────────────────
     → the diff accepted by the device is written to the audit log
```

**The revertible commit** is the safety net: if the second session does not
confirm, the device rolls itself back. The lock covers loading, the commit and
the confirmation — on Junos, while a `commit confirmed` is pending, anyone
else's commit would confirm it as well. No separate `commit check` is issued: on
ELS it can take more than ten seconds and `confirmed` performs the same
validation anyway.

## Two JunOS dialects

Old (pre-ELS) and new (ELS) devices say the same thing differently: is the L3
interface `vlan` or `irb`, how speed values are written, what the analyzer root
is. The panel keeps this in one place and learns it with a **schema probe**: a
test payload is loaded into a private candidate configuration; a syntax error
means ELS, success means pre-ELS. The probe never commits. It does not make up
models it does not know; the screen says "Bilinmiyor — ELS öncesi varsayılıyor"
(unknown — assuming pre-ELS).

Device lines the panel does not model are **preserved**: the unreadable part is
kept as `residual_xml` and written back. The panel does not delete what it does
not understand.

## Measurement

Three sources: **Zabbix** (speed, duplex, live traffic, CPU/memory/temperature,
history), **NETCONF** (interface list and configuration — the actual truth),
**SNMP** (IP addresses, commit history — fallback).

- **Circuit breaker:** a failing source is not tried for a while; without it,
  every page would freeze for the full timeout during an outage.
- **"Unknown" ≠ "offline":** if no source can answer, the state becomes unknown
  and the recorded measurements are not deleted.
- **An HTTP request never connects to a device to measure.** Measurements come
  from the recorded data; otherwise every page would wait on as many device
  round trips as there are switches.

## Permissions

Each screen is a resource, with ten actions (`view`, `create`, `update`,
`delete`, `connect`, `configure`, `provision`, `secret`, `rotate`, `export`). For device objects
(ports, VLANs, firewall…) the records are **switches**: you can say "may edit
this switch's VLANs".

The role is read live; a person's own rows are exceptions. Decision order: the
person's record-specific row → the person's resource-wide row → the role's
permission → deny. Actions that write to a device additionally require a
**NETCONF credential**, and not even an administrator can bypass that.

Every state-changing request requires a **reason**; the check is server-side,
and since the reason goes into the chain it cannot be changed afterwards.

## Installation

Container installation uses `docker-compose.yml` at the repository root (Compose
standard: MariaDB and redis as separate services, the panel speaks HTTP only; the
same file on every platform). Details and variables: `docker/README.md`,
`.env.example`.

```bash
cp .env.example .env && docker compose up -d --build   # install / update
docker compose logs -f panel                           # logs
```

The rest of this
section describes the container-less installation (with a site user):

```bash
composer setup     # development
composer deploy    # production (no-dev, optimize, migrate, build)
```

All `artisan` and `npm` commands are run **as the site user**:

```bash
sudo -u <site-kullanicisi> php artisan ...
```

If run as root, `storage/framework/views`, `bootstrap/cache` and `public/build`
become owned by root and the whole panel returns 500. Recovery:

```bash
chown -R <site-kullanicisi>:<site-kullanicisi> storage bootstrap/cache public/build
sudo -u <site-kullanicisi> php artisan optimize
```

### Device write queue

Write jobs run on **a separate queue** and its worker is a systemd unit. Without
the unit, write jobs never run and "cihaza yazılıyor" (writing to device) never
changes on screen:

```bash
sudo cp deploy/nms-switch-write.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now nms-switch-write
```

The other queue workers are started by the scheduler; no separate supervisor is
needed.

## Tests

```bash
composer test                                        # full suite
sudo -u <site-kullanicisi> env PLAYWRIGHT_BROWSERS_PATH=<kurulum-dizini>/.cache \
  npx playwright test --reporter=list                # visual, three widths
```

Devices are emulated by a fake Junos: it answers by looking at the incoming RPC,
so the tests do not depend on call order.

## Rules

Coding standards are in `CLAUDE.md`; domain knowledge is in the files under
`.ai/rules/` (NETCONF, JunOS dialect, permissions, addressing, announcements,
passwords, interface, tests). The relevant rule file is read before opening a
new screen.
