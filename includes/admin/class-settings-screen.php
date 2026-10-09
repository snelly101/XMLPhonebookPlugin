<?php
/**
 * Plugin settings screen.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Admin;

use SitePhonebooks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Settings API registration and rendering.
 */
final class Settings_Screen {

	/**
	 * Register the option with the Settings API.
	 */
	public static function register_settings() {
		register_setting(
			'spb_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Render.
	 */
	public static function render() {
		if ( ! Admin::can_manage() ) {
			Admin::deny();
		}
		$s = Settings::all();
		?>
		<div class="wrap spb-wrap">
			<h1><?php esc_html_e( 'Phonebook Settings', 'site-phonebooks' ); ?></h1>
			<?php settings_errors(); ?>
			<?php Notices::render(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( 'spb_settings_group' ); ?>
				<h2><?php esc_html_e( 'Defaults for new phonebooks', 'site-phonebooks' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Default feed access', 'site-phonebooks' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Default feed access', 'site-phonebooks' ); ?></legend>
								<label><input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[default_access_mode]" value="token" <?php checked( 'token', $s['default_access_mode'] ); ?>> <?php esc_html_e( 'Secret URL (recommended)', 'site-phonebooks' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[default_access_mode]" value="public" <?php checked( 'public', $s['default_access_mode'] ); ?>> <?php esc_html_e( 'Public', 'site-phonebooks' ); ?></label>
								<p class="description"><?php esc_html_e( 'Applies to phonebooks created from now on. Existing phonebooks keep their own setting.', 'site-phonebooks' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Imports and retention', 'site-phonebooks' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="spb-import-max-rows"><?php esc_html_e( 'Maximum rows per import', 'site-phonebooks' ); ?></label></th>
						<td><input type="number" id="spb-import-max-rows" name="<?php echo esc_attr( Settings::OPTION ); ?>[import_max_rows]" value="<?php echo (int) $s['import_max_rows']; ?>" min="1" max="50000" class="small-text"> <p class="description"><?php esc_html_e( 'Files with more rows are rejected before processing. The default of 5,000 has been tested; larger values need more PHP memory and time.', 'site-phonebooks' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="spb-import-max-kb"><?php esc_html_e( 'Maximum upload size (KB)', 'site-phonebooks' ); ?></label></th>
						<td><input type="number" id="spb-import-max-kb" name="<?php echo esc_attr( Settings::OPTION ); ?>[import_max_file_kb]" value="<?php echo (int) $s['import_max_file_kb']; ?>" min="16" max="16384" class="small-text"> <p class="description"><?php echo esc_html( sprintf( /* translators: %s: PHP upload limit */ __( 'Also limited by the server\'s PHP upload limit (currently %s).', 'site-phonebooks' ), size_format( wp_max_upload_size() ) ) ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="spb-staging-ttl"><?php esc_html_e( 'Keep unfinished imports for (minutes)', 'site-phonebooks' ); ?></label></th>
						<td><input type="number" id="spb-staging-ttl" name="<?php echo esc_attr( Settings::OPTION ); ?>[staging_ttl_minutes]" value="<?php echo (int) $s['staging_ttl_minutes']; ?>" min="5" max="1440" class="small-text"> <p class="description"><?php esc_html_e( 'Uploaded files and previews that are not applied are deleted after this time.', 'site-phonebooks' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="spb-retention"><?php esc_html_e( 'Snapshots kept per phonebook', 'site-phonebooks' ); ?></label></th>
						<td><input type="number" id="spb-retention" name="<?php echo esc_attr( Settings::OPTION ); ?>[revision_retention]" value="<?php echo (int) $s['revision_retention']; ?>" min="1" max="100" class="small-text"> <p class="description"><?php esc_html_e( 'Older snapshots are removed automatically.', 'site-phonebooks' ); ?></p></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Delivery', 'site-phonebooks' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="spb-max-age"><?php esc_html_e( 'Cache max-age (seconds)', 'site-phonebooks' ); ?></label></th>
						<td><input type="number" id="spb-max-age" name="<?php echo esc_attr( Settings::OPTION ); ?>[feed_max_age]" value="<?php echo (int) $s['feed_max_age']; ?>" min="0" max="86400" class="small-text"> <p class="description"><?php esc_html_e( 'Sent in the Cache-Control header. 0 (default) asks clients to revalidate every time using ETag / Last-Modified. Protected feeds are always marked private.', 'site-phonebooks' ); ?></p></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Uninstall', 'site-phonebooks' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Data removal', 'site-phonebooks' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[delete_on_uninstall]" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all phonebooks, contacts, snapshots and settings when the plugin is uninstalled', 'site-phonebooks' ); ?></label>
							<p class="description"><?php esc_html_e( 'Deactivating never removes data. Uninstalling keeps data unless this box is ticked.', 'site-phonebooks' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
