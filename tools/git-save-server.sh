#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
MESSAGE="${1:-}"
SSH_KEY_PATH="${GIT_SAVE_SSH_KEY:-/root/.ssh/id_ed25519_textwhisper}"

git_repo() {
  git -c safe.directory="$REPO_DIR" "$@"
}

if [[ -z "$MESSAGE" ]]; then
  read -r -p "Commit message: " MESSAGE
fi

if [[ -z "$MESSAGE" ]]; then
  echo "Commit message cannot be empty."
  exit 1
fi

cd "$REPO_DIR"

if [[ -z "$(git_repo status --porcelain)" ]]; then
  echo "No changes to commit."
  exit 0
fi

git_repo add -A
git_repo commit -m "$MESSAGE"

if [[ -f "$SSH_KEY_PATH" ]]; then
  GIT_SSH_COMMAND="ssh -i $SSH_KEY_PATH -o IdentitiesOnly=yes" git_repo push
else
  git_repo push
fi

echo "Done."
