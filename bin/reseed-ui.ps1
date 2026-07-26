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
& ssh -p $sgPort -o ConnectTimeout=40 "$sgUser@$sgHost" "cd '$sgDir' && php bin/seed-uitest.php"
if ($LASTEXITCODE -ne 0) {
    throw "Seeding failed (exit $LASTEXITCODE)."
}
