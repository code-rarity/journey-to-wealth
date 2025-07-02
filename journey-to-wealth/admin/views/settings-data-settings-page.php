<?php
/**
 * Provides the view for the Data Settings page.
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

global $wpdb;
$table_name = $wpdb->prefix . 'jtw_industry_betas';
$beta_data = $wpdb->get_results( "SELECT industry_name, unlevered_beta FROM $table_name ORDER BY industry_name ASC" );

?>

<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <p><?php esc_html_e( 'Configure the core assumptions for the DCF valuation model and manage the industry beta data.', 'journey-to-wealth' ); ?></p>

    <?php if ( isset( $_GET['message'] ) ) : ?>
        <div id="message" class="<?php echo $_GET['message'] === 'success' ? 'updated' : 'error'; ?> notice is-dismissible">
            <p>
                <?php 
                if ($_GET['message'] === 'success') {
                    esc_html_e( 'Beta data has been successfully imported.', 'journey-to-wealth' );
                } elseif ($_GET['message'] === 'error') {
                    esc_html_e( 'There was an error importing the beta data. Please check the URL and the page structure.', 'journey-to-wealth' );
                }
                ?>
            </p>
        </div>
    <?php endif; ?>
    <?php settings_errors(); ?>

    <div id="poststuff">
        <div id="post-body" class="metabox-holder columns-2">
            <div id="post-body-content">
                <div class="meta-box-sortables ui-sortable">
                    <div class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e( 'Valuation & Beta Settings', 'journey-to-wealth' ); ?></span></h2>
                        <div class="inside">
                            <form method="post" action="options.php">
                                <?php
                                settings_fields( 'jtw_data_settings_group' );
                                do_settings_sections( 'jtw-data-settings' );
                                submit_button( __( 'Save All Assumptions', 'journey-to-wealth' ) );
                                ?>
                            </form>
                            <hr>
                             <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <p><?php esc_html_e( 'Once the URL is saved, click the button below to fetch and process the beta data.', 'journey-to-wealth' ); ?></p>
                                <input type="hidden" name="action" value="jtw_fetch_beta_data">
                                <?php wp_nonce_field( 'jtw_fetch_beta_data_nonce', 'jtw_beta_nonce' ); ?>
                                <?php submit_button( __( 'Fetch and Process Beta Data', 'journey-to-wealth' ), 'secondary', 'jtw_fetch_beta_submit' ); ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div id="postbox-container-1" class="postbox-container">
                <div class="meta-box-sortables">
                    <div class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e( 'Current Beta Data', 'journey-to-wealth' ); ?></span></h2>
                        <div class="inside">
                            <div id="jtw-beta-data-table-wrapper" style="max-height: 400px; overflow-y: auto;">
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th scope="col"><?php esc_html_e( 'Industry', 'journey-to-wealth' ); ?></th>
                                            <th scope="col"><?php esc_html_e( 'Unlevered Beta', 'journey-to-wealth' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ( ! empty( $beta_data ) ) : ?>
                                            <?php foreach ( $beta_data as $row ) : ?>
                                                <tr>
                                                    <td><?php echo esc_html( $row->industry_name ); ?></td>
                                                    <td><?php echo esc_html( $row->unlevered_beta ); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <tr>
                                                <td colspan="2"><?php esc_html_e( 'No beta data found. Please fetch the data.', 'journey-to-wealth' ); ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <br class="clear">
    </div>
</div>
