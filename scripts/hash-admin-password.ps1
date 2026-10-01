#Requires -Version 5.1
<#
.SYNOPSIS
Generates a verified administrator password hash with hidden, repeated input.
.DESCRIPTION
Works in Windows PowerShell 5.1 and PowerShell 7. Passwords are passed to PHP
through UTF-8 standard input, never command-line arguments or temporary files.
The script only prints a hash; it does not change local or server configuration.
.EXAMPLE
.\scripts\hash-admin-password.ps1
.EXAMPLE
.\scripts\hash-admin-password.ps1 -Bcrypt
#>
[CmdletBinding()]
param(
    [string]$PhpPath = $env:PHP_BIN,
    [switch]$Bcrypt
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
if ([string]::IsNullOrWhiteSpace($PhpPath)) {
    $PhpPath = Join-Path $projectRoot 'artifacts/tools/php/php.exe'
    if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
        $PhpPath = (Get-Command php.exe -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source
    }
}
$PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path
$hashScript = Join-Path $projectRoot 'backend/bin/hash-password.php'

$previousConsoleInputEncoding = [Console]::InputEncoding
$passwordSecret = $null
$confirmationSecret = $null
$passwordBuffer = [IntPtr]::Zero
$confirmationBuffer = [IntPtr]::Zero
$plainPassword = $null
$plainConfirmation = $null
$hashProcess = $null
$processStarted = $false
try {
    $passwordSecret = Read-Host 'Admin password (at least 5 characters)' -AsSecureString
    $confirmationSecret = Read-Host 'Repeat admin password' -AsSecureString
    $passwordBuffer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($passwordSecret)
    $confirmationBuffer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($confirmationSecret)
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordBuffer)
    $plainConfirmation = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($confirmationBuffer)
    if (-not [string]::Equals($plainPassword, $plainConfirmation, [StringComparison]::Ordinal)) {
        throw 'Passwords do not match. Run the script again.'
    }

    # Bypass PowerShell's native pipeline encoding and its possible UTF-8 BOM.
    # .NET Framework uses the console input encoding for redirected child stdin.
    [Console]::InputEncoding = New-Object System.Text.UTF8Encoding -ArgumentList $false
    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $PhpPath
    $startInfo.Arguments = '"' + $hashScript + '"'
    if ($Bcrypt) { $startInfo.Arguments += ' --bcrypt' }
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardInput = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    if ($null -ne $startInfo.PSObject.Properties['StandardInputEncoding']) {
        $startInfo.StandardInputEncoding = New-Object System.Text.UTF8Encoding -ArgumentList $false
    }
    $hashProcess = New-Object System.Diagnostics.Process
    $hashProcess.StartInfo = $startInfo
    $processStarted = $hashProcess.Start()
    if (-not $processStarted) { throw 'PHP could not be started.' }
    $outputTask = $hashProcess.StandardOutput.ReadToEndAsync()
    $errorTask = $hashProcess.StandardError.ReadToEndAsync()
    $hashProcess.StandardInput.Write($plainPassword)
    $hashProcess.StandardInput.Close()
    if (-not $hashProcess.WaitForExit(30000)) { throw 'Password hash generation timed out.' }
    $hash = $outputTask.GetAwaiter().GetResult().Trim()
    $failure = $errorTask.GetAwaiter().GetResult().Trim()
    if ($hashProcess.ExitCode -ne 0) { throw "Password hash generation failed: $failure" }
    if ($hash -notmatch '^\$(?:argon2id|2y)\$[^\r\n]+$') { throw 'PHP returned an unexpected hash format.' }
    Write-Output $hash
} finally {
    if ($null -ne $hashProcess) {
        if ($processStarted -and -not $hashProcess.HasExited) {
            $hashProcess.Kill()
            $hashProcess.WaitForExit()
        }
        $hashProcess.Dispose()
    }
    [Console]::InputEncoding = $previousConsoleInputEncoding
    $plainPassword = $null
    $plainConfirmation = $null
    if ($passwordBuffer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordBuffer)
    }
    if ($confirmationBuffer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($confirmationBuffer)
    }
    if ($null -ne $passwordSecret) { $passwordSecret.Dispose() }
    if ($null -ne $confirmationSecret) { $confirmationSecret.Dispose() }
}
