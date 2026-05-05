[CmdletBinding()]
param(
    [string]$PublicRepoPath,

    [string]$Version,

    [string]$Ref = "HEAD",

    [string]$RemoteName = "origin",

    [string]$PublicBranch = "main",

    [string]$ExpectedPublicRemoteUrl,

    [switch]$AllowDirty,

    [switch]$AllowDirtyPublic,

    [switch]$SkipRemoteCheck,

    [switch]$Push,

    [switch]$NoPush,

    [switch]$Yes
)

$ErrorActionPreference = "Stop"

function Invoke-Git {
    param(
        [Parameter(Mandatory = $true, ValueFromRemainingArguments = $true)]
        [string[]]$Arguments
    )

    $output = & git @Arguments 2>&1

    if ($LASTEXITCODE -ne 0) {
        throw ($output -join [Environment]::NewLine)
    }

    return $output
}

function Read-Required {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Prompt
    )

    do {
        $value = Read-Host $Prompt
    } while ([string]::IsNullOrWhiteSpace($value))

    return $value.Trim()
}

function Confirm-Continue {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Prompt
    )

    if ($Yes) {
        return $true
    }

    $answer = Read-Host "$Prompt [y/N]"

    return $answer -match "^(y|yes)$"
}

function Normalize-Version {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Value
    )

    $normalized = $Value.Trim()

    if ($normalized -notmatch "^v") {
        $normalized = "v$normalized"
    }

    if ($normalized -notmatch "^v\d+\.\d+\.\d+([\-+][0-9A-Za-z.-]+)?$") {
        throw "Release version must look like vX.Y.Z, for example v0.2.1."
    }

    return $normalized
}

if ($Push -and $NoPush) {
    throw "Use either -Push or -NoPush, not both."
}

if ([string]::IsNullOrWhiteSpace($PublicRepoPath)) {
    $PublicRepoPath = Read-Required "Public repository path"
}

if ([string]::IsNullOrWhiteSpace($Version)) {
    $Version = Read-Required "Release version, for example v0.2.1"
}

$releaseVersion = Normalize-Version $Version
$sourceRepo = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$publicRepo = (Resolve-Path $PublicRepoPath).Path
$publicRoot = [System.IO.Path]::GetPathRoot($publicRepo)

if ($sourceRepo -eq $publicRepo) {
    throw "Public repo path must be different from the private source repo."
}

$pathSeparators = [char[]]@([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)
$sourcePrefix = $sourceRepo.TrimEnd($pathSeparators) + [System.IO.Path]::DirectorySeparatorChar

if ($publicRepo.StartsWith($sourcePrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "Public repo path must be outside the private source repo."
}

if ($publicRepo -eq $publicRoot) {
    throw "Refusing to sync into a filesystem root: $publicRepo"
}

if (-not (Test-Path (Join-Path $publicRepo ".git"))) {
    throw "Target path must be an existing git repository: $publicRepo"
}

$sourceStatus = Invoke-Git @("-C", $sourceRepo, "status", "--short")

if (-not $AllowDirty -and $sourceStatus) {
    throw "Private repo has uncommitted changes. Commit or stash them first, or rerun with -AllowDirty."
}

$publicStatus = Invoke-Git @("-C", $publicRepo, "status", "--short")

if (-not $AllowDirtyPublic -and $publicStatus) {
    throw "Public repo has uncommitted changes. Commit or stash them first, or rerun with -AllowDirtyPublic."
}

$publicBranchName = (Invoke-Git @("-C", $publicRepo, "rev-parse", "--abbrev-ref", "HEAD") | Select-Object -First 1).Trim()

if ($publicBranchName -ne $PublicBranch) {
    throw "Public repo must be checked out on '$PublicBranch'. Current branch is '$publicBranchName'."
}

if (-not $SkipRemoteCheck) {
    $sourceOrigin = ""
    $publicOrigin = ""

    try {
        $sourceOrigin = (Invoke-Git @("-C", $sourceRepo, "remote", "get-url", $RemoteName) | Select-Object -First 1).Trim()
    } catch {
        $sourceOrigin = ""
    }

    try {
        $publicOrigin = (Invoke-Git @("-C", $publicRepo, "remote", "get-url", $RemoteName) | Select-Object -First 1).Trim()
    } catch {
        throw "Public repo does not have a '$RemoteName' remote configured. Rerun with -SkipRemoteCheck to bypass."
    }

    if ($sourceOrigin -and ($publicOrigin -eq $sourceOrigin)) {
        throw "Public repo '$RemoteName' points at the same remote as the private repo: $publicOrigin"
    }

    if ($ExpectedPublicRemoteUrl -and ($publicOrigin -ne $ExpectedPublicRemoteUrl)) {
        throw "Public repo '$RemoteName' is '$publicOrigin', expected '$ExpectedPublicRemoteUrl'."
    }

    if ($publicOrigin -match "(?i)(-dev|private)") {
        throw "Public repo '$RemoteName' looks like a private/development remote: $publicOrigin"
    }

    Write-Host "Public remote: $publicOrigin"

    if (-not (Confirm-Continue "Continue with this public remote?")) {
        throw "Release cancelled."
    }

    Invoke-Git @("-C", $publicRepo, "fetch", $RemoteName, $PublicBranch, "--tags") | Out-Null

    $remoteBranch = "$RemoteName/$PublicBranch"
    Invoke-Git @("-C", $publicRepo, "rev-parse", "--verify", $remoteBranch) | Out-Null

    & git -C $publicRepo merge-base --is-ancestor $remoteBranch HEAD

    if ($LASTEXITCODE -eq 1) {
        throw "Public repo branch '$PublicBranch' is missing commits from '$remoteBranch'. Pull or reset the public repo before releasing."
    }

    if ($LASTEXITCODE -ne 0) {
        throw "Unable to compare '$PublicBranch' with '$remoteBranch'."
    }
}

$existingTag = Invoke-Git @("-C", $publicRepo, "tag", "--list", $releaseVersion)

if ($existingTag) {
    throw "Tag '$releaseVersion' already exists locally in the public repo."
}

if (-not (Confirm-Continue "Replace public repo contents at '$publicRepo' with the curated release snapshot for $releaseVersion?")) {
    throw "Release cancelled."
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

$copySource = $sourceRepo
$tempWorktree = $null
$useWorkingTree = $Ref -eq "HEAD"

try {
    if (-not $useWorkingTree) {
        $tempWorktree = Join-Path ([System.IO.Path]::GetTempPath()) "public-release-$([guid]::NewGuid().ToString('N'))"
        Invoke-Git @("-C", $sourceRepo, "worktree", "add", "--detach", $tempWorktree, $Ref) | Out-Null
        $copySource = $tempWorktree
    }

    if ($useWorkingTree) {
        $trackedFiles = Invoke-Git @("-C", $sourceRepo, "ls-files")
    } else {
        $trackedFiles = Invoke-Git @("-C", $sourceRepo, "ls-tree", "-r", "--name-only", $Ref)
    }

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

    Get-ChildItem -LiteralPath $publicRepo -Force |
        Where-Object { $_.Name -ne ".git" } |
        Remove-Item -Recurse -Force

    foreach ($relativePath in $filesToCopy) {
        $sourcePath = Join-Path $copySource $relativePath
        $targetPath = Join-Path $publicRepo $relativePath
        $targetDirectory = Split-Path -Parent $targetPath

        if (-not (Test-Path $sourcePath)) {
            if ($useWorkingTree) {
                continue
            }

            throw "Tracked file is missing from release source: $relativePath"
        }

        if (-not (Test-Path $targetDirectory)) {
            New-Item -ItemType Directory -Path $targetDirectory -Force | Out-Null
        }

        Copy-Item -LiteralPath $sourcePath -Destination $targetPath -Force
    }
} finally {
    if ($tempWorktree -and (Test-Path $tempWorktree)) {
        Invoke-Git @("-C", $sourceRepo, "worktree", "remove", "--force", $tempWorktree) | Out-Null
    }
}

if (Test-Path (Join-Path $publicRepo "RELEASING.md")) {
    throw "RELEASING.md was copied into the public repo unexpectedly."
}

Invoke-Git @("-C", $publicRepo, "add", "-A") | Out-Null

& git -C $publicRepo diff --cached --quiet

if ($LASTEXITCODE -eq 0) {
    throw "No public repo changes to release."
}

if ($LASTEXITCODE -ne 1) {
    throw "Unable to inspect staged public repo diff."
}

Write-Host "Staged public release changes:"
Invoke-Git @("-C", $publicRepo, "diff", "--cached", "--stat") | ForEach-Object { Write-Host $_ }

if (-not (Confirm-Continue "Commit and tag $releaseVersion in the public repo?")) {
    throw "Release cancelled. Changes are staged in the public repo."
}

Invoke-Git @("-C", $publicRepo, "commit", "-m", "Release $releaseVersion") | ForEach-Object { Write-Host $_ }
Invoke-Git @("-C", $publicRepo, "tag", "-a", $releaseVersion, "-m", "Release $releaseVersion") | Out-Null

Write-Host "Created public release commit and annotated tag $releaseVersion."

$shouldPush = $Push

if (-not $Push -and -not $NoPush) {
    $shouldPush = Confirm-Continue "Push '$PublicBranch' and tags to '$RemoteName' now?"
}

if ($shouldPush) {
    Invoke-Git @("-C", $publicRepo, "push", $RemoteName, $PublicBranch, "--follow-tags") | ForEach-Object { Write-Host $_ }
    Write-Host "Pushed $releaseVersion to the public repo."
} else {
    Write-Host "Push skipped. To publish later, run this from the public repo:"
    Write-Host "  git push $RemoteName $PublicBranch --follow-tags"
}
