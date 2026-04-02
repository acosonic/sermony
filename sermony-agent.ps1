#Requires -Version 5.1
<#
.SYNOPSIS
    Sermony Windows Agent - collects system metrics and reports to a Sermony server.
.DESCRIPTION
    Payload matches the Linux sermony-agent.sh format exactly so all dashboard
    fields (public IP, IPv6, FQDN, NIC details, Docker, services, etc.) populate.

    Config is stored in $env:ProgramData\sermony\config (written by install.ps1).

.PARAMETER ConfigFile
    Override the default config file path (useful for testing).
#>
[CmdletBinding()]
param(
    [string]$ConfigFile = "$env:ProgramData\sermony\config"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Read-Config {
    param([string]$Path)
    if (-not (Test-Path $Path)) {
        Write-Error "Config not found: $Path. Run install.ps1 first."
        exit 1
    }
    $cfg = @{}
    Get-Content $Path | ForEach-Object {
        if ($_ -match '^\s*([^#=]+?)\s*=\s*(.+)$') { $cfg[$Matches[1]] = $Matches[2] }
    }
    return $cfg
}

# ── Identifiers ────────────────────────────────────────────────────────────────
function Get-PublicIPv4 {
    # Use plain-text endpoints only; validate strict IPv4 pattern
    $sources = @('https://acosonic.com/ip.php','https://api.ipify.org','https://ipv4.icanhazip.com')
    foreach ($url in $sources) {
        try {
            $ip = (Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 5).Content.Trim()
            if ($ip -match '^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$') { return $ip }
        } catch { }
    }
    return ''
}

function Get-PublicIPv6 {
    # Use plain-text endpoints only; validate strict IPv6 pattern (contains : but no spaces/tags)
    $ipv6Pattern = '^[0-9a-fA-F:]{2,39}$'
    $sources = @('https://api6.ipify.org','https://ipv6.icanhazip.com')
    foreach ($url in $sources) {
        try {
            $ip = (Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 5).Content.Trim()
            if ($ip -match $ipv6Pattern) { return $ip }
        } catch { }
    }
    # Fall back to first global unicast IPv6 from local adapters
    try {
        $addr = Get-NetIPAddress -AddressFamily IPv6 -PrefixOrigin RouterAdvertisement -ErrorAction SilentlyContinue |
                Where-Object { $_.IPAddress -notmatch '^fe80' } |
                Select-Object -First 1 -ExpandProperty IPAddress
        if ($addr) { return $addr }
    } catch { }
    return ''
}

function Get-FQDN {
    try { return [System.Net.Dns]::GetHostEntry('').HostName } catch { return $env:COMPUTERNAME }
}

# ── CPU (1-second sample like the Linux agent) ─────────────────────────────────
function Get-CpuUsage {
    $s1 = (Get-CimInstance -ClassName Win32_Processor).LoadPercentage
    Start-Sleep -Seconds 1
    $s2 = (Get-CimInstance -ClassName Win32_Processor).LoadPercentage
    $avg = ($s1 + $s2) / 2
    return [math]::Round($avg, 1)
}

# ── Memory ────────────────────────────────────────────────────────────────────
function Get-MemoryInfo {
    $os = Get-CimInstance -ClassName Win32_OperatingSystem
    $totalKB = $os.TotalVisibleMemorySize
    $freeKB  = $os.FreePhysicalMemory
    $usedKB  = $totalKB - $freeKB
    return @{
        pct      = [math]::Round(($usedKB / $totalKB) * 100, 1)
        total_mb = [int][math]::Round($totalKB / 1024, 0)
        total_gb = [math]::Round($totalKB / 1048576, 1)
    }
}

# ── Disk ──────────────────────────────────────────────────────────────────────
function Get-DiskInfo {
    $drives = Get-CimInstance -ClassName Win32_LogicalDisk -Filter 'DriveType=3'
    $total  = ($drives | Measure-Object -Property Size -Sum).Sum
    $free   = ($drives | Measure-Object -Property FreeSpace -Sum).Sum
    $gb     = [math]::Round($total / 1GB, 1)
    return @{
        pct   = [math]::Round((($total - $free) / $total) * 100, 1)
        total = "${gb}G"
    }
}

function Get-DiskIOPS {
    $iops = 0
    try {
        $iops = [math]::Round(
            (Get-Counter '\PhysicalDisk(_Total)\Disk Transfers/sec' -SampleInterval 1 -MaxSamples 1 -ErrorAction Stop
            ).CounterSamples[0].CookedValue, 0)
    } catch { }
    return $iops
}

# ── Network throughput ─────────────────────────────────────────────────────────
function Get-NetworkBps {
    $s = Get-Counter '\Network Interface(*)\Bytes Received/sec','\Network Interface(*)\Bytes Sent/sec' `
         -SampleInterval 1 -MaxSamples 1 -ErrorAction SilentlyContinue
    $rx = 0; $tx = 0
    if ($s) {
        foreach ($c in $s.CounterSamples) {
            if ($c.Path -match 'Bytes Received') { $rx += $c.CookedValue }
            elseif ($c.Path -match 'Bytes Sent')  { $tx += $c.CookedValue }
        }
    }
    return @{ rx = [long][math]::Round($rx, 0); tx = [long][math]::Round($tx, 0) }
}

# ── Load (processor queue approximation) ──────────────────────────────────────
function Get-LoadAverages {
    $q = 0
    try {
        $q = (Get-Counter '\System\Processor Queue Length' -SampleInterval 1 -MaxSamples 1 -ErrorAction Stop
             ).CounterSamples[0].CookedValue
    } catch { }
    $q = [math]::Round($q, 2)
    return @{ load_1 = $q; load_5 = $q; load_15 = $q }
}

# ── Uptime string ─────────────────────────────────────────────────────────────
function Get-UptimeString {
    $os = Get-CimInstance -ClassName Win32_OperatingSystem
    $ts = $os.LocalDateTime - $os.LastBootUpTime
    $d = [int]$ts.Days; $h = [int]$ts.Hours; $m = [int]$ts.Minutes
    $parts = @()
    if ($d -gt 0) { $parts += "$d day$(if($d -ne 1){'s'})" }
    if ($h -gt 0) { $parts += "$h hour$(if($h -ne 1){'s'})" }
    if ($m -gt 0 -or $parts.Count -eq 0) { $parts += "$m minute$(if($m -ne 1){'s'})" }
    return $parts -join ', '
}

# ── Network interfaces ─────────────────────────────────────────────────────────
function Get-NetworkInterfaces {
    $result = @()
    $adapters = Get-CimInstance -ClassName Win32_NetworkAdapter -Filter 'NetEnabled=True' -ErrorAction SilentlyContinue
    $configs  = Get-CimInstance -ClassName Win32_NetworkAdapterConfiguration -Filter 'IPEnabled=True' -ErrorAction SilentlyContinue

    foreach ($cfg in $configs) {
        $adapter  = $adapters | Where-Object { $_.DeviceID -eq $cfg.Index } | Select-Object -First 1
        $state    = if ($adapter -and $adapter.NetConnectionStatus -eq 2) { 'UP' } else { 'DOWN' }
        $ip4      = if ($cfg.IPAddress) { ($cfg.IPAddress | Where-Object { $_ -match '^\d+\.\d+\.\d+\.\d+$' } | Select-Object -First 1) } else { '' }
        $ip6      = if ($cfg.IPAddress) { ($cfg.IPAddress | Where-Object { $_ -match ':' } | Select-Object -First 1) } else { '' }
        $mac      = if ($cfg.MACAddress) { $cfg.MACAddress } else { '' }

        # Try to get link speed in Mbps
        $speed = ''
        if ($adapter) {
            try {
                $speedBps = (Get-NetAdapter -InterfaceIndex $adapter.InterfaceIndex -ErrorAction Stop).LinkSpeed
                if ($speedBps -match '(\d+)\s*(Gbps|Mbps|Kbps)') {
                    $val  = [int]$Matches[1]
                    $unit = $Matches[2]
                    $speed = switch ($unit) {
                        'Gbps' { "$($val * 1000)Mb/s" }
                        'Mbps' { "${val}Mb/s" }
                        'Kbps' { "${val}Kb/s" }
                    }
                }
            } catch { }
        }

        $result += @{
            name  = $cfg.Description
            state = $state
            ip4   = if ($ip4) { "$ip4/$($cfg.IPSubnet[0])" } else { '' }
            ip6   = if ($ip6) { $ip6 } else { '' }
            mac   = $mac
            speed = $speed
        }
    }
    return $result
}

# ── DNS servers ───────────────────────────────────────────────────────────────
function Get-DnsServers {
    try {
        $dns = Get-DnsClientServerAddress -AddressFamily IPv4 -ErrorAction Stop |
               Where-Object { $_.ServerAddresses } |
               ForEach-Object { $_.ServerAddresses } |
               Select-Object -Unique
        return ($dns -join ', ')
    } catch { return '' }
}

# ── Docker (Docker Desktop on Windows) ────────────────────────────────────────
function Get-DockerInfo {
    $dockerBin = Get-Command docker -ErrorAction SilentlyContinue
    if (-not $dockerBin) {
        return @{ present = $false; count = 0; containers = @() }
    }
    try {
        $running = & docker ps -q 2>$null | Measure-Object | Select-Object -ExpandProperty Count
        $lines   = & docker ps --format '{{.Names}}|{{.Image}}|{{.Status}}|{{.Ports}}' 2>$null
        $containers = @()
        foreach ($line in $lines) {
            if (-not $line) { continue }
            $parts = $line -split '\|', 4
            $containers += @{
                name   = $parts[0]
                image  = if ($parts.Count -gt 1) { $parts[1] } else { '' }
                status = if ($parts.Count -gt 2) { $parts[2] } else { '' }
                ports  = if ($parts.Count -gt 3) { $parts[3] } else { '' }
            }
        }
        return @{ present = $true; count = $running; containers = $containers }
    } catch {
        return @{ present = $true; count = 0; containers = @() }
    }
}

# ── Services ──────────────────────────────────────────────────────────────────
function Get-RunningServices {
    $svcMap = [ordered]@{
        mysql         = @('MySQL','MySQL80','MySQL57','MySQL56')
        mariadb       = @('MariaDB')
        postgresql    = @('postgresql','postgresql-x64-14','postgresql-x64-15','postgresql-x64-16')
        nginx         = @('nginx')
        apache2       = @('Apache2.4','Apache2','httpd')
        iis           = @('W3SVC')
        postfix       = @('Postfix')
        redis         = @('Redis','Redis-x64-*')
        mongod        = @('MongoDB')
        rabbitmq      = @('RabbitMQ')
        elasticsearch = @('elasticsearch-service-x64','elasticsearch')
        mssql         = @('MSSQLSERVER','MSSQL$*')
    }
    $allSvcs = Get-Service -ErrorAction SilentlyContinue
    $result  = @()
    foreach ($name in $svcMap.Keys) {
        foreach ($pat in $svcMap[$name]) {
            if ($allSvcs | Where-Object { $_.Name -like $pat -and $_.Status -eq 'Running' }) {
                $result += @{ name = $name; active = $true }
                break
            }
        }
    }
    return $result
}

# ── OS info ───────────────────────────────────────────────────────────────────
function Get-OSInfo {
    $os = Get-CimInstance -ClassName Win32_OperatingSystem
    return @{
        name    = $os.Caption.Trim()
        kernel  = $os.Version
    }
}

function Get-CpuInfo {
    $cpu   = Get-CimInstance -ClassName Win32_Processor | Select-Object -First 1
    $cores = (Get-CimInstance -ClassName Win32_Processor | Measure-Object -Property NumberOfLogicalProcessors -Sum).Sum
    return @{ model = $cpu.Name.Trim(); cores = [int]$cores }
}

# ── Main ───────────────────────────────────────────────────────────────────────
$cfg = Read-Config -Path $ConfigFile
Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Collecting metrics for $($cfg.hostname)..."

# Collect everything
$mem    = Get-MemoryInfo
$disk   = Get-DiskInfo
$net    = Get-NetworkBps
$load   = Get-LoadAverages
$ifaces = @(Get-NetworkInterfaces)
$docker = Get-DockerInfo
$os     = Get-OSInfo
$cpu    = Get-CpuInfo
$iops   = Get-DiskIOPS
$cpuPct = Get-CpuUsage

$payload = @{
    agent_key      = $cfg.agent_key
    hostname       = $cfg.hostname
    public_ip      = Get-PublicIPv4
    ipv6           = Get-PublicIPv6
    fqdn           = Get-FQDN
    cpu_usage      = $cpuPct
    memory_usage   = $mem.pct
    memory_total_mb= $mem.total_mb
    disk_usage     = $disk.pct
    disk_iops      = $iops
    network_rx_bps = $net.rx
    network_tx_bps = $net.tx
    mail_queue     = $null
    load_1         = $load.load_1
    load_5         = $load.load_5
    load_15        = $load.load_15
    collected_at   = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    timezone       = [System.TimeZoneInfo]::Local.Id
    system_info    = @{
        cpu_cores         = $cpu.cores
        cpu_model         = $cpu.model
        ram_total_gb      = $mem.total_gb
        disk_total        = $disk.total
        os                = $os.name
        kernel            = $os.kernel
        uptime            = Get-UptimeString
        net_interfaces    = $ifaces
        iface_count       = $ifaces.Count
        dns_servers       = Get-DnsServers
        docker            = $docker.present
        docker_count      = $docker.count
        docker_containers = @($docker.containers)
        services          = @(Get-RunningServices)
    }
}

try {
    $json = $payload | ConvertTo-Json -Depth 10 -Compress
    $resp = Invoke-RestMethod `
        -Uri "$($cfg.server_url)?action=ingest" `
        -Method Post `
        -ContentType 'application/json' `
        -Body ([System.Text.Encoding]::UTF8.GetBytes($json)) `
        -UseBasicParsing
    Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] OK: $($resp | ConvertTo-Json -Compress)"
} catch {
    Write-Error "Failed to send metrics: $_"
    exit 1
}
