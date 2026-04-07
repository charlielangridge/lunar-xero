# Releasing To The Public Repo

This package is developed in the private `lunar-xero-dev` repository and published into the separate public `lunar-xero` repository when a release is ready.

## One-time setup

Rename the current private GitHub repository to `lunar-xero-dev`.

Create a separate public GitHub repository called `lunar-xero` and clone it somewhere outside this repo, for example:

```powershell
cd C:\Users\charl\PhpstormProjects
git clone https://github.com/charlielangridge/lunar-xero.git lunar-xero
```

Keep `lunar-xero-dev` private. Packagist should point at the public `lunar-xero` repository only.

## Release flow

From the private repo:

```powershell
pwsh -File .\scripts\Sync-PublicRelease.ps1 -PublicRepoPath C:\Users\charl\PhpstormProjects\lunar-xero
```

The script:

- checks that the target path is a separate git repository
- refuses to run against a dirty private repo unless `-AllowDirty` is passed
- copies only tracked files from this repo
- excludes private or local-only paths such as `.agents`, `.claude`, `vendor`, `build`, and `workbench`

Then from the public repo:

```powershell
git status
git add .
git commit -m "Release v0.1.0"
git tag v0.1.0
git push origin main --follow-tags
```

## Notes

- The public repo gets its own clean history. Your private WIP history stays private.
- If you want to publish from a specific ref, pass `-Ref <tag-or-commit>` to the sync script.
- If you intentionally want to export the current dirty working tree, pass `-AllowDirty`.
