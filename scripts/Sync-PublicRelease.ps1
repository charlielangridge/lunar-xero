[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$PublicRepoPath,

    [string]$Ref = "HEAD",

    [switch]$AllowDirty,

    [switch]$SkipRemoteCheck
)

$ErrorActionPreference = "Stop"

$sourceRepo = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$publicRepo = (Resolve-Path $PublicRepoPath).Path

if ($sourceRepo -eq $publicRepo) {
    throw "Public repo path must be different from the private source repo."
}

if (-not (Test-Path (Join-Path $publicRepo ".git"))) {
    throw "Target path must be an existing git repository: $publicRepo"
}

$statusOutput = git -C $sourceRepo status --short

if (-not $AllowDirty -and $statusOutput) {
    throw "Private repo has uncommitted changes. Commit or stash them first, or rerun with -AllowDirty."
}

if (-not $SkipRemoteCheck) {
    $publicOrigin = git -C $publicRepo remote get-url origin 2>$null

    if (-not $publicOrigin) {
        throw "Target repo does not have an origin remote configured. Rerun with -SkipRemoteCheck to bypass."
    }
}

$excludePatterns = @(
    ".agents/**",
    ".claude/**",
    ".fleet/**",
    ".github/**",
    ".idea/**",
    ".phpunit.cache/**",
    ".vscode/**",
    "build/**",
    "composer.lock",
    "coverage/**",
    "docs/**",
    "RELEASING.md",
    "skills-lock.json",
    "vendor/**",
    "workbench/**"
)

$trackedFiles = git -C $sourceRepo ls-tree -r --name-only $Ref

if (-not $trackedFiles) {
    throw "No tracked files found for ref '$Ref'."
}

$filesToCopy = $trackedFiles | Where-Object {
    $path = $_ -replace "\\", "/"

    foreach ($pattern in $excludePatterns) {
        if ($path -like $pattern) {
            return $false
        }
    }

    return $true
}

if (-not $filesToCopy) {
    throw "No files left to sync after applying exclusions."
}

Get-ChildItem -LiteralPath $publicRepo -Force | Where-Object { $_.Name -ne ".git" } | Remove-Item -Recurse -Force

foreach ($relativePath in $filesToCopy) {
    $sourcePath = Join-Path $sourceRepo $relativePath
    $targetPath = Join-Path $publicRepo $relativePath
    $targetDirectory = Split-Path -Parent $targetPath

    if (-not (Test-Path $sourcePath)) {
        throw "Tracked file is missing from working tree: $relativePath"
    }

    if (-not (Test-Path $targetDirectory)) {
        New-Item -ItemType Directory -Path $targetDirectory -Force | Out-Null
    }

    Copy-Item -LiteralPath $sourcePath -Destination $targetPath -Force
}

Write-Host "Synced $($filesToCopy.Count) files from '$sourceRepo' to '$publicRepo'."
Write-Host "Next steps:"
Write-Host "  1. Review the public repo diff"
Write-Host "  2. Commit the curated snapshot"
Write-Host "  3. Tag and push the release"
