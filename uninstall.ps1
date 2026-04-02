#Requires -RunAsAdministrator
#Requires -Version 5.1
<#
.SYNOPSIS
    Removes the Sermony Windows agent (scheduled task + config files).
#>

$TaskName  = 'SermonyAgent'
$ConfigDir = "$env:ProgramData\sermony"

if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Write-Host "Scheduled task '$TaskName' removed."
} else {
    Write-Host "Scheduled task '$TaskName' not found (already removed?)."
}

if (Test-Path $ConfigDir) {
    Remove-Item -Recurse -Force $ConfigDir
    Write-Host "Config directory $ConfigDir removed."
} else {
    Write-Host "Config directory $ConfigDir not found (already removed?)."
}

Write-Host "Uninstall complete."
