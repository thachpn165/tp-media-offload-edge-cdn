# Deployment Guide

## Overview

This guide covers building, testing, and deploying the TP Media Offload & Edge CDN WordPress plugin across local development, staging, and production environments.

## Official WordPress.org Endpoints

- Public plugin URL: `https://wordpress.org/plugins/tp-media-offload-edge-cdn`
- SVN repository URL: `https://plugins.svn.wordpress.org/tp-media-offload-edge-cdn`
- Recommended local SVN working copy: `~/tmp/wporg-tp-media-offload-edge-cdn`

---

## Prerequisites

### Required Software

- **PHP**: 8.0 or higher
- **WordPress**: 6.0 or higher
- **Composer**: Latest version
- **Node.js**: 18.0 or higher
- **NPM**: 9.0 or higher
- **Docker** (optional): For isolated environments

### System Requirements

- 100MB disk space (minimum)
- 512MB RAM (development)
- 2GB RAM (with Docker)

### Environment Setup

```bash
# Verify installations
php --version        # PHP 8.0+
composer --version   # Latest
node --version       # Node 18+
npm --version        # NPM 9+

# Optional: Docker
docker --version
docker-compose --version
```

---

## Local Development Setup

### 1. Clone & Initialize

```bash
# Clone repository
git clone https://github.com/yourusername/tp-media-offload-edge-cdn.git
cd tp-media-offload-edge-cdn

# Run initialization script
./scripts/init.sh
```

**What `init.sh` does**:
- Prompts for plugin name, slug, namespace
- Replaces boilerplate strings globally
- Generates unique Docker ports
- Creates `.env` file
- Installs Composer dependencies
- Installs NPM dependencies
- Builds assets with Vite
- Optionally initializes git repository

### 2. Install Dependencies

```bash
# Install PHP dependencies (Composer)
composer install

# Install Node dependencies (NPM)
npm install

# Install WordPress Coding Standards
composer run phpcs
```

### 3. Build Assets

```bash
# One-time production build
npm run build

# Watch mode (during development)
npm run dev
```

**Output**:
- Compiled CSS: `assets/css/admin.css`, `assets/css/public.css`
- Compiled JS: `assets/js/admin.js`, `assets/js/public.js`
- SCSS source: `assets/src/scss/`
- JS source: `assets/src/js/`

### 4. Start Docker Environment (Optional)

```bash
# Start all services
docker-compose up -d

# Check logs
docker-compose logs -f

# View status
docker-compose ps

# Stop services
docker-compose down
```

**Services**:
- WordPress (random port from .env)
- MySQL database (random port from .env)
- phpMyAdmin (random port from .env)

**Access**:
- WordPress: `http://localhost:<WP_DOCKER_PORT>`
- phpMyAdmin: `http://localhost:<PHPMYADMIN_PORT>`
- Database: `localhost:<DB_DOCKER_PORT>`

---

## Development Workflow

### Code Quality Checks

#### PHPCS (PHP Code Sniffer)

```bash
# Check WordPress Coding Standards
composer phpcs

# Auto-fix style issues (when possible)
composer phpcbf

# Check specific file
composer phpcs -- src/Admin/AdminMenu.php
```

**Standards Applied**:
- WordPress Coding Standards (WPCS)
- PHP Compatibility (WordPress)

#### PHPMD (Mess Detection)

```bash
# Detect code smells
composer phpmd
```

Detects:
- Copy-paste violations
- Unused variables
- Overly complex methods

### Testing

#### PHPUnit

```bash
# Run all tests
composer test

# Run unit tests only
composer test:unit

# Run integration tests only
composer test:integration

# Generate coverage report
composer test:coverage
# Report: coverage/index.html
```

**Test Location**: `tests/` directory

#### Git Pre-commit Hook

```bash
# Install pre-commit hook
composer run setup-hooks

# Runs PHPCS before every commit
./scripts/pre-commit
```

---

## Building for Distribution

### Build Script (`./scripts/build.sh`)

The main deployment tool with multiple targets.

#### 1. Build to Distribution Folder

```bash
./scripts/build.sh build
```

**Output**: `dist/tp-media-offload-edge-cdn/`

**Contents**:
- All source files (PHP, JS, CSS)
- Vendor directory (Composer)
- No node_modules
- No test files
- No docker configs
- No git history

**Excludes**:
- `.git`
- `.github`
- `node_modules`
- `tests`
- `docker`
- `.env`
- `.gitignore`
- `package*.json` (after build)
- `vite.config.js`

#### 2. Create ZIP Archive

```bash
./scripts/build.sh zip
```

**Output**: `tp-media-offload-edge-cdn.zip`

**Use Cases**:
- WordPress.org submission
- Manual plugin distribution
- Client delivery

**Verification**:
```bash
# Check ZIP contents
unzip -l tp-media-offload-edge-cdn.zip | head -20

# Extract and test
unzip -d /tmp tp-media-offload-edge-cdn.zip
```

#### 3. Deploy to SVN

```bash
# Prepare SVN structure for WordPress.org
./scripts/build.sh deploy-svn
```

**Output**: `svn/`

**Structure**:
```
svn/
├── trunk/           # Latest development snapshot
├── tags/
│   └── 1.0.0/       # Release snapshot
└── assets/          # WordPress.org plugin assets
```

**Next Steps**:
```bash
cd /path/to/your/svn-working-copy

# Commit to WordPress.org SVN
svn add trunk/
svn add assets/
svn add tags/1.0.0/
svn commit -m "Version 1.0.0 release"
```

#### 4. Automated WordPress.org Release (Recommended)

```bash
# Interactive flow:
# - asks release version
# - bumps version (if needed)
# - runs tests (optional skip)
# - builds + prepares svn/
# - syncs to SVN working copy (trunk/assets/tags/<version>)
# - shows svn status
# - optional commit confirmation
./scripts/release-svn.sh

# Example
./scripts/release-svn.sh -v 1.0.1 -m "Release 1.0.1"
```

Environment variables (in `.env` or shell):

```bash
WPORG_SVN_URL=https://plugins.svn.wordpress.org/tp-media-offload-edge-cdn
WPORG_SVN_WORKING_COPY=~/tmp/wporg-tp-media-offload-edge-cdn
```

#### 5. Version Bump

```bash
# Update version in all files
./scripts/build.sh version 1.1.0
```

**Updates**:
- `tp-media-offload-edge-cdn.php` (header + version constant)
- `package.json`
- `readme.txt` (stable tag)

#### 6. Clean Build Artifacts

```bash
./scripts/build.sh clean
```

Removes `dist/` folder for fresh rebuild.

---

## Staging Deployment

### 1. Prepare Release

```bash
# Create release branch
git checkout -b release/v1.1.0

# Update version
./scripts/build.sh version 1.1.0

# Run final tests
composer test
composer phpcs

# Create ZIP
./scripts/build.sh zip

# Commit
git add .
git commit -m "chore: Bump version to 1.1.0"
git push origin release/v1.1.0
```

### 2. Staging Server Installation

**Option A: Manual Upload**

```bash
# Download ZIP from release
wget https://github.com/yourusername/tp-media-offload-edge-cdn/releases/download/v1.1.0/tp-media-offload-edge-cdn-1.1.0.zip

# Extract to WordPress plugins folder
unzip tp-media-offload-edge-cdn-1.1.0.zip -d /var/www/wordpress/wp-content/plugins/

# Set permissions
chown -R www-data:www-data /var/www/wordpress/wp-content/plugins/tp-media-offload-edge-cdn/
chmod -R 755 /var/www/wordpress/wp-content/plugins/tp-media-offload-edge-cdn/
```

**Option B: Git Clone**

```bash
# Clone into plugins folder
cd /var/www/wordpress/wp-content/plugins/
git clone https://github.com/yourusername/tp-media-offload-edge-cdn.git

# Install dependencies
cd tp-media-offload-edge-cdn
composer install --no-dev
npm run build
```

### 3. Activate & Test

```bash
# Via WordPress CLI
wp plugin activate tp-media-offload-edge-cdn

# Verify in admin
# Dashboard > Plugins > TP Media Offload & Edge CDN
```

### 4. Staging QA Checklist

**Core Functionality**:
- [ ] Plugin activates without errors
- [ ] Admin menu appears at position 80
- [ ] Settings page loads with all 8 tabs
- [ ] All tabs render correctly (Dashboard, Bulk Actions, System Info, etc.)
- [ ] AJAX settings save works without page reload
- [ ] Toast notifications appear for success/error
- [ ] Settings persist after page reload

**Security & Validation**:
- [ ] Nonce validation prevents CSRF attacks
- [ ] Rate limiting works (10 saves/min)
- [ ] Permission checks enforce `manage_options`
- [ ] Input sanitization prevents injection
- [ ] Output escaping prevents XSS
- [ ] Frame-busting headers set correctly

**Bulk Operations**:
- [ ] Media Library shows R2 status column
- [ ] Row actions: Offload, Restore, etc.
- [ ] Bulk select works with checkboxes
- [ ] Bulk offload creates queue items
- [ ] Progress tracking updates in real-time
- [ ] Progress percentage increases as items process
- [ ] Completed items show in activity log
- [ ] Failed items show error messages

**Database Tables**:
- [ ] cfr2_offload_queue table exists with data
- [ ] cfr2_offload_status table has metadata
- [ ] cfr2_stats table tracks usage
- [ ] Queue items mark status correctly (pending → processing → completed)
- [ ] Post meta fields (_cfr2_*) populated correctly

**Sync Delete & Disk Saving**:
- [ ] Sync Delete setting appears in Offload tab
- [ ] Disk Saving setting appears in Offload tab
- [ ] Deleting attachment removes from R2 (when sync enabled)
- [ ] Local files removed when disk saving enabled (after successful upload)

**Console & Logs**:
- [ ] No JavaScript console errors
- [ ] No PHP error logs
- [ ] AJAX requests complete successfully
- [ ] Progress polling works (GET progress endpoint)

---

## Production Deployment

### Pre-Production Checklist

**Code Quality**:
- [ ] All PHPCS standards pass: `composer phpcs`
- [ ] All tests pass: `composer test`
- [ ] Code coverage >= 70%: `composer test:coverage`
- [ ] No security vulnerabilities detected
- [ ] No hardcoded credentials in code

**Documentation**:
- [ ] README.md updated
- [ ] Changelog entry added
- [ ] Code comments clear and current
- [ ] API docs updated (if applicable)

**Testing**:
- [ ] Manual QA passed on staging
- [ ] Browser compatibility verified (Chrome, Firefox, Safari, Edge)
- [ ] Mobile responsiveness checked
- [ ] Performance baseline established
- [ ] Backup of current production taken

### Production Installation

**Option A: WordPress.org Directory**

1. Submit to [WordPress.org Plugin Directory](https://wordpress.org/plugins/developers/)
2. Follow review process
3. Plugin automatically distributed to all WordPress sites

**Option B: Manual Deployment**

```bash
# SSH to production server
ssh user@production.example.com

# Navigate to plugins directory
cd /var/www/wordpress/wp-content/plugins/

# Download latest ZIP
wget https://github.com/yourusername/tp-media-offload-edge-cdn/releases/download/v1.1.0/tp-media-offload-edge-cdn-1.1.0.zip

# Backup existing plugin
[ -d tp-media-offload-edge-cdn ] && mv tp-media-offload-edge-cdn tp-media-offload-edge-cdn.backup

# Extract new version
unzip tp-media-offload-edge-cdn-1.1.0.zip

# Set permissions
chown -R www-data:www-data tp-media-offload-edge-cdn/
chmod -R 755 tp-media-offload-edge-cdn/

# Activate plugin (via admin or WP-CLI)
wp plugin activate tp-media-offload-edge-cdn
```

### Post-Deployment Verification

```bash
# Check plugin is activated
wp plugin list | grep tp-media-offload-edge-cdn

# Check error logs
tail -f /var/log/php-errors.log
tail -f /var/log/wordpress/debug.log

# Verify settings saved correctly
wp option get cfr2_settings

# Test AJAX endpoint
curl -X POST http://example.com/wp-admin/admin-ajax.php \
  -d "action=cfr2_save_settings&enable_feature=1"
```

### Rollback Plan

**If issues occur**:

```bash
# Deactivate plugin
wp plugin deactivate tp-media-offload-edge-cdn

# Restore from backup
rm -rf tp-media-offload-edge-cdn/
mv tp-media-offload-edge-cdn.backup tp-media-offload-edge-cdn/

# Reactivate
wp plugin activate tp-media-offload-edge-cdn

# Check logs
wp plugin verify-plugin-files tp-media-offload-edge-cdn
```

---

## Update & Patch Management

### Minor Updates (1.0.x)

```bash
# Create bugfix branch
git checkout -b bugfix/1.0.1

# Make fixes
# Run tests
composer test

# Update version
./scripts/build.sh version 1.0.1

# Tag release
git tag -a v1.0.1 -m "Patch: Fix rate limit issue"
git push origin bugfix/1.0.1 --tags
```

### Major Updates (1.1.0+)

```bash
# Create release branch
git checkout -b release/v1.1.0

# Integrate features from feature branches
git merge feature/r2-integration
git merge feature/analytics

# Update version
./scripts/build.sh version 1.1.0

# Update CHANGELOG
nano docs/project-changelog.md

# Tag and release
git tag -a v1.1.0 -m "Release: R2 Integration"
git push origin release/v1.1.0 --tags
```

---

## Environment-Specific Configuration

### Local Development (.env)

```bash
WP_DEBUG=true
WP_DEBUG_LOG=true
```

### Staging (.env)

```bash
WP_DEBUG=true
WP_DEBUG_LOG=true
```

### Production (.env)

```bash
WP_DEBUG=false
WP_DEBUG_LOG=false
```

**Note**: Never commit `.env` files to Git.

---

## Bulk Operations Deployment

### Queue System Requirements

The bulk operations feature requires background processing. Choose one:

#### Option 1: WordPress Cron (Default)

No additional setup required. WordPress Cron processes queue items on page visits.

**Limitations**:
- Only runs when site gets traffic
- Processing may be delayed during low traffic periods
- Not ideal for 1000+ item batches

**To enable**:
```php
// In wp-config.php, ensure DISABLE_WP_CRON is false (default)
define( 'DISABLE_WP_CRON', false );
```

#### Option 2: System Cron (Recommended for Production)

Disable WordPress Cron and set up system cron job:

```bash
# In wp-config.php
define( 'DISABLE_WP_CRON', true );

# Add system cron job (runs every minute)
* * * * * curl https://example.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1

# Or use WP-CLI (runs every 2 minutes)
*/2 * * * * wp cron event run --due-now --path=/var/www/wordpress
```

#### Option 3: Action Scheduler

For high-volume sites, use the Action Scheduler library (included in some plugins):

```php
// Queue item will be automatically scheduled
as_enqueue_async_action( 'cfr2_process_queue', array(), 'cfr2' );
```

### Database Setup

Ensure tables are created during plugin activation:

```bash
# Check if tables exist
wp db query "SHOW TABLES LIKE 'cfr2_%';"

# Manually create if missing
wp cfr2 db create-tables
```

### Queue Monitoring

Monitor bulk operations via database:

```bash
# Check pending items
wp db query "SELECT COUNT(*) FROM cfr2_offload_queue WHERE status = 'pending';"

# Get processing status
wp db query "SELECT attachment_id, status, error_message FROM cfr2_offload_queue LIMIT 10;"

# Check progress
wp option get cfr2_progress_{progress_id}

# View operation logs
wp option get cfr2_operation_logs | jq . | head -20
```

### Performance Tuning

**Batch Size**:
- Default: 10 items per cycle
- Edit in src/Constants/BatchConfig.php
- Larger batches = faster overall, higher server load
- Smaller batches = slower overall, lower load per cycle

**Memory Usage**:
- PHP memory_limit should be 256MB+ for bulk ops
- Adjust in wp-config.php:
```php
define( 'WP_MEMORY_LIMIT', '256M' );
define( 'WP_MEMORY_NICE_LIMIT', '512M' );
```

**Timeout**:
- WP_CRON_LOCK_TIMEOUT: 60 seconds (default)
- Increase if processing takes longer:
```php
define( 'WP_CRON_LOCK_TIMEOUT', 120 );
```

### Troubleshooting Bulk Ops

**Queue Items Never Process**:
```bash
# Check if WP Cron is disabled
wp config get DISABLE_WP_CRON

# If disabled, set up system cron job
# Or enable WP Cron: define( 'DISABLE_WP_CRON', false );

# Check error logs
tail -f /var/log/wordpress/debug.log | grep cfr2
```

**Progress Stuck at 0%**:
```bash
# Check if progress transient exists
wp transient get cfr2_progress_{progress_id}

# Reset progress (force restart)
wp transient delete cfr2_progress_{progress_id}

# Check for queue items in 'processing' state
wp db query "SELECT * FROM cfr2_offload_queue WHERE status = 'processing';"

# Reset stuck items to 'pending'
wp db query "UPDATE cfr2_offload_queue SET status = 'pending' WHERE status = 'processing';"
```

**Memory Exhaustion During Bulk Ops**:
```bash
# Monitor memory during processing
wp cron event run cfr2_process_queue

# Reduce batch size in BatchConfig.php
define( 'BATCH_SIZE', 5 ); // Reduced from 10

# Monitor processes
top -p $(pgrep -f "wp cron")
```

---

## Monitoring & Maintenance

### Health Checks

```bash
# Plugin status
wp plugin verify-plugin-files tp-media-offload-edge-cdn

# Option verification
wp option get cfr2_settings

# Error log check
tail -100 /var/log/wordpress/debug.log | grep cloudflare
```

### Automated Updates

**Via WordPress Admin**:
1. Dashboard > Updates
2. Check for plugin updates automatically
3. Enable auto-update (recommended)

**Via WP-CLI**:
```bash
wp plugin update tp-media-offload-edge-cdn
wp plugin update tp-media-offload-edge-cdn --all
```

### Backup Strategy

**Before Each Update**:

```bash
# Database backup
wp db export /backups/wordpress-$(date +%Y%m%d).sql

# Plugin directory backup
tar -czf /backups/tp-media-offload-edge-cdn-$(date +%Y%m%d).tar.gz \
  /var/www/wordpress/wp-content/plugins/tp-media-offload-edge-cdn/
```

---

## Troubleshooting

### Installation Fails

```bash
# Check PHP version
php -v  # Must be 7.4+

# Check WordPress version
wp core version  # Must be 6.0+

# Check file permissions
ls -la /var/www/wordpress/wp-content/plugins/

# Check error log
tail -50 /var/log/php-errors.log
```

### Plugin Doesn't Activate

```bash
# Check for parse errors
php -l tp-media-offload-edge-cdn.php

# Check WordPress debug log
tail -50 /var/log/wordpress/debug.log

# Disable all plugins, test individually
wp plugin deactivate --all
wp plugin activate tp-media-offload-edge-cdn
```

### Settings Don't Save

```bash
# Verify nonce in request
curl -X POST http://example.com/wp-admin/admin-ajax.php \
  -d "action=cfr2_save_settings" \
  -d "cfr2_nonce=YOUR_NONCE"

# Check WordPress options table
wp option list | grep cloudflare

# Verify database write permissions
wp db query "SELECT * FROM wp_options LIMIT 1;"
```

### AJAX Errors

```bash
# Check JavaScript console
# Browser DevTools > Console tab

# Verify AJAX endpoint
curl http://example.com/wp-admin/admin-ajax.php?action=cfr2_save_settings

# Check server error logs
tail -50 /var/log/apache2/error.log  # Apache
tail -50 /var/log/nginx/error.log    # Nginx
```

---

## Performance Optimization

### Asset Minification

```bash
# Already handled by Vite in production
npm run build  # Creates minified assets

# Verify compression
gzip -l assets/js/app.js
gzip -l assets/css/app.css
```

### Caching Headers

**Set in .htaccess** (Apache):
```apache
<FilesMatch "\.(js|css|png|jpg|jpeg|gif|ico)$">
  Header set Cache-Control "max-age=31536000, public"
</FilesMatch>
```

**Or nginx.conf** (Nginx):
```nginx
location ~* \.(js|css|png|jpg|jpeg|gif|ico)$ {
  expires 1y;
  add_header Cache-Control "public, immutable";
}
```

### Database Optimization

```bash
# Optimize WordPress options table
wp db optimize

# Check database size
wp db check

# Repair if needed
wp db repair
```

---

## Security Hardening

### File Permissions

```bash
# Plugin directory (755)
chmod 755 /var/www/wordpress/wp-content/plugins/tp-media-offload-edge-cdn/

# Plugin files (644)
find /var/www/wordpress/wp-content/plugins/tp-media-offload-edge-cdn/ \
  -type f -exec chmod 644 {} \;

# Plugin subdirectories (755)
find /var/www/wordpress/wp-content/plugins/tp-media-offload-edge-cdn/ \
  -type d -exec chmod 755 {} \;
```

### Disable Plugin Editing

**In wp-config.php**:
```php
define( 'DISALLOW_FILE_EDIT', true );
define( 'DISALLOW_FILE_MODS', true );
```

### Web Server Configuration

**Hide plugin directory listing** (.htaccess):
```apache
<FilesMatch "^\.">
  Order allow,deny
  Deny from all
</FilesMatch>
```

---

## Support & Documentation

- **Issue Tracker**: https://github.com/yourusername/tp-media-offload-edge-cdn/issues
- **Documentation**: `/docs/` folder
- **Email Support**: support@example.com
- **Community Forum**: (future)

---

## Release Checklist

Before every release:

- [ ] Version bumped in all files
- [ ] CHANGELOG updated
- [ ] All tests passing (100%)
- [ ] PHPCS compliance verified
- [ ] Documentation current
- [ ] README.md updated
- [ ] Security review completed
- [ ] Staging deployment tested
- [ ] ZIP file created successfully
- [ ] SVN structure prepared
- [ ] Release notes written
- [ ] Git tag created
- [ ] Release published on GitHub

---

## Quick Reference Commands

```bash
# Development
npm run dev                    # Watch assets
composer test                  # Run tests
composer phpcs                # Check standards
composer phpcbf               # Fix standards

# Build
./scripts/build.sh build      # Build distribution
./scripts/build.sh zip        # Create ZIP
./scripts/build.sh version 1.1.0  # Bump version

# Deploy
docker-compose up -d          # Start local
docker-compose down           # Stop local
wp plugin activate tp-media-offload-edge-cdn  # Activate

# Maintenance
wp option get cfr2_settings  # Check settings
wp plugin verify-plugin-files tp-media-offload-edge-cdn  # Verify
wp db export backup.sql       # Backup database
```

---

Last Updated: January 2025
