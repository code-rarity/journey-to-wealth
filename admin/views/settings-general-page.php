<?php
/**
 * Provides the view for the General Settings page.
 *
 * This file is included by the Journey_To_Wealth_Admin class.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      1.0.0
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/admin/views
 */

// Prevent direct access to this file.
if ( ! defined( 'WPINC' ) ) {
    die;
}

?>

<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <form method="post" action="options.php">
        <?php
        /**
         * WordPress function that outputs nonce, action, and option_page fields for a settings page.
         *
         * The parameter 'journey-to-wealth_general_settings_group' should match
         * the first argument in the register_setting() function call in Journey_To_Wealth_Admin::register_settings().
         */
        settings_fields( 'journey-to-wealth_general_settings_group' );

        /**
         * WordPress function that prints out all settings sections added to a particular settings page.
         *
         * The parameter 'journey-to-wealth_general_settings_page_identifier' should match
         * the page slug passed to add_settings_section() and add_settings_field()
         * in Journey_To_Wealth_Admin::register_settings() for the general settings.
         */
        do_settings_sections( 'journey-to-wealth_general_settings_page_identifier' );

        /**
         * WordPress function that prints the submit button.
         */
        submit_button( __( 'Save General Settings', 'journey-to-wealth' ) );
        ?>
    </form>
</div>
