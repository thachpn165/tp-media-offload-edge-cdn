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
ENV_WPORG_SVN_USERNAME="${WPORG_SVN_USERNAME-}"

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

if [ -n "$ENV_WPORG_SVN_USERNAME" ]; then
	WPORG_SVN_USERNAME="$ENV_WPORG_SVN_USERNAME"
fi

SVN_URL="${WPORG_SVN_URL:-https://plugins.svn.wordpress.org/${PLUGIN_SLUG}}"
SVN_WORKING_COPY="${WPORG_SVN_WORKING_COPY:-$HOME/tmp/wporg-${PLUGIN_SLUG}}"
SVN_USERNAME="${WPORG_SVN_USERNAME:-}"

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

to_lower() {
	printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

is_yes() {
	case "$(to_lower "${1:-}")" in
		y|yes)
			return 0
			;;
		*)
			return 1
			;;
	esac
}

run_svn() {
	if [ -n "$SVN_USERNAME" ]; then
		svn --username "$SVN_USERNAME" --force-interactive "$@"
	else
		svn "$@"
	fi
}

verify_release_source_metadata() {
	local expected_version="$1"
	local source_readme="$ROOT_DIR/readme.txt"
	local source_plugin="$ROOT_DIR/$(basename "$MAIN_PLUGIN_FILE")"

	grep -Eq "^Stable tag: $expected_version$" "$source_readme" || {
		log_error "Source readme.txt stable tag is not $expected_version"
		exit 1
	}

	grep -Eq "^ \* Version:[[:space:]]*$expected_version$" "$source_plugin" || {
		log_error "Source plugin header version is not $expected_version"
		exit 1
	}

	grep -Fq "define( 'CFR2_VERSION', '$expected_version' );" "$source_plugin" || {
		log_error "Source CFR2_VERSION constant is not $expected_version"
		exit 1
	}
}

verify_release_artifacts() {
	local expected_version="$1"
	local trunk_readme="$ROOT_DIR/svn/trunk/readme.txt"
	local trunk_plugin="$ROOT_DIR/svn/trunk/$PLUGIN_SLUG.php"
	local tag_dir="$ROOT_DIR/svn/tags/$expected_version"
	local tag_readme="$tag_dir/readme.txt"
	local tag_plugin="$tag_dir/$PLUGIN_SLUG.php"

	[ -d "$tag_dir" ] || {
		log_error "Missing built tag directory: $tag_dir"
		exit 1
	}

	grep -Eq "^Stable tag: $expected_version$" "$trunk_readme" || {
		log_error "Built trunk readme.txt stable tag is not $expected_version"
		exit 1
	}

	grep -Eq "^Stable tag: $expected_version$" "$tag_readme" || {
		log_error "Built tag readme.txt stable tag is not $expected_version"
		exit 1
	}

	grep -Eq "^ \* Version:[[:space:]]*$expected_version$" "$trunk_plugin" || {
		log_error "Built trunk plugin header version is not $expected_version"
		exit 1
	}

	grep -Eq "^ \* Version:[[:space:]]*$expected_version$" "$tag_plugin" || {
		log_error "Built tag plugin header version is not $expected_version"
		exit 1
	}

	grep -Fq "define( 'CFR2_VERSION', '$expected_version' );" "$trunk_plugin" || {
		log_error "Built trunk CFR2_VERSION constant is not $expected_version"
		exit 1
	}

	grep -Fq "define( 'CFR2_VERSION', '$expected_version' );" "$tag_plugin" || {
		log_error "Built tag CFR2_VERSION constant is not $expected_version"
		exit 1
	}
}

svn_url_exists() {
	run_svn ls "$1" >/dev/null 2>&1
}

print_help() {
	echo "Usage: ./scripts/release-svn.sh [options]"
	echo ""
	echo "Options:"
	echo "  -v, --version <x.y.z>   Release version (if omitted, script asks)"
	echo "  -m, --message <text>    SVN commit message"
	echo "  -u, --username <name>   Force SVN username (overrides cache)"
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
		-u|--username)
			SVN_USERNAME="${2:-}"
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
if [ -n "$SVN_USERNAME" ]; then
	log_info "SVN username (forced): $SVN_USERNAME"
fi

SVN_TRUNK_URL="${SVN_URL%/}/trunk"
SVN_TAG_URL="${SVN_URL%/}/tags/$RELEASE_VERSION"

if [ "$RELEASE_VERSION" != "$CURRENT_VERSION" ]; then
	log_info "Bumping version from $CURRENT_VERSION to $RELEASE_VERSION..."
	"$ROOT_DIR/scripts/build.sh" version "$RELEASE_VERSION"
else
	log_info "Version already set to $RELEASE_VERSION"
fi

verify_release_source_metadata "$RELEASE_VERSION"

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

verify_release_artifacts "$RELEASE_VERSION"

if [ ! -d "$SVN_WORKING_COPY/.svn" ]; then
	log_info "Checking out SVN repository..."
	mkdir -p "$(dirname "$SVN_WORKING_COPY")"
	if [ ! -w "$(dirname "$SVN_WORKING_COPY")" ]; then
		log_error "No write permission for SVN parent directory: $(dirname "$SVN_WORKING_COPY")"
		log_error "Please choose a writable path in .env (WPORG_SVN_WORKING_COPY) or fix ownership."
		exit 1
	fi
	run_svn checkout "$SVN_URL" "$SVN_WORKING_COPY"
else
	log_info "Updating existing SVN working copy..."
	(
		cd "$SVN_WORKING_COPY"
		run_svn update
	)
fi

if [ ! -w "$SVN_WORKING_COPY" ]; then
	log_error "No write permission for SVN working copy: $SVN_WORKING_COPY"
	log_error "Fix ownership, for example:"
	log_error "  sudo chown -R $(id -un):$(id -gn) \"$SVN_WORKING_COPY\""
	exit 1
fi

if svn_url_exists "$SVN_TAG_URL"; then
	log_warn "Remote tag already exists: tags/$RELEASE_VERSION"
	read -r -p "Continue and skip tag creation step? [y/N]: " skip_existing_tag
	if ! is_yes "$skip_existing_tag"; then
		log_error "Release aborted to avoid overwriting existing tag."
		exit 1
	fi
fi

log_info "Syncing trunk/assets into SVN working copy..."
mkdir -p "$SVN_WORKING_COPY/trunk" "$SVN_WORKING_COPY/assets"

rsync -a --delete "$ROOT_DIR/svn/trunk/" "$SVN_WORKING_COPY/trunk/"
rsync -a --delete "$ROOT_DIR/svn/assets/" "$SVN_WORKING_COPY/assets/"

log_info "Staging SVN changes..."
(
	cd "$SVN_WORKING_COPY"
	run_svn add --force . >/dev/null
	run_svn status | awk '/^\!/ {print substr($0,9)}' | while IFS= read -r missing_path; do
		[ -n "$missing_path" ] && run_svn rm --force "$missing_path" >/dev/null
	done
)

echo ""
log_info "SVN status:"
(
	cd "$SVN_WORKING_COPY"
	run_svn status
)

if [ "$NO_COMMIT" = "1" ]; then
	log_warn "Skipping commit (--no-commit). Working copy is ready at:"
	echo "  $SVN_WORKING_COPY"
	echo ""
	log_info "Manual commands (recommended, lower timeout risk):"
	echo "  cd \"$SVN_WORKING_COPY\""
	echo "  svn commit -m \"Release $RELEASE_VERSION\" trunk assets"
	echo "  svn copy \"$SVN_TRUNK_URL\" \"$SVN_TAG_URL\" -m \"Tag $RELEASE_VERSION\""
	exit 0
fi

if [ -z "$COMMIT_MESSAGE" ]; then
	read -r -p "Commit message [Release ${RELEASE_VERSION}]: " COMMIT_MESSAGE
	COMMIT_MESSAGE="${COMMIT_MESSAGE:-Release ${RELEASE_VERSION}}"
fi

echo ""
read -r -p "Commit to WordPress.org SVN now? [y/N]: " confirm_commit
if ! is_yes "$confirm_commit"; then
	log_warn "Commit skipped. You can commit manually from:"
	echo "  $SVN_WORKING_COPY"
	exit 0
fi

log_info "Committing to SVN..."
(
	cd "$SVN_WORKING_COPY"
	run_svn commit -m "$COMMIT_MESSAGE" trunk assets
)

if svn_url_exists "$SVN_TAG_URL"; then
	log_warn "Tag already exists, skipped remote tag creation: tags/$RELEASE_VERSION"
else
	log_info "Creating remote tag via server-side copy (fast, no full re-upload)..."
	run_svn copy "$SVN_TRUNK_URL" "$SVN_TAG_URL" -m "Tag $RELEASE_VERSION"
fi

log_info "Release completed successfully."
