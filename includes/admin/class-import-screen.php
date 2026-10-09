<?php
/**
 * Import tab: upload, mapping, preview.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Admin;

use SitePhonebooks\Csv\Csv_Parser;
use SitePhonebooks\Import\Import_Planner;
use SitePhonebooks\Import\Import_Staging;
use SitePhonebooks\Settings;
use SitePhonebooks\Xml\Yealink_Xml_Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Three-step import flow. All state lives in the staging table.
 */
final class Import_Screen {

	const PREVIEW_ROWS = 500;

	/**
	 * Render the tab.
	 *
	 * @param object $phonebook Phonebook.
	 */
	public static function render( $phonebook ) {
		$user_id = get_current_user_id();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$import_id = isset( $_GET['import'] ) ? absint( $_GET['import'] ) : 0;
		$step      = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		// phpcs:enable
		$staging = $import_id ? Import_Staging::get( $import_id, $phonebook->id, $user_id, true ) : null;

		if ( $import_id && ! $staging ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'That staged import is no longer available (it expired, was cancelled or was applied). Upload the file again.', 'site-phonebooks' ) . '</p></div>';
		}
		if ( $staging && Import_Staging::STATUS_PREVIEW === $staging->status && 'map' !== $step ) {
			self::render_preview( $phonebook, $staging );
			return;
		}
		if ( $staging ) {
			self::render_mapping( $phonebook, $staging );
			return;
		}
		self::render_upload( $phonebook );
	}

	/**
	 * Step 1: upload.
	 *
	 * @param object $phonebook Phonebook.
	 */
	private static function render_upload( $phonebook ) {
		$open         = Import_Staging::open_for( $phonebook->id, get_current_user_id() );
		$template_url = wp_nonce_url( add_query_arg( array( 'action' => 'spb_template' ), admin_url( 'admin-post.php' ) ), 'spb_template' );
		$max_kb       = (int) Settings::get( 'import_max_file_kb' );
		$max_rows     = (int) Settings::get( 'import_max_rows' );
		?>
		<div class="spb-columns">
			<div class="spb-main">
				<div class="spb-card">
					<h2><?php esc_html_e( 'Upload a CSV or XML file', 'site-phonebooks' ); ?></h2>
					<p><?php esc_html_e( 'Nothing changes until you review the preview and confirm. You will be able to choose which columns hold the name and telephone, and whether to merge into or replace the current list.', 'site-phonebooks' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="spb-form spb-single-submit">
						<?php wp_nonce_field( 'spb_import_upload_' . $phonebook->id ); ?>
						<input type="hidden" name="action" value="spb_import_upload">
						<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
						<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo (int) ( $max_kb * 1024 ); ?>">
						<p>
							<label for="spb-import-file" class="spb-label"><?php esc_html_e( 'File', 'site-phonebooks' ); ?></label>
							<input type="file" id="spb-import-file" name="import_file" accept=".csv,.txt,.tsv,.xml,text/csv,text/plain,text/xml,application/xml" required aria-describedby="spb-import-file-desc">
							<span class="description" id="spb-import-file-desc"><?php echo esc_html( sprintf( /* translators: 1: max file size in KB, 2: max rows */ __( 'CSV (comma, semicolon or tab separated, UTF-8) or Yealink XML. Up to %1$s KB and %2$s rows.', 'site-phonebooks' ), number_format_i18n( $max_kb ), number_format_i18n( $max_rows ) ) ); ?></span>
						</p>
						<?php submit_button( __( 'Upload and continue', 'site-phonebooks' ), 'primary', 'submit', false ); ?>
					</form>
				</div>
				<?php if ( ! empty( $open ) ) : ?>
				<div class="spb-card">
					<h2><?php esc_html_e( 'Imports in progress', 'site-phonebooks' ); ?></h2>
					<ul class="spb-list">
						<?php foreach ( $open as $item ) : ?>
							<li>
								<strong><?php echo esc_html( $item->filename ); ?></strong>
								<span class="spb-muted"><?php echo esc_html( sprintf( /* translators: 1: status, 2: expiry */ __( '%1$s · expires %2$s', 'site-phonebooks' ), Import_Staging::STATUS_PREVIEW === $item->status ? __( 'previewed', 'site-phonebooks' ) : __( 'uploaded', 'site-phonebooks' ), Admin::format_date( $item->expires_at ) ) ); ?></span>
								<a class="button button-small" href="<?php echo esc_url( Admin::phonebook_url( $phonebook->id, 'import', array( 'import' => $item->id ) ) ); ?>"><?php esc_html_e( 'Continue', 'site-phonebooks' ); ?></a>
								<?php self::cancel_form( $phonebook, $item->id ); ?>
								<?php self::cancel_button( $item->id, true ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
			</div>
			<aside class="spb-side">
				<div class="spb-card">
					<h2><?php esc_html_e( 'CSV template', 'site-phonebooks' ); ?></h2>
					<p><a class="button" href="<?php echo esc_url( $template_url ); ?>"><?php esc_html_e( 'Download template', 'site-phonebooks' ); ?></a></p>
					<pre class="spb-pre">Name,Telephone
Reception,1001
Support Engineer,0001
External Contact,+441234567890</pre>
					<p class="description"><?php esc_html_e( 'Other headers are fine; you map columns in the next step. An optional Notes column is kept internally and never published.', 'site-phonebooks' ); ?></p>
				</div>
				<div class="spb-card spb-card-warning">
					<h2><?php esc_html_e( 'Leading zeros', 'site-phonebooks' ); ?></h2>
					<p><?php esc_html_e( 'Spreadsheet software often converts "0001" to "1" and "+441234" to a number when it opens a CSV. The plugin keeps telephone text exactly as it is in the uploaded file, but it cannot restore digits that were already lost. Format telephone columns as Text before saving.', 'site-phonebooks' ); ?></p>
				</div>
			</aside>
		</div>
		<?php
	}

	/**
	 * Standalone cancel form (never nested inside another form). Buttons
	 * elsewhere reference it with the HTML "form" attribute.
	 *
	 * @param object $phonebook Phonebook.
	 * @param int    $import_id Import ID.
	 */
	private static function cancel_form( $phonebook, $import_id ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-inline-form" id="spb-import-cancel-<?php echo (int) $import_id; ?>">
			<?php wp_nonce_field( 'spb_import_cancel_' . $phonebook->id ); ?>
			<input type="hidden" name="action" value="spb_import_cancel">
			<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
			<input type="hidden" name="import_id" value="<?php echo (int) $import_id; ?>">
		</form>
		<?php
	}

	/**
	 * Button that submits the standalone cancel form.
	 *
	 * @param int  $import_id Import ID.
	 * @param bool $small     Small button.
	 */
	private static function cancel_button( $import_id, $small = false ) {
		?>
		<button type="submit" form="spb-import-cancel-<?php echo (int) $import_id; ?>" class="button <?php echo $small ? 'button-small' : ''; ?>"><?php esc_html_e( 'Discard upload', 'site-phonebooks' ); ?></button>
		<?php
	}

	/**
	 * Step 2: mapping and options.
	 *
	 * @param object $phonebook Phonebook.
	 * @param object $staging   Staging record (with raw content).
	 */
	private static function render_mapping( $phonebook, $staging ) {
		$options  = $staging->options;
		$is_xml   = 'xml' === $staging->source_type;
		$mode     = isset( $options['mode'] ) && 'replace' === $options['mode'] ? 'replace' : 'merge';
		$encoding = isset( $options['encoding'] ) && in_array( $options['encoding'], Csv_Parser::encodings(), true ) ? $options['encoding'] : 'utf-8';
		?>
		<div class="spb-card">
			<h2><?php echo esc_html( sprintf( /* translators: %s: file name */ __( 'Step 2 of 3: options for %s', 'site-phonebooks' ), $staging->filename ) ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-form spb-single-submit">
				<?php wp_nonce_field( 'spb_import_map_' . $phonebook->id ); ?>
				<input type="hidden" name="action" value="spb_import_map">
				<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
				<input type="hidden" name="import_id" value="<?php echo (int) $staging->id; ?>">
				<?php
				if ( $is_xml ) {
					self::render_xml_options( $staging, $options );
				} else {
					self::render_csv_options( $staging, $options, $encoding );
				}
				?>
				<fieldset class="spb-fieldset">
					<legend><?php esc_html_e( 'How to apply the file', 'site-phonebooks' ); ?></legend>
					<p><label><input type="radio" name="mode" value="merge" <?php checked( 'merge', $mode ); ?>> <strong><?php esc_html_e( 'Merge', 'site-phonebooks' ); ?></strong> — <?php esc_html_e( 'add new contacts and keep every existing one. Rows whose name and telephone exactly match an existing contact are skipped. A matching name with a different number becomes a separate contact; this mode does not detect number changes (edit those manually or use Replace).', 'site-phonebooks' ); ?></label></p>
					<p><label><input type="radio" name="mode" value="replace" <?php checked( 'replace', $mode ); ?>> <strong><?php esc_html_e( 'Replace', 'site-phonebooks' ); ?></strong> — <?php echo esc_html( sprintf( /* translators: %s: current contact count */ __( 'the file becomes the complete list. Existing contacts that are not in the file are removed (currently %s contacts). A snapshot is taken first and you must confirm on the preview.', 'site-phonebooks' ), number_format_i18n( $phonebook->contact_count ) ) ); ?></label></p>
					<p><label><input type="checkbox" name="valid_only" value="1" <?php checked( ! empty( $options['valid_only'] ) ); ?>> <?php esc_html_e( 'Import valid rows only: skip rows with errors instead of blocking the whole import. The preview will show exactly which rows are skipped.', 'site-phonebooks' ); ?></label></p>
				</fieldset>
				<p class="spb-actions">
					<?php submit_button( __( 'Build preview', 'site-phonebooks' ), 'primary', 'submit', false ); ?>
					<?php self::cancel_button( $staging->id ); ?>
				</p>
			</form>
			<?php self::cancel_form( $phonebook, $staging->id ); ?>
		</div>
		<?php
	}

	/**
	 * CSV-specific options: encoding, delimiter, header, mapping, sample.
	 *
	 * @param object $staging  Staging.
	 * @param array  $options  Options.
	 * @param string $encoding Current encoding.
	 */
	private static function render_csv_options( $staging, array $options, $encoding ) {
		$decoded = Csv_Parser::to_utf8( $staging->raw_content, $encoding );
		$records = array();
		$delim   = isset( $options['delimiter'] ) ? $options['delimiter'] : '';
		if ( $decoded['ok'] ) {
			if ( ! isset( Csv_Parser::delimiters()[ $delim ] ) ) {
				$delim = Csv_Parser::detect_delimiter( $decoded['text'] );
			}
			$parsed  = Csv_Parser::parse( $decoded['text'], $delim, 6 );
			$records = $parsed['records'];
		} else {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $decoded['error'] ) . '</p></div>';
		}
		$has_header = array_key_exists( 'has_header', $options ) ? ! empty( $options['has_header'] ) : ( ! empty( $records ) && Csv_Parser::looks_like_header( $records[0]['fields'] ) );
		$columns    = 0;
		foreach ( $records as $record ) {
			$columns = max( $columns, count( $record['fields'] ) );
		}
		$header = ( $has_header && ! empty( $records ) ) ? $records[0]['fields'] : array();
		$map    = isset( $options['map'] ) && is_array( $options['map'] ) ? $options['map'] : Csv_Parser::guess_mapping( $header ? $header : ( $records ? $records[0]['fields'] : array() ) );
		$delims = array(
			'comma'     => __( 'Comma (,)', 'site-phonebooks' ),
			'semicolon' => __( 'Semicolon (;)', 'site-phonebooks' ),
			'tab'       => __( 'Tab', 'site-phonebooks' ),
		);
		?>
		<fieldset class="spb-fieldset">
			<legend><?php esc_html_e( 'File format', 'site-phonebooks' ); ?></legend>
			<p>
				<label for="spb-encoding"><?php esc_html_e( 'Text encoding', 'site-phonebooks' ); ?></label>
				<select name="encoding" id="spb-encoding">
					<option value="utf-8" <?php selected( 'utf-8', $encoding ); ?>><?php esc_html_e( 'UTF-8 (recommended; UTF-16 with BOM is detected automatically)', 'site-phonebooks' ); ?></option>
					<option value="windows-1252" <?php selected( 'windows-1252', $encoding ); ?>><?php esc_html_e( 'Windows-1252 (legacy Excel on Windows)', 'site-phonebooks' ); ?></option>
					<option value="iso-8859-1" <?php selected( 'iso-8859-1', $encoding ); ?>><?php esc_html_e( 'ISO-8859-1 (Latin-1)', 'site-phonebooks' ); ?></option>
				</select>
			</p>
			<p>
				<label for="spb-delimiter"><?php esc_html_e( 'Column separator', 'site-phonebooks' ); ?></label>
				<select name="delimiter" id="spb-delimiter">
					<?php foreach ( $delims as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $delim ); ?>><?php echo esc_html( $label ); ?><?php echo $key === $delim && ! isset( $options['delimiter'] ) ? ' ' . esc_html__( '(detected)', 'site-phonebooks' ) : ''; ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p><label><input type="checkbox" name="has_header" value="1" <?php checked( $has_header ); ?>> <?php esc_html_e( 'The first row is a header, not a contact', 'site-phonebooks' ); ?></label></p>
			<p class="description"><?php esc_html_e( 'Change any of these and choose "Build preview" to re-read the file with the new settings.', 'site-phonebooks' ); ?></p>
		</fieldset>

		<?php if ( ! empty( $records ) ) : ?>
		<h3><?php esc_html_e( 'First rows of the file', 'site-phonebooks' ); ?></h3>
		<div class="spb-table-scroll">
			<table class="widefat striped spb-table spb-sample">
				<thead><tr><th scope="col">#</th><?php for ( $c = 0; $c < $columns; $c++ ) : ?><th scope="col"><?php echo esc_html( self::column_label( $c, $header ) ); ?></th><?php endfor; ?></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $records, $has_header ? 1 : 0, 5 ) as $record ) : ?>
					<tr><td><?php echo (int) $record['row']; ?></td><?php for ( $c = 0; $c < $columns; $c++ ) : ?><td><?php echo esc_html( isset( $record['fields'][ $c ] ) ? $record['fields'][ $c ] : '' ); ?></td><?php endfor; ?></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>

		<fieldset class="spb-fieldset">
			<legend><?php esc_html_e( 'Column mapping', 'site-phonebooks' ); ?></legend>
			<?php foreach ( array( 'name' => __( 'Name column', 'site-phonebooks' ), 'telephone' => __( 'Telephone column', 'site-phonebooks' ), 'notes' => __( 'Notes column (optional, internal)', 'site-phonebooks' ) ) as $field => $label ) : ?>
			<p>
				<label for="spb-map-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label>
				<select name="map[<?php echo esc_attr( $field ); ?>]" id="spb-map-<?php echo esc_attr( $field ); ?>" <?php echo 'notes' === $field ? '' : 'required'; ?>>
					<option value=""><?php echo 'notes' === $field ? esc_html__( '— none —', 'site-phonebooks' ) : esc_html__( '— choose —', 'site-phonebooks' ); ?></option>
					<?php for ( $c = 0; $c < max( $columns, 2 ); $c++ ) : ?>
						<option value="<?php echo (int) $c; ?>" <?php selected( isset( $map[ $field ] ) && null !== $map[ $field ] && '' !== $map[ $field ] ? (int) $map[ $field ] : -1, $c ); ?>><?php echo esc_html( self::column_label( $c, $header ) ); ?></option>
					<?php endfor; ?>
				</select>
			</p>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Column label for mapping selects.
	 *
	 * @param int      $index  Column index.
	 * @param string[] $header Header cells.
	 * @return string
	 */
	private static function column_label( $index, array $header ) {
		$name = isset( $header[ $index ] ) && '' !== trim( $header[ $index ] ) ? $header[ $index ] : '';
		/* translators: %d: column number */
		$generic = sprintf( __( 'Column %d', 'site-phonebooks' ), $index + 1 );
		return '' !== $name ? $generic . ': ' . $name : $generic;
	}

	/**
	 * XML-specific options.
	 *
	 * @param object $staging Staging.
	 * @param array  $options Options.
	 */
	private static function render_xml_options( $staging, array $options ) {
		$parsed = Yealink_Xml_Parser::parse( $staging->raw_content, (int) Settings::get( 'import_max_rows' ) );
		if ( ! $parsed['ok'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $parsed['error'] ) . '</p></div>';
			return;
		}
		?>
		<fieldset class="spb-fieldset">
			<legend><?php esc_html_e( 'XML file', 'site-phonebooks' ); ?></legend>
			<dl class="spb-dl">
				<dt><?php esc_html_e( 'Title', 'site-phonebooks' ); ?></dt><dd><?php echo '' !== $parsed['title'] ? esc_html( $parsed['title'] ) : esc_html__( '(none)', 'site-phonebooks' ); ?></dd>
				<dt><?php esc_html_e( 'Entries', 'site-phonebooks' ); ?></dt><dd><?php echo esc_html( number_format_i18n( count( $parsed['records'] ) ) ); ?></dd>
			</dl>
			<?php if ( '' !== $parsed['title'] ) : ?>
			<p><label><input type="checkbox" name="rename_from_title" value="1" <?php checked( ! empty( $options['rename_from_title'] ) ); ?>> <?php echo esc_html( sprintf( /* translators: %s: XML title */ __( 'Rename this phonebook to "%s" (the XML title). The feed address does not change.', 'site-phonebooks' ), $parsed['title'] ) ); ?></label></p>
			<?php endif; ?>
			<?php foreach ( $parsed['warnings'] as $warning ) : ?>
				<p class="spb-warning"><?php echo esc_html( $warning ); ?></p>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Step 3: preview and confirm.
	 *
	 * @param object $phonebook Phonebook.
	 * @param object $staging   Staging.
	 */
	private static function render_preview( $phonebook, $staging ) {
		$plan    = $staging->plan;
		$summary = $plan['summary'];
		$replace = Import_Planner::MODE_REPLACE === $plan['mode'];
		$stale   = (int) $staging->base_revision !== (int) $phonebook->revision;
		$blocked = ! empty( $plan['blocking'] ) || $stale;
		$labels  = array(
			Import_Planner::STATUS_ADD          => __( 'Will be added', 'site-phonebooks' ),
			Import_Planner::STATUS_DUP_EXISTING => __( 'Already exists (kept)', 'site-phonebooks' ),
			Import_Planner::STATUS_DUP_FILE     => __( 'Duplicate in file (skipped)', 'site-phonebooks' ),
			Import_Planner::STATUS_INVALID      => $plan['valid_only'] ? __( 'Invalid (skipped)', 'site-phonebooks' ) : __( 'Invalid (blocks import)', 'site-phonebooks' ),
		);
		$rows = $plan['rows'];
		usort(
			$rows,
			static function ( $a, $b ) {
				$order = array( Import_Planner::STATUS_INVALID => 0, Import_Planner::STATUS_ADD => 1, Import_Planner::STATUS_DUP_EXISTING => 2, Import_Planner::STATUS_DUP_FILE => 3 );
				$cmp   = $order[ $a['status'] ] <=> $order[ $b['status'] ];
				return 0 !== $cmp ? $cmp : $a['row'] <=> $b['row'];
			}
		);
		?>
		<div class="spb-card">
			<h2><?php echo esc_html( sprintf( /* translators: %s: file name */ __( 'Step 3 of 3: preview of %s', 'site-phonebooks' ), $staging->filename ) ); ?></h2>
			<p><?php echo $replace ? esc_html__( 'Mode: Replace — the file becomes the complete contact list.', 'site-phonebooks' ) : esc_html__( 'Mode: Merge — new contacts are added, existing contacts are kept.', 'site-phonebooks' ); ?> <?php echo esc_html__( 'The live phonebook has not been changed yet.', 'site-phonebooks' ); ?></p>

			<?php if ( $stale ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'The phonebook changed after this preview was built (someone added, edited or imported contacts). Build a fresh preview before applying.', 'site-phonebooks' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( $plan['blocking'] as $message ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endforeach; ?>

			<ul class="spb-stats" aria-label="<?php esc_attr_e( 'Import summary', 'site-phonebooks' ); ?>">
				<li><span class="spb-stat-value"><?php echo esc_html( number_format_i18n( $summary['total'] ) ); ?></span><span class="spb-stat-label"><?php esc_html_e( 'rows in file', 'site-phonebooks' ); ?></span></li>
				<li class="spb-stat-good"><span class="spb-stat-value"><?php echo esc_html( number_format_i18n( $summary['add'] ) ); ?></span><span class="spb-stat-label"><?php esc_html_e( 'to add', 'site-phonebooks' ); ?></span></li>
				<li><span class="spb-stat-value"><?php echo esc_html( number_format_i18n( $summary['retained'] ) ); ?></span><span class="spb-stat-label"><?php echo $replace ? esc_html__( 'existing kept (in file)', 'site-phonebooks' ) : esc_html__( 'existing kept', 'site-phonebooks' ); ?></span></li>
				<li><span class="spb-stat-value"><?php echo esc_html( number_format_i18n( $summary['duplicate_existing'] + $summary['duplicate_in_file'] ) ); ?></span><span class="spb-stat-label"><?php esc_html_e( 'duplicates skipped', 'site-phonebooks' ); ?></span></li>
				<?php if ( $replace ) : ?>
				<li class="spb-stat-bad"><span class="spb-stat-value"><?php echo esc_html( number_format_i18n( $summary['removed'] ) ); ?></span><span class="spb-stat-label"><?php esc_html_e( 'existing to remove', 'site-phonebooks' ); ?></span></li>
				<?php endif; ?>
				<li class="<?php echo $summary['invalid'] > 0 ? 'spb-stat-bad' : ''; ?>"><span class="spb-stat-value"><?php echo esc_html( number_format_i18n( $summary['invalid'] ) ); ?></span><span class="spb-stat-label"><?php esc_html_e( 'invalid rows', 'site-phonebooks' ); ?></span></li>
				<li><span class="spb-stat-value"><?php echo esc_html( number_format_i18n( $summary['final_count'] ) ); ?></span><span class="spb-stat-label"><?php esc_html_e( 'contacts after import', 'site-phonebooks' ); ?></span></li>
			</ul>
			<?php if ( ! empty( $plan['rename'] ) ) : ?>
				<p class="spb-warning"><?php echo esc_html( sprintf( /* translators: %s: new name */ __( 'The phonebook will be renamed to "%s".', 'site-phonebooks' ), $plan['rename'] ) ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-form spb-single-submit">
				<?php wp_nonce_field( 'spb_import_commit_' . $phonebook->id ); ?>
				<input type="hidden" name="action" value="spb_import_commit">
				<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
				<input type="hidden" name="import_id" value="<?php echo (int) $staging->id; ?>">
				<?php if ( $replace && ! $blocked ) : ?>
					<p><label><input type="checkbox" name="confirm_replace" value="1" required> <?php echo esc_html( sprintf( /* translators: %s: number removed */ _n( 'I understand %s existing contact will be removed from the feed.', 'I understand %s existing contacts will be removed from the feed.', $summary['removed'], 'site-phonebooks' ), number_format_i18n( $summary['removed'] ) ) ); ?></label></p>
				<?php endif; ?>
				<p class="spb-actions">
					<button type="submit" class="button button-primary" <?php disabled( $blocked ); ?>><?php echo $replace ? esc_html__( 'Apply import and replace contacts', 'site-phonebooks' ) : esc_html__( 'Apply import', 'site-phonebooks' ); ?></button>
					<a class="button" href="<?php echo esc_url( Admin::phonebook_url( $phonebook->id, 'import', array( 'import' => $staging->id, 'step' => 'map' ) ) ); ?>"><?php esc_html_e( 'Back to options', 'site-phonebooks' ); ?></a>
					<?php self::cancel_button( $staging->id ); ?>
				</p>
			</form>
			<?php self::cancel_form( $phonebook, $staging->id ); ?>
		</div>

		<div class="spb-card">
			<h2><?php esc_html_e( 'Rows', 'site-phonebooks' ); ?></h2>
			<?php if ( count( $rows ) > self::PREVIEW_ROWS ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: 1: shown, 2: total */ __( 'Showing the first %1$s of %2$s rows (invalid rows first). The counts above cover every row.', 'site-phonebooks' ), number_format_i18n( self::PREVIEW_ROWS ), number_format_i18n( count( $rows ) ) ) ); ?></p>
			<?php endif; ?>
			<div class="spb-table-scroll">
			<table class="widefat striped spb-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Row', 'site-phonebooks' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'site-phonebooks' ); ?></th><th scope="col"><?php esc_html_e( 'Name', 'site-phonebooks' ); ?></th><th scope="col"><?php esc_html_e( 'Telephone', 'site-phonebooks' ); ?></th><th scope="col"><?php esc_html_e( 'Messages', 'site-phonebooks' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $rows, 0, self::PREVIEW_ROWS ) as $row ) : ?>
					<tr class="spb-row-<?php echo esc_attr( $row['status'] ); ?>">
						<td><?php echo (int) $row['row']; ?></td>
						<td><?php echo esc_html( $labels[ $row['status'] ] ); ?></td>
						<td><?php echo esc_html( $row['name'] ); ?></td>
						<td><code><?php echo esc_html( $row['telephone'] ); ?></code></td>
						<td><?php echo esc_html( implode( ' ', array_merge( $row['errors'], $row['warnings'] ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
		<?php
	}
}
