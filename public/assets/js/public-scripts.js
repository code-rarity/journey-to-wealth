/**
 * Public-facing JavaScript for Journey to Wealth plugin.
 *
 * This script powers:
 * 1. The header lookup form, which handles live search and redirects to the analysis page.
 * 2. The main analyzer page, which detects the URL parameter and auto-fetches data on load.
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
     * Initializes the interactive PEG/PEGY calculator on the analysis page.
     */
    function initializePegPegyCalculator($container) {
        const $pegCard = $container.find('.jtw-interactive-card');
        if (!$pegCard.length) return;
        
        const $growthInput = $pegCard.find('#jtw-peg-growth-rate');
        
        function updatePegAndPegy() {
            const pe = parseFloat($pegCard.data('pe-value'));
            const dividendYield = parseFloat($pegCard.data('dividend-yield'));
            const growthRate = parseFloat($growthInput.val());
            const pegValueEl = $pegCard.find('#jtw-peg-value');
            if (pe && growthRate && growthRate > 0) {
                const peg = pe / growthRate;
                pegValueEl.text(peg.toFixed(2)).removeClass('text-green-600 text-yellow-600 text-red-600');
                if (peg < 1.0) { pegValueEl.addClass('text-green-600');
                } else if (peg <= 2.0) { pegValueEl.addClass('text-yellow-600');
                } else { pegValueEl.addClass('text-red-600'); }
            } else {
                pegValueEl.text('N/A').removeClass('text-green-600 text-yellow-600 text-red-600');
            }
            const pegyValueEl = $pegCard.find('#jtw-pegy-value');
            if (pe && !isNaN(dividendYield) && !isNaN(growthRate)) {
                const pegyDenominator = growthRate + dividendYield;
                if (pegyDenominator > 0) {
                    const pegy = pe / pegyDenominator;
                    pegyValueEl.text(pegy.toFixed(2)).removeClass('text-green-600 text-yellow-600 text-red-600');
                    if (pegy < 1.0) { pegyValueEl.addClass('text-green-600');
                    } else if (pegy <= 1.5) { pegyValueEl.addClass('text-yellow-600');
                    } else { pegyValueEl.addClass('text-red-600'); }
                } else {
                     pegyValueEl.text('N/A').removeClass('text-green-600 text-yellow-600 text-red-600');
                }
            } else {
                pegyValueEl.text('N/A').removeClass('text-green-600 text-yellow-600 text-red-600');
            }
        }
        $growthInput.on('input', updatePegAndPegy);
        updatePegAndPegy();
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

        initializePegPegyCalculator($contentArea);
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
        
        // --- CORRECTED LOGIC FOR AUTO-FETCH ---
        const urlParams = new URLSearchParams(window.location.search);
        const symbolFromUrl = urlParams.get('jtw_selected_symbol');

        if (symbolFromUrl) {
            fetchData(symbolFromUrl.toUpperCase());
        }
    }

    $(document).ready(function() {
        // Initialize both potential functionalities.
        initializeHeaderSearch();
        initializeAnalyzerPage();
    });

})( jQuery );
