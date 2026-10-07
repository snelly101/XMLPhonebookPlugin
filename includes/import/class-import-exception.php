<?php
/**
 * Import exception carrying a user-safe message.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown inside the commit transaction for expected, user-facing failures.
 */
final class Import_Exception extends \RuntimeException {}
