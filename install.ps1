#Requires -RunAsAdministrator
#Requires -Version 5.1
<#
.SYNOPSIS
    Sermony Windows Agent Installer
.DESCRIPTION
    Enrolls this Windows machine with a Sermony server, saves the agent key,
    and creates a Windows Scheduled Task to run sermony-agent.ps1 on the
    interval returned by the server (default 15 minutes).

.PARAMETER ServerUrl
    Full URL of the Sermony index.php (e.g. https://monitor.example.com/sermony/)

.PARAMETER EnrollmentKey
    64-character hex enrollment key shown in the Sermony dashboard.

.PARAMETER Hostname
    Name to identify this machine in the dashboard. Defaults to $env:COMPUTERNAME.

.EXAMPLE
    .\install.ps1 -ServerUrl https://monitor.example.com/sermony/ -EnrollmentKey abc123...
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$ServerUrl,

    [Parameter(Mandatory)]
    [string]$EnrollmentKey,

    [string]$Hostname = $env:COMPUTERNAME
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Normalize server URL (ensure trailing slash)
$ServerUrl = $ServerUrl.TrimEnd('/') + '/'

# Paths
$ConfigDir   = "$env:ProgramData\sermony"
$ConfigFile  = "$ConfigDir\config"
$AgentScript = "$ConfigDir\sermony-agent.ps1"
$TaskName    = 'SermonyAgent'

# ── Step 1: Enroll ────────────────────────────────────────────────────────────
Write-Host "Enrolling '$Hostname' with $ServerUrl ..."

$enrollBody = @{
    enrollment_key = $EnrollmentKey
    hostname       = $Hostname
} | ConvertTo-Json -Compress

try {
    $resp = Invoke-RestMethod `
        -Uri "${ServerUrl}?action=enroll" `
        -Method Post `
        -ContentType 'application/json' `
        -Body ([System.Text.Encoding]::UTF8.GetBytes($enrollBody)) `
        -UseBasicParsing
} catch {
    Write-Error "Enrollment request failed: $_"
    exit 1
}

if (-not $resp.agent_key) {
    Write-Error "Enrollment failed. Server response: $($resp | ConvertTo-Json)"
    exit 1
}

$AgentKey = $resp.agent_key
$Interval = if ($resp.interval) { [int]$resp.interval } else { 15 }
Write-Host "Enrolled! Agent key received. Reporting interval: $Interval minutes."

# ── Step 2: Write config ───────────────────────────────────────────────────────
Write-Host "Writing config to $ConfigFile ..."
New-Item -ItemType Directory -Force -Path $ConfigDir | Out-Null

@"
# Sermony agent config — do not edit manually
server_url=$ServerUrl
agent_key=$AgentKey
hostname=$Hostname
interval=$Interval
"@ | Set-Content -Path $ConfigFile -Encoding UTF8

# Lock config file to current user + SYSTEM only
$acl = Get-Acl $ConfigFile
$acl.SetAccessRuleProtection($true, $false)
$adminRule  = New-Object System.Security.AccessControl.FileSystemAccessRule('BUILTIN\Administrators','FullControl','Allow')
$systemRule = New-Object System.Security.AccessControl.FileSystemAccessRule('NT AUTHORITY\SYSTEM','FullControl','Allow')
$acl.AddAccessRule($adminRule)
$acl.AddAccessRule($systemRule)
Set-Acl -Path $ConfigFile -AclObject $acl

# ── Step 3: Copy agent script ──────────────────────────────────────────────────
$SourceAgent = Join-Path $PSScriptRoot 'sermony-agent.ps1'
if (-not (Test-Path $SourceAgent)) {
    Write-Error "sermony-agent.ps1 not found next to install.ps1 ($PSScriptRoot)"
    exit 1
}
Copy-Item -Path $SourceAgent -Destination $AgentScript -Force
Write-Host "Agent script installed to $AgentScript"

# ── Step 4: Create Scheduled Task ─────────────────────────────────────────────
Write-Host "Creating Scheduled Task '$TaskName' (every $Interval minutes) ..."

# Remove old task if it exists
if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

$psExe  = "$PSHOME\powershell.exe"
$action = New-ScheduledTaskAction `
    -Execute $psExe `
    -Argument "-NonInteractive -NoProfile -ExecutionPolicy Bypass -File `"$AgentScript`""

$trigger = New-ScheduledTaskTrigger -RepetitionInterval (New-TimeSpan -Minutes $Interval) -Once -At (Get-Date)

$settings = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5) `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable

$principal = New-ScheduledTaskPrincipal `
    -UserId 'NT AUTHORITY\SYSTEM' `
    -LogonType ServiceAccount `
    -RunLevel Highest

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Principal $principal `
    -Description "Sermony Windows monitoring agent — reports to $ServerUrl" | Out-Null

Write-Host ""
Write-Host "Installation complete!"
Write-Host "  Config : $ConfigFile"
Write-Host "  Agent  : $AgentScript"
Write-Host "  Task   : $TaskName (runs every $Interval minutes as SYSTEM)"
Write-Host ""
Write-Host "To run immediately: Start-ScheduledTask -TaskName '$TaskName'"
Write-Host "To uninstall     : Unregister-ScheduledTask -TaskName '$TaskName' -Confirm:`$false"
