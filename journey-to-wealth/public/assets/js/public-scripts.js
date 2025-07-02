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
        const $calculator = $container.find('.jtw-peg-pegy-calculator');
        if (!$calculator.length) return;
    
        const $stockPriceInput = $('#jtw-sim-stock-price');
        const $epsInput = $('#jtw-sim-eps');
        const $growthInput = $('#jtw-sim-growth-rate');
        const $dividendInput = $('#jtw-sim-dividend-yield');
        
        const $pegValueEl = $('#jtw-peg-value');
        const $pegyValueEl = $('#jtw-pegy-value');
        
        const $pegBar = $('#jtw-peg-bar');
        const $pegyBar = $('#jtw-pegy-bar');
    
        function updateRatios() {
            const stockPrice = parseFloat($stockPriceInput.val());
            const eps = parseFloat($epsInput.val());
            const growthRate = parseFloat($growthInput.val());
            const dividendYield = parseFloat($dividendInput.val());
    
            let pe = NaN;
            if (stockPrice > 0 && eps > 0) {
                pe = stockPrice / eps;
            }
    
            function updateBar($bar, $valueEl, value) {
                if (isNaN(value) || value === null || !isFinite(value)) {
                    $valueEl.text('-');
                    $bar.css('width', '0%').removeClass('good fair poor');
                    return;
                }

                $valueEl.text(value.toFixed(2) + 'x');
                
                const max_val = 2.0;
                const width_percent = Math.min((Math.abs(value) / max_val) * 100, 100);
                $bar.css('width', width_percent + '%');

                $bar.removeClass('good fair poor');
                if (value < 1.0 && value >= 0) {
                    $bar.addClass('good');
                } else if (value >= 1.0 && value <= 1.2) {
                    $bar.addClass('fair');
                } else {
                    $bar.addClass('poor');
                }
            }

            let peg = NaN;
            if (!isNaN(pe) && growthRate > 0) {
                peg = pe / growthRate;
            }
            updateBar($pegBar, $pegValueEl, peg);
    
            let pegy = NaN;
            if (!isNaN(pe) && (growthRate + dividendYield) > 0) {
                pegy = pe / (growthRate + dividendYield);
            }
            updateBar($pegyBar, $pegyValueEl, pegy);
        }
    
        $container.on('input', '.jtw-sim-input', debounce(updateRatios, 250));
        updateRatios();
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

        const ctx = document.getElementById('jtw-valuation-chart');
        if (!ctx) return;
        
        const dynamicBackgroundPlugin = {
            id: 'dynamicBackground',
            beforeDatasetsDraw(chart, args, options) {
                const { ctx, chartArea: { top, bottom, left, right, width, height }, scales: { x, y } } = chart;
                ctx.save();
                
                const fairValueData = chart.data.datasets[0].data[1]; 
                const undervaluedLimit = fairValueData * 1.2;
                const overvaluedLimit = fairValueData * 0.8;

                const undervaluedPixel = x.getPixelForValue(undervaluedLimit);
                const overvaluedPixel = x.getPixelForValue(overvaluedLimit);
                
                // **UPDATED** Changed to solid colors
                ctx.fillStyle = '#4CAF50'; // Solid Green for undervalued
                ctx.fillRect(left, top, overvaluedPixel - left, height);
                ctx.fillStyle = '#FFC107'; // Solid Yellow for fairly valued
                ctx.fillRect(overvaluedPixel, top, undervaluedPixel - overvaluedPixel, height);
                ctx.fillStyle = '#F44336'; // Solid Red for overvalued
                ctx.fillRect(undervaluedPixel, top, right - undervaluedPixel, height);

                ctx.restore();
            }
        };

        const customLabelsPlugin = {
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
                            
                            const labelFontSize = Math.max(height * 0.20, 8);
                            const valueFontSize = Math.max(height * 0.25, 10);

                            const totalTextHeight = labelFontSize + valueFontSize + 4;
                            const textYPosition = y - (totalTextHeight / 2);

                            ctx.save();
                            ctx.fillStyle = 'white';
                            ctx.textBaseline = 'top';
                            ctx.font = `bold ${labelFontSize}px Arial`;
                            ctx.fillText(label, base + 15, textYPosition);
                            ctx.font = `bold ${valueFontSize}px Arial`;
                            ctx.fillText('$' + value.toFixed(2), base + 15, textYPosition + labelFontSize + 4);
                            ctx.restore();
                        });
                    }
                });
            }
        };
        
        const valuationChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Current Price', 'Fair Value'],
                datasets: [{
                    data: [currentPrice, fairValue],
                    backgroundColor: [ 'rgba(0, 0, 0, 0.7)', 'rgba(0, 0, 0, 0.5)'],
                    borderColor: 'transparent',
                    borderWidth: 0,
                    barThickness: function(context) {
                        const chart = context.chart;
                        const { chartArea } = chart;
                        if (!chartArea) return 20;
                        return chartArea.height * 0.4;
                    },
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
                        ticks: { display: false },
                        grid: { display: false, drawBorder: false }
                    },
                    y: {
                        grid: { display: false, drawBorder: false },
                        ticks: { display: false }
                    }
                }
            },
            plugins: [dynamicBackgroundPlugin, customLabelsPlugin]
        });
        
        $container.find('.jtw-valuation-range-container').remove();
        
        let annotationText = '';
        if (percentageDiff > 20) {
            annotationText = percentageDiff.toFixed(1) + '% Undervalued';
        } else if (percentageDiff < -20) {
            annotationText = Math.abs(percentageDiff).toFixed(1) + '% Overvalued';
        } else {
            annotationText = 'Fairly Valued';
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

        let charts = {}; 

        const hasData = (data) => {
            if (!data || !data.labels || data.labels.length === 0) return false;
            if (data.datasets && data.datasets.length > 0) {
                return data.datasets.some(ds => ds.data && ds.data.some(v => v !== null && v !== 0));
            }
             return data.data && data.data.some(v => v !== null && v !== 0);
        };

        $chartDataScripts.each(function() {
            const $script = $(this);
            const $chartItem = $script.closest('.jtw-chart-item');
            const chartId = $script.data('chart-id');
            const chartType = $script.data('chart-type');
            const prefix = $script.data('prefix');
            let annualData;

            let colors = ['rgba(0, 122, 255, 0.6)', 'rgba(0, 122, 255, 1)'];
            const colorsAttr = $script.attr('data-colors');
            if (colorsAttr) {
                try {
                    const parsedColors = JSON.parse(colorsAttr);
                    if(Array.isArray(parsedColors) && parsedColors.length > 0) {
                        colors = parsedColors;
                    }
                } catch (e) {
                    console.error("Failed to parse colors JSON for chart:", chartId, e);
                }
            }

            try {
                annualData = JSON.parse($script.attr('data-annual'));
            } catch (e) {
                console.error("Failed to parse annual data for chart:", chartId, e);
                $chartItem.hide();
                return; 
            }
            
            if (!hasData(annualData)) {
                $chartItem.hide();
            }

            const ctx = document.getElementById(chartId);
            if (!ctx) return;
            
            let datasets;
            const options = {
                responsive: true,
                maintainAspectRatio: false, 
                plugins: {
                    legend: { 
                        display: !!annualData.datasets,
                        position: 'top',
                        labels: { boxWidth: 12, font: { size: 11 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed.y !== null) {
                                    label += prefix + formatLargeNumber(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: false,
                        ticks: { autoSkip: true, maxRotation: 0, font: { size: 10 } }
                    },
                    y: {
                        stacked: false,
                        ticks: {
                            maxTicksLimit: 5, 
                            callback: function(value) { return prefix + formatLargeNumber(value).replace('.00',''); },
                            font: { size: 10 }
                        }
                    }
                }
            };
            
            if (chartId.includes('price')) {
                options.elements = { point: { radius: 0, hoverRadius: 4 }, line: { tension: 0.1 } };
                options.scales.x.type = 'time';
                options.scales.x.time = { unit: 'year' };
                options.scales.x.grid = { display: false };
            } else if (chartId.includes('cash-and-debt') || chartId.includes('expenses')) {
                options.scales.x.stacked = true;
                options.scales.y.stacked = true;
            }
            
            if (annualData.datasets) {
                 datasets = annualData.datasets.map((dataset, index) => ({
                    label: dataset.label, data: dataset.data,
                    backgroundColor: colors[index] || 'rgba(0, 122, 255, 0.6)',
                }));
            } else { 
                datasets = [{
                    label: 'Value', data: annualData.data,
                    borderColor: colors[0],
                    backgroundColor: chartType === 'line' ? colors[1] : colors[0],
                    fill: chartType === 'line',
                }];
            }

            const config = { type: chartType, data: { labels: annualData.labels, datasets: datasets }, options: options };
            charts[chartId] = new Chart(ctx, config);
        });

        function updateAndFilterCharts() {
            const activePeriod = $container.find('.jtw-period-button.active').data('period');
            const activeCategory = $container.find('.jtw-category-button.active').data('category');

            $container.find('.jtw-chart-item').hide().promise().done(function() {
                $chartDataScripts.each(function() {
                    const $script = $(this);
                    const $chartItem = $script.closest('.jtw-chart-item');
                    const chartCategory = $chartItem.data('category');
                    const chartId = $script.data('chart-id');
                    const chart = charts[chartId];
                    if (!chart) return;

                    const shouldBeVisible = (activeCategory === 'all' || chartCategory === activeCategory);

                    if (shouldBeVisible) {
                        let dataToUse;
                        try {
                            dataToUse = JSON.parse($script.attr('data-' + activePeriod));
                        } catch (e) {
                            return; 
                        }

                        if (hasData(dataToUse)) {
                            chart.data.labels = dataToUse.labels;
                            if (dataToUse.datasets) {
                                chart.data.datasets.forEach((dataset, index) => {
                                    if (dataToUse.datasets[index]) {
                                        dataset.data = dataToUse.datasets[index].data;
                                        dataset.label = dataToUse.datasets[index].label;
                                    }
                                });
                            } else {
                                chart.data.datasets[0].data = dataToUse.data;
                            }
                            
                            $chartItem.show();
                            chart.update();
                        }
                    }
                });
            });
        }

        $container.find('.jtw-period-button').on('click', function() {
            const $button = $(this);
            if ($button.hasClass('active')) return;

            $container.find('.jtw-period-button').removeClass('active');
            $button.addClass('active');
            
            updateAndFilterCharts();
        });

        $container.find('.jtw-category-button').on('click', function() {
            const $button = $(this);
            if ($button.hasClass('active')) return;

            $container.find('.jtw-category-button').removeClass('active');
            $button.addClass('active');

            updateAndFilterCharts();
        });
    }

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

    function initializeHeaderSearch() {
        const $headerForms = $('.jtw-header-lookup-form');
        if (!$headerForms.length) return;

        $headerForms.each(function() {
            const $form = $(this);
            const $input = $form.find('.jtw-header-ticker-input');
            const $button = $form.find('.jtw-header-fetch-button');
            const $resultsContainer = $form.find('.jtw-header-search-results');
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
                if (keywords.length < 1) {
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
                                const placeholderImgUrl = 'https://beardedinvestor.com/wp-content/uploads/2025/04/Dictionary-Item-Graphic.png';
                                
                                let iconHtml;
                                if (match.icon_url) {
                                    iconHtml = `<img src="${match.icon_url}" class="jtw-result-icon" alt="${match.name} logo" onerror="this.onerror=null; this.src='${placeholderImgUrl}';">`;
                                } else {
                                    iconHtml = `<img src="${placeholderImgUrl}" class="jtw-result-icon" alt="Placeholder">`;
                                }

                                let flagHtml = '';
                                if (match.locale && match.locale.toLowerCase() !== 'us') {
                                    flagHtml = `<img class="jtw-result-flag" src="https://flagcdn.com/w20/${match.locale.toLowerCase()}.png" alt="${match.locale.toUpperCase()} flag">`;
                                }

                                const $li = $('<li>').addClass('jtw-header-result-item').attr('data-symbol', match.ticker);

                                const itemHtml = `
                                    <div class="jtw-result-icon-wrapper">
                                        ${iconHtml}
                                    </div>
                                    <div class="jtw-result-details">
                                        <div class="jtw-result-name">${match.name}</div>
                                        <div class="jtw-result-meta">
                                            ${flagHtml}
                                            <span class="jtw-result-exchange">${match.exchange}:${match.ticker}</span>
                                        </div>
                                    </div>
                                `;

                                $li.html(itemHtml);
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

            $form.on('click', '.jtw-header-result-item', function() {
                redirectToAnalysisPage($(this).data('symbol'));
            });
        });
        
        $(document).on('click', function(event) {
            if (!$(event.target).closest('.jtw-header-lookup-form').length) {
                $('.jtw-header-search-results').empty().hide();
            }
        });
    }

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
