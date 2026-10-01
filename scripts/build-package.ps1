#Requires -Version 5.1
<#
.SYNOPSIS
Builds and verifies the local Glimpse package for a PHP web host.
.DESCRIPTION
Installs locked frontend dependencies with pnpm, builds the public/private release,
runs its local HTTP smoke checks, and creates artifacts/glimpse-package.tar.gz.
One domain (-Domain) serves everything: the landing page on /,
sign-in on /login, the admin panel on /admin, access links on /s/ and the API.
The script checks that the landing page's canonical URL names that domain.
The script does not upload files, run database migrations, or change Git state.
.EXAMPLE
.\scripts\build-package.ps1 -OpenFolder
.EXAMPLE
.\scripts\build-package.ps1 -PhpPath 'C:\php\php.exe' -ComposerPhar 'C:\tools\composer.phar'
.EXAMPLE
.\scripts\build-package.ps1 -Domain 'glimpse.example.com'
#>
[CmdletBinding()]
param(
    [string]$PhpPath = $env:PHP_BIN,
    [string]$ComposerPhar = $env:COMPOSER_PHAR,
    # Public address of the landing page, sign-in and the admin panel.
    [string]$Domain = 'glimpse.finitysoftware.io',
    [switch]$OpenFolder
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Invoke-NativeCommand {
    param(
        [Parameter(Mandatory = $true)][string]$Executable,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )

    & $Executable @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed with exit code ${LASTEXITCODE}: $Executable"
    }
}

if ($Domain -notmatch '^[a-z0-9-]+(?:\.[a-z0-9-]+)+$') {
    throw 'Provide -Domain as a host name only, for example glimpse.example.com.'
}

$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$artifactsPath = Join-Path $projectRoot 'artifacts'
$nodePath = (Get-Command node.exe -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source
# pnpm installed through npm is pnpm.cmd; the standalone installer provides pnpm.exe.
$pnpmCommand = Get-Command pnpm.cmd, pnpm.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
if ($null -eq $pnpmCommand) {
    throw 'pnpm was not found. Install it (for example: npm install --global pnpm) and run the script again.'
}
$pnpmPath = $pnpmCommand.Source
# The Windows tar.exe (bsdtar) understands drive-letter paths; a GNU tar earlier on PATH (for example from Git) does not.
$tarPath = Join-Path $env:SystemRoot 'System32\tar.exe'
if (-not (Test-Path -LiteralPath $tarPath -PathType Leaf)) {
    $tarPath = (Get-Command tar.exe -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source
}

if ([string]::IsNullOrWhiteSpace($PhpPath)) {
    $PhpPath = Join-Path $artifactsPath 'tools/php/php.exe'
    if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
        $PhpPath = (Get-Command php.exe -CommandType Application -ErrorAction Stop | Select-Object -First 1).Source
    }
}
if ([string]::IsNullOrWhiteSpace($ComposerPhar)) {
    $ComposerPhar = Join-Path $artifactsPath 'tools/composer.phar'
}
if (-not (Test-Path -LiteralPath $ComposerPhar -PathType Leaf)) {
    throw 'Composer PHAR was not found. Provide its path with -ComposerPhar.'
}
$PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path
$ComposerPhar = (Resolve-Path -LiteralPath $ComposerPhar).Path

$savedEnvironment = @{}
foreach ($name in @('PHP_BIN', 'COMPOSER_PHAR', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR')) {
    $savedEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

Push-Location -LiteralPath $projectRoot
try {
    $env:PHP_BIN = $PhpPath
    $env:COMPOSER_PHAR = $ComposerPhar
    $env:COMPOSER_HOME = Join-Path $artifactsPath 'composer-home'
    $env:COMPOSER_CACHE_DIR = Join-Path $artifactsPath 'composer-cache'

    Write-Host 'Checking PHP and required extensions...'
    Invoke-NativeCommand $PhpPath @('backend/bin/preflight.php')

    Write-Host 'Installing locked frontend dependencies...'
    Invoke-NativeCommand $pnpmPath @('install', '--frozen-lockfile', '--filter', './frontend')

    Write-Host 'Building and verifying the production package...'
    Invoke-NativeCommand $nodePath @('scripts/package.mjs')
    Invoke-NativeCommand $nodePath @('scripts/smoke-package.mjs')

    $release = (Get-Content -LiteralPath (Join-Path $artifactsPath 'latest-package.txt') -Raw).Trim()
    $release = (Resolve-Path -LiteralPath $release).Path
    $releaseRoot = (Resolve-Path -LiteralPath (Join-Path $artifactsPath 'releases')).Path
    if (-not $release.StartsWith($releaseRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'The release directory must be inside artifacts/releases.'
    }
    # Search engines follow the canonical URL; robots.txt, sitemap.xml and the structured data use the same domain (verify-package.mjs).
    $landing = Get-Content -LiteralPath (Join-Path $release 'public/index.html') -Raw -Encoding UTF8
    $canonical = [regex]::Match($landing, '<link rel="canonical" href="https://([^"/]+)/">').Groups[1].Value
    if ($canonical -ne $Domain) {
        throw "The landing page's canonical domain is '$canonical', not '$Domain'. Update website/index.html, robots.txt and sitemap.xml, or pass the right -Domain."
    }

    # Create and inspect the new archive before replacing the previous upload package.
    $releaseArchive = Join-Path $release 'glimpse-package.tar.gz'
    Invoke-NativeCommand $tarPath @('-czf', $releaseArchive, '-C', $release, '.htaccess', 'public', 'private')
    $entries = @(& $tarPath -tzf $releaseArchive)
    if ($LASTEXITCODE -ne 0) {
        throw 'The release archive could not be read.'
    }
    foreach ($required in @('.htaccess', 'public/.htaccess', 'private/.htaccess', 'public/index.html', 'public/workspace.html', 'public/api/index.php', 'private/backend/vendor/autoload.php')) {
        if ($entries -notcontains $required) {
            throw "The release archive is missing: $required"
        }
    }

    $uploadArchive = Join-Path $artifactsPath 'glimpse-package.tar.gz'
    Copy-Item -LiteralPath $releaseArchive -Destination $uploadArchive -Force
    Write-Host "Release directory: $release"
    Write-Host "Upload archive: $uploadArchive"
    Write-Host ('SHA-256: ' + (Get-FileHash -LiteralPath $uploadArchive -Algorithm SHA256).Hash.ToLowerInvariant())
    Write-Host "Upload .htaccess, public/ and private/ into the document root of $Domain."
    Write-Host 'Verify that HTTP access to private/ is denied before adding server secrets.'
    Write-Host "Hosting setup for https://${Domain}:"
    Write-Host "  - The domain points to an Apache-compatible PHP host with HTTPS, and its document root holds the package."
    Write-Host "  - private/backend/config/settings.local.php: 'origin' => 'https://$Domain', with any other addresses"
    Write-Host '    of the same installation listed in workspace_origins.'
    Write-Host "  - Check https://$Domain/ (landing page), /login (sign-in), /admin (admin sign-in) and /api/health."
    Write-Host 'No upload or database migration was performed.'

    if ($OpenFolder) {
        # Explorer is shown only when explicitly requested with -OpenFolder.
        & explorer.exe $release
    }
} finally {
    foreach ($name in $savedEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($name, $savedEnvironment[$name], 'Process')
    }
    Pop-Location
}
