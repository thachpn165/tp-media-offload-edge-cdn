#!/bin/bash

# =============================================================================
# TP Media Offload & Edge CDN Build Script
# =============================================================================

set -e

# Configuration
MAIN_PLUGIN_FILE="tp-media-offload-edge-cdn.php"
PLUGIN_SLUG="$(basename "$MAIN_PLUGIN_FILE" .php)"
PLUGIN_VERSION=$(grep -m1 "Version:" "$MAIN_PLUGIN_FILE" 2>/dev/null | sed 's/.*Version:[[:space:]]*//' | tr -d ' ' || echo "1.0.0")
BUILD_DIR="dist"
SVN_DIR="svn"
ZIP_FILE="${PLUGIN_SLUG}.zip"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Helpers
log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# =============================================================================
# Commands
# =============================================================================

# Clean build directories
cmd_clean() {
    log_info "Cleaning build directories..."
    rm -rf "$BUILD_DIR"
    rm -rf "$SVN_DIR"
    rm -f *.zip
    log_info "Clean complete!"
}

# Install dependencies
cmd_install() {
    log_info "Installing dependencies..."

    # Composer
    if [ -f "composer.json" ]; then
        log_info "Installing Composer dependencies..."
        composer install --no-dev --optimize-autoloader
    fi

    # NPM
    if [ -f "package.json" ]; then
        log_info "Installing NPM dependencies..."
        npm ci || npm install
    fi

    log_info "Install complete!"
}

# Build assets
cmd_build_assets() {
    log_info "Building assets..."

    if [ -f "package.json" ]; then
        npm run build
    fi

    log_info "Assets built!"
}

# Clean vendor directory - remove unnecessary files to reduce size
cmd_clean_vendor() {
    log_info "Cleaning vendor directory..."

    local vendor_dir="$BUILD_DIR/$PLUGIN_SLUG/vendor"

    if [ ! -d "$vendor_dir" ]; then
        return
    fi

    # Remove dev-only packages that should not be in production
    log_info "Removing dev-only packages..."
    # Only remove packages that are truly dev-only (not runtime dependencies)
    # NOTE: symfony, psr, guzzlehttp, mtdowling, ralouphie are AWS SDK runtime deps
    local dev_packages=(
        "phpunit"
        "sebastian"
        "phpmd"
        "pdepend"
        "squizlabs"
        "phpcsstandards"
        "phpcompatibility"
        "wp-coding-standards"
        "dealerdirect"
        "yoast"
        "doctrine"
        "myclabs"
        "nikic"
        "phar-io"
        "theseer"
    )

    for pkg in "${dev_packages[@]}"; do
        if [ -d "$vendor_dir/$pkg" ]; then
            rm -rf "$vendor_dir/$pkg"
            log_info "  Removed: $pkg"
        fi
    done

    # Remove documentation and test files
    find "$vendor_dir" -type f \( \
        -name "*.md" -o \
        -name "*.txt" -o \
        -name "*.rst" -o \
        -name "LICENSE*" -o \
        -name "CHANGELOG*" -o \
        -name "CHANGES*" -o \
        -name "CONTRIBUTING*" -o \
        -name "UPGRADE*" -o \
        -name "README*" -o \
        -name "composer.json" -o \
        -name "phpunit.xml*" -o \
        -name "phpcs.xml*" -o \
        -name "phpstan.neon*" -o \
        -name ".travis.yml" -o \
        -name ".gitignore" -o \
        -name ".gitattributes" -o \
        -name ".editorconfig" -o \
        -name "Makefile" -o \
        -name "*.dist" \
    \) -delete 2>/dev/null || true

    # Remove test and doc directories
    find "$vendor_dir" -type d \( \
        -name "tests" -o \
        -name "test" -o \
        -name "Tests" -o \
        -name "Test" -o \
        -name "docs" -o \
        -name "doc" -o \
        -name "examples" -o \
        -name "example" -o \
        -name ".git" -o \
        -name ".github" \
    \) -exec rm -rf {} + 2>/dev/null || true

    # Only clean up data directory (API definitions) - keep S3, Sts, endpoints
    # NOTE: Keep all AWS SDK core files intact to avoid missing class errors
    local aws_data="$vendor_dir/aws/aws-sdk-php/src/data"
    if [ -d "$aws_data" ]; then
        log_info "Optimizing AWS SDK data directory..."
        for data_dir in "$aws_data"/*/; do
            if [ -d "$data_dir" ]; then
                local data_name=$(basename "$data_dir")
                if [[ "$data_name" != "s3" && "$data_name" != "sts" && "$data_name" != "endpoints" ]]; then
                    rm -rf "$data_dir"
                fi
            fi
        done
    fi

    log_info "Vendor cleanup complete!"
}

# Build plugin to dist/
cmd_build() {
    log_info "Building plugin v${PLUGIN_VERSION}..."

    # Create dir if not exists (don't delete - preserves Docker mount)
    mkdir -p "$BUILD_DIR/$PLUGIN_SLUG"

    # Build assets first
    if [ -f "package.json" ]; then
        cmd_build_assets
    fi

    # Install production composer deps
    if [ -f "composer.json" ]; then
        composer install --no-dev --optimize-autoloader
    fi

    # Files to include
    local include_files=(
        "$MAIN_PLUGIN_FILE"
        "composer.json"
        "uninstall.php"
        "readme.txt"
        "LICENSE.txt"
    )

    # Directories to include
    local include_dirs=(
        "src"
        "assets/css"
        "assets/js"
        "assets/images"
        "languages"
        "vendor"
    )

    # Use rsync if available (preserves Docker mount, faster incremental)
    if command -v rsync &> /dev/null; then
        log_info "Using rsync for incremental sync..."

        # Sync files
        for file in "${include_files[@]}"; do
            if [ -f "$file" ]; then
                rsync -a "$file" "$BUILD_DIR/$PLUGIN_SLUG/"
            fi
        done

        # Sync directories (--delete removes old files)
        for dir in "${include_dirs[@]}"; do
            if [ -d "$dir" ]; then
                mkdir -p "$BUILD_DIR/$PLUGIN_SLUG/$(dirname $dir)"
                rsync -a --delete "$dir/" "$BUILD_DIR/$PLUGIN_SLUG/$dir/"
            fi
        done
    else
        # Fallback: Copy files
        for file in "${include_files[@]}"; do
            if [ -f "$file" ]; then
                cp "$file" "$BUILD_DIR/$PLUGIN_SLUG/"
            fi
        done

        # Copy directories (remove old first to ensure clean state)
        for dir in "${include_dirs[@]}"; do
            if [ -d "$dir" ]; then
                rm -rf "$BUILD_DIR/$PLUGIN_SLUG/$dir"
                mkdir -p "$BUILD_DIR/$PLUGIN_SLUG/$(dirname $dir)"
                cp -r "$dir" "$BUILD_DIR/$PLUGIN_SLUG/$dir"
            fi
        done
    fi

    # Clean up vendor directory - remove unnecessary files
    cmd_clean_vendor

    # Re-install dev deps for development
    if [ -f "composer.json" ]; then
        composer install
    fi

    log_info "Build complete! Output: $BUILD_DIR/$PLUGIN_SLUG"
}

# Create ZIP file
cmd_zip() {
    log_info "Creating ZIP archive..."

    # Build first if not exists
    if [ ! -d "$BUILD_DIR/$PLUGIN_SLUG" ]; then
        cmd_build
    fi

    # Create ZIP
    cd "$BUILD_DIR"
    zip -r "../$ZIP_FILE" "$PLUGIN_SLUG" -x "*.DS_Store" -x "*__MACOSX*"
    cd ..

    log_info "ZIP created: $ZIP_FILE"
}

# Deploy to SVN directory structure
cmd_deploy_svn() {
    log_info "Deploying to SVN structure..."

    # Always rebuild to avoid deploying stale artifacts.
    cmd_build

    # Clean SVN dir
    rm -rf "$SVN_DIR"

    # Create SVN structure
    mkdir -p "$SVN_DIR/trunk"
    mkdir -p "$SVN_DIR/tags/$PLUGIN_VERSION"
    mkdir -p "$SVN_DIR/assets"

    # Copy to trunk
    cp -r "$BUILD_DIR/$PLUGIN_SLUG/"* "$SVN_DIR/trunk/"

    # Copy to tag
    cp -r "$BUILD_DIR/$PLUGIN_SLUG/"* "$SVN_DIR/tags/$PLUGIN_VERSION/"

    # Copy assets (screenshots, banner, icon)
    if [ -d "wp-assets" ]; then
        cp -r wp-assets/* "$SVN_DIR/assets/"
    fi

    log_info "SVN structure created at: $SVN_DIR"
    log_info "  - trunk/"
    log_info "  - tags/$PLUGIN_VERSION/"
    log_info "  - assets/"
}

# Bump version number
cmd_version() {
    local new_version=$1

    if [ -z "$new_version" ]; then
        log_error "Please provide version number: ./build.sh version X.X.X"
        exit 1
    fi

    log_info "Bumping version to $new_version..."

    # Update main plugin file
    if [ -f "$MAIN_PLUGIN_FILE" ]; then
        sed -i.bak "s/^ \\* Version:.*$/ * Version:           $new_version/" "$MAIN_PLUGIN_FILE"
        sed -i.bak "s/define( 'CFR2_VERSION', '.*' );/define( 'CFR2_VERSION', '$new_version' );/" "$MAIN_PLUGIN_FILE"
    fi

    # Update readme.txt
    if [ -f "readme.txt" ]; then
        sed -i.bak "s/Stable tag:.*$/Stable tag: $new_version/" readme.txt
    fi

    # Update package.json
    if [ -f "package.json" ]; then
        sed -i.bak "s/\"version\": \".*\"/\"version\": \"$new_version\"/" package.json
    fi

    # Clean backup files
    find . -name "*.bak" -type f -delete 2>/dev/null || true

    log_info "Version bumped to $new_version"
}

# Show help
cmd_help() {
    echo ""
    echo "TP Media Offload & Edge CDN Build Script"
    echo ""
    echo "Usage: ./scripts/build.sh [command]"
    echo ""
    echo "Commands:"
    echo "  build       Build plugin to dist/"
    echo "  zip         Create .zip archive"
    echo "  deploy-svn  Deploy to svn/ directory"
    echo "  clean       Remove dist/, svn/, *.zip"
    echo "  install     Install composer & npm dependencies"
    echo "  version X.X Bump version number"
    echo "  help        Show this help"
    echo ""
    echo "Examples:"
    echo "  ./scripts/build.sh build"
    echo "  ./scripts/build.sh zip"
    echo "  ./scripts/build.sh version 1.2.0"
    echo ""
}

# =============================================================================
# Main
# =============================================================================

case "$1" in
    build)
        cmd_build
        ;;
    zip)
        cmd_zip
        ;;
    deploy-svn)
        cmd_deploy_svn
        ;;
    clean)
        cmd_clean
        ;;
    install)
        cmd_install
        ;;
    version)
        cmd_version "$2"
        ;;
    help|--help|-h|"")
        cmd_help
        ;;
    *)
        log_error "Unknown command: $1"
        cmd_help
        exit 1
        ;;
esac
