<div class="wrap">
    <h1>API Settings</h1>
    <form action="options.php" method="post">
        <?php
        // **REFACTORED** Ensure the correct settings group and page slug are used.
        settings_fields( 'jtw_api_settings_group' );
        do_settings_sections( 'jtw-settings' ); // Corresponds to the page slug in the admin class
        submit_button( 'Save Settings' );
        ?>
    </form>
</div>
