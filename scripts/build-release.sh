#!/usr/bin/env bash
# ==============================================================================
# UPI Gateway — Production Release Packaging Script
#
# Creates a clean, secure, and production-ready distributable ZIP package
# suitable for cPanel shared hosting and VPS environments.
#
# Hardened against:
#   - SEC-01: Version parameter path traversal & invalid characters
#   - SEC-02: Isolated frontend build strictly from target Git commit
#   - SEC-03: Atomic publication preventing partial/corrupted archives on SIGINT
#   - SEC-04: Deterministic build with commit-normalized timestamps & sorted files
#   - SEC-05: Guard against accidental overwriting of existing release archives
#
# Requirements:
#   - bash, git, zip, tar
#   - php (>= 8.2), composer
#   - node, npm
#
# Usage:
#   ./scripts/build-release.sh <version> [commit] [output_dir]
#
# Examples:
#   ./scripts/build-release.sh v1.1.0 021a875 releases
#   OVERWRITE=1 ./scripts/build-release.sh v1.1.0 021a875 releases
#   RELEASE_VERSION=v1.1.0 ./scripts/build-release.sh
# ==============================================================================

set -euo pipefail
IFS=$'\n\t'

# --- 1. Terminal Styling & Logging --------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log_info() {
    printf "${BLUE}[INFO]${NC} %s\n" "$*"
}

log_success() {
    printf "${GREEN}[SUCCESS]${NC} %s\n" "$*"
}

log_warn() {
    printf "${YELLOW}[WARN]${NC} %s\n" "$*"
}

log_error() {
    printf "${RED}[ERROR]${NC} %s\n" "$*" >&2
}

# --- 2. Input Parameter Validation --------------------------------------------
VERSION="${1:-${RELEASE_VERSION:-}}"
COMMIT="${2:-${RELEASE_COMMIT:-HEAD}}"
OUTPUT_DIR_PARAM="${3:-${OUTPUT_DIR:-releases}}"
OVERWRITE="${OVERWRITE:-0}"

if [[ -z "${VERSION}" ]]; then
    log_error "Release version is required."
    printf "\nUsage: %s <version> [commit] [output_dir]\n" "$0"
    printf "Example: %s v1.1.0 021a875 releases\n\n" "$0"
    exit 1
fi

# [SEC-01 Fix] Validate version using strict safe-character allowlist
if [[ ! "${VERSION}" =~ ^[a-zA-Z0-9][a-zA-Z0-9._-]*$ ]] || [[ "${VERSION}" == *".."* ]]; then
    log_error "Invalid release version format '${VERSION}'."
    log_error "Version must start with an alphanumeric character and contain only [a-zA-Z0-9._-]. Path traversal (..) is forbidden."
    exit 1
fi

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Verify git commit exists
if ! RESOLVED_COMMIT="$(git -C "${PROJECT_ROOT}" rev-parse --verify "${COMMIT}^{commit}" 2>/dev/null)"; then
    log_error "Target Git commit '${COMMIT}' could not be resolved in repository."
    exit 1
fi

SHORT_COMMIT="$(git -C "${PROJECT_ROOT}" rev-parse --short "${RESOLVED_COMMIT}")"
COMMIT_MSG="$(git -C "${PROJECT_ROOT}" log -1 --pretty=format:'%s' "${RESOLVED_COMMIT}")"
COMMIT_TIMESTAMP="$(git -C "${PROJECT_ROOT}" log -1 --pretty=format:'%ct' "${RESOLVED_COMMIT}")"

# Resolve output directory (relative to project root unless absolute)
if [[ "${OUTPUT_DIR_PARAM}" = /* ]]; then
    TARGET_OUTPUT_DIR="${OUTPUT_DIR_PARAM}"
else
    TARGET_OUTPUT_DIR="${PROJECT_ROOT}/${OUTPUT_DIR_PARAM}"
fi

# Safety check: do not overwrite existing dist/ unless explicitly forced
if [[ "${TARGET_OUTPUT_DIR}" == "${PROJECT_ROOT}/dist" ]] && [[ -f "${PROJECT_ROOT}/dist/upi-gateway-release-candidate.zip" ]] && [[ "${ALLOW_OVERWRITE_DIST:-0}" != "1" ]]; then
    log_warn "Target output directory is 'dist/' which contains existing development artifacts."
    log_warn "Redirecting output to 'releases/' to preserve existing dist/ files."
    TARGET_OUTPUT_DIR="${PROJECT_ROOT}/releases"
fi

mkdir -p "${TARGET_OUTPUT_DIR}"

ARCHIVE_FILENAME="upi-gateway-${VERSION}.zip"
ARCHIVE_PATH="${TARGET_OUTPUT_DIR}/${ARCHIVE_FILENAME}"
CHECKSUM_FILENAME="${ARCHIVE_FILENAME}.sha256"
CHECKSUM_PATH="${TARGET_OUTPUT_DIR}/${CHECKSUM_FILENAME}"

# [SEC-05 Fix] Guard against accidental overwriting of existing release versions
if [[ -f "${ARCHIVE_PATH}" ]] && [[ "${OVERWRITE}" != "1" ]]; then
    log_error "Destination archive already exists: '${ARCHIVE_PATH}'"
    log_error "Refusing to overwrite existing release. Set OVERWRITE=1 to replace."
    exit 1
fi

if [[ -f "${CHECKSUM_PATH}" ]] && [[ "${OVERWRITE}" != "1" ]]; then
    log_error "Destination checksum already exists: '${CHECKSUM_PATH}'"
    log_error "Refusing to overwrite existing checksum. Set OVERWRITE=1 to replace."
    exit 1
fi

printf "\n==============================================================================\n"
printf " UPI Gateway — Production Release Builder\n"
printf "==============================================================================\n"
printf "  Release Version : %s\n" "${VERSION}"
printf "  Target Commit   : %s (%s)\n" "${SHORT_COMMIT}" "${COMMIT_MSG}"
printf "  Commit Epoch    : %s\n" "${COMMIT_TIMESTAMP}"
printf "  Output Archive  : %s\n" "${ARCHIVE_PATH}"
printf "  Allow Overwrite : %s\n" "${OVERWRITE}"
printf "==============================================================================\n\n"

# --- 3. Prepare Staging Directory from Clean Git Export -----------------------
# [SEC-03 Fix] All build operations and temporary archive generation take place
# in an isolated staging directory. The destination archive is only published
# upon complete, successful validation.
TMP_STAGING_DIR="$(mktemp -d "${TMPDIR:-/tmp}/upi-gateway-release.XXXXXX")"
cleanup() {
    local exit_code=$?
    if [[ -n "${TMP_STAGING_DIR:-}" && -d "${TMP_STAGING_DIR}" ]]; then
        rm -rf "${TMP_STAGING_DIR}"
    fi
    if [[ ${exit_code} -ne 0 ]]; then
        log_error "Build aborted or failed (exit code ${exit_code}). Temporary files cleaned up."
    fi
}
trap cleanup EXIT INT TERM HUP

log_info "Exporting clean source tree from commit ${SHORT_COMMIT} into staging..."
git -C "${PROJECT_ROOT}" archive "${RESOLVED_COMMIT}" | tar -x -C "${TMP_STAGING_DIR}"

# Ensure development-only files and test suites are not present in staging
rm -rf \
    "${TMP_STAGING_DIR}/tests" \
    "${TMP_STAGING_DIR}/phpunit.xml" \
    "${TMP_STAGING_DIR}/.phpunit.result.cache" \
    "${TMP_STAGING_DIR}/.phpunit.cache" \
    "${TMP_STAGING_DIR}/.editorconfig" \
    "${TMP_STAGING_DIR}/.styleci.yml" \
    "${TMP_STAGING_DIR}/.github" \
    "${TMP_STAGING_DIR}/UPI-Gateway-Read-Only-Audit-Plan.md"

# --- 4. Build Frontend Assets Inside Staging (Isolated) -----------------------
# Frontend dependencies are installed strictly from the target commit's locked
# package-lock.json inside the isolated staging directory, eliminating any coupling
# to or mutation of the developer's workspace node_modules.
log_info "Compiling frontend assets strictly from commit ${SHORT_COMMIT} in staging..."
if ! command -v npm >/dev/null 2>&1; then
    log_error "npm command not found. Node.js and npm are required to build assets."
    exit 1
fi

(
    cd "${TMP_STAGING_DIR}"
    log_info "Installing locked frontend dependencies in staging (isolated)..."
    npm ci --ignore-scripts --no-audit --no-fund
    log_info "Building frontend assets inside staging..."
    npm run build
    log_info "Purging staging node_modules..."
    rm -rf node_modules
)

if [[ ! -f "${TMP_STAGING_DIR}/public/build/manifest.json" ]]; then
    log_error "Frontend build failed: 'public/build/manifest.json' was not generated in staging."
    exit 1
fi
log_success "Frontend assets compiled successfully in staging."

# --- 5. Install Composer Production Dependencies in Staging -------------------
log_info "Installing Composer production dependencies (no-dev, optimized)..."
if ! command -v composer >/dev/null 2>&1; then
    log_error "composer command not found. Composer is required for packaging."
    exit 1
fi

(
    cd "${TMP_STAGING_DIR}"
    composer install \
        --no-dev \
        --prefer-dist \
        --optimize-autoloader \
        --no-interaction \
        --no-scripts
)

# Run explicit Laravel package discovery
log_info "Running explicit Laravel package discovery in staging..."
(
    cd "${TMP_STAGING_DIR}"
    php artisan package:discover --ansi
)

# Verify packages.php was created in bootstrap/cache
if [[ ! -f "${TMP_STAGING_DIR}/bootstrap/cache/packages.php" ]]; then
    log_error "Laravel package discovery failed: 'bootstrap/cache/packages.php' not found."
    exit 1
fi

# Ensure no pre-cached runtime configuration or routes exist in the package
rm -f \
    "${TMP_STAGING_DIR}/bootstrap/cache/config.php" \
    "${TMP_STAGING_DIR}/bootstrap/cache/routes-v7.php"

# --- 6. Prepare Storage & Runtime Skeleton ------------------------------------
log_info "Preparing clean storage directory skeleton..."

STORAGE_DIRS=(
    "storage/app/public"
    "storage/app/private"
    "storage/framework/cache/data"
    "storage/framework/sessions"
    "storage/framework/views"
    "storage/logs"
    "bootstrap/cache"
)

for dir in "${STORAGE_DIRS[@]}"; do
    mkdir -p "${TMP_STAGING_DIR}/${dir}"
    if [[ ! -f "${TMP_STAGING_DIR}/${dir}/.gitignore" ]]; then
        printf "*\n!.gitignore\n" > "${TMP_STAGING_DIR}/${dir}/.gitignore"
    fi
done

# Ensure storage is completely clean of runtime logs or artifacts
find "${TMP_STAGING_DIR}/storage/logs" -type f ! -name ".gitignore" -delete
find "${TMP_STAGING_DIR}/storage/framework/sessions" -type f ! -name ".gitignore" -delete
find "${TMP_STAGING_DIR}/storage/framework/cache/data" -type f ! -name ".gitignore" -delete
find "${TMP_STAGING_DIR}/storage/framework/views" -type f ! -name ".gitignore" -delete

# --- 7. Security & Completeness Preflight Checks ------------------------------
log_info "Running strict pre-packaging security & completeness preflight checks..."

check_forbidden() {
    local path="$1"
    local desc="$2"
    if [[ -e "${TMP_STAGING_DIR}/${path}" ]]; then
        log_error "Security violation: Forbidden ${desc} found at '${path}'!"
        exit 1
    fi
}

check_required() {
    local path="$1"
    local desc="$2"
    if [[ ! -f "${TMP_STAGING_DIR}/${path}" ]]; then
        log_error "Completeness violation: Required ${desc} missing at '${path}'!"
        exit 1
    fi
}

# 1. Credentials and environment files
check_forbidden ".env" "live environment file"
check_forbidden ".env.backup" "environment backup file"
check_forbidden ".env.testing" "testing environment file"
check_forbidden "auth.json" "Composer authentication file"
check_required ".env.example" "environment template"

# Verify .env.example contains SESSION_ENCRYPT=true
if ! grep -q "SESSION_ENCRYPT=true" "${TMP_STAGING_DIR}/.env.example"; then
    log_error ".env.example does not have 'SESSION_ENCRYPT=true' enabled by default."
    exit 1
fi

# 2. Databases and data files
check_forbidden "upi_gateway" "root SQLite database file"
SQLITE_COUNT=$(find "${TMP_STAGING_DIR}" -name "*.sqlite*" | wc -l | tr -d ' ')
if [[ "${SQLITE_COUNT}" -ne 0 ]]; then
    log_error "Found ${SQLITE_COUNT} SQLite database file(s) in staging directory!"
    exit 1
fi

# 3. Installer markers and tokens
check_forbidden "storage/installed" "installer lock marker"
TOKEN_COUNT=$(find "${TMP_STAGING_DIR}/storage" -name "installer-*" | wc -l | tr -d ' ')
if [[ "${TOKEN_COUNT}" -ne 0 ]]; then
    log_error "Found installer setup token or lock file in staging storage!"
    exit 1
fi

# 4. Development directories and tests
check_forbidden ".git" "Git repository metadata"
check_forbidden "node_modules" "Node.js modules directory"
check_forbidden "tests" "test suite directory"
check_forbidden "phpunit.xml" "PHPUnit test configuration"
check_forbidden "vendor/phpunit" "PHPUnit development vendor package"
check_forbidden "vendor/fakerphp" "FakerPHP development vendor package"
check_forbidden "vendor/mockery" "Mockery development vendor package"

# 5. Core application & security files
check_required "vendor/autoload.php" "Composer autoloader"
check_required "public/build/manifest.json" "Vite asset manifest"
check_required "public/deployment-check.json" "public hosting probe"
check_required "deployment-private-check.txt" "harmless private hosting probe"
check_required "app/Support/DeploymentUrl.php" "deployment URL abstraction"
check_required "app/Support/DeploymentFilesystem.php" "safe runtime directory preparation"
check_required "app/Support/DeploymentConfiguration.php" "hosting configuration generator"
check_required "app/Http/Middleware/SetDeploymentBasePath.php" "deployment prefix middleware"
check_required "install.php" "root browser installation wizard"
check_required "public/install.php" "public installation wizard delegator"
check_required ".htaccess" "root web server redirection rules"
check_required "public/.htaccess" "public front-controller rules"
check_required "index.php" "root front-controller forwarder"
check_required "app/Http/Middleware/TrustConfiguredProxies.php" "trusted proxy security middleware"
check_required "config/proxy.php" "trusted proxy configuration"
check_required "database/migrations/2026_11_20_000001_create_archived_webhook_records.php" "webhook archival migration"
check_required "docs/CPANEL.md" "cPanel installation documentation"

# Verify docs/CPANEL.md mentions session encryption at rest
if ! grep -q "SESSION_ENCRYPT" "${TMP_STAGING_DIR}/docs/CPANEL.md"; then
    log_error "docs/CPANEL.md does not document SESSION_ENCRYPT at rest."
    exit 1
fi

log_success "All security and completeness preflight checks PASSED."

# --- 8. Normalize Timestamps [SEC-04 Fix] -------------------------------------
# Normalizes all file modification timestamps to the target Git commit date,
# enabling deterministic and reproducible builds.
if date -r 0 +%Y >/dev/null 2>&1; then
    # BSD / macOS date
    TOUCH_DATE="$(date -r "${COMMIT_TIMESTAMP}" +%Y%m%d%H%M.%S)"
else
    # GNU date
    TOUCH_DATE="$(date -d "@${COMMIT_TIMESTAMP}" +%Y%m%d%H%M.%S)"
fi

log_info "Normalizing archive file timestamps to commit date (${TOUCH_DATE})..."
find "${TMP_STAGING_DIR}" -exec touch -t "${TOUCH_DATE}" {} + 2>/dev/null || true

# --- 9. Generate Staging Archive & Publish Atomically [SEC-03 Fix] ------------
log_info "Generating distribution ZIP archive inside temporary staging..."
if ! command -v zip >/dev/null 2>&1; then
    log_error "zip command not found. zip is required to build the release archive."
    exit 1
fi

STAGING_ARCHIVE="${TMP_STAGING_DIR}/${ARCHIVE_FILENAME}"

(
    cd "${TMP_STAGING_DIR}"
    # Sort files to guarantee deterministic archive member ordering
    find . -mindepth 1 ! -name "${ARCHIVE_FILENAME}" | sort | zip -q -@ "${STAGING_ARCHIVE}" -x "*.DS_Store*" -x "*__MACOSX*"
)

if [[ ! -s "${STAGING_ARCHIVE}" ]]; then
    log_error "Generated staging archive is missing or empty."
    exit 1
fi

# Atomically move verified archive into destination directory
log_info "Atomically publishing verified archive to ${TARGET_OUTPUT_DIR}..."
mv "${STAGING_ARCHIVE}" "${ARCHIVE_PATH}"
log_success "Archive published at: ${ARCHIVE_PATH}"

# --- 10. Generate SHA-256 Checksum After Successful Publication ---------------
log_info "Computing SHA-256 checksum on published archive..."
(
    cd "${TARGET_OUTPUT_DIR}"
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "${ARCHIVE_FILENAME}" > "${CHECKSUM_FILENAME}"
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "${ARCHIVE_FILENAME}" > "${CHECKSUM_FILENAME}"
    else
        openssl dgst -sha256 "${ARCHIVE_FILENAME}" | awk -v fname="${ARCHIVE_FILENAME}" '{print $2 "  " fname}' > "${CHECKSUM_FILENAME}"
    fi
)

CHECKSUM_VALUE="$(cat "${CHECKSUM_PATH}")"
ARCHIVE_SIZE="$(du -h "${ARCHIVE_PATH}" | cut -f1)"
TOTAL_FILES="$(unzip -l "${ARCHIVE_PATH}" | tail -1 | awk '{print $2}')"

printf "\n==============================================================================\n"
printf " Release Build Summary\n"
printf "==============================================================================\n"
printf "  Package File : %s\n" "${ARCHIVE_PATH}"
printf "  Package Size : %s\n" "${ARCHIVE_SIZE}"
printf "  Total Files  : %s\n" "${TOTAL_FILES}"
printf "  SHA-256      : %s\n" "${CHECKSUM_VALUE}"
printf "==============================================================================\n\n"
log_success "Release build completed successfully!"
