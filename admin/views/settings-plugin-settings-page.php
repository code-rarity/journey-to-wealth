<?php
/**
 * Provides the view for the main Plugin Settings page (API and Tools).
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      2.9.0
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
    
    <?php if ( isset( $_GET['message'] ) ) : ?>
        <div id="message" class="<?php echo $_GET['message'] === 'reset_success' ? 'updated' : 'error'; ?> notice is-dismissible">
            <p>
                <?php 
                if ($_GET['message'] === 'reset_success') {
                    esc_html_e( 'The plugin\'s database tables have been successfully reset.', 'journey-to-wealth' );
                } elseif ($_GET['message'] === 'no_confirmation') {
                    esc_html_e( 'Please check the confirmation box before resetting the database.', 'journey-to-wealth' );
                }
                ?>
            </p>
        </div>
    <?php endif; ?>
    <?php settings_errors(); ?>

    <div class="postbox">
        <h2 class="hndle"><span><?php esc_html_e( 'Main Settings', 'journey-to-wealth' ); ?></span></h2>
        <div class="inside">
            <form method="post" action="options.php">
                <?php
                settings_fields( 'jtw_plugin_settings_group' );
                do_settings_sections( 'jtw-plugin-settings' );
                submit_button( __( 'Save Settings', 'journey-to-wealth' ) );
                ?>
            </form>
        </div>
    </div>
    
    <div class="postbox">
        <h2 class="hndle"><span><?php esc_html_e( 'Tools', 'journey-to-wealth' ); ?></span></h2>
        <div class="inside">
            <div class="notice notice-warning inline">
                <p><strong><?php esc_html_e( 'Important:', 'journey-to-wealth' ); ?></strong> <?php esc_html_e( 'If you are having trouble saving industry mappings (e.g., a selection does not save), you may need to run this reset tool once to update the database structure to the latest version.', 'journey-to-wealth' ); ?></p>
            </div>
            <p style="color: #d63638; font-weight: bold;"><?php esc_html_e( 'Warning: This will permanently delete all saved beta data and industry mappings. This action cannot be undone.', 'journey-to-wealth' ); ?></p>
            <form id="jtw-reset-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="jtw_reset_database">
                <?php wp_nonce_field( 'jtw_reset_database_nonce', 'jtw_reset_nonce' ); ?>
                
                <p>
                    <label>
                        <input type="checkbox" id="jtw-confirm-reset-checkbox" name="jtw_confirm_reset" value="yes">
                        <?php esc_html_e( 'I understand that this will delete all data from the plugin\'s custom tables.', 'journey-to-wealth' ); ?>
                    </label>
                </p>

                <?php submit_button( __( 'Drop and Recreate Tables', 'journey-to-wealth' ), 'delete', 'jtw_reset_submit', true, ['disabled' => true] ); ?>
            </form>
        </div>
    </div>
</div>
<script type="text/javascript">
    jQuery(document).ready(function($) {
        const $checkbox = $('#jtw-confirm-reset-checkbox');
        const $submitButton = $('#jtw_reset_submit');

        $checkbox.on('change', function() {
            $submitButton.prop('disabled', !this.checked);
        });
    });
</script>
