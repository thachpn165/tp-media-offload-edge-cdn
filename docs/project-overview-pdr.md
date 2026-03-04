# TP Media Offload & Edge CDN - Project Overview & PDR

## Project Overview

**TP Media Offload & Edge CDN** is a production-ready WordPress plugin designed for seamless offloading and CDN delivery via Cloudflare R2. It provides a modern development foundation with clean architecture, comprehensive tooling, and enterprise-grade security practices.

### Core Purpose
Offload WordPress media and static assets to Cloudflare R2 object storage with automatic CDN distribution, reducing server load and improving site performance.

### Key Metadata
- **Plugin Name**: TP Media Offload & Edge CDN
- **Namespace**: `ThachPN165\CFR2OffLoad`
- **Version**: 1.0.0
- **License**: GPL-2.0+
- **Text Domain**: tp-media-offload-edge-cdn
- **PHP Requirement**: 8.0+
- **WordPress Requirement**: 6.0+

---

## Product Development Requirements (PDR)

### Phase 1: Foundation & Bulk Operations (COMPLETE)

#### Functional Requirements
- [x] PSR-4 autoloading structure
- [x] Singleton plugin class
- [x] Hook loader abstraction
- [x] Tabbed admin settings interface (8 tabs)
- [x] AJAX settings save (no page reload)
- [x] Toast notification system
- [x] Media Library integration with status indicators
- [x] Queue-based bulk operations
- [x] Sync Delete feature (delete from R2 on local deletion)
- [x] Disk Saving feature (remove local files)
- [x] Image Format selector (Original/WebP/AVIF)
- [x] Real-time progress tracking
- [x] REST API endpoints for offload/status

#### Non-Functional Requirements
- [x] Code follows WordPress Coding Standards
- [x] Security: Nonce verification (all AJAX endpoints)
- [x] Security: Rate limiting (10/min per user)
- [x] Security: Capability checks (manage_options)
- [x] Security: Input sanitization (wp_unslash, sanitize_*)
- [x] Security: Output escaping (esc_html, esc_attr)
- [x] Frame-busting headers (X-Frame-Options, CSP)
- [x] AjaxSecurityTrait for centralized security
- [x] ErrorMessages constants for consistency
- [x] Organized Constants (12 files for domains)

#### Architecture
- **Singleton Pattern**: Plugin class ensures single instance
- **Hook Manager**: Centralized `Loader` class for action/filter registration
- **Tabbed UI**: Modular tab components for extensibility
- **Queue System**: QueueManager + BulkProgressService for bulk ops
- **Services**: 12 domain-specific services
- **Traits**: SingletonTrait, CdnUrlRewriterTrait, AjaxSecurityTrait, CredentialsHelperTrait
- **Database**: 3 tables (queue, status, stats)

---

### Phase 2: R2 Integration & CDN (PLANNED)

#### Functional Requirements
- [ ] AWS SDK integration for R2 API
- [ ] R2Client service implementation
- [ ] File upload handler (images, media, thumbnails)
- [ ] Automatic CDN URL rewriting
- [ ] Cloudflare Worker deployment
- [ ] Image transformation (WebP, AVIF, resize)
- [ ] Bulk migration tool
- [ ] Activity logs with operation history
- [ ] Connection testing & validation

#### Non-Functional Requirements
- [ ] Async upload queue (background processing via WP Cron)
- [ ] Retry logic with exponential backoff
- [ ] SSRF protection for URLs
- [ ] API key encryption (AES-256-CBC + HMAC)
- [ ] Fallback to local storage on failure
- [ ] Performance: <500ms per async upload
- [ ] Batch processing (10 items/cycle)
- [ ] Error handling with detailed messages

#### Architecture
- **R2Client Service**: AWS SDK wrapper (S3-compatible API)
- **URLRewriter Service**: CDN URL transformation
- **WorkerDeployer Service**: Cloudflare Worker deployment
- **EncryptionService**: Secure credential storage
- **ActivityAjaxHandler**: Operation history & logging
- **Advanced Tab**: R2 credentials & configuration
- **Dashboard Widget**: Sync status & statistics

---

### Phase 3: Advanced Features (PLANNED)

#### Functional Requirements
- [ ] Webhooks for external integrations
- [ ] Third-party API integration
- [ ] Custom CSS support
- [ ] Analytics tracking
- [ ] Debug logging
- [ ] Health check endpoint

#### Non-Functional Requirements
- [ ] Webhook signature verification
- [ ] Rate limiting per webhook
- [ ] Custom CSS sandboxing
- [ ] Performance monitoring
- [ ] Zero-trust logging

---

### Phase 4: Testing & Quality (PLANNED)

#### Functional Requirements
- [ ] Unit test suite (70%+ coverage)
- [ ] Integration test suite
- [ ] E2E test scenarios
- [ ] Security audit
- [ ] Performance benchmarks

#### Non-Functional Requirements
- [ ] PHPUnit configuration
- [ ] CI/CD pipeline setup
- [ ] Automated testing on push
- [ ] Code coverage reporting

---

## Success Metrics

| Metric | Target | Status |
|--------|--------|--------|
| Code Coverage | 70%+ | Pending |
| PHPUnit Passing | 100% | Setup ready |
| PHPCS Compliance | 100% | Ready |
| Security Audit | Pass | Pending |
| Response Time (settings save) | <200ms | Expected |

---

## Dependencies

### External
- WordPress 6.0+ (admin API, settings API, nonces)
- PHP 8.0+ (type declarations, named arguments)
- Cloudflare R2 API (future phase)
- AWS SDK PHP (future phase)

### Internal
- Composer autoloader (PSR-4)
- Vite bundler (assets)
- PHPUnit (testing)

---

## Known Limitations

1. **Foundation Phase Only**: R2 integration not yet implemented
2. **No Direct S3 Support**: R2-specific for now
3. **Single Admin Page**: Extendable but limited by design
4. **No Multisite Support**: Single-site WordPress only

---

## Timeline

| Phase | Status | ETA |
|-------|--------|-----|
| Phase 1: Foundation | COMPLETE | Done |
| Phase 2: R2 Integration | Not Started | TBD |
| Phase 3: Advanced Features | Not Started | TBD |
| Phase 4: Testing & QA | In Progress | TBD |
| V1.0.0 Release | Planned | TBD |

---

## Stakeholders

- **Owner**: ThachPN165
- **Maintainers**: Development team
- **Users**: WordPress administrators, agencies
- **Contributors**: Open to community PRs

---

## Links & Resources

- Main Plugin File: `/tp-media-offload-edge-cdn.php`
- Plugin Class: `/src/Plugin.php`
- Admin Menu: `/src/Admin/AdminMenu.php`
- Settings Page: `/src/Admin/SettingsPage.php`
- Build Script: `/scripts/build.sh`
- WordPress.org Plugin: `https://wordpress.org/plugins/tp-media-offload-edge-cdn`
- WordPress.org SVN: `https://plugins.svn.wordpress.org/tp-media-offload-edge-cdn`
