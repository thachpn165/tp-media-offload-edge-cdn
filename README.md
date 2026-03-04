# TP Media Offload & Edge CDN

<p align="center">
  <img src="https://img.shields.io/badge/WP-6.0%2B-21759b?logo=wordpress" alt="WordPress 6.0+">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.0+">
  <img src="https://img.shields.io/badge/Tested-6.9-success" alt="Tested up to 6.9">
  <img src="https://img.shields.io/badge/License-GPL--2.0%2B-blue" alt="GPL-2.0+">
</p>

A production-ready WordPress plugin for offloading media to Cloudflare R2 with automatic CDN delivery and image optimization. Built with modern standards (PSR-4 autoloading, WordPress Coding Standards, Vite for assets).

## Requirements

- WordPress 6.0+
- PHP 8.0+

## Features (v1.0.0)

### Core Capabilities
- **R2 Media Offload**: Automatically upload media to Cloudflare R2 with queue-based processing
- **CDN Image Optimization**: WebP/AVIF conversion via Cloudflare Image Transformations
- **Responsive Images**: Smart srcset generation with preset breakpoints (320, 640, 768, 1024, 1280, 1536)
- **Worker Auto-Deploy**: One-click Cloudflare Worker deployment via API
- **Bulk Operations**: Offload, restore, or delete local files in bulk
- **Disk Space Saving**: Delete local files after offload to save server storage

### Integrations
- **WooCommerce**: Product images automatically served via CDN
- **Gutenberg**: Native support for image blocks with CDN URLs
- **REST API**: Full REST endpoints for programmatic access
- **WP-CLI**: Command line interface for bulk operations and automation

### Admin Features
- **Tabbed Admin UI**: Dashboard, Offload, Storage, CDN, Bulk Actions, System Info tabs
- **Real-time Progress**: Live progress tracking for bulk operations
- **AJAX Settings Save**: No page refresh, toast notifications
- **Media Library Extension**: Status column, bulk actions, and row actions
- **Activity Logs**: Track offload/restore activities

### Internationalization
- **Vietnamese (vi_VN)**: Full translation support
- **Translation Ready**: POT template included for other languages

### Security & Quality
- **Enterprise Security**: Nonce verification, capability checks, input sanitization
- **Encrypted Credentials**: API keys stored encrypted in database
- **Safe Uninstall**: Auto-restore image URLs to local when deactivating (no broken images)
- **WordPress Coding Standards**: PHPCS compliant
- **Build Tools**: Vite for assets, build.sh for ZIP/SVN deployment

## Quick Setup

1. **Configure R2**: Enter R2 credentials in Storage tab
2. **Test Connection**: Click "Test Connection" to verify credentials
3. **Add API Token**: Enter Cloudflare API Token for Worker deployment
4. **Deploy Worker**: Click "Deploy Worker" in CDN tab
5. **Enable CDN**: Turn on CDN delivery
6. **Bulk Offload**: Use Bulk Actions tab to migrate existing media

## Development

> **Note:** Requires Composer and Node.js 18+ for building from source.

### Build Commands

```bash
./scripts/build.sh clean       # Clean build directories
./scripts/build.sh build       # Build to dist/
./scripts/build.sh zip         # Create ZIP archive (tp-media-offload-edge-cdn.zip)
./scripts/build.sh deploy-svn  # Deploy to SVN structure
./scripts/build.sh version X.X # Bump version
```

### Code Quality

```bash
composer phpcs          # Check WordPress Coding Standards
composer phpcbf         # Auto-fix style issues
composer test           # Run PHPUnit tests
```

### Assets

```bash
npm run dev             # Watch mode (development)
npm run build           # Production build (minified)
```

## WP-CLI Commands

```bash
wp cfr2 offload <id>    # Offload single attachment
wp cfr2 restore <id>    # Restore single attachment
wp cfr2 bulk-offload    # Offload all non-offloaded media
wp cfr2 bulk-restore    # Restore all offloaded media
wp cfr2 status          # Show offload statistics
wp cfr2 free-space      # Delete local files for offloaded media
```

## REST API (Read-Only)

```bash
GET /wp-json/cfr2/v1/attachment/{id}  # Get attachment URLs (local, R2, CDN)
GET /wp-json/cfr2/v1/stats            # Get usage stats (requires auth)
```

**Example Response** (`/attachment/123`):
```json
{
  "id": 123,
  "offloaded": true,
  "urls": {
    "local": "https://example.com/wp-content/uploads/2024/01/image.jpg",
    "r2": "https://bucket.r2.dev/uploads/2024/01/image.jpg",
    "cdn": "https://cdn.example.com/uploads/2024/01/image.jpg"
  },
  "local_exists": true,
  "mime_type": "image/jpeg",
  "file_size": 102400,
  "width": 1920,
  "height": 1080
}
```

## Project Structure

```
src/
├── Admin/              # Admin interface (Tabs, Widgets, Ajax handlers)
├── Services/           # Core services (R2Client, OffloadService, URLRewriter)
├── Integrations/       # WooCommerce, Gutenberg, REST API
├── CLI/                # WP-CLI commands
├── Core/               # Activator, Deactivator, Loader
├── Database/           # Schema management
├── Hooks/              # WordPress hooks
├── Constants/          # Configuration constants
├── Traits/             # Shared behavior
└── PublicSide/         # Frontend assets
```

## License

GPL-2.0+
