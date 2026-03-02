=== TP Media Offload & Edge CDN ===
Contributors: thachpn165
Tags: cloudflare, cdn, media, offload, image optimization
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload WordPress media to Cloudflare R2 storage and serve via CDN with automatic image optimization.

== Description ==

**TP Media Offload & Edge CDN** is a powerful WordPress plugin that offloads your media files to Cloudflare R2 object storage and serves them through Cloudflare's global CDN network with automatic image optimization.

= Key Features =

* **R2 Storage Integration** - Seamlessly upload media to Cloudflare R2 with S3-compatible API
* **Automatic Offload** - New uploads are automatically offloaded to R2
* **Bulk Offload** - Offload existing media library with configurable batch size
* **CDN Delivery** - Serve media through Cloudflare's global CDN network
* **Image Optimization** - Automatic WebP/AVIF conversion via Cloudflare Image Transformations
* **Responsive Images** - Smart srcset generation with preset breakpoints (320, 640, 768, 1024, 1280, 1536)
* **Quality Control** - Configurable image quality (1-100)
* **Worker Auto-Deploy** - One-click Cloudflare Worker deployment for image processing
* **WooCommerce Support** - Full integration with product images and galleries
* **Background Processing** - Queue-based processing with WP Cron (Action Scheduler supported)
* **Media Library Integration** - Status column, bulk actions, and row actions
* **WP-CLI Support** - Command line interface for bulk operations and automation

= Requirements =

* WordPress 6.0 or higher
* PHP 8.0 or higher
* Cloudflare account with R2 storage enabled
* R2 bucket with public access or custom domain
* Cloudflare API Token (for Worker deployment)

= How It Works =

1. Configure your R2 credentials (Account ID, Access Key, Secret Key, Bucket)
2. Set up your CDN URL (R2 public domain or custom domain)
3. Enable auto-offload or use bulk offload for existing media
4. Plugin automatically rewrites URLs to serve from CDN
5. Cloudflare Worker handles image transformations on-the-fly

= Security =

* API credentials encrypted with AES-256-CBC + HMAC
* Rate limiting on settings saves
* Nonce verification on all AJAX requests
* Capability checks for all admin operations
* Secure uninstall (wipes all sensitive data)

= Performance =

* Batch processing to prevent memory exhaustion
* Transient caching for dashboard stats
* Conditional asset loading
* Background queue processing

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/cf-r2-offload-cdn` or install through WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to **TP Media Offload & Edge CDN** in the admin menu

= Initial Setup =

**Step 1: Configure R2 Storage**

1. Log in to your Cloudflare dashboard
2. Go to R2 > Overview and create a bucket
3. Go to R2 > Manage R2 API Tokens
4. Create a new API token with read/write access
5. Copy Account ID, Access Key ID, and Secret Access Key
6. Enter these in the plugin's Storage tab

**Step 2: Configure CDN (Optional but Recommended)**

1. In Cloudflare, set up a custom domain for your R2 bucket
2. Or use the R2.dev public URL
3. Enter the CDN URL in the plugin's CDN tab

**Step 3: Deploy Worker (For Image Optimization)**

1. Create a Cloudflare API Token with Workers permissions
2. Enter the token in the CDN tab
3. Click "Deploy Worker" button
4. Worker will be automatically deployed

**Step 4: Start Offloading**

1. Enable "Auto Offload" for new uploads
2. Use "Bulk Actions" tab to offload existing media
3. Monitor progress in the terminal-style activity log

= WP-CLI Commands =

The plugin provides WP-CLI commands for automation and bulk operations:

`wp cfr2 status`
Show offload statistics (total, offloaded, not offloaded, disk saveable, pending).

`wp cfr2 offload <id|all> [--dry-run] [--batch-size=50]`
Offload media to R2. Use specific ID or "all" for bulk offload.

`wp cfr2 restore <id|all> [--dry-run] [--batch-size=50]`
Restore media from R2 (removes R2 metadata, reverts to local URLs).

`wp cfr2 free-space <id|all> [--dry-run] [--batch-size=50]`
Delete local files for offloaded media to free disk space.

**Examples:**

    # Check current offload status
    wp cfr2 status

    # Offload single attachment
    wp cfr2 offload 123

    # Offload all media with larger batch size
    wp cfr2 offload all --batch-size=100

    # Preview what would be offloaded (dry run)
    wp cfr2 offload all --dry-run

    # Free disk space by removing local copies
    wp cfr2 free-space all

== External services ==

This plugin connects to Cloudflare services to offload media and deliver files via CDN.

= Cloudflare R2 Object Storage =

* **What it is used for:** Store and serve media objects.
* **Data sent:** Account ID, Access Key ID, Secret Access Key, bucket name, file paths, and media file contents.
* **When data is sent:** During connection testing, single/bulk offload, restore, and local-file cleanup actions.
* **Service provider:** Cloudflare, Inc.
* **Terms of Service:** https://www.cloudflare.com/website-terms/
* **Privacy Policy:** https://www.cloudflare.com/privacypolicy/

= Cloudflare API (Workers and DNS) =

* **What it is used for:** Deploy/remove Workers, validate DNS records, and enable DNS proxy for CDN routing.
* **Data sent:** API token, account ID, zone ID, DNS record ID, worker configuration, and configured CDN domain.
* **When data is sent:** When you click Deploy Worker, Remove Worker, Validate DNS, or Enable Proxy.
* **Service provider:** Cloudflare, Inc.
* **Terms of Service:** https://www.cloudflare.com/website-terms/
* **Privacy Policy:** https://www.cloudflare.com/privacypolicy/

== Frequently Asked Questions ==

= What is Cloudflare R2? =

Cloudflare R2 is an S3-compatible object storage service with zero egress fees. It's perfect for storing and serving media files.

= Do I need a paid Cloudflare plan? =

No, R2 storage is available on all Cloudflare plans including Free. You get 10GB storage free per month. Pricing: $0.015/GB/month after that.

= What about Cloudflare Image Transformations pricing? =

First 5,000 transformations per month are free. After that, it's $0.50 per 1,000 transformations. The plugin tracks your usage in the dashboard.

= Can I keep local files after offloading? =

Yes, there's an option to keep local files. This is useful for backup or if you need local file access.

= What happens if I deactivate the plugin? =

Media URLs will revert to local paths. Your files remain in R2 storage. The plugin stores original local URLs for seamless fallback.

= Does it work with WooCommerce? =

Yes! Full WooCommerce integration for product images, galleries, and thumbnails.

= Can I restore files from R2 to local? =

Yes, use the "Restore" action in Media Library or bulk restore in the Bulk Actions tab.

= What image formats are supported for optimization? =

The plugin supports WebP (recommended) and AVIF conversion. Original format is preserved as fallback.

= Is it multisite compatible? =

Currently designed for single-site installations. Multisite support is planned for future releases.

= Does it support WP-CLI? =

Yes! The plugin includes WP-CLI commands for bulk operations: `wp cfr2 status`, `wp cfr2 offload`, `wp cfr2 restore`, and `wp cfr2 free-space`. All commands support `--dry-run` and `--batch-size` options.

== Screenshots ==

1. Dashboard with setup guides and statistics
2. Storage settings with R2 credentials
3. CDN settings with Worker deployment
4. Bulk Actions with terminal-style activity log
5. Media Library with offload status column
6. System Info with debug information

== Changelog ==

= 1.0.0 =
* Initial release
* R2 storage integration with AWS SDK
* Automatic and bulk media offload
* CDN URL rewriting
* Cloudflare Worker auto-deployment
* Image optimization (WebP/AVIF)
* Responsive srcset generation
* WooCommerce integration
* Background queue processing
* Rate limiting and security features
* Dashboard with usage statistics
* WP-CLI commands (status, offload, restore, free-space)

== Upgrade Notice ==

= 1.0.0 =
Initial release. Please backup your database before installing.

== Privacy Policy ==

This plugin:
* Stores your Cloudflare API credentials encrypted in your WordPress database
* Uploads your media files to your Cloudflare R2 bucket
* Sends required API data directly to Cloudflare services to provide plugin functionality
* Does not include any tracking or analytics

Your data stays between your WordPress site and your Cloudflare account.

== Support ==

For support, feature requests, or bug reports:
* Visit [WordPress support forum](https://wordpress.org/support/plugin/cf-r2-offload-cdn/)
* Create an issue on GitHub (coming soon)

== Credits ==

* Built with AWS SDK for PHP for R2 compatibility
* Uses WP Cron for background processing (Action Scheduler compatible)
* Cloudflare Workers for image transformations

== Disclaimer ==

This plugin is an independent, third-party project and is **not affiliated with, endorsed by, or officially associated with Cloudflare, Inc.** in any way. "Cloudflare" and "R2" are trademarks of Cloudflare, Inc. The use of these names is solely for descriptive purposes to indicate compatibility with Cloudflare services.

This plugin is developed and maintained independently by the plugin author and the open-source community.
