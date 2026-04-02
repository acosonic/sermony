#Requires -Version 5.1
<#
.SYNOPSIS
    Sermony Windows Services Plugin Agent
.DESCRIPTION
    Reports all non-system Windows services (status, start type, account) to
    the Sermony server via the plugin-data endpoint. Visible as the
    "windows-services" plugin in the dashboard.

.PARAMETER ConfigFile
    Override the default config file path.

.PARAMETER IncludeSystem
    Include Microsoft system services (excluded by default to reduce noise).
#>
[CmdletBinding()]
param(
    [string]$ConfigFile    = "$env:ProgramData\sermony\config",
    [switch]$IncludeSystem
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

# ── Collect services ──────────────────────────────────────────────────────────
# System service name prefixes to skip (noisy, always present on every Windows box)
$systemPrefixes = @(
    'AarSvc', 'AJRouter', 'ALG', 'AppID', 'AppInfo', 'AppMgmt', 'AppReadiness',
    'AppVClient', 'AssignedAccessManager', 'AudioEndpointBuilder', 'Audiosrv',
    'AxInstSV', 'BcastDVRUserService', 'BDESVC', 'BFE', 'BITS', 'BrokerInfrastructure',
    'BTAGService', 'BthAvctpSvc', 'bthserv', 'camsvc', 'CDPSvc', 'CDPUserSvc',
    'CertPropSvc', 'ClipSVC', 'COMSysApp', 'CoreMessagingRegistrar', 'CryptSvc',
    'CscService', 'DcomLaunch', 'defragsvc', 'DeviceAssociationService', 'DeviceInstall',
    'DevQueryBroker', 'Dhcp', 'diagnosticshub', 'DiagTrack', 'DispBrokerDesktopSvc',
    'DisplayEnhancementService', 'DmEnrollmentSvc', 'dmwappushservice', 'Dnscache',
    'DoSvc', 'dot3svc', 'DPS', 'DsmSvc', 'DsSvc', 'DusmSvc', 'EapHost', 'EFS',
    'embeddedmode', 'EntAppSvc', 'EventLog', 'EventSystem', 'Fax', 'fdPHost',
    'FDResPub', 'fhsvc', 'FontCache', 'FrameServer', 'FrameServerMonitor', 'gpsvc',
    'GraphicsPerfSvc', 'hidserv', 'hns', 'HvHost', 'icssvc', 'IKEEXT', 'InstallService',
    'iphlpsvc', 'IpxlatCfgSvc', 'KeyIso', 'KtmRm', 'LanmanServer', 'LanmanWorkstation',
    'lfsvc', 'LicenseManager', 'lltdsvc', 'lmhosts', 'LSM', 'LxpAutoUpdateSvc',
    'MapsBroker', 'McpManagementService', 'MessagingService', 'MicrosoftEdgeElevationService',
    'MixedRealityOpenXRSvc', 'MLAutoUpdate', 'MMCSS', 'MpsSvc', 'MSDTC', 'MSiSCSI',
    'msiserver', 'NaturalAuthentication', 'NcaSvc', 'NcbService', 'NcdAutoSetup',
    'netprofm', 'NetSetupSvc', 'NetTcpPortSharing', 'NgcCtnrSvc', 'NgcSvc', 'NlaSvc',
    'nsi', 'OneSyncSvc', 'PcaSvc', 'perceptionsimulation', 'PerfHost', 'PhoneSvc',
    'pla', 'PlugPlay', 'PolicyAgent', 'Power', 'PrintNotify', 'PrintWorkflowUserSvc',
    'ProfSvc', 'PushToInstall', 'QWAVE', 'RasAuto', 'RasMan', 'RemoteAccess',
    'RemoteRegistry', 'RetailDemo', 'RmSvc', 'RpcEptMapper', 'RpcLocator', 'RpcSs',
    'RtkBtManServ', 'SamSs', 'ScDeviceEnum', 'Schedule', 'SCPolicySvc', 'SDRSVC',
    'seclogon', 'SecurityHealthService', 'SEMgrSvc', 'SENS', 'Sense', 'SensorDataService',
    'SensorService', 'SensrSvc', 'SessionEnv', 'SgrmBroker', 'SharedAccess',
    'SharedRealitySvc', 'ShellHWDetection', 'shpamsvc', 'simptcp', 'smphost',
    'SmsRouter', 'SNMPTrap', 'Spooler', 'SSDPSRV', 'SstpSvc', 'StateRepository',
    'stisvc', 'StorSvc', 'svsvc', 'swprv', 'SysMain', 'SystemEventsBroker',
    'TabletInputService', 'TapiSrv', 'TermService', 'TextInputManagementService',
    'Themes', 'TieringEngineService', 'TimeBrokerSvc', 'TrkWks', 'TroubleshootingSvc',
    'TrustedInstaller', 'tzautoupdate', 'UevAgentService', 'UmRdpService', 'upnphost',
    'UserDataSvc', 'UserManager', 'UsoSvc', 'VaultSvc', 'vds', 'vmicguestinterface',
    'vmicheartbeat', 'vmickvpexchange', 'vmicrdv', 'vmicshutdown', 'vmictimesync',
    'vmicvmsession', 'vmicvss', 'VSS', 'W32Time', 'WaaSMedicSvc', 'WarpJITSvc',
    'wbengine', 'WbioSrvc', 'Wcmsvc', 'wcncsvc', 'WdiServiceHost', 'WdiSystemHost',
    'WdNisSvc', 'WebClient', 'Wecsvc', 'WEPHOSTSVC', 'wercplsupport', 'WerSvc',
    'WiaRpc', 'WinDefend', 'WinHttpAutoProxySvc', 'Winmgmt', 'WinRM', 'wisvc',
    'WlanSvc', 'wlidsvc', 'wlpasvc', 'WManSvc', 'WMPNetworkSvc', 'workfolderssvc',
    'WpcMonSvc', 'WPDBusEnum', 'WpnService', 'WpnUserService', 'wscsvc', 'WSearch',
    'wuauserv', 'WwanSvc', 'XblAuthManager', 'XblGameSave', 'XboxGipSvc', 'XboxNetApiSvc',
    'XblGameSave'
)

$allServices = Get-CimInstance -ClassName Win32_Service
$result = @()

foreach ($svc in $allServices) {
    if (-not $IncludeSystem) {
        $isSystem = $systemPrefixes | Where-Object { $svc.Name -like "$_*" }
        if ($isSystem) { continue }
    }

    $result += @{
        name       = $svc.Name
        display    = $svc.DisplayName
        status     = $svc.State.ToLower()       # running, stopped, paused, etc.
        start_type = $svc.StartMode.ToLower()   # auto, manual, disabled
        account    = $svc.StartName
        pid        = if ($svc.ProcessId -gt 0) { $svc.ProcessId } else { $null }
    }
}

# Sort: running first, then alphabetically
$result = $result | Sort-Object { if ($_.status -eq 'running') { 0 } else { 1 } }, { $_.name }

Write-Host "Found $($result.Count) non-system services."

# ── Send to server ────────────────────────────────────────────────────────────
$payload = @{
    agent_key = $cfg.agent_key
    plugin    = 'windows-services'
    data      = $result
}

$json = $payload | ConvertTo-Json -Depth 5 -Compress
Write-Host "Sending to $($cfg.server_url)?action=plugin-data ..."
try {
    $resp = Invoke-RestMethod `
        -Uri "$($cfg.server_url)?action=plugin-data" `
        -Method Post `
        -ContentType 'application/json' `
        -Body ([System.Text.Encoding]::UTF8.GetBytes($json)) `
        -UseBasicParsing
    Write-Host "Response: $($resp | ConvertTo-Json -Compress)"
} catch {
    Write-Error "Failed to send service data: $_"
    exit 1
}
