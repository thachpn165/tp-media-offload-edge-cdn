<?php
/**
 * Storage Tab class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * StorageTab class - renders R2 storage credentials and connection test.
 */
class StorageTab {

	/**
	 * Render the storage tab.
	 *
	 * @param array $settings Current settings.
	 */
	public static function render( array $settings ): void {
		?>
		<div class="cloudflare-r2-offload-cdn-tab-content" id="tab-storage">
			<h2><?php esc_html_e( 'R2 Storage Configuration', 'tp-media-offload-edge-cdn' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Configure your Cloudflare R2 storage credentials. Get these from Cloudflare Dashboard > R2 > Manage R2 API Tokens.', 'tp-media-offload-edge-cdn' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="r2_account_id"><?php esc_html_e( 'Account ID', 'tp-media-offload-edge-cdn' ); ?></label>
					</th>
					<td>
						<input type="text" id="r2_account_id" name="r2_account_id"
							value="<?php echo esc_attr( $settings['r2_account_id'] ?? '' ); ?>"
							class="regular-text" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Your Cloudflare account ID (alphanumeric).', 'tp-media-offload-edge-cdn' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="r2_access_key_id"><?php esc_html_e( 'Access Key ID', 'tp-media-offload-edge-cdn' ); ?></label>
					</th>
					<td>
						<input type="text" id="r2_access_key_id" name="r2_access_key_id"
							value="<?php echo esc_attr( $settings['r2_access_key_id'] ?? '' ); ?>"
							class="regular-text" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'R2 Access Key ID from API token.', 'tp-media-offload-edge-cdn' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="r2_secret_access_key"><?php esc_html_e( 'Secret Access Key', 'tp-media-offload-edge-cdn' ); ?></label>
					</th>
					<td>
						<?php
						$secret_value = ! empty( $settings['r2_secret_access_key'] ) ? '********' : '';
						?>
						<input type="password" id="r2_secret_access_key" name="r2_secret_access_key"
							value="<?php echo esc_attr( $secret_value ); ?>"
							class="regular-text" autocomplete="new-password" />
						<p class="description"><?php esc_html_e( 'R2 Secret Access Key (encrypted in database). Leave blank to keep existing value.', 'tp-media-offload-edge-cdn' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="r2_bucket"><?php esc_html_e( 'Bucket Name', 'tp-media-offload-edge-cdn' ); ?></label>
					</th>
					<td>
						<input type="text" id="r2_bucket" name="r2_bucket"
							value="<?php echo esc_attr( $settings['r2_bucket'] ?? '' ); ?>"
							class="regular-text" />
						<p class="description"><?php esc_html_e( 'R2 bucket name (lowercase alphanumeric and hyphens only).', 'tp-media-offload-edge-cdn' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="r2_public_domain"><?php esc_html_e( 'Public Domain', 'tp-media-offload-edge-cdn' ); ?></label>
					</th>
					<td>
						<input type="url" id="r2_public_domain" name="r2_public_domain"
							value="<?php echo esc_url( $settings['r2_public_domain'] ?? '' ); ?>"
							class="regular-text" placeholder="https://pub-xxx.r2.dev" />
						<p class="description">
							<?php esc_html_e( 'Public URL for R2 bucket. Required for CDN Worker deployment.', 'tp-media-offload-edge-cdn' ); ?>
							<br>
							<?php esc_html_e( 'Use R2.dev subdomain (e.g., https://pub-xxx.r2.dev) or custom domain.', 'tp-media-offload-edge-cdn' ); ?>
							<br>
							<?php esc_html_e( 'To enable public access, follow the "Public buckets" section in Cloudflare R2 documentation.', 'tp-media-offload-edge-cdn' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection Test', 'tp-media-offload-edge-cdn' ); ?></th>
					<td>
						<button type="button" id="test-r2-connection" class="button button-secondary">
							<?php esc_html_e( 'Test Connection', 'tp-media-offload-edge-cdn' ); ?>
						</button>
						<span id="r2-connection-result" style="margin-left: 10px;"></span>
						<p class="description"><?php esc_html_e( 'Verify R2 credentials by testing connection.', 'tp-media-offload-edge-cdn' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}
}
