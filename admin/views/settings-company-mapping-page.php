<?php
/**
 * Provides the view for the Company to Industry Mapping page.
 */

// Prevent direct access to this file.
if ( ! defined( 'WPINC' ) ) {
    die;
}

global $wpdb;

// Get Damodaran industries with their IDs
$beta_table_name = $wpdb->prefix . 'jtw_industry_betas';
$damodaran_industries = $wpdb->get_results( "SELECT id, industry_name FROM $beta_table_name ORDER BY industry_name ASC" );

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
            if (stripos($industry->industry_name, $keyword) !== false) {
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

// Get discovered companies and their mappings
$discovered_companies = get_option('jtw_discovered_companies', []);
$mapping_table_name = $wpdb->prefix . 'jtw_company_mappings';

$all_mappings = $wpdb->get_results( "
    SELECT m.ticker, b.id as damodaran_id, b.industry_name as damodaran_name 
    FROM $mapping_table_name as m
    JOIN $beta_table_name as b ON m.damodaran_industry_id = b.id
" );

$mappings_by_ticker = [];
foreach ($all_mappings as $mapping) {
    if (!isset($mappings_by_ticker[$mapping->ticker])) {
        $mappings_by_ticker[$mapping->ticker] = [];
    }
    $mappings_by_ticker[$mapping->ticker][] = [
        'id' => $mapping->damodaran_id,
        'name' => $mapping->damodaran_name
    ];
}

// **FIX**: Reverse the array to show the most recently added companies first.
if (is_array($discovered_companies)) {
    $discovered_companies = array_reverse($discovered_companies, true);
}

?>

<div class="wrap jtw-mapping-wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <p><?php esc_html_e( 'To fine-tune beta calculations, you can map individual companies to specific Damodaran industries. Select a company on the left, then assign one or more industries from the right. Mappings are saved automatically.', 'journey-to-wealth' ); ?></p>

    <div id="jtw-ajax-save-status" style="display:none;" class="notice notice-success is-dismissible"><p></p></div>

    <div id="jtw-mapping-ui-container">
        <!-- Left Column: Discovered Companies -->
        <div class="jtw-mapping-column" id="jtw-company-column">
            <h3><?php esc_html_e( 'Discovered Companies', 'journey-to-wealth' ); ?></h3>
            
            <div class="jtw-list-controls">
                <input type="text" id="jtw-company-search" placeholder="Search companies...">
            </div>

            <div class="jtw-list-container">
                <ul id="jtw-company-list">
                    <?php if ( ! empty( $discovered_companies ) ) : ?>
                        <?php foreach ( $discovered_companies as $ticker => $industry_name ) : 
                            $assigned_damodarans = $mappings_by_ticker[ $ticker ] ?? [];
                        ?>
                            <li class="jtw-company-item" data-ticker="<?php echo esc_attr($ticker); ?>">
                                <strong class="jtw-company-name"><?php echo esc_html( $ticker ); ?></strong>
                                <small class="jtw-company-industry"><?php echo esc_html( $industry_name ); ?></small>
                                <div class="jtw-assigned-tags">
                                    <?php foreach ($assigned_damodarans as $dam_mapping) : ?>
                                        <span class="jtw-assigned-tag" data-damodaran-id="<?php echo esc_attr($dam_mapping['id']); ?>"><?php echo esc_html($dam_mapping['name']); ?><button type="button" class="jtw-remove-tag">&times;</button></span>
                                    <?php endforeach; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <li><?php esc_html_e( 'No companies analyzed yet. Analyze a stock on the front-end to begin mapping.', 'journey-to-wealth' ); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <div class="jtw-pagination-controls">
                <button id="jtw-prev-page" class="button" disabled>&laquo; Previous</button>
                <span id="jtw-page-info">Page 1 of 1</span>
                <button id="jtw-next-page" class="button" disabled>Next &raquo;</button>
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
                                        <button type="button" class="jtw-damodaran-toggle button" data-industry-id="<?php echo esc_attr($industry->id); ?>"><?php echo esc_html($industry->industry_name); ?></button>
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
