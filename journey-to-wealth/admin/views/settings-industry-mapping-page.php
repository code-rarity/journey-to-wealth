<?php
/**
 * Provides the view for the Industry Mapping page with an interactive two-column UI.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      3.2.0
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/admin/views
 */

// Prevent direct access to this file.
if ( ! defined( 'WPINC' ) ) {
    die;
}

global $wpdb;

// Get Damodaran industries for the toggle buttons
$beta_table_name = $wpdb->prefix . 'jtw_industry_betas';
$damodaran_industries = $wpdb->get_col( "SELECT industry_name FROM $beta_table_name ORDER BY industry_name ASC" );

// Define the logical groups
$industry_groups = [
    'Financials' => ['Bank', 'Brokerage', 'Insurance', 'Financial Svcs', 'Investments'],
    'Technology' => ['Software', 'Computer', 'Semiconductor', 'Telecom', 'Internet', 'Electronics'],
    'Healthcare' => ['Healthcare', 'Hospitals', 'Drug', 'Biotechnology', 'Medical'],
    'Consumer Cyclical' => ['Retail', 'Apparel', 'Auto', 'Hotel', 'Gaming', 'Recreation', 'Restaurant', 'Furnishings'],
    'Consumer Defensive' => ['Food', 'Beverage', 'Household Products', 'Tobacco'],
    'Industrials' => ['Aerospace', 'Defense', 'Building Materials', 'Machinery', 'Engineering', 'Construction', 'Air Transport', 'Transportation', 'Shipbuilding'],
    'Energy' => ['Oil', 'Gas', 'Energy', 'Coal', 'Pipeline'],
    'Materials' => ['Chemical', 'Metals', 'Mining', 'Paper', 'Forest Products', 'Rubber', 'Steel'],
    'Real Estate' => ['Real Estate', 'R.E.I.T.'],
    'Utilities' => ['Utility', 'Power'],
    'Services' => ['Advertising', 'Business & Consumer Svcs', 'Publishing', 'Education', 'Entertainment', 'Information Services', 'Office Equipment'],
];

// Group the industries
$grouped_industries = [];
foreach (array_keys($industry_groups) as $group_name) {
    $grouped_industries[$group_name] = [];
}
$grouped_industries['Other'] = [];

foreach ($damodaran_industries as $industry) {
    $assigned = false;
    foreach ($industry_groups as $group_name => $keywords) {
        foreach ($keywords as $keyword) {
            if (stripos($industry, $keyword) !== false) {
                $grouped_industries[$group_name][] = $industry;
                $assigned = true;
                break 2;
            }
        }
    }
    if (!$assigned) {
        $grouped_industries['Other'][] = $industry;
    }
}


// Get unique Alpha Vantage industries
$discovered_av_industries = get_option('jtw_discovered_av_industries', []);
$mapping_table_name = $wpdb->prefix . 'jtw_industry_mappings';
$all_mappings = $wpdb->get_results( "SELECT av_industry, damodaran_industry FROM $mapping_table_name" );
$mappings_by_av = [];
$mapped_av_industries = [];
foreach ($all_mappings as $mapping) {
    $mapped_av_industries[] = $mapping->av_industry;
    if (!isset($mappings_by_av[$mapping->av_industry])) {
        $mappings_by_av[$mapping->av_industry] = [];
    }
    $mappings_by_av[$mapping->av_industry][] = $mapping->damodaran_industry;
}
$all_av_industries = array_unique(array_merge($discovered_av_industries, $mapped_av_industries));
sort($all_av_industries);

?>

<div class="wrap jtw-mapping-wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <p><?php esc_html_e( 'To map industries, first click on an Alpha Vantage industry from the list on the left to select it. Then, click on one or more Damodaran industries from the categories on the right to assign them. Mappings are saved automatically.', 'journey-to-wealth' ); ?></p>

    <div id="jtw-ajax-save-status" style="display:none;" class="notice notice-success is-dismissible"><p></p></div>

    <div id="jtw-mapping-ui-container">
        <!-- Left Column: Alpha Vantage Industries -->
        <div class="jtw-mapping-column" id="jtw-av-column">
            <h3><?php esc_html_e( 'Alpha Vantage Industries', 'journey-to-wealth' ); ?></h3>
            <div class="jtw-list-container">
                <ul id="jtw-av-industry-list">
                    <?php if ( ! empty( $all_av_industries ) ) : ?>
                        <?php foreach ( $all_av_industries as $av_industry ) : 
                            $assigned_damodarans = $mappings_by_av[ $av_industry ] ?? [];
                        ?>
                            <li class="jtw-av-item" data-av-industry="<?php echo esc_attr($av_industry); ?>">
                                <strong class="jtw-av-industry-name"><?php echo esc_html( $av_industry ); ?></strong>
                                <div class="jtw-assigned-tags">
                                    <?php foreach ($assigned_damodarans as $dam_industry) : ?>
                                        <span class="jtw-assigned-tag" data-damodaran-industry="<?php echo esc_attr($dam_industry); ?>"><?php echo esc_html($dam_industry); ?><button type="button" class="jtw-remove-tag">&times;</button></span>
                                    <?php endforeach; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <li><?php esc_html_e( 'No Alpha Vantage industries discovered yet. Analyze a stock to begin.', 'journey-to-wealth' ); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Right Column: Damodaran Industries -->
        <div class="jtw-mapping-column" id="jtw-damodaran-column">
            <h3><?php esc_html_e( 'Damodaran Industries', 'journey-to-wealth' ); ?></h3>
            <div class="jtw-list-container">
                <div class="jtw-industry-groups">
                    <?php foreach ($grouped_industries as $group_name => $industries) : ?>
                        <?php if (!empty($industries)) : ?>
                            <div class="jtw-industry-group">
                                <h4><?php echo esc_html($group_name); ?></h4>
                                <div class="jtw-damodaran-toggles">
                                    <?php foreach ($industries as $industry) : ?>
                                        <button type="button" class="jtw-damodaran-toggle button" data-industry="<?php echo esc_attr($industry); ?>"><?php echo esc_html($industry); ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
