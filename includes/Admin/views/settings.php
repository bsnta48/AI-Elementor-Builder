<?php
/**
 * Settings admin page.
 *
 * @package AI_Elementor_Builder
 *
 * @var string $title Page title.
 */

use AI_Elementor_Builder\Settings\Settings;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php echo esc_html( $title ); ?></h1>

	<?php settings_errors( Settings::OPTION_KEY ); ?>

	<form action="options.php" method="post">
		<?php
		// settings_fields() emits the nonce, action, and _wp_http_referer.
		settings_fields( Settings::GROUP );
		do_settings_sections( Settings::PAGE );
		submit_button();
		?>
	</form>
</div>
