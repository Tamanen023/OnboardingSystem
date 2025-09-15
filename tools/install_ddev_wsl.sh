#!/usr/bin/env bash
# Install/initialize DDEV for an existing Drupal project on WSL2 (Ubuntu/Debian).
# Run from the repo root: bash tools/install_ddev_wsl.sh
set -euo pipefail

# ---------------- helpers ----------------
log()   { printf "\n\033[1;34m[INFO]\033[0m  %s\n" "$*"; }
warn()  { printf "\n\033[1;33m[WARN]\033[0m  %s\n" "$*"; }
fatal() { printf "\n\033[1;31m[ERROR]\033[0m %s\n\n" "$*"; exit 1; }

# ---------------- preflight --------------
# Ensure WSL2/Debian/Ubuntu
if ! grep -qiE "(microsoft|wsl)" /proc/version; then
  fatal "This script is intended to be run inside WSL2 (Ubuntu/Debian)."
fi
if ! command -v apt-get >/dev/null 2>&1; then
  fatal "This script currently supports Debian/Ubuntu (apt-based) distros."
fi

# Repo sanity check: must look like a Drupal project
if [[ ! -d ".git" ]] && [[ ! -f "composer.json" ]]; then
  warn "Not a git/composer project. Continuing anyway…"
fi

# Pick project name from folder if not set
PROJECT_NAME="${PROJECT_NAME:-$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9-_')}"
PHP_VERSION="${PHP_VERSION:-8.3}"   # change if you need a specific PHP
DB_TYPE="${DB_TYPE:-mariadb}"       # or mysql/postgres
DB_VERSION="${DB_VERSION:-10.11}"   # mariadb version; ignored for postgres

# Detect docroot (web/ or docroot/ or .)
DETECT_DOCROOT() {
  for d in web docroot . ; do
    if [[ -f "$d/core/lib/Drupal.php" ]] || [[ -f "$d/core/scripts/drupal.sh" ]]; then
      echo "$d"
      return 0
    fi
  done
  echo ""
  return 1
}
DOCROOT="$(DETECT_DOCROOT || true)"
[[ -n "$DOCROOT" ]] || fatal "Could not detect Drupal docroot (looked for core in web/, docroot/, .)."

log "Project: $PROJECT_NAME"
log "Docroot: $DOCROOT"
log "PHP:     $PHP_VERSION"
log "DB:      $DB_TYPE ($DB_VERSION)"

# ---------------- install deps -----------
log "Installing prerequisites (curl, ca-certificates)…"
sudo apt-get update -y
sudo apt-get install -y curl ca-certificates

# Docker Desktop (with WSL integration) must be running
log "Checking Docker availability in WSL…"
command -v docker >/dev/null 2>&1 || fatal "Docker CLI not found in WSL. Install Docker Desktop for Windows and enable WSL integration."
docker version >/dev/null 2>&1 || fatal "Docker is not responding. Start Docker Desktop on Windows and try again."

# ---------------- install ddev -----------
if ! command -v ddev >/dev/null 2>&1; then
  log "Installing DDEV (latest) via official installer…"
  curl -fsSL https://ddev.com/install.sh | bash
else
  log "DDEV already installed: $(ddev version | head -n1)"
fi

# ---------------- configure ddev ---------
if [[ ! -d ".ddev" ]]; then
  log "Creating .ddev/config.yaml…"
  ddev config \
    --project-name="$PROJECT_NAME" \
    --project-type=drupal10 \
    --docroot="$DOCROOT" \
    --php-version="$PHP_VERSION" \
    --webserver-type=nginx-fpm \
    --create-docroot=false

  # Set DB type/version if using MariaDB/MySQL
  if [[ "$DB_TYPE" == "mariadb" ]]; then
    yq_cmd="spruce" # placeholder fallback if yq isn't installed
  fi

  # Write DB engine/version directly (no yq dependency)
  # (DDEV tolerates these keys; OK to leave defaults if you prefer)
  perl -0777 -pe "s|^database:\n  type:.*\n  version:.*|database:\n  type: $DB_TYPE\n  version: \"$DB_VERSION\"|m" -i .ddev/config.yaml || true
else
  log ".ddev already exists — skipping config."
fi

# ---------------- start ddev -------------
log "Starting DDEV…"
ddev start

# ---------------- composer install (optional) ----
if [[ -f "composer.json" ]]; then
  log "Running 'ddev composer install' (skip with SKIP_COMPOSER=1)…"
  if [[ "${SKIP_COMPOSER:-0}" != "1" ]]; then
    ddev composer install
  else
    warn "Skipping composer install as requested."
  fi
fi

# ---------------- wrap-up ----------------
ROUTER_URL="$(ddev describe 2>/dev/null | awk '/https:.*ddev.site/ {print $1; exit}')"
DIRECT_URL="$(ddev describe 2>/dev/null | awk '/:80|:8025|:8036/ {print $1}' | head -n1)"

cat <<EOF

✅ DDEV is ready.

Project name:    $PROJECT_NAME
Docroot:         $DOCROOT
Drupal URL:      ${ROUTER_URL:-"(via router not detected yet)"}
Direct container: (use only for debugging)
                  $DIRECT_URL

Next steps:
1) If your browser shows TLS warnings on *.ddev.site, on WINDOWS run:
     PowerShell (Admin):
       winget install FiloSottile.mkcert
       mkcert -install
   Then back in WSL:
       ddev poweroff && ddev start

2) If this is a fresh checkout:
     - Create a database or import one:
         ddev import-db --src=path/to/dump.sql.gz
     - Sync files (if needed) into web/sites/default/files

3) Open the site:
     ddev launch

Tips:
- Keep the repo under your WSL home (e.g., /home/<you>/projects/…) for best performance.
- To stop all projects: ddev poweroff
- To enable utilities:
     ddev get ddev/ddev-phpmyadmin && ddev restart
     ddev get ddev/ddev-mailpit && ddev restart
EOF
