<?php die('inert lw-scan fixture'); ?>
<?php
/**
 * Plugin Name: Example Settings
 * Description: An ordinary plugin file — sanitizes input into options.
 *
 * @package Example_Settings
 */

add_action( 'admin_post_example_save', 'example_save' );

function example_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'example' ) );
	}

	check_admin_referer( 'example_save' );

	$title = sanitize_text_field( wp_unslash( $_POST['example_title'] ) );
	$body  = wp_kses_post( wp_unslash( $_POST['example_body'] ) );

	update_option( 'example_title', $title );
	update_option( 'example_body', $body );

	wp_mail( get_option( 'admin_email' ), esc_html( $title ), $body );

	include __DIR__ . '/views/notice.php';

	wp_safe_redirect( admin_url( 'options-general.php' ) );
	exit;
}
