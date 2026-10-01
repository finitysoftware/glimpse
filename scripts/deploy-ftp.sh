#!/usr/bin/env bash
# Publishes a verified release directory (from scripts/package.mjs) to a web host over FTPS.
# The FTP account should be limited to the Glimpse folder, so "/" on the server is that folder.
#
# Required: FTP_HOST, FTP_USERNAME, LFTP_PASSWORD (the FTP password), RELEASE (release directory), DEPLOY_SHA (commit).
# Optional: FTP_REMOTE_DIR (default /), FTP_TLS_VERIFY (yes|no, default yes).
#
# Server-only files are never uploaded or deleted: private/backend/config/*.local.php (settings and keys),
# private/logs/ and .well-known/. The API answers 503 (maintenance.flag) while files are copied; the pages
# are copied last, so they never point to assets that are not there yet. If the release adds database
# migrations, maintenance stays on until they are run over SSH.
set -euo pipefail
: "${FTP_HOST:?}" "${FTP_USERNAME:?}" "${LFTP_PASSWORD:?}" "${RELEASE:?}" "${DEPLOY_SHA:?}"
remote="${FTP_REMOTE_DIR:-/}"
verify="${FTP_TLS_VERIFY:-yes}"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
touch "$work/maintenance.flag"
printf '%s\n' "$DEPLOY_SHA" > "$work/deployed-commit"

# FTPS only: the password and the files never travel unencrypted.
session() {
  cat <<EOF
set cmd:fail-exit yes
set ftp:ssl-force yes
set ftp:ssl-protect-data yes
set ftp:ssl-protect-list yes
set ssl:verify-certificate $verify
set ftp:list-options -a
set net:max-retries 3
set net:timeout 30
open -u "$FTP_USERNAME" --env-password "ftp://$FTP_HOST"
cd "$remote"
EOF
}

# Migrations since the last deployed commit decide whether maintenance must stay on.
previous="$(lftp -c "$(session)
cat private/deployed-commit" 2>/dev/null | tr -dc '0-9a-f' || true)"
if [[ ${#previous} -eq 40 ]] && git cat-file -e "${previous}^{commit}" 2>/dev/null; then
  migrations="$(git diff --name-only "$previous" "$DEPLOY_SHA" -- backend/migrations)"
  echo "Previous deployment: $previous"
else
  migrations=""
  echo "::warning::The previously deployed commit is unknown; check 'php bin/migrate.php --status' on the server after this deployment."
fi

echo "Uploading $RELEASE to $FTP_HOST:$remote"
lftp -c "$(session)
lcd \"$RELEASE\"
mput -O private/backend/config \"$work/maintenance.flag\"
mirror --reverse --delete --no-perms --parallel=4 --verbose=1 \
  --exclude \"^private/backend/config/[^/]+\\.local\\.php\$\" \
  --exclude \"^private/backend/config/maintenance\\.flag\$\" \
  --exclude \"^private/deployed-commit\$\" \
  --exclude \"^private/logs/\" \
  --exclude \"^\\.well-known/\" \
  --exclude \"^public/(index|workspace)\\.html\$\" \
  . .
mput -O public public/index.html public/workspace.html
mput -O private \"$work/deployed-commit\""

if [[ -n "$migrations" ]]; then
  {
    echo "### Glimpse is deployed, but maintenance is still on"
    echo "This release adds database migrations:"
    echo '```'
    echo "$migrations"
    echo '```'
    echo "Over SSH, in the Glimpse folder's private/backend: \`php bin/migrate.php\`, then \`php bin/maintenance-mode.php off\`."
  } >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}"
  echo "::error::New migrations: run 'php bin/migrate.php' and then 'php bin/maintenance-mode.php off' over SSH."
  exit 1
fi

lftp -c "$(session)
rm private/backend/config/maintenance.flag"
echo "Deployed $DEPLOY_SHA; maintenance is off."
echo "### Glimpse $DEPLOY_SHA is live" >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}"
