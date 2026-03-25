param(
    [Parameter(Position = 0)]
    [string]$Message
)

$ErrorActionPreference = "Stop"

function Run-Git([string[]]$Args) {
    & git @Args
    if ($LASTEXITCODE -ne 0) {
        throw ("git " + ($Args -join " ") + " failed with exit code $LASTEXITCODE")
    }
}

try {
    git --version | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Git is not installed or not in PATH."
    }

    $inside = git rev-parse --is-inside-work-tree 2>$null
    if ($LASTEXITCODE -ne 0 -or $inside.Trim() -ne "true") {
        throw "Run this script from inside a Git repository."
    }

    if ([string]::IsNullOrWhiteSpace($Message)) {
        $Message = Read-Host "Commit message"
    }

    if ([string]::IsNullOrWhiteSpace($Message)) {
        throw "Commit message cannot be empty."
    }

    $hasChanges = (git status --porcelain)
    if ([string]::IsNullOrWhiteSpace($hasChanges)) {
        Write-Host "No changes to commit."
        exit 0
    }

    Write-Host "Staging changes..."
    Run-Git @("add", "-A")

    Write-Host "Creating commit..."
    Run-Git @("commit", "-m", $Message)

    Write-Host "Pushing..."
    Run-Git @("push")

    Write-Host "Done."
}
catch {
    Write-Error $_.Exception.Message
    exit 1
}
