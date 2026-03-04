#!/bin/bash

# =============================================================================
# WordPress.org SVN Release Script
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

MAIN_PLUGIN_FILE="$ROOT_DIR/tp-media-offload-edge-cdn.php"
PLUGIN_SLUG="$(basename "$MAIN_PLUGIN_FILE" .php)"
CURRENT_VERSION="$(grep -m1 "Version:" "$MAIN_PLUGIN_FILE" | sed 's/.*Version:[[:space:]]*//' | tr -d ' ')"

ENV_FILE="$ROOT_DIR/.env"

ENV_WPORG_SVN_URL="${WPORG_SVN_URL-}"
ENV_WPORG_SVN_WORKING_COPY="${WPORG_SVN_WORKING_COPY-}"

if [ -f "$ENV_FILE" ]; then
	# shellcheck disable=SC1090
	source "$ENV_FILE"
fi

if [ -n "$ENV_WPORG_SVN_URL" ]; then
	WPORG_SVN_URL="$ENV_WPORG_SVN_URL"
fi

if [ -n "$ENV_WPORG_SVN_WORKING_COPY" ]; then
	WPORG_SVN_WORKING_COPY="$ENV_WPORG_SVN_WORKING_COPY"
fi

SVN_URL="${WPORG_SVN_URL:-https://plugins.svn.wordpress.org/${PLUGIN_SLUG}}"
SVN_WORKING_COPY="${WPORG_SVN_WORKING_COPY:-$HOME/tmp/wporg-${PLUGIN_SLUG}}"

RELEASE_VERSION=""
COMMIT_MESSAGE=""
SKIP_TESTS="0"
NO_COMMIT="0"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

print_help() {
	echo "Usage: ./scripts/release-svn.sh [options]"
	echo ""
	echo "Options:"
	echo "  -v, --version <x.y.z>   Release version (if omitted, script asks)"
	echo "  -m, --message <text>    SVN commit message"
	echo "      --skip-tests        Skip composer test"
	echo "      --no-commit         Prepare working copy only, do not commit"
	echo "  -h, --help              Show this help"
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		-v|--version)
			RELEASE_VERSION="${2:-}"
			shift 2
			;;
		-m|--message)
			COMMIT_MESSAGE="${2:-}"
			shift 2
			;;
		--skip-tests)
			SKIP_TESTS="1"
			shift
			;;
		--no-commit)
			NO_COMMIT="1"
			shift
			;;
		-h|--help)
			print_help
			exit 0
			;;
		*)
			log_error "Unknown option: $1"
			print_help
			exit 1
			;;
	esac
done

if ! command -v svn >/dev/null 2>&1; then
	log_error "svn command not found. Install Subversion first."
	exit 1
fi

if [ -z "$RELEASE_VERSION" ]; then
	read -r -p "Enter release version (current: $CURRENT_VERSION): " RELEASE_VERSION
fi

if [[ ! "$RELEASE_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	log_error "Invalid version format. Use semantic version (e.g. 1.0.1)."
	exit 1
fi

log_info "Release version: $RELEASE_VERSION"
log_info "SVN URL: $SVN_URL"
log_info "SVN working copy: $SVN_WORKING_COPY"

if [ "$RELEASE_VERSION" != "$CURRENT_VERSION" ]; then
	log_info "Bumping version from $CURRENT_VERSION to $RELEASE_VERSION..."
	"$ROOT_DIR/scripts/build.sh" version "$RELEASE_VERSION"
else
	log_info "Version already set to $RELEASE_VERSION"
fi

if [ "$SKIP_TESTS" = "0" ]; then
	log_info "Running tests..."
	(
		cd "$ROOT_DIR"
		composer test
	)
else
	log_warn "Skipping tests (--skip-tests)"
fi

log_info "Building release artifacts..."
(
	cd "$ROOT_DIR"
	./scripts/build.sh deploy-svn
)

if [ ! -d "$SVN_WORKING_COPY/.svn" ]; then
	log_info "Checking out SVN repository..."
	mkdir -p "$(dirname "$SVN_WORKING_COPY")"
	svn checkout "$SVN_URL" "$SVN_WORKING_COPY"
else
	log_info "Updating existing SVN working copy..."
	(
		cd "$SVN_WORKING_COPY"
		svn update
	)
fi

if [ -d "$SVN_WORKING_COPY/tags/$RELEASE_VERSION" ] && [ -n "$(ls -A "$SVN_WORKING_COPY/tags/$RELEASE_VERSION" 2>/dev/null || true)" ]; then
	log_warn "Tag $RELEASE_VERSION already exists in working copy."
	read -r -p "Continue and overwrite local tag content? [y/N]: " overwrite_tag
	if [[ ! "${overwrite_tag,,}" =~ ^y(es)?$ ]]; then
		log_error "Release aborted."
		exit 1
	fi
fi

log_info "Syncing trunk/assets/tag into SVN working copy..."
mkdir -p "$SVN_WORKING_COPY/trunk" "$SVN_WORKING_COPY/assets" "$SVN_WORKING_COPY/tags/$RELEASE_VERSION"

rsync -a --delete "$ROOT_DIR/svn/trunk/" "$SVN_WORKING_COPY/trunk/"
rsync -a --delete "$ROOT_DIR/svn/assets/" "$SVN_WORKING_COPY/assets/"
rsync -a --delete "$ROOT_DIR/svn/tags/$RELEASE_VERSION/" "$SVN_WORKING_COPY/tags/$RELEASE_VERSION/"

log_info "Staging SVN changes..."
(
	cd "$SVN_WORKING_COPY"
	svn add --force . >/dev/null
	svn status | awk '/^\!/ {print substr($0,9)}' | while IFS= read -r missing_path; do
		[ -n "$missing_path" ] && svn rm --force "$missing_path" >/dev/null
	done
)

echo ""
log_info "SVN status:"
(
	cd "$SVN_WORKING_COPY"
	svn status
)

if [ "$NO_COMMIT" = "1" ]; then
	log_warn "Skipping commit (--no-commit). Working copy is ready at:"
	echo "  $SVN_WORKING_COPY"
	exit 0
fi

if [ -z "$COMMIT_MESSAGE" ]; then
	read -r -p "Commit message [Release ${RELEASE_VERSION}]: " COMMIT_MESSAGE
	COMMIT_MESSAGE="${COMMIT_MESSAGE:-Release ${RELEASE_VERSION}}"
fi

echo ""
read -r -p "Commit to WordPress.org SVN now? [y/N]: " confirm_commit
if [[ ! "${confirm_commit,,}" =~ ^y(es)?$ ]]; then
	log_warn "Commit skipped. You can commit manually from:"
	echo "  $SVN_WORKING_COPY"
	exit 0
fi

log_info "Committing to SVN..."
(
	cd "$SVN_WORKING_COPY"
	svn commit -m "$COMMIT_MESSAGE"
)

log_info "Release completed successfully."
