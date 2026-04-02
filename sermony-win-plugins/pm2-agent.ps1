#Requires -Version 5.1
<#
.SYNOPSIS
    Sermony PM2 Plugin Agent for Windows
.DESCRIPTION
    Collects PM2 process information and sends it to the Sermony server
    via the plugin-data endpoint. Mirrors the behaviour of the Linux pm2-agent.sh.

    Config is read from $env:ProgramData\sermony\config (written by install.ps1).
    Can also be run standalone by passing -ConfigFile.

.PARAMETER ConfigFile
    Override the default config file path.
#>
[CmdletBinding()]
param(
    [string]$ConfigFile = "$env:ProgramData\sermony\config"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# ── Read config ───────────────────────────────────────────────────────────────
if (-not (Test-Path $ConfigFile)) {
    Write-Error "Config file not found: $ConfigFile. Run install.ps1 first."
    exit 1
}
$cfg = @{}
Get-Content $ConfigFile | ForEach-Object {
    if ($_ -match '^\s*([^#=]+?)\s*=\s*(.+)$') { $cfg[$Matches[1]] = $Matches[2] }
}

# ── Locate PM2 ────────────────────────────────────────────────────────────────
function Find-PM2 {
    # 1. On PATH
    $cmd = Get-Command pm2 -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }

    # 2. Common npm global bin locations
    $candidates = @(
        "$env:APPDATA\npm\pm2.cmd",
        "$env:APPDATA\npm\pm2",
        "$env:ProgramFiles\nodejs\pm2.cmd",
        "C:\Program Files\nodejs\pm2.cmd"
    )

    # 3. NVM for Windows
    if ($env:NVM_HOME) {
        $nvmVersions = Get-ChildItem $env:NVM_HOME -Directory -ErrorAction SilentlyContinue
        foreach ($v in $nvmVersions) {
            $candidates += "$($v.FullName)\pm2.cmd"
        }
    }

    # 4. nvm-windows default location
    foreach ($d in @("$env:APPDATA\nvm", "C:\nvm", "C:\Users\$env:USERNAME\.nvm")) {
        if (Test-Path $d) {
            Get-ChildItem $d -Directory -ErrorAction SilentlyContinue | ForEach-Object {
                $candidates += "$($_.FullName)\pm2.cmd"
            }
        }
    }

    foreach ($c in $candidates) {
        if (Test-Path $c -ErrorAction SilentlyContinue) { return $c }
    }
    return $null
}

# ── Parse PM2 process list ────────────────────────────────────────────────────
function Format-Uptime {
    param([long]$Ms)
    if ($Ms -le 0) { return '0m' }
    $s = [math]::Floor($Ms / 1000)
    $m = [math]::Floor($s / 60)
    $h = [math]::Floor($m / 60)
    $d = [math]::Floor($h / 24)
    $h = $h % 24; $m = $m % 60
    $parts = @()
    if ($d -gt 0) { $parts += "${d}d" }
    if ($h -gt 0) { $parts += "${h}h" }
    if ($m -gt 0 -or $parts.Count -eq 0) { $parts += "${m}m" }
    return $parts -join ' '
}

function Get-PM2Processes {
    param([string]$PM2Path)

    # Run pm2 jlist (JSON output) — works on all versions
    try {
        $raw = & $PM2Path jlist 2>&1
    } catch {
        Write-Warning "pm2 jlist failed: $_"
        return @()
    }

    # pm2 jlist can emit warnings before the JSON — find the JSON array
    $jsonStart = $raw | Select-String -Pattern '^\[' | Select-Object -First 1
    if (-not $jsonStart) {
        Write-Warning "Could not find JSON in pm2 jlist output"
        return @()
    }
    $jsonLines = $raw[$jsonStart.LineNumber..($raw.Count - 1)] -join "`n"

    try {
        $processes = $jsonLines | ConvertFrom-Json
    } catch {
        Write-Warning "Failed to parse pm2 JSON: $_"
        return @()
    }

    $result = @()
    foreach ($proc in $processes) {
        $memBytes = 0
        try { $memBytes = [long]$proc.monit.memory } catch {}
        $memMB = if ($memBytes -gt 0) { "$([math]::Round($memBytes / 1MB, 1))MB" } else { '0MB' }

        $cpu = 0
        try { $cpu = [double]$proc.monit.cpu } catch {}

        $uptimeMs = 0
        try { $uptimeMs = [long]$proc.pm2_env.pm_uptime } catch {}
        $uptime = Format-Uptime -Ms ([DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds() - $uptimeMs)

        $result += @{
            name     = [string]$proc.name
            user     = if ($proc.pm2_env.username) { [string]$proc.pm2_env.username } else { $env:USERNAME }
            status   = [string]$proc.pm2_env.status
            cpu      = "$cpu"
            mem      = $memMB
            restarts = [string]$proc.pm2_env.restart_time
            uptime   = $uptime
            script   = [string]$proc.pm2_env.pm_exec_path
        }
    }
    return $result
}

# ── Main ───────────────────────────────────────────────────────────────────────
$pm2 = Find-PM2
if (-not $pm2) {
    Write-Host "PM2 not found on this machine - skipping plugin-data submission."
    exit 0
}

Write-Host "Found PM2 at: $pm2"
$procs = Get-PM2Processes -PM2Path $pm2

if ($procs.Count -eq 0) {
    Write-Host "No PM2 processes found."
}

Write-Host "Sending $($procs.Count) PM2 process(es) to $($cfg.server_url)..."

$payload = @{
    agent_key = $cfg.agent_key
    plugin    = 'pm2'
    data      = $procs
}

$json = $payload | ConvertTo-Json -Depth 10 -Compress
try {
    $resp = Invoke-RestMethod `
        -Uri "$($cfg.server_url)?action=plugin-data" `
        -Method Post `
        -ContentType 'application/json' `
        -Body ([System.Text.Encoding]::UTF8.GetBytes($json)) `
        -UseBasicParsing
    Write-Host "Response: $($resp | ConvertTo-Json -Compress)"
} catch {
    Write-Error "Failed to send PM2 data: $_"
    exit 1
}
