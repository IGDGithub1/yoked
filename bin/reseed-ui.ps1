<#
.SYNOPSIS
    Re-seed the browser-test fixtures on the server.

.DESCRIPTION
    The browser suite MUTATES its fixture: it logs meals, raises a veto, switches a
    preference off. A second run against the same data produced twenty cascading failures
    that all looked like real bugs, so drive-logging.mjs refuses to start against a dirty
    fixture and tells you to re-seed.

    Telling a human to run a command the machine could run itself is a papercut that gets
    hit on every single test run. This is that command, and `npm run drive` chains it in
    front of the suite so the normal path is one word.

    Kept separate from deploy.ps1 on purpose: seeding wipes and recreates three users, and
    that should never be a silent side effect of shipping code.

.EXAMPLE
    .\bin\reseed-ui.ps1
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

# Same host, user and port resolution as deploy.ps1, read from the same file so there is one
# place to change. SG_PORT defaults to SiteGround's 18765 — port 22 is closed, and a bare ssh
# fails with a banner-exchange timeout that names no port and reads like a block.
$envFile = Join-Path $PSScriptRoot 'deploy.env'
if (-not (Test-Path $envFile)) {
    throw "Missing $envFile. Copy deploy.env.example and fill it in."
}

$cfg = @{}
foreach ($line in Get-Content $envFile) {
    if ($line -match '^\s*#' -or $line -notmatch '=') { continue }
    $k, $v = $line -split '=', 2
    $cfg[$k.Trim()] = $v.Trim()
}

$sgUser = $cfg['SG_USER']
$sgHost = $cfg['SG_HOST']
$sgPort = if ($cfg.ContainsKey('SG_PORT')) { $cfg['SG_PORT'] } else { '18765' }
$sgDir  = $cfg['SG_APP_DIR']

if (-not $sgUser -or -not $sgHost -or -not $sgDir) {
    throw "deploy.env needs SG_USER, SG_HOST and SG_APP_DIR."
}

Write-Host "-> re-seeding UI fixtures on $sgUser@$sgHost"

<#
    Clear this machine's login throttle as part of seeding.

    The browser suite signs in nine times per run against a 20-per-IP-per-15-minute limit, so a
    run plus a hand-written probe exhausts it — and then every later block fails at the sign-in
    screen, producing thirty cascading failures that all read like real regressions.

    The server cannot work out our address: seed-uitest.php runs over SSH, where RateLimit::ip()
    is 0.0.0.0. But the SSH connection itself knows it, in $SSH_CLIENT — so the address is taken
    from there rather than from an external lookup service, which keeps this working offline and
    avoids trusting a third party with a request.
#>
# awk rather than `cut -d" "`: PowerShell mangles the quoting around the delimiter before ssh
# ever sees it, and cut then reports "the delimiter must be a single character". awk splits on
# whitespace by default, so there is nothing to quote.
$remote = "cd '$sgDir' && php bin/seed-uitest.php " +
          '--clear-login-ip=$(echo $SSH_CLIENT | awk ''{print $1}'')'
& ssh -p $sgPort -o ConnectTimeout=40 "$sgUser@$sgHost" $remote
if ($LASTEXITCODE -ne 0) {
    throw "Seeding failed (exit $LASTEXITCODE)."
}
