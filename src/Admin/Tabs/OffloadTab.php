<?php
/**
 * Offload Tab class.
 *
 * @package CFR2OffLoad
 */

namespace ThachPN165\CFR2OffLoad\Admin\Tabs;

defined( 'ABSPATH' ) || exit;

/**
 * OffloadTab class - renders offload behavior settings.
 */
class OffloadTab {

	/**
	 * Render the offload tab.
	 *
	 * @param array $settings Current settings.
	 */
	public static function render( array $settings ): void {
		?>
		<div class="cloudflare-r2-offload-cdn-tab-content" id="tab-offload">
			<h2><?php esc_html_e( 'Offload Settings', 'cf-r2-offload-cdn' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Configure how media files are offloaded to R2 storage.', 'cf-r2-offload-cdn' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="auto_offload"><?php esc_html_e( 'Auto-Offload on Upload', 'cf-r2-offload-cdn' ); ?></label>
					</th>
					<td>
						<input type="hidden" name="auto_offload" value="0" />
						<label class="cloudflare-r2-offload-cdn-toggle">
							<input type="checkbox" id="auto_offload" name="auto_offload" value="1"
								<?php checked( 1, $settings['auto_offload'] ?? 0 ); ?> />
							<span class="cloudflare-r2-offload-cdn-toggle-slider"></span>
						</label>
						<p class="description"><?php esc_html_e( 'Automatically offload media files to R2 when uploaded.', 'cf-r2-offload-cdn' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="batch_size"><?php esc_html_e( 'Batch Size', 'cf-r2-offload-cdn' ); ?></label>
					</th>
					<td>
						<select id="batch_size" name="batch_size" class="regular-text">
							<option value="10" <?php selected( 10, $settings['batch_size'] ?? 25 ); ?>>
								<?php esc_html_e( '10 files per batch', 'cf-r2-offload-cdn' ); ?>
							</option>
							<option value="25" <?php selected( 25, $settings['batch_size'] ?? 25 ); ?>>
								<?php esc_html_e( '25 files per batch', 'cf-r2-offload-cdn' ); ?>
							</option>
							<option value="50" <?php selected( 50, $settings['batch_size'] ?? 25 ); ?>>
								<?php esc_html_e( '50 files per batch', 'cf-r2-offload-cdn' ); ?>
							</option>
						</select>
						<p class="description"><?php esc_html_e( 'Number of files to process in each batch during bulk offload.', 'cf-r2-offload-cdn' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="keep_local_files"><?php esc_html_e( 'Keep Local Files', 'cf-r2-offload-cdn' ); ?></label>
					</th>
					<td>
						<input type="hidden" name="keep_local_files" value="0" />
						<label class="cloudflare-r2-offload-cdn-toggle">
							<input type="checkbox" id="keep_local_files" name="keep_local_files" value="1"
								<?php checked( 1, $settings['keep_local_files'] ?? 1 ); ?> />
							<span class="cloudflare-r2-offload-cdn-toggle-slider"></span>
						</label>
						<p class="description"><?php esc_html_e( 'Keep local copies of files after offloading to R2. Disable to save disk space (files will be served from R2/CDN).', 'cf-r2-offload-cdn' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="sync_delete"><?php esc_html_e( 'Sync Delete', 'cf-r2-offload-cdn' ); ?></label>
					</th>
					<td>
						<input type="hidden" name="sync_delete" value="0" />
						<label class="cloudflare-r2-offload-cdn-toggle">
							<input type="checkbox" id="sync_delete" name="sync_delete" value="1"
								<?php checked( 1, $settings['sync_delete'] ?? 0 ); ?> />
							<span class="cloudflare-r2-offload-cdn-toggle-slider"></span>
						</label>
						<p class="description"><?php esc_html_e( 'When deleting media from WordPress, also delete from R2 storage. Disable to keep R2 copies as backup.', 'cf-r2-offload-cdn' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}
}
