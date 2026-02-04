<?php
/**
 * Admin dashboard for ST WP Importer.
 */
?>
<div class="wrap stwi-wrap">
	<h1>ST WP Importer</h1>

	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
	<?php endif; ?>

	<div class="stwi-grid">
		<div class="stwi-card">
			<h2>Connection &amp; Import Settings</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stwi-settings-form">
				<?php wp_nonce_field( 'stwi_save_settings' ); ?>
				<input type="hidden" name="action" value="stwi_save_settings">

				<table class="form-table">
					<tr>
						<th scope="row"><label for="stwi_source_db_host">Source DB Host</label></th>
						<td>
							<input name="source_db_host" id="stwi_source_db_host" type="text" class="regular-text" placeholder="e.g. 127.0.0.1" value="<?php echo esc_attr( $settings['source_db_host'] ); ?>">
							<p class="description">Hostname or IP of the source database server.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stwi_source_db_port">Source DB Port</label></th>
						<td>
							<input name="source_db_port" id="stwi_source_db_port" type="number" class="small-text" placeholder="3306" value="<?php echo esc_attr( $settings['source_db_port'] ); ?>">
							<p class="description">MySQL port (default 3306).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stwi_source_db_name">Source DB Name</label></th>
						<td>
							<input name="source_db_name" id="stwi_source_db_name" type="text" class="regular-text" placeholder="gpstrategies-source" value="<?php echo esc_attr( $settings['source_db_name'] ); ?>">
							<p class="description">Database name that holds the source multisite.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stwi_source_db_user">Source DB Username</label></th>
						<td>
							<input name="source_db_user" id="stwi_source_db_user" type="text" class="regular-text" placeholder="root" value="<?php echo esc_attr( $settings['source_db_user'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stwi_source_db_pass">Source DB Password</label></th>
						<td>
							<input name="source_db_pass" id="stwi_source_db_pass" type="password" class="regular-text" placeholder="••••••" value="<?php echo esc_attr( $settings['source_db_pass'] ); ?>">
							<p class="description">Not echoed back after save in the UI.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stwi_source_site_url">Source Site URL</label></th>
						<td>
							<input name="source_site_url" id="stwi_source_site_url" type="url" class="regular-text" placeholder="https://www.gpstrategies.com/" value="<?php echo esc_attr( $settings['source_site_url'] ); ?>">
							<p class="description">Used to build attachment URLs when sideloading.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stwi_source_table_prefix">Source Table Prefix</label></th>
						<td>
							<input name="source_table_prefix" id="stwi_source_table_prefix" type="text" class="regular-text" placeholder="wp_" value="<?php echo esc_attr( $settings['source_table_prefix'] ); ?>">
							<p class="description">Default multisite prefix is <code>wp_</code>; blog_id will adjust tables.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stwi_source_blog_id">Source Blog ID</label></th>
						<td>
							<input name="source_blog_id" id="stwi_source_blog_id" type="number" class="small-text" placeholder="1" value="<?php echo esc_attr( $settings['source_blog_id'] ); ?>">
							<p class="description">For multisite. Blog 1 has no extra prefix.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stwi_posts_per_run">Posts per batch</label></th>
						<td>
							<input name="posts_per_run" id="stwi_posts_per_run" type="number" class="small-text" placeholder="5" value="<?php echo esc_attr( $settings['posts_per_run'] ); ?>">
							<p class="description">Total posts processed per cron run (round-robin across types).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stwi_run_interval_minutes">Run interval (minutes)</label></th>
						<td>
							<input name="run_interval_minutes" id="stwi_run_interval_minutes" type="number" class="small-text" placeholder="1" value="<?php echo esc_attr( $settings['run_interval_minutes'] ); ?>">
							<p class="description">WP-Cron schedule (traffic-driven) uses this interval.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Dry run</th>
						<td>
							<label><input name="dry_run" type="checkbox" value="1" <?php checked( $settings['dry_run'], 1 ); ?>> Do everything except write posts/media/mappings.</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Logging</th>
						<td>
							<label><input name="enable_logging" type="checkbox" value="1" <?php checked( $settings['enable_logging'], 1 ); ?>> Write importer logs to <code><?php echo esc_html( $log_path ); ?></code></label>
							<p class="description">Runs regardless of <code>WP_DEBUG_LOG</code>. Leave enabled for troubleshooting.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Plugin data</th>
						<td>
							<p><label><input name="plugin_yoastseo" type="checkbox" value="1" <?php checked( $settings['plugin_yoastseo'], 1 ); ?>> Include Yoast SEO metadata</label></p>
							<p><label><input name="plugin_acf" type="checkbox" value="1" <?php checked( $settings['plugin_acf'], 1 ); ?>> Include ACF meta</label></p>
							<p><label><input name="plugin_permalink_manager" type="checkbox" value="1" <?php checked( $settings['plugin_permalink_manager'], 1 ); ?>> Include Permalink Manager Pro meta</label></p>
							<p><label><input name="plugin_powerpress" type="checkbox" value="1" <?php checked( $settings['plugin_powerpress'], 1 ); ?>> Import PowerPress settings/options</label></p>
							<p><label><input name="plugin_acf_theme_settings" type="checkbox" value="1" <?php checked( $settings['plugin_acf_theme_settings'], 1 ); ?>> Import ACF Theme Settings (options)</label></p>
							<p class="description">Toggle plugin-specific migrations. Logs remain verbose for debugging.</p>
						</td>
					</tr>
				</table>

				<h3>Import Scope (Post Types + Taxonomies)</h3>
				<p class="description">
					Add one row per post type. Use slugs. Taxonomies can be comma-separated (e.g., <code>industry,topic</code>).
				</p>
				<table class="widefat stwi-scope-table">
					<thead>
						<tr>
							<th style="width:10%">Enabled</th>
							<th style="width:20%">Post Type</th>
							<th>Taxonomies (comma-separated)</th>
							<th style="width:10%">Remove</th>
						</tr>
					</thead>
					<tbody id="stwi-scope-rows">
						<?php if ( ! empty( $settings['import_scope'] ) ) : ?>
							<?php foreach ( $settings['import_scope'] as $index => $row ) : ?>
								<tr class="stwi-scope-row">
									<td class="stwi-enable-cell">
										<label>
											<input type="hidden" name="import_scope[<?php echo esc_attr( $index ); ?>][enabled]" value="0">
											<input type="checkbox" name="import_scope[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( $row['enabled'] ?? 1, 1 ); ?>>
											<span class="screen-reader-text">Enable <?php echo esc_html( $row['post_type'] ); ?></span>
										</label>
									</td>
									<td>
										<input type="text" name="import_scope[<?php echo esc_attr( $index ); ?>][post_type]" value="<?php echo esc_attr( $row['post_type'] ); ?>" placeholder="e.g. events-cpt">
									</td>
									<td>
										<input type="text" name="import_scope[<?php echo esc_attr( $index ); ?>][taxonomies]" value="<?php echo esc_attr( implode( ',', $row['taxonomies'] ?? array() ) ); ?>" placeholder="e.g. event-type,industry,topic">
										<p class="description">Tip: match theme taxonomies to avoid missing assignments.</p>
									</td>
									<td><button type="button" class="button-link stwi-remove-row">Remove</button></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				<p><button type="button" class="button stwi-add-row">Add Row</button></p>
				<p class="description">Example: <code>training-course</code> with taxonomies <code>modality,leadership-level,course-type,topic</code>.</p>

				<?php submit_button( 'Save Settings', 'primary', 'submit', false ); ?>
				<button type="button" class="button" id="stwi-test-connection">Test Source DB Connection</button>
			</form>
		</div>

		<div class="stwi-card">
			<h2>Controls</h2>
			<div class="stwi-actions">
				<button type="button" class="button button-primary" id="stwi-start-import">Start Import (enable cron)</button>
				<button type="button" class="button" id="stwi-run-once">Run 1 Batch Now</button>
				<button type="button" class="button button-secondary" id="stwi-stop-import">Stop Import</button>
			</div>
			<p class="description">
				Start: sets running=true, clears stop flag, and schedules WP-Cron using the interval above.<br>
				Run 1 Batch: immediate AJAX batch (useful locally).<br>
				Stop: sets stop flag and clears scheduled cron.
			</p>

			<h3>Status</h3>
			<table class="widefat stwi-status-table">
				<tbody>
					<tr><th>Running</th><td><?php echo ! empty( $state['running'] ) ? 'Yes' : 'No'; ?></td></tr>
					<tr><th>Stop requested</th><td><?php echo ! empty( $state['stop_requested'] ) ? 'Yes' : 'No'; ?></td></tr>
					<tr><th>Last run</th><td><?php echo $state['last_run_at'] ? esc_html( date_i18n( 'Y-m-d H:i:s', $state['last_run_at'] ) ) : '–'; ?></td></tr>
					<tr><th>Next run (if scheduled)</th><td>
						<?php
						$next = wp_next_scheduled( 'stwi_run_batch' );
						echo $next ? esc_html( date_i18n( 'Y-m-d H:i:s', $next ) ) : 'Not scheduled';
						?>
					</td></tr>
					<tr><th>Active post types</th><td><?php echo esc_html( implode( ', ', $state['active_post_types'] ?? array() ) ); ?></td></tr>
					<tr><th>Cursors</th><td><code><?php echo esc_html( wp_json_encode( $state['cursor'] ?? array() ) ); ?></code></td></tr>
					<tr><th>Stats</th><td><code><?php echo esc_html( wp_json_encode( $state['stats'] ?? array() ) ); ?></code></td></tr>
					<tr><th>Last error</th><td><?php echo esc_html( $state['last_error'] ?? '' ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<div class="stwi-card">
			<h2>Logs</h2>
			<p class="description">Showing last ~100 lines from <code><?php echo esc_html( $log_path ); ?></code>.</p>
			<textarea id="stwi-log-viewer" readonly rows="16" class="large-text code"><?php echo esc_textarea( $log_tail ); ?></textarea>
			<p><button type="button" class="button" id="stwi-refresh-log">Refresh logs</button></p>
		</div>

		<div class="stwi-card stwi-danger-zone">
			<h2>Danger Zone</h2>
			<p class="description"><strong>Deletes only content imported via this plugin (using the mapping table), even if edited after import.</strong> Cannot be undone. Runs in small batches.</p>
			<button type="button" class="button stwi-danger-button" id="stwi-delete-imported">Delete Imported Content</button>
			<p class="description">Processes items one batch at a time (posts, attachments, terms, users). Click again if items remain. When nothing remains, importer state (cursor/stats), mapping table, and log are reset.</p>
			<div id="stwi-delete-status"></div>
		</div>
	</div>
</div>
