<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <form action="options.php" method="post">
        <?php
        settings_fields( 'jtw_options_group' );
        do_settings_sections( 'jtw_settings_page' );
        submit_button( 'Save Settings' );
        ?>
    </form>
</div>
