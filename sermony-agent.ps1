#Requires -Version 5.1
<#
.SYNOPSIS
    Sermony Windows Agent - collects system metrics and reports to a Sermony server.
.DESCRIPTION
    Reads metrics from Windows (CPU, memory, disk, network) and POSTs them
    to the configured Sermony server endpoint. Config is stored in
    $env:ProgramData\sermony\config (created by install.ps1).
#>

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# ── Config ────────────────────────────────────────────────────────────────────
$ConfigDir  = "$env:ProgramData\sermony"
$ConfigFile = "$ConfigDir\config"

function Read-Config {
    if (-not (Test-Path $ConfigFile)) {
        Write-Error "Config file not found: $ConfigFile. Run install.ps1 first."
        exit 1
    }
    $cfg = @{}
    Get-Content $ConfigFile | ForEach-Object {
        if ($_ -match '^\s*([^#=]+?)\s*=\s*(.+)$') {
            $cfg[$Matches[1]] = $Matches[2]
        }
    }
    return $cfg
}

# ── Metric helpers ────────────────────────────────────────────────────────────
function Get-CpuUsage {
    $load = Get-CimInstance -ClassName Win32_Processor | Measure-Object -Property LoadPercentage -Average
    return [math]::Round($load.Average, 1)
}

function Get-MemoryUsage {
    $os = Get-CimInstance -ClassName Win32_OperatingSystem
    $used = $os.TotalVisibleMemorySize - $os.FreePhysicalMemory
    return [math]::Round(($used / $os.TotalVisibleMemorySize) * 100, 1)
}

function Get-RamGB {
    $os = Get-CimInstance -ClassName Win32_OperatingSystem
    return [math]::Round($os.TotalVisibleMemorySize / 1MB, 1)
}

function Get-DiskUsage {
    # Aggregate across all fixed drives
    $drives = Get-CimInstance -ClassName Win32_LogicalDisk -Filter "DriveType=3"
    $total = ($drives | Measure-Object -Property Size -Sum).Sum
    $free  = ($drives | Measure-Object -Property FreeSpace -Sum).Sum
    if ($total -eq 0) { return 0 }
    return [math]::Round((($total - $free) / $total) * 100, 1)
}

function Get-DiskGB {
    $drives = Get-CimInstance -ClassName Win32_LogicalDisk -Filter "DriveType=3"
    $total = ($drives | Measure-Object -Property Size -Sum).Sum
    return [math]::Round($total / 1GB, 1)
}

function Get-DiskIOPS {
    try {
        $counters = Get-Counter '\PhysicalDisk(_Total)\Disk Transfers/sec' -SampleInterval 1 -MaxSamples 1
        return [math]::Round($counters.CounterSamples[0].CookedValue, 0)
    } catch {
        return 0
    }
}

function Get-LoadAverages {
    # Windows has no native load average; approximate with CPU queue length
    $q1 = 0
    try {
        $q1 = (Get-Counter '\System\Processor Queue Length' -SampleInterval 1 -MaxSamples 1).CounterSamples[0].CookedValue
    } catch { }
    # Return the same value for all three windows (best approximation)
    return @{ load_1 = [math]::Round($q1, 2); load_5 = [math]::Round($q1, 2); load_15 = [math]::Round($q1, 2) }
}

function Get-NetworkBps {
    # Sample twice 1 second apart to get bytes/sec
    $nics = Get-CimInstance -ClassName Win32_NetworkAdapterConfiguration -Filter "IPEnabled=True"
    $nicNames = $nics | Select-Object -ExpandProperty Description

    $s1 = Get-Counter -Counter '\Network Interface(*)\Bytes Received/sec','\Network Interface(*)\Bytes Sent/sec' -SampleInterval 1 -MaxSamples 1 -ErrorAction SilentlyContinue

    $rx = 0; $tx = 0
    if ($s1) {
        foreach ($s in $s1.CounterSamples) {
            if ($s.Path -match 'Bytes Received') { $rx += $s.CookedValue }
            if ($s.Path -match 'Bytes Sent')     { $tx += $s.CookedValue }
        }
    }
    return @{ rx = [math]::Round($rx, 0); tx = [math]::Round($tx, 0) }
}

function Get-Interfaces {
    $result = @()
    $nics = Get-CimInstance -ClassName Win32_NetworkAdapterConfiguration -Filter "IPEnabled=True"
    foreach ($nic in $nics) {
        $ip = if ($nic.IPAddress) { $nic.IPAddress[0] } else { '' }
        $result += @{
            name  = $nic.Description
            ip    = $ip
            speed = 0   # link speed not reliably available via WMI without extra queries
        }
    }
    return $result
}

function Get-UptimeSeconds {
    $os = Get-CimInstance -ClassName Win32_OperatingSystem
    return [math]::Round(($os.LocalDateTime - $os.LastBootUpTime).TotalSeconds, 0)
}

function Get-OSInfo {
    $os = Get-CimInstance -ClassName Win32_OperatingSystem
    return $os.Caption.Trim()
}

function Get-KernelVersion {
    return (Get-CimInstance -ClassName Win32_OperatingSystem).Version
}

function Get-CpuModel {
    return (Get-CimInstance -ClassName Win32_Processor | Select-Object -First 1).Name.Trim()
}

function Get-CpuCores {
    return (Get-CimInstance -ClassName Win32_Processor | Measure-Object -Property NumberOfLogicalProcessors -Sum).Sum
}

function Get-RunningServices {
    $svcMap = [ordered]@{
        mysql      = @('MySQL', 'MySQL80', 'MySQL57')
        postgresql = @('postgresql', 'postgresql-x64-14', 'postgresql-x64-15', 'postgresql-x64-16')
        nginx      = @('nginx')
        iis        = @('W3SVC')
        redis      = @('Redis', 'Redis-x64-*')
        mssql      = @('MSSQLSERVER', 'MSSQL$*')
        mongodb    = @('MongoDB')
        rabbitmq   = @('RabbitMQ')
        elasticsearch = @('elasticsearch-service-x64')
    }

    $running = @{}
    $allSvcs = Get-Service -ErrorAction SilentlyContinue

    foreach ($name in $svcMap.Keys) {
        $patterns = $svcMap[$name]
        $found = $false
        foreach ($pattern in $patterns) {
            if ($allSvcs | Where-Object { $_.Name -like $pattern -and $_.Status -eq 'Running' }) {
                $found = $true; break
            }
        }
        $running[$name] = $found
    }
    return $running
}

# ── HTTP helpers ───────────────────────────────────────────────────────────────
function Invoke-JsonPost {
    param([string]$Url, [hashtable]$Body)
    $json = $Body | ConvertTo-Json -Depth 10 -Compress
    $response = Invoke-RestMethod -Uri $Url -Method Post `
        -ContentType 'application/json' `
        -Body ([System.Text.Encoding]::UTF8.GetBytes($json)) `
        -UseBasicParsing
    return $response
}

# ── Agent config fetch ─────────────────────────────────────────────────────────
function Get-AgentInterval {
    param($cfg)
    try {
        $resp = Invoke-JsonPost -Url "$($cfg.server_url)?action=agent-config" -Body @{ agent_key = $cfg.agent_key }
        if ($resp.interval) { return [int]$resp.interval }
    } catch { }
    return 15  # default fallback
}

# ── Main ───────────────────────────────────────────────────────────────────────
$cfg = Read-Config

Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Collecting metrics for $($cfg.hostname)..."

$net  = Get-NetworkBps
$load = Get-LoadAverages
$disk_iops = 0
try { $disk_iops = Get-DiskIOPS } catch { }

$payload = @{
    agent_key      = $cfg.agent_key
    hostname       = $cfg.hostname
    cpu_usage      = Get-CpuUsage
    memory_usage   = Get-MemoryUsage
    disk_usage     = Get-DiskUsage
    disk_iops      = $disk_iops
    network_rx_bps = $net.rx
    network_tx_bps = $net.tx
    mail_queue     = 0
    load_1         = $load.load_1
    load_5         = $load.load_5
    load_15        = $load.load_15
    timestamp      = [int][double]::Parse((Get-Date -UFormat %s))
    timezone       = [System.TimeZoneInfo]::Local.Id
    cores          = Get-CpuCores
    cpu_model      = Get-CpuModel
    ram_gb         = Get-RamGB
    disk_gb        = Get-DiskGB
    os             = Get-OSInfo
    kernel         = Get-KernelVersion
    uptime_seconds = Get-UptimeSeconds
    interfaces     = @(Get-Interfaces)
    docker_running = 0
    services       = Get-RunningServices
}

try {
    $resp = Invoke-JsonPost -Url "$($cfg.server_url)?action=ingest" -Body $payload
    Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] OK: $($resp | ConvertTo-Json -Compress)"
} catch {
    Write-Error "Failed to send metrics: $_"
    exit 1
}
