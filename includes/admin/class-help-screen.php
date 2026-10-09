<?php
/**
 * Help & Diagnostics screen.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Admin;

use SitePhonebooks\Diagnostics;
use SitePhonebooks\Feed\Feed_Router;
use SitePhonebooks\Phonebook_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Setup guidance, format reference and environment checks.
 */
final class Help_Screen {

	/**
	 * Render.
	 */
	public static function render() {
		if ( ! Admin::can_manage() ) {
			Admin::deny();
		}
		$template_url = wp_nonce_url( add_query_arg( array( 'action' => 'spb_template' ), admin_url( 'admin-post.php' ) ), 'spb_template' );
		$checks       = Diagnostics::run();
		$phonebooks   = Phonebook_Repository::all_summaries();
		$icons        = array(
			'ok'      => '✔',
			'warning' => '⚠',
			'error'   => '✖',
			'info'    => 'ℹ',
		);
		?>
		<div class="wrap spb-wrap">
			<h1><?php esc_html_e( 'Help & Diagnostics', 'site-phonebooks' ); ?></h1>
			<?php Notices::render(); ?>
			<div class="spb-columns">
				<div class="spb-main">
					<div class="spb-card">
						<h2><?php esc_html_e( 'Environment checks', 'site-phonebooks' ); ?></h2>
						<table class="widefat striped spb-table spb-checks">
							<thead><tr><th scope="col"><?php esc_html_e( 'Check', 'site-phonebooks' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'site-phonebooks' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $checks as $check ) : ?>
								<tr class="spb-check-<?php echo esc_attr( $check['status'] ); ?>">
									<th scope="row"><?php echo esc_html( $check['label'] ); ?></th>
									<td><span class="spb-check-icon" aria-hidden="true"><?php echo esc_html( $icons[ $check['status'] ] ); ?></span> <span class="screen-reader-text"><?php echo esc_html( $check['status'] ); ?>:</span> <?php echo esc_html( $check['detail'] ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<div class="spb-card">
						<h2><?php esc_html_e( 'Server self-test', 'site-phonebooks' ); ?></h2>
						<p><?php esc_html_e( 'Asks this server to fetch one of its own feeds (HEAD request with the phonebook\'s key). It verifies routing, permalinks and access settings on the server. It cannot tell you whether a handset on another network, behind a firewall or with its own TLS trust store can reach the feed – verify that on the phone.', 'site-phonebooks' ); ?></p>
						<?php if ( empty( $phonebooks ) ) : ?>
							<p class="spb-empty"><?php esc_html_e( 'Create a phonebook first.', 'site-phonebooks' ); ?></p>
						<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-inline-form spb-single-submit">
							<?php wp_nonce_field( 'spb_self_test' ); ?>
							<input type="hidden" name="action" value="spb_self_test">
							<label for="spb-self-test-phonebook" class="screen-reader-text"><?php esc_html_e( 'Phonebook to test', 'site-phonebooks' ); ?></label>
							<select name="phonebook_id" id="spb-self-test-phonebook">
								<?php foreach ( $phonebooks as $pb ) : ?>
									<option value="<?php echo (int) $pb->id; ?>"><?php echo esc_html( $pb->name ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="button"><?php esc_html_e( 'Run self-test', 'site-phonebooks' ); ?></button>
						</form>
						<?php endif; ?>
					</div>

					<div class="spb-card">
						<h2><?php esc_html_e( 'Setting up a Yealink handset', 'site-phonebooks' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Open the phonebook in WordPress and copy its feed URL (Feed & access tab).', 'site-phonebooks' ); ?></li>
							<li><?php esc_html_e( 'In the phone\'s web interface go to Directory > Remote Phone Book (the exact menu depends on model and firmware; on some models it is Directory > Remote Phonebook or Settings > Remote Phone Book).', 'site-phonebooks' ); ?></li>
							<li><?php esc_html_e( 'Paste the URL into a Remote URL field and give it a display name. Save.', 'site-phonebooks' ); ?></li>
							<li><?php esc_html_e( 'On the handset, open Directory > Remote Phone Book to confirm the contacts appear.', 'site-phonebooks' ); ?></li>
						</ol>
						<p><?php esc_html_e( 'Handsets fetch the feed on their own schedule (often when the directory is opened, or at a configurable refresh interval). Updates made in WordPress appear after the next fetch; the plugin cannot push to phones. Support for HTTPS, query-string keys, the number of remote phonebooks and contact capacity differ between models and firmware versions – verify with one phone before rolling out.', 'site-phonebooks' ); ?></p>
					</div>

					<div class="spb-card">
						<h2><?php esc_html_e( 'XML format', 'site-phonebooks' ); ?></h2>
						<p><?php esc_html_e( 'Feeds use the structure of the owner\'s known-working file. The title is the site name; each contact is one entry with one name and one number.', 'site-phonebooks' ); ?></p>
						<pre class="spb-pre">&lt;?xml version="1.0" encoding="utf-8"?&gt;
&lt;XXXIPPhoneDirectory clearlight="true"&gt;
  &lt;Title&gt;Frickley Mews&lt;/Title&gt;
  &lt;Prompt&gt;Prompt&lt;/Prompt&gt;
  &lt;DirectoryEntry&gt;
    &lt;Name&gt;Reception&lt;/Name&gt;
    &lt;Telephone&gt;1001&lt;/Telephone&gt;
  &lt;/DirectoryEntry&gt;
&lt;/XXXIPPhoneDirectory&gt;</pre>
						<p><?php esc_html_e( 'Files in this format can also be imported. Other manufacturer formats are not supported yet.', 'site-phonebooks' ); ?></p>
					</div>
				</div>
				<aside class="spb-side">
					<div class="spb-card">
						<h2><?php esc_html_e( 'CSV template', 'site-phonebooks' ); ?></h2>
						<p><a class="button" href="<?php echo esc_url( $template_url ); ?>"><?php esc_html_e( 'Download template', 'site-phonebooks' ); ?></a></p>
						<p class="description"><?php esc_html_e( 'Save files as UTF-8. Keep telephone columns formatted as Text so leading zeros survive.', 'site-phonebooks' ); ?></p>
					</div>
					<div class="spb-card">
						<h2><?php esc_html_e( 'Feed address forms', 'site-phonebooks' ); ?></h2>
						<p><?php esc_html_e( 'Protected (secret URL):', 'site-phonebooks' ); ?></p>
						<code><?php echo esc_html( home_url( '/' . Feed_Router::base() . '/{site}.xml?key={key}' ) ); ?></code><br>
						<code><?php echo esc_html( home_url( '/' . Feed_Router::base() . '/{key}/{site}.xml' ) ); ?></code>
						<p><?php esc_html_e( 'Public:', 'site-phonebooks' ); ?></p>
						<code><?php echo esc_html( home_url( '/' . Feed_Router::base() . '/{site}.xml' ) ); ?></code>
						<p><?php esc_html_e( 'Without pretty permalinks:', 'site-phonebooks' ); ?></p>
						<code><?php echo esc_html( home_url( '/?spb_feed={site}.xml&key={key}' ) ); ?></code>
					</div>
					<div class="spb-card">
						<h2><?php esc_html_e( 'Caches, CDNs and security plugins', 'site-phonebooks' ); ?></h2>
						<p><?php echo esc_html( sprintf( /* translators: %s: path */ __( 'Exclude /%s/ and the spb_feed query parameter from page caching, CDN caching, bot protection and login-redirect rules. Cached copies would bypass key checks and key rotation.', 'site-phonebooks' ), Feed_Router::base() ) ); ?></p>
					</div>
					<div class="spb-card">
						<h2><?php esc_html_e( 'Data retention', 'site-phonebooks' ); ?></h2>
						<p><?php esc_html_e( 'Deactivating keeps all data. Uninstalling keeps data unless "Delete all data on uninstall" is enabled in Settings. Only administrators can manage phonebooks (capability manage_site_phonebooks).', 'site-phonebooks' ); ?></p>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}
}
