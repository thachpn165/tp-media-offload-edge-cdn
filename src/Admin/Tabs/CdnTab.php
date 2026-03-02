<?php
/**
 * CDN Tab class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * CdnTab class - renders CDN configuration and Worker deployment.
 */
class CdnTab {

	/**
	 * Render the CDN tab.
	 *
	 * @param array $settings Current settings.
	 */
	public static function render( array $settings ): void {
		?>
		<div class="cloudflare-r2-offload-cdn-tab-content" id="tab-cdn">
			<h2><?php esc_html_e( 'CDN Configuration', 'thachpham-offload-cdn-cloudflare-r2' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Configure CDN URL rewriting and image optimization settings.', 'thachpham-offload-cdn-cloudflare-r2' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="cdn_enabled"><?php esc_html_e( 'Enable CDN (Auto Avif/WebP & Optimization)', 'thachpham-offload-cdn-cloudflare-r2' ); ?></label>
					</th>
					<td>
						<input type="hidden" name="cdn_enabled" value="0" />
						<label class="cloudflare-r2-offload-cdn-toggle">
							<input type="checkbox" id="cdn_enabled" name="cdn_enabled" value="1"
								<?php checked( 1, $settings['cdn_enabled'] ?? 0 ); ?> />
							<span class="cloudflare-r2-offload-cdn-toggle-slider"></span>
						</label>
						<p class="description"><?php esc_html_e( 'Replace media URLs with CDN URLs for optimized delivery.', 'thachpham-offload-cdn-cloudflare-r2' ); ?></p>
					</td>
				</tr>
			</table>

			<div class="cdn-fields" <?php echo empty( $settings['cdn_enabled'] ) ? 'style="display:none;"' : ''; ?>>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="cf_api_token"><?php esc_html_e( 'Cloudflare API Token', 'thachpham-offload-cdn-cloudflare-r2' ); ?></label>
						</th>
						<td>
							<?php
							$token_value = ! empty( $settings['cf_api_token'] ) ? '********' : '';
							?>
							<input type="password" id="cf_api_token" name="cf_api_token"
								value="<?php echo esc_attr( $token_value ); ?>"
								class="regular-text" autocomplete="new-password" />
							<p class="description"><?php esc_html_e( 'Required permissions: Workers Scripts, Workers R2, Zone, Zone Settings, DNS, Workers Routes, Cache Purge', 'thachpham-offload-cdn-cloudflare-r2' ); ?></p>
							<p class="description"><?php esc_html_e( 'Create at: Cloudflare Dashboard → My Profile → API Tokens', 'thachpham-offload-cdn-cloudflare-r2' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cdn_url"><?php esc_html_e( 'CDN URL', 'thachpham-offload-cdn-cloudflare-r2' ); ?></label>
						</th>
						<td>
							<div class="cfr2-cdn-url-row">
								<input type="url" id="cdn_url" name="cdn_url"
									value="<?php echo esc_url( $settings['cdn_url'] ?? '' ); ?>"
									class="regular-text" placeholder="https://cdn.example.com" />
								<button type="button" id="validate-cdn-dns" class="button">
									<?php esc_html_e( 'Validate DNS', 'thachpham-offload-cdn-cloudflare-r2' ); ?>
								</button>
							</div>
							<div id="cdn-dns-status" class="cfr2-dns-status" style="display:none;"></div>
							<p class="description"><?php esc_html_e( 'Your custom domain pointing to the Worker. DNS record will be created automatically if not exists.', 'thachpham-offload-cdn-cloudflare-r2' ); ?></p>

							<details class="cfr2-setup-guide">
								<summary><?php esc_html_e( 'How does automatic DNS setup work?', 'thachpham-offload-cdn-cloudflare-r2' ); ?></summary>
								<div class="cfr2-setup-guide-content">
									<p><strong><?php esc_html_e( 'Automatic Setup (Recommended)', 'thachpham-offload-cdn-cloudflare-r2' ); ?></strong></p>
									<ol>
										<li><?php esc_html_e( 'Enter your desired CDN URL (e.g., https://cdn.yourdomain.com)', 'thachpham-offload-cdn-cloudflare-r2' ); ?></li>
										<li><?php esc_html_e( 'Click "Validate DNS" to check/create the DNS record automatically', 'thachpham-offload-cdn-cloudflare-r2' ); ?></li>
										<li><?php esc_html_e( 'Click "Deploy Worker" to deploy and configure routes', 'thachpham-offload-cdn-cloudflare-r2' ); ?></li>
									</ol>

									<div class="cfr2-notice cfr2-notice-info">
										<strong><?php esc_html_e( 'What happens when you validate:', 'thachpham-offload-cdn-cloudflare-r2' ); ?></strong>
										<ul style="margin: 8px 0 0 16px;">
											<li><?php esc_html_e( 'If DNS record does not exist → Creates A record with proxy enabled', 'thachpham-offload-cdn-cloudflare-r2' ); ?></li>
											<li><?php esc_html_e( 'If DNS record exists but proxy disabled → Shows warning with fix button', 'thachpham-offload-cdn-cloudflare-r2' ); ?></li>
											<li><?php esc_html_e( 'If DNS record exists with proxy → Ready to deploy!', 'thachpham-offload-cdn-cloudflare-r2' ); ?></li>
										</ul>
									</div>

									<p style="margin-top: 16px;"><strong><?php esc_html_e( 'Requirements:', 'thachpham-offload-cdn-cloudflare-r2' ); ?></strong></p>
									<ul>
										<li><?php esc_html_e( 'Domain must be in your Cloudflare account', 'thachpham-offload-cdn-cloudflare-r2' ); ?></li>
										<li><?php esc_html_e( 'API Token with Zone (Read), DNS (Edit) permissions', 'thachpham-offload-cdn-cloudflare-r2' ); ?></li>
										<li><?php esc_html_e( 'Zone Resources must include your domain', 'thachpham-offload-cdn-cloudflare-r2' ); ?></li>
									</ul>
								</div>
							</details>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="quality"><?php esc_html_e( 'Image Quality', 'thachpham-offload-cdn-cloudflare-r2' ); ?></label>
						</th>
						<td>
							<input type="range" id="quality" name="quality"
								min="1" max="100" value="<?php echo esc_attr( $settings['quality'] ?? 85 ); ?>" />
							<span id="quality-value"><?php echo esc_html( $settings['quality'] ?? 85 ); ?></span>
							<p class="description"><?php esc_html_e( '1-100. Higher = better quality, larger files. Default: 85', 'thachpham-offload-cdn-cloudflare-r2' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Image Format', 'thachpham-offload-cdn-cloudflare-r2' ); ?></label>
						</th>
						<td>
							<?php $image_format = $settings['image_format'] ?? 'webp'; ?>
							<fieldset>
								<label style="display: block; margin-bottom: 12px;">
									<input type="radio" name="image_format" value="original" <?php checked( 'original', $image_format ); ?> />
									<strong><?php esc_html_e( 'Original', 'thachpham-offload-cdn-cloudflare-r2' ); ?></strong>
									<span class="description" style="display: block; margin-left: 24px; color: #666;">
										<?php esc_html_e( 'Keep original format (JPEG, PNG, GIF). No conversion, maximum compatibility. Larger file sizes.', 'thachpham-offload-cdn-cloudflare-r2' ); ?>
									</span>
								</label>
								<label style="display: block; margin-bottom: 12px;">
									<input type="radio" name="image_format" value="webp" <?php checked( 'webp', $image_format ); ?> />
									<strong><?php esc_html_e( 'WebP (Recommended)', 'thachpham-offload-cdn-cloudflare-r2' ); ?></strong>
									<span class="description" style="display: block; margin-left: 24px; color: #666;">
										<?php esc_html_e( '25-35% smaller than JPEG. Supported by 97%+ browsers. Best balance of compression and compatibility.', 'thachpham-offload-cdn-cloudflare-r2' ); ?>
									</span>
								</label>
								<label style="display: block; margin-bottom: 0;">
									<input type="radio" name="image_format" value="avif" <?php checked( 'avif', $image_format ); ?> />
									<strong><?php esc_html_e( 'AVIF', 'thachpham-offload-cdn-cloudflare-r2' ); ?></strong>
									<span class="description" style="display: block; margin-left: 24px; color: #666;">
										<?php esc_html_e( '50% smaller than JPEG. Best compression. Supported by 93%+ browsers. Falls back to WebP for older browsers.', 'thachpham-offload-cdn-cloudflare-r2' ); ?>
									</span>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="smart_sizes"><?php esc_html_e( 'Smart Sizes', 'thachpham-offload-cdn-cloudflare-r2' ); ?></label>
						</th>
						<td>
							<input type="hidden" name="smart_sizes" value="0" />
							<label class="cloudflare-r2-offload-cdn-toggle">
								<input type="checkbox" id="smart_sizes" name="smart_sizes" value="1"
									<?php checked( 1, $settings['smart_sizes'] ?? 0 ); ?> />
								<span class="cloudflare-r2-offload-cdn-toggle-slider"></span>
							</label>
							<p class="description"><?php esc_html_e( 'Calculate optimal sizes attribute based on content width. Reduces bandwidth on mobile but increases Cloudflare Transformations cost.', 'thachpham-offload-cdn-cloudflare-r2' ); ?></p>
						</td>
					</tr>
					<tr class="smart-sizes-options" <?php echo empty( $settings['smart_sizes'] ) ? 'style="display:none;"' : ''; ?>>
						<th scope="row">
							<label for="content_max_width"><?php esc_html_e( 'Content Max Width', 'thachpham-offload-cdn-cloudflare-r2' ); ?></label>
						</th>
						<td>
							<input type="number" id="content_max_width" name="content_max_width"
								value="<?php echo esc_attr( $settings['content_max_width'] ?? 800 ); ?>"
								min="320" max="1920" step="10" class="small-text" /> px
							<p class="description"><?php esc_html_e( 'Maximum content area width in your theme. Used to calculate optimal image sizes.', 'thachpham-offload-cdn-cloudflare-r2' ); ?></p>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Worker Deployment', 'thachpham-offload-cdn-cloudflare-r2' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Deploy Cloudflare Worker for image transformation.', 'thachpham-offload-cdn-cloudflare-r2' ); ?></p>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Worker Status', 'thachpham-offload-cdn-cloudflare-r2' ); ?></th>
						<td>
							<div id="worker-status" class="cfr2-worker-status">
								<?php
								if ( ! empty( $settings['worker_deployed'] ) ) {
									echo '<span style="color: green;">✓ ' . esc_html__( 'Deployed', 'thachpham-offload-cdn-cloudflare-r2' ) . '</span>';
									if ( ! empty( $settings['worker_deployed_at'] ) ) {
										echo ' <span class="description">(' . esc_html( $settings['worker_deployed_at'] ) . ')</span>';
									}
								} else {
									echo '<span style="color: #999;">○ ' . esc_html__( 'Not deployed', 'thachpham-offload-cdn-cloudflare-r2' ) . '</span>';
								}
								?>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Actions', 'thachpham-offload-cdn-cloudflare-r2' ); ?></th>
						<td>
							<button type="button" id="deploy-worker" class="button button-primary">
								<?php esc_html_e( 'Deploy Worker', 'thachpham-offload-cdn-cloudflare-r2' ); ?>
							</button>
							<button type="button" id="remove-worker" class="button button-secondary" <?php echo empty( $settings['worker_deployed'] ) ? 'style="display:none;"' : ''; ?>>
								<?php esc_html_e( 'Remove Worker', 'thachpham-offload-cdn-cloudflare-r2' ); ?>
							</button>
							<span id="worker-deploy-result" style="margin-left: 10px;"></span>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}
}
