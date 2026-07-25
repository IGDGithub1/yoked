# Deploy Runbook — SiteGround

Target: `yoked.lil-boxes.com` on SiteGround shared hosting. PHP 8.4.23, MySQL,
SSH on port 18765.

## Layout

`src/`, `database/`, `bin/`, and `storage/` sit **beside** `public_html`, not
inside it. Anything under the web root is fetchable over HTTP — and `src/`
holds `config.php` (database password, Anthropic key) while `storage/` holds
progress photos.

```
/home/SG_USER/www/yoked.lil-boxes.com/
    public_html/          ← web root. SPA + api/index.php only.
    src/                  ← app code + config.php
    database/migrations/
    bin/
    storage/uploads/      ← progress photos, chmod 700
```

`setup-remote.sh` fails the check if `src/` or `storage/` ends up under
`public_html`.

---

## First-time setup

### 1. SSH key

SiteGround requires key auth; passwords are refused.

```sh
ssh-keygen -t ed25519 -f ~/.ssh/yoked_sg -C "yoked-deploy" -N ""
cat ~/.ssh/yoked_sg.pub
```

Paste the public key into **Site Tools → Devs → SSH Keys Manager → Import
Existing Key**. The private key never leaves your machine.

Verify:

```sh
ssh -p 18765 -i ~/.ssh/yoked_sg SG_USER@ssh.lil-boxes.com 'php -v'
```

### 2. Local deploy config

```sh
cp bin/deploy.env.example bin/deploy.env
```

Fill in `SG_USER` with the account's SSH username (**Site Tools → Devs → SSH
Keys Manager**). `bin/deploy.env` is gitignored — it holds no secrets either way
(the key is referenced by path, credentials live server-side), but the account
identifier stays out of a public repo.

### 3. Database

Already created. The real name, user, and password are in **Site Tools → MySQL**
— read them from there rather than from this file. This repo is public, so it
carries no account identifiers.

| | |
|---|---|
| Name | `dbXXXXXXXXXX` (Site Tools → MySQL → Databases) |
| User | `uXXXXXXXXXXX` (Site Tools → MySQL → Users) |
| Host | `localhost` |

The user must be **granted on** the database — SiteGround creates users and
databases separately, and a missing grant produces the same `1045 Access denied`
error as a wrong password.

### 4. Ship the code

```sh
bin/deploy.sh --no-migrate
```

Skip migrating on the first run — `config.php` doesn't exist yet, so there are
no credentials to migrate with. The script will tell you this and exit 1.

### 5. Create `config.php` on the server

Once, by hand. It is never deployed and never overwritten.

```sh
ssh -p 18765 -i ~/.ssh/yoked_sg SG_USER@ssh.lil-boxes.com
cd /home/SG_USER/www/yoked.lil-boxes.com
cp src/config.example.php src/config.php
chmod 600 src/config.php
nano src/config.php
```

Fill in the database name/user/password, the Anthropic API key, and leave
`'env' => 'production'`.

### 6. Verify the environment

```sh
bin/setup-remote.sh
```

Checks PHP version, required extensions, the directory layout, that `src/` and
`storage/` are outside the web root, database reachability, and whether the API
key is set. Creates missing directories. Idempotent.

`php bin/envcheck.php` reports the same environment in more detail, including
outbound HTTPS reachability to the Claude API. Note that **`imagick` is not
available to CLI PHP** on this host — `gd` is, and it can re-encode uploads,
which is the security-relevant part. The image pipeline should prefer imagick and
fall back to gd.

### 7. Migrate

```sh
bin/deploy.sh
```

Or directly:

```sh
ssh -p 18765 -i ~/.ssh/yoked_sg SG_USER@ssh.lil-boxes.com \
    'cd /home/SG_USER/www/yoked.lil-boxes.com && php bin/migrate.php'
```

Migrations 001–005 are applied: 39 tables, 57 foreign keys, plus the seeded
exercise library and goal presets. Verify with `php bin/dbcheck.php`.

---

## Routine deploys

**On Windows, use the PowerShell script.** `bin/deploy.sh` cannot authenticate:
Git Bash's `ssh` does not reach the Windows ssh-agent, so a passphrase-protected
key stalls it. `deploy.ps1` uses the Windows OpenSSH client, which does. Both read
the same `bin/deploy.env`.

```powershell
.\bin\deploy.ps1              # ship + migrate
.\bin\deploy.ps1 -NoMigrate   # ship only
.\bin\deploy.ps1 -DryRun      # list what would ship, change nothing
.\bin\deploy.ps1 -Verify      # + envcheck, dbcheck, smoketest, goal tests
```

From Linux, macOS, or WSL:

```sh
bin/deploy.sh              # ship + migrate
bin/deploy.sh --no-migrate # ship only
bin/deploy.sh --dry-run    # list what would ship, change nothing
```

Migrating on every deploy is safe: `schema_migrations` makes it a no-op when
nothing is pending.

### What ships

An **allowlist**, not an exclude list: `src`, `database`, `bin`, `public_html`.
An exclude list silently ships anything you forget to add to it — and this repo
has a `source-projects/` tree with live Friendspace credentials in it, so that
failure mode is not hypothetical.

Never overwritten on the server: `src/config.php`, `storage/`.

### Why tar, not rsync

Git Bash on Windows has no `rsync`, and SiteGround shared hosting doesn't
guarantee it. `tar` streamed over SSH needs nothing beyond what's already
there. The tradeoff: every deploy ships every file rather than just changes.
At this size that's a second or two.

---

## Migration runner

```sh
php bin/migrate.php --status    # applied vs pending
php bin/migrate.php --dry-run   # what would run
php bin/migrate.php             # apply pending
```

Applied filenames are recorded in `schema_migrations` and never re-run.
Friendspace had numbered migrations but no applied-log, which is how migration
010 got skipped in a deploy and stayed unnoticed until something broke.

**MySQL DDL is not transactional.** A migration that fails halfway leaves the
earlier statements applied and does *not* record the file, so a re-run hits
"table already exists". Fix forward: drop the partial objects, or split the
file. The runner exits loudly rather than continuing, because a silently
half-migrated database is far worse.

---

## Cron

Once the coaching engine exists, add in **Site Tools → Devs → Cron Jobs**:

```
/usr/local/bin/php /home/SG_USER/www/yoked.lil-boxes.com/bin/cron.php
```

Every 15 minutes. It will own weekly plan generation, weekly check-in
creation, drift detection, and nudge escalation — all the things that must
happen without a user opening the app.

`bin/cron.php` does not exist yet.

---

## Rollback

No automated rollback. Options, in order of preference:

1. **Roll forward.** `git checkout <last-good-sha>` then `bin/deploy.sh
   --no-migrate`. Fastest, and correct for a bad code deploy.
2. **Schema rollback.** There are no `down` migrations, deliberately — they're
   usually wrong when you need them. Write a new numbered migration that
   reverses the change.
3. **Database restore.** Site Tools → Backups. SiteGround keeps daily backups.
   Loses data written since the snapshot.

Before anything destructive on a live database, take a manual backup in Site
Tools first.

---

## Troubleshooting

**`Permission denied (publickey)`** — key not imported, or the wrong key. Check
`SG_SSH_KEY` in `bin/deploy.env` and confirm the `.pub` is in SSH Keys Manager.

**`Not configured: copy src/config.example.php`** — `config.php` missing on the
server. Step 5.

**Database connection fails in `setup-remote.sh`** — credentials wrong in
`config.php`, or the DB user isn't granted on that database. Re-check Site
Tools → MySQL.

**Migration says "table already exists"** — a previous run failed partway. See
the DDL note above.

**`tar: command not found`** on the remote — unexpected on SiteGround; would
mean falling back to SFTP upload.
