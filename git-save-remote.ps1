param(
    [string]$Server = "root@77.42.45.28",
    [string]$RepoDir = "/var/www/textwhisper-test",
    [Parameter(Position = 0)]
    [string]$Message
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($Message)) {
    $Message = Read-Host "Commit message"
}

if ([string]::IsNullOrWhiteSpace($Message)) {
    Write-Error "Commit message cannot be empty."
    exit 1
}

# Escape single quotes for safe use inside bash single-quoted strings.
$EscapedRepoDir = $RepoDir.Replace("'", "'""'""'")
$EscapedMessage = $Message.Replace("'", "'""'""'")

$RemoteCmdTemplate = @'
set -e; cd '{0}'; if [ -z "$(git status --porcelain)" ]; then echo "No changes to commit."; exit 0; fi; git add -A; git commit -m '{1}'; git push; echo "Done."
'@

$RemoteCmd = [string]::Format($RemoteCmdTemplate, $EscapedRepoDir, $EscapedMessage)

Write-Host "Saving changes to Git on $Server ..."
ssh $Server $RemoteCmd
