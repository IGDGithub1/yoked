<#
.SYNOPSIS
    Deploy Yoked to SiteGround shared hosting.

.DESCRIPTION
    Packs an allowlist of paths into a tar.gz, ships it with scp, extracts it
    remotely, then runs any pending migrations.

    This is the PowerShell counterpart to bin/deploy.sh, and on Windows it is
    the one that actually works: Git Bash's ssh cannot reach the Windows
    ssh-agent, so the bash version stalls on the key passphrase. PowerShell
    uses the Windows OpenSSH client, which talks to the agent.

    Never overwritten on the server:
      src/config.php   live credentials
      storage/         uploads (progress photos)

.PARAMETER NoMigrate
    Ship the code but skip migrations. Use on the very first deploy, before
    src/config.php exists on the server.

.PARAMETER DryRun
    List what would ship and stop. Touches nothing, local or remote.

.PARAMETER Verify
    After deploying, run envcheck + dbcheck + the schema smoke test.

.EXAMPLE
    bin\deploy.ps1
.EXAMPLE
    bin\deploy.ps1 -DryRun
.EXAMPLE
    bin\deploy.ps1 -NoMigrate
#>
[CmdletBinding()]
param(
    [switch]$NoMigrate,
    [switch]$DryRun,
    [switch]$Verify
)

$ErrorActionPreference = 'Stop'

# PowerShell 5.1 wraps a native command's stderr in ErrorRecords, and with
# $ErrorActionPreference = 'Stop' that turns any stderr output - including
# progress chatter from a command that exits 0 - into a terminating error.
# ssh and tar both write to stderr routinely, so native calls are wrapped in
# this helper: it drops the preference for the duration of the call and keys
# success off the exit code instead, which is the only reliable signal.
function Invoke-Native {
    param(
        [Parameter(Mandatory)][string]$Exe,
        [string[]]$Arguments = @()
    )
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $out = & $Exe @Arguments 2>&1 | ForEach-Object { "$_" }
        return [pscustomobject]@{
            Output   = $out
            ExitCode = $LASTEXITCODE
            Ok       = ($LASTEXITCODE -eq 0)
        }
    }
    finally {
        $ErrorActionPreference = $prev
    }
}

# Repo root, regardless of where this was invoked from.
$AppRoot = Split-Path -Parent $PSScriptRoot
Push-Location $AppRoot

try {
    # ---- config ------------------------------------------------------------

    $EnvFile = Join-Path $AppRoot 'bin\deploy.env'
    if (-not (Test-Path $EnvFile)) {
        throw "Missing bin\deploy.env - copy bin\deploy.env.example and fill it in."
    }

    # Parse the shared KEY=value env file. Same file the bash script reads, so
    # the two stay in sync rather than drifting into separate configs.
    $cfg = @{}
    foreach ($line in Get-Content $EnvFile) {
        $t = $line.Trim()
        if ($t -eq '' -or $t.StartsWith('#')) { continue }
        $kv = $t -split '=', 2
        if ($kv.Count -eq 2) {
            $cfg[$kv[0].Trim()] = $kv[1].Trim()
        }
    }

    foreach ($k in 'SG_HOST', 'SG_USER', 'SG_APP_DIR') {
        if (-not $cfg.ContainsKey($k) -or $cfg[$k] -eq '') {
            throw "Set $k in bin\deploy.env"
        }
    }
    $sgHost = $cfg['SG_HOST']
    $sgUser = $cfg['SG_USER']
    $sgPort = if ($cfg.ContainsKey('SG_PORT')) { $cfg['SG_PORT'] } else { '18765' }
    $sgDir  = $cfg['SG_APP_DIR']
    $target = "$sgUser@$sgHost"

    # ---- what ships --------------------------------------------------------
    #
    # An allowlist, not an exclude list. An exclude list silently ships
    # anything new you forget to add to it - and this repo carries a
    # source-projects tree with live Friendspace credentials, so that failure
    # mode is real rather than theoretical.

    $shipPaths = @('src', 'database', 'bin', 'public_html')
    $excludes  = @(
        '--exclude=.git'
        '--exclude=.gitignore'
        '--exclude=node_modules'
        '--exclude=source-projects'
        '--exclude=storage'
        '--exclude=src/config.php'      # live credentials stay put
        '--exclude=bin/deploy.env'      # local only
        '--exclude=*.log'
        '--exclude=.DS_Store'
    )

    $present = @($shipPaths | Where-Object { Test-Path (Join-Path $AppRoot $_) })
    if ($present.Count -eq 0) { throw 'Nothing to deploy.' }

    Write-Host "-> deploying to ${target}:${sgDir}"
    Write-Host "   paths: $($present -join ', ')"

    if ($DryRun) {
        Write-Host ''
        Write-Host 'would ship:'
        # Build the real archive, then list it with -t. Two reasons over
        # `tar -cv`: -v writes member names to stderr, which PowerShell 5.1
        # turns into error records, and this exercises the exclude list exactly
        # as a real deploy would.
        $probe = Join-Path $env:TEMP 'yoked-dryrun.tgz'
        if (Test-Path $probe) { Remove-Item $probe -Force }
        $r = Invoke-Native tar (@('-czf', $probe) + $excludes + $present)
        if (-not $r.Ok) { $r.Output | Write-Host; throw 'tar failed.' }
        (Invoke-Native tar @('-tzf', $probe)).Output | ForEach-Object { Write-Host "  $_" }
        Write-Host ''
        Write-Host "  ($((Get-Item $probe).Length) bytes)"
        Remove-Item $probe -Force
        Write-Host ''
        Write-Host 'would then run: php bin/migrate.php  (unless -NoMigrate)'
        return
    }

    # ---- preflight ---------------------------------------------------------

    Write-Host '-> checking ssh ... ' -NoNewline
    $r = Invoke-Native ssh @('-p', $sgPort, '-o', 'BatchMode=yes',
                             '-o', 'ConnectTimeout=20',
                             '-o', 'StrictHostKeyChecking=accept-new',
                             $target, 'echo ok')
    if (-not $r.Ok -or ($r.Output -join ' ') -notmatch 'ok') {
        Write-Host 'FAILED'
        Write-Host ''
        Write-Host 'Cannot authenticate over SSH. If the key is passphrase-protected,'
        Write-Host 'load it into the Windows agent once:'
        Write-Host ''
        Write-Host '    Get-Service ssh-agent | Set-Service -StartupType Automatic'
        Write-Host '    Start-Service ssh-agent'
        Write-Host '    ssh-add $env:USERPROFILE\.ssh\yoked_sg'
        Write-Host ''
        Write-Host 'Verify with:  ssh-add -l'
        throw 'SSH authentication failed.'
    }
    Write-Host 'ok'

    # ---- pack --------------------------------------------------------------

    $tgz = Join-Path $env:TEMP 'yoked-deploy.tgz'
    if (Test-Path $tgz) { Remove-Item $tgz -Force }

    Write-Host '-> packing ... ' -NoNewline
    $r = Invoke-Native tar (@('-czf', $tgz) + $excludes + $present)
    if (-not $r.Ok) { Write-Host 'FAILED'; $r.Output | Write-Host; throw 'tar failed.' }
    $size = (Get-Item $tgz).Length
    Write-Host "ok ($size bytes)"

    # ---- ship --------------------------------------------------------------
    #
    # scp then extract, rather than piping tar over ssh: PowerShell 5.1 has no
    # -AsByteStream and mangles binary in a pipeline.

    Write-Host '-> uploading ... ' -NoNewline
    $r = Invoke-Native scp @('-P', $sgPort, '-q', $tgz, "${target}:/tmp/yoked-deploy.tgz")
    if (-not $r.Ok) { Write-Host 'FAILED'; $r.Output | Write-Host; throw 'scp failed.' }
    Write-Host 'ok'

    Write-Host '-> extracting ... ' -NoNewline
    $extract = "mkdir -p '$sgDir' && tar -xzf /tmp/yoked-deploy.tgz -C '$sgDir' && rm -f /tmp/yoked-deploy.tgz && echo done"
    $r = Invoke-Native ssh @('-p', $sgPort, $target, $extract)
    if (-not $r.Ok -or ($r.Output -join ' ') -notmatch 'done') {
        Write-Host 'FAILED'
        $r.Output | ForEach-Object { Write-Host "   $_" }
        throw 'Remote extract failed.'
    }
    Write-Host 'ok'
    Remove-Item $tgz -Force

    # Keep the shell scripts executable - tar preserves modes, but a file added
    # on Windows may arrive without the bit set.
    $null = Invoke-Native ssh @('-p', $sgPort, $target,
        "cd '$sgDir' && chmod +x bin/*.sh 2>/dev/null; chmod 700 storage 2>/dev/null; true")

    # ---- config check ------------------------------------------------------

    $r = Invoke-Native ssh @('-p', $sgPort, $target, "test -f '$sgDir/src/config.php'")
    if (-not $r.Ok) {
        Write-Host ''
        Write-Host 'No src/config.php on the server. Nothing will run without it.'
        Write-Host ''
        Write-Host "    ssh -p $sgPort $target"
        Write-Host "    cd $sgDir"
        Write-Host '    cp src/config.example.php src/config.php'
        Write-Host '    chmod 600 src/config.php'
        Write-Host '    nano src/config.php'
        Write-Host ''
        throw 'Missing remote config.php.'
    }

    # ---- migrate -----------------------------------------------------------

    if (-not $NoMigrate) {
        Write-Host '-> migrating'
        # Safe on every deploy: schema_migrations makes it a no-op when there
        # is nothing pending.
        $r = Invoke-Native ssh @('-p', $sgPort, $target, "cd '$sgDir' && php bin/migrate.php")
        $r.Output | ForEach-Object { Write-Host "   $_" }
        if (-not $r.Ok) { throw 'Migration failed.' }
    }

    # ---- verify ------------------------------------------------------------

    if ($Verify) {
        $failed = @()
        foreach ($script in 'envcheck', 'dbcheck', 'smoketest', 'test-goals') {
            $path = "bin/$script.php"
            if (-not (Invoke-Native ssh @('-p', $sgPort, $target, "test -f '$sgDir/$path'")).Ok) {
                continue
            }
            Write-Host ''
            Write-Host "-> $script"
            $r = Invoke-Native ssh @('-p', $sgPort, $target, "cd '$sgDir' && php $path")
            $r.Output | ForEach-Object { Write-Host "   $_" }
            if (-not $r.Ok) { $failed += $script }
        }
        if ($failed.Count -gt 0) {
            Write-Host ''
            throw "Verification failed: $($failed -join ', ')"
        }
    }

    Write-Host ''
    Write-Host 'deployed.'
}
finally {
    Pop-Location
}
