/**
 * Public-facing JavaScript for Journey to Wealth plugin.
 *
 * This script powers:
 * 1. The header lookup form, which handles live search and redirects to the analysis page.
 * 2. The main analyzer page, which detects the URL parameter, auto-fetches data, and renders charts.
 *
 * @link       https://example.com/journey-to-wealth/
 * @since      1.0.0
 *
 * @package    Journey_To_Wealth
 * @subpackage Journey_To_Wealth/public/assets/js
 */

(function ($) {
    'use strict';

    // Helper to safely get localized text from wp_localize_script
    function getLocalizedText(key, fallbackText) {
        if (typeof jtw_public_params !== 'undefined' && jtw_public_params[key]) {
            return jtw_public_params[key];
        }
        return fallbackText;
    }
    
    // Debounce function to limit how often AJAX calls are made during typing
    function debounce(func, delay) {
        let timeout;
        return function() {
            const context = this;
            const args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), delay);
        };
    }

    /**
     * Formats large numbers into a compact representation (K, M, B, T).
     * @param {number} num The number to format.
     * @returns {string} The formatted number as a string.
     */
    function formatLargeNumber(num) {
        if (typeof num !== 'number' || num === 0) return '0';
        const absNum = Math.abs(num);
        const sign = num < 0 ? "-" : "";

        if (absNum >= 1.0e+12) return sign + (absNum / 1.0e+12).toFixed(2) + 'T';
        if (absNum >= 1.0e+9) return sign + (absNum / 1.0e+9).toFixed(2) + 'B';
        if (absNum >= 1.0e+6) return sign + (absNum / 1.0e+6).toFixed(2) + 'M';
        if (absNum >= 1.0e+3) return sign + (absNum / 1.0e+3).toFixed(1) + 'K';
        return sign + num.toFixed(2);
    }

    /**
     * Initializes the interactive PEG/PEGY calculator on the analysis page.
     */
    function initializePegPegySimulator($container) {
        const $card = $container.find('.jtw-interactive-card');
        if (!$card.length) return;
    
        const $stockPriceInput = $('#jtw-sim-stock-price');
        const $epsInput = $('#jtw-sim-eps');
        const $growthInput = $('#jtw-sim-growth-rate');
        const $dividendInput = $('#jtw-sim-dividend-yield');
    
        const $pegValueEl = $('#jtw-peg-value');
        const $pegyValueEl = $('#jtw-pegy-value');
    
        function updateRatios() {
            const stockPrice = parseFloat($stockPriceInput.val());
            const eps = parseFloat($epsInput.val());
            const growthRate = parseFloat($growthInput.val());
            const dividendYield = parseFloat($dividendInput.val());
    
            let pe = NaN;
            if (stockPrice > 0 && eps > 0) {
                pe = stockPrice / eps;
            }
    
            if (!isNaN(pe) && growthRate > 0) {
                const peg = pe / growthRate;
                $pegValueEl.text(peg.toFixed(2));
            } else {
                $pegValueEl.text('-');
            }
    
            if (!isNaN(pe) && (growthRate + dividendYield) > 0) {
                const pegy = pe / (growthRate + dividendYield);
                $pegyValueEl.text(pegy.toFixed(2));
            } else {
                $pegyValueEl.text('-');
            }
        }
    
        $container.on('input', '.jtw-sim-input', updateRatios);
        updateRatios(); // Initial calculation
    }

    /**
     * Initializes the Intrinsic Valuation histogram-style chart.
     */
    function initializeValuationChart($container) {
        const $chartContainer = $container.find('#jtw-valuation-chart-container');
        if (!$chartContainer.length) return;
        
        const currentPrice = parseFloat($chartContainer.data('current-price'));
        const fairValue = parseFloat($chartContainer.data('fair-value'));
        const percentageDiff = parseFloat($chartContainer.data('percentage-diff'));

        const undervaluedLimit = fairValue * 0.8;
        const overvaluedLimit = fairValue * 1.2;
        const maxValue = Math.max(currentPrice, overvaluedLimit) * 1.1; 

        const undervaluedPercent = (undervaluedLimit / maxValue) * 100;
        const fairValuePercent = ((overvaluedLimit - undervaluedLimit) / maxValue) * 100;
        
        $container.find('.jtw-range-undervalued').css('width', undervaluedPercent + '%');
        $container.find('.jtw-range-fair').css('width', fairValuePercent + '%');
        $container.find('.jtw-range-overvalued').css('width', (100 - undervaluedPercent - fairValuePercent) + '%');

        const ctx = document.getElementById('jtw-valuation-chart');
        if (!ctx) return;
        
        const valuationChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Fair Value', 'Current Price'],
                datasets: [{
                    data: [fairValue, currentPrice],
                    backgroundColor: [
                        function(context) {
                            const chart = context.chart;
                            const {ctx, chartArea} = chart;
                            if (!chartArea) {
                                return null;
                            }
                            const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
                            gradient.addColorStop(0, 'rgba(0, 0, 0, 0.6)');
                            gradient.addColorStop(1, 'rgba(0, 0, 0, 0)');
                            return gradient;
                        },
                        'rgba(0, 0, 0, 0.8)'
                    ],
                    borderColor: 'transparent',
                    borderWidth: 0,
                    barThickness: 80, 
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        max: maxValue,
                        ticks: { display: false },
                        grid: { display: false, drawBorder: false }
                    },
                    y: {
                        grid: { display: false, drawBorder: false },
                        ticks: { display: false }
                    }
                }
            },
            plugins: [{
                id: 'customLabelsAndLines',
                afterDraw: (chart) => {
                    const ctx = chart.ctx;
                    chart.data.datasets.forEach((dataset, i) => {
                        const meta = chart.getDatasetMeta(i);
                        if (!meta.hidden) {
                            meta.data.forEach((element, index) => {
                                const label = chart.data.labels[index];
                                const value = dataset.data[index];
                                const {x, y, base, width, height} = element;
                                
                                const xPos = base + 15;
                                const yPos = y - (height / 2) + 15;

                                ctx.save();
                                ctx.fillStyle = 'white';
                                ctx.font = 'bold 1.2em Arial';
                                ctx.textBaseline = 'top';
                                ctx.fillText(label, xPos, yPos);
                                ctx.font = 'bold 1.5em Arial';
                                ctx.fillText('$' + value.toFixed(2), xPos, yPos + 25);
                                ctx.restore();

                                // Draw the end line
                                ctx.save();
                                ctx.strokeStyle = '#00BFFF';
                                ctx.lineWidth = 4;
                                ctx.beginPath();
                                ctx.moveTo(x, y - height / 2);
                                ctx.lineTo(x, y + height / 2);
                                ctx.stroke();
                                ctx.restore();
                            });
                        }
                    });
                }
            }]
        });
        
        let annotationText = '';
        if (percentageDiff > 0.1) {
            annotationText = percentageDiff.toFixed(1) + '% Overvalued';
        } else if (percentageDiff < -0.1) {
            annotationText = Math.abs(percentageDiff).toFixed(1) + '% Undervalued';
        } else {
            annotationText = 'About Right';
        }
        const $annotation = $('<div class="jtw-valuation-annotation"></div>').text(annotationText);
        $chartContainer.append($annotation);
    }

    /**
     * Initializes the Historical Trends charts and the period toggle.
     */
    function initializeHistoricalCharts($container) {
        const $chartDataScripts = $container.find('.jtw-chart-data');
        if (!$chartDataScripts.length) return;

        let charts = {}; // To hold chart instances for updating

        // Helper function to check for data
        const hasData = (data) => {
            if (!data || !data.labels || data.labels.length === 0) return false;
            if (data.datasets && data.datasets.length > 0) {
                return data.datasets.some(ds => ds.data && ds.data.length > 0);
            }
            return data.data && data.data.length > 0;
        };

        $chartDataScripts.each(function() {
            const $script = $(this);
            const $chartItem = $script.closest('.jtw-chart-item');
            const chartId = $script.data('chart-id');
            const chartType = $script.data('chart-type');
            const prefix = $script.data('prefix');
            const annualData = JSON.parse($script.attr('data-annual'));
            
            // Initial visibility check (default to annual)
            if (!hasData(annualData)) {
                $chartItem.hide();
            }

            const ctx = document.getElementById(chartId);
            if (!ctx) return;

            let datasets;
            // Check if data is multi-dataset (like for grouped/stacked bars)
            if (annualData.datasets) {
                 datasets = annualData.datasets.map((dataset, index) => ({
                    label: dataset.label,
                    data: dataset.data,
                    backgroundColor: index === 0 ? 'rgba(54, 162, 235, 0.6)' : 'rgba(255, 99, 132, 0.6)',
                }));
            } else { // Single dataset (line or simple bar)
                datasets = [{
                    label: 'Value',
                    data: annualData.data,
                    borderColor: 'rgba(0, 122, 255, 1)',
                    backgroundColor: 'rgba(0, 122, 255, 0.6)',
                    fill: chartType === 'line',
                    tension: 0.1
                }];
            }

            const config = {
                type: chartType,
                data: {
                    labels: annualData.labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: !!annualData.datasets }, // Show legend only for multi-dataset charts
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) { label += ': '; }
                                    if (context.parsed.y !== null) {
                                        label += prefix + context.parsed.y.toLocaleString('en-US', {maximumFractionDigits: 2});
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            stacked: chartType === 'bar_stacked', // Only stack if explicitly told to
                        },
                        y: {
                            stacked: chartType === 'bar_stacked',
                            ticks: {
                                callback: function(value, index, values) {
                                    return prefix + formatLargeNumber(value);
                                }
                            }
                        }
                    }
                }
            };
            
            // Adjust type for what was formerly 'bar_stacked' to be just 'bar'
            if (chartType === 'bar_stacked') {
                config.type = 'bar';
            }


            charts[chartId] = new Chart(ctx, config);
        });

        // Event listener for the toggle buttons
        $container.find('.jtw-period-button').on('click', function() {
            const $button = $(this);
            if ($button.hasClass('active')) return;

            const period = $button.data('period');

            // Update button styles
            $container.find('.jtw-period-button').removeClass('active');
            $button.addClass('active');

            // Update all charts
            $chartDataScripts.each(function() {
                const $script = $(this);
                const $chartItem = $script.closest('.jtw-chart-item');
                const chartId = $script.data('chart-id');
                const chart = charts[chartId];
                if (!chart) return;
                
                const dataToUse = period === 'annual' 
                    ? JSON.parse($script.attr('data-annual')) 
                    : JSON.parse($script.attr('data-quarterly'));
                
                if (hasData(dataToUse)) {
                    $chartItem.show();
                    chart.data.labels = dataToUse.labels;
                    if (dataToUse.datasets) { // Multi-dataset chart
                        chart.data.datasets.forEach((dataset, index) => {
                            // Ensure the dataset exists in the new data before trying to access it
                            if(dataToUse.datasets[index]) {
                                dataset.data = dataToUse.datasets[index].data;
                            }
                        });
                    } else { // Single-dataset chart
                        chart.data.datasets[0].data = dataToUse.data;
                    }
                    chart.update();
                } else {
                    $chartItem.hide();
                }
            });
        });
    }

    /**
     * Sets up interactivity for the loaded analyzer content (anchor links, scroll spying).
     */
    function setupSWSLayoutInteractivity($contentArea) {
        const $anchorNav = $contentArea.find('.jtw-anchor-nav');
        if (!$anchorNav.length) return;

        const $navLinks = $anchorNav.find('a.jtw-anchor-link');
        const $contentMain = $contentArea.find('.jtw-content-main');
        const $sections = $contentMain.find('.jtw-content-section');
        const offsetTop = 150; 

        $navLinks.off('click').on('click', function(e) {
            e.preventDefault();
            const targetId = $(this).attr('href');
            const $targetSection = $(targetId);
            if ($targetSection.length) {
                $('html, body').animate({
                    scrollTop: $targetSection.offset().top - offsetTop
                }, 500);
            }
        });

        function onScroll() {
            const scrollPos = $(document).scrollTop() + offsetTop + 1;
            let activeLink = null;
            $sections.each(function() {
                const top = $(this).offset().top;
                const height = $(this).height();
                if (top <= scrollPos && (top + height) > scrollPos) {
                    activeLink = $navLinks.filter('[href="#' + $(this).attr('id') + '"]');
                }
            });
            $navLinks.removeClass('active');
            if (activeLink && activeLink.length > 0) {
                activeLink.addClass('active');
            } else if ($(window).scrollTop() + $(window).height() > $(document).height() - 100) {
                 $navLinks.last().addClass('active');
            }
        }
        
        $(document).off('scroll.jtw').on('scroll.jtw', onScroll);
        onScroll();

        initializePegPegySimulator($contentArea);
    }

    /**
     * Initializes the header search form functionality.
     */
    function initializeHeaderSearch() {
        const $headerForm = $('.jtw-header-lookup-form');
        if (!$headerForm.length) return;

        const $input = $headerForm.find('.jtw-header-ticker-input');
        const $button = $headerForm.find('.jtw-header-fetch-button');
        const $resultsContainer = $headerForm.find('.jtw-header-search-results');
        let searchRequest;

        function redirectToAnalysisPage(ticker) {
            const analysisPageUrl = jtw_public_params.analysis_page_url || '/';
            window.location.href = analysisPageUrl + '?jtw_selected_symbol=' + ticker;
        }
        
        $button.on('click', function() {
            const ticker = $input.val().toUpperCase().trim();
            if (ticker) {
                redirectToAnalysisPage(ticker);
            }
        });

        $input.on('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $button.trigger('click');
            }
        });

        $input.on('keyup', debounce(function(event) {
            if (event.key === "Enter") return;

            const keywords = $input.val().trim();
            if (keywords.length < 2) {
                $resultsContainer.empty().hide();
                return;
            }

            $resultsContainer.html('<div class="jtw-search-loading">' + getLocalizedText('text_searching', 'Searching...') + '</div>').show();

            if (searchRequest) {
                searchRequest.abort();
            }

            searchRequest = $.ajax({
                url: jtw_public_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'jtw_symbol_search',
                    jtw_symbol_search_nonce: jtw_public_params.symbol_search_nonce,
                    keywords: keywords
                },
                dataType: 'json',
                success: function(response) {
                    $resultsContainer.empty(); 
                    if (response.success && response.data.matches && response.data.matches.length > 0) {
                        const $ul = $('<ul>').addClass('jtw-symbol-results-list');
                        response.data.matches.forEach(function(match) {
                            const $li = $('<li>').addClass('jtw-header-result-item').attr('data-symbol', match.symbol).html('<strong>' + match.symbol + '</strong> - ' + match.name);
                            $ul.append($li);
                        });
                        $resultsContainer.append($ul).show();
                    } else {
                        $resultsContainer.html('<div class="jtw-no-results">' + getLocalizedText('text_no_results', 'No symbols found.') + '</div>').show();
                    }
                },
                error: function(jqXHR, textStatus) {
                    if (textStatus !== 'abort') { 
                        $resultsContainer.html('<div class="jtw-error notice notice-error inline"><p>' + getLocalizedText('text_error', 'Search request failed.') + '</p></div>').show();
                    }
                }
            });
        }, 500));

        // Use event delegation for dynamically added result items
        $headerForm.on('click', '.jtw-header-result-item', function() {
            redirectToAnalysisPage($(this).data('symbol'));
        });
        
        $(document).on('click', function(event) {
            if (!$(event.target).closest('.jtw-header-lookup-form').length) {
                $('.jtw-header-search-results').empty().hide();
            }
        });
    }

    /**
     * Main function to initialize the analyzer page.
     */
    function initializeAnalyzerPage() {
        const $container = $('.jtw-analyzer-wrapper').first();
        if (!$container.length) return;

        const $mainContentArea = $container.find('#jtw-main-content-area');
        
        function fetchData(ticker) {
            if (!ticker) return;

            $mainContentArea.html('<p class="jtw-loading-message">' + getLocalizedText('text_loading', 'Fetching...') + '</p>');

            $.ajax({
                url: jtw_public_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'jtw_fetch_analyzer_data',
                    analyzer_nonce: jtw_public_params.analyzer_nonce, 
                    ticker: ticker
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data && response.data.html) {
                        $mainContentArea.html(response.data.html);
                        setupSWSLayoutInteractivity($mainContentArea);
                        initializeHistoricalCharts($mainContentArea);
                        initializeValuationChart($mainContentArea);
                    } else {
                        const errorMessage = response.data.message || getLocalizedText('text_error');
                        $mainContentArea.html('<div class="jtw-error notice notice-error inline"><p>' + errorMessage + '</p></div>');
                    }
                },
                error: function(jqXHR) {
                    let serverError = jqXHR.responseText || getLocalizedText('text_error');
                    $mainContentArea.html('<div class="jtw-error notice notice-error inline"><p>AJAX request failed. Server responded: <br><small><code>' + serverError + '</code></small></p></div>');
                }
            });
        }
        
        const urlParams = new URLSearchParams(window.location.search);
        const symbolFromUrl = urlParams.get('jtw_selected_symbol');

        if (symbolFromUrl) {
            fetchData(symbolFromUrl.toUpperCase());
        }
    }

    $(document).ready(function() {
        initializeHeaderSearch();
        initializeAnalyzerPage();

        // Modal open/close logic
        $('body').on('click', '.jtw-modal-trigger', function(e) {
            e.preventDefault();
            const targetModal = $(this).data('modal-target');
            $('.jtw-modal-overlay').fadeIn(200);
            $(targetModal).fadeIn(200);
        });

        const closeModal = () => {
            $('.jtw-modal').fadeOut(200);
            $('.jtw-modal-overlay').fadeOut(200);
        };

        $('body').on('click', '.jtw-modal-close, .jtw-modal-overlay', closeModal);
    });

})( jQuery );
