(function ($) {
    'use strict';

    $(document).ready(function () {

        // Logic for the interactive industry mapping page
        if ($('#jtw-mapping-ui-container').length) {
            
            let $activeRow = null;
            let saveTimer;
            
            const itemsPerPage = 20;
            let currentPage = 1;
            let $companyListItems = $('#jtw-company-list li.jtw-company-item');

            function displayCompanies() {
                const searchTerm = $('#jtw-company-search').val().toLowerCase();
                
                let $filteredItems = $companyListItems.filter(function() {
                    const ticker = $(this).data('ticker').toLowerCase();
                    const industry = $(this).find('.jtw-company-industry').text().toLowerCase();
                    return ticker.includes(searchTerm) || industry.includes(searchTerm);
                });

                const totalItems = $filteredItems.length;
                const totalPages = Math.ceil(totalItems / itemsPerPage);
                
                if (currentPage > totalPages) {
                    currentPage = totalPages > 0 ? totalPages : 1;
                }

                $companyListItems.hide();

                const start = (currentPage - 1) * itemsPerPage;
                const end = start + itemsPerPage;
                $filteredItems.slice(start, end).show();

                $('#jtw-page-info').text(`Page ${currentPage} of ${totalPages > 0 ? totalPages : 1}`);
                $('#jtw-prev-page').prop('disabled', currentPage === 1);
                $('#jtw-next-page').prop('disabled', currentPage === totalPages || totalPages === 0);
            }

            $('#jtw-company-search').on('keyup', function() {
                currentPage = 1; 
                displayCompanies();
            });

            $('#jtw-next-page').on('click', function() {
                currentPage++;
                displayCompanies();
            });

            $('#jtw-prev-page').on('click', function() {
                currentPage--;
                displayCompanies();
            });

            displayCompanies();

            $('#jtw-company-list').on('click', '.jtw-company-item', function() {
                if ($(this).hasClass('active')) {
                    return;
                }
                
                $('#jtw-company-list .jtw-company-item').removeClass('active');
                $activeRow = $(this).addClass('active');
                updateTogglesForActiveRow();
            });

            $('.jtw-damodaran-toggle').on('click', function() {
                if (!$activeRow) {
                    alert('Please select a company from the list first.');
                    return;
                }

                // **FIX**: Enforce a maximum of 2 tags.
                const assignedCount = $activeRow.find('.jtw-assigned-tag').length;
                if (assignedCount >= 2 && !$(this).hasClass('active')) {
                    alert('You can only assign a maximum of 2 industries per company.');
                    return; // Prevent adding more than 2 tags
                }

                $(this).toggleClass('active');
                updateTagsAndSave();
            });

            $('#jtw-company-list').on('click', '.jtw-remove-tag', function(e) {
                e.stopPropagation();
                $activeRow = $(this).closest('.jtw-company-item');
                const $tagToRemove = $(this).closest('.jtw-assigned-tag');
                const damodaranIdToRemove = $tagToRemove.data('damodaran-id');
                
                $tagToRemove.remove();

                $('.jtw-damodaran-toggle[data-industry-id="' + damodaranIdToRemove + '"]').removeClass('active');
                
                const assignedIds = [];
                $activeRow.find('.jtw-assigned-tag').each(function() {
                    assignedIds.push($(this).data('damodaran-id'));
                });
                saveActiveRowMapping(assignedIds);
            });

            function updateTagsAndSave() {
                if (!$activeRow) return;

                const $assignedTagsContainer = $activeRow.find('.jtw-assigned-tags');
                let assignedIds = [];
                
                $assignedTagsContainer.empty(); 

                $('.jtw-damodaran-toggle.active').each(function() {
                    const industryId = $(this).data('industry-id');
                    const industryName = $(this).text();
                    assignedIds.push(industryId);
                    
                    const newTag = $('<span class="jtw-assigned-tag"></span>').text(industryName);
                    newTag.attr('data-damodaran-id', industryId); 
                    newTag.append('<button type="button" class="jtw-remove-tag">&times;</button>');
                    $assignedTagsContainer.append(newTag);
                });

                saveActiveRowMapping(assignedIds);
            }

            function updateTogglesForActiveRow() {
                $('.jtw-damodaran-toggle').removeClass('active');
                if (!$activeRow) return;

                const assignedIds = [];
                $activeRow.find('.jtw-assigned-tag').each(function() {
                    assignedIds.push($(this).data('damodaran-id'));
                });

                assignedIds.forEach(function(id) {
                    $('.jtw-damodaran-toggle[data-industry-id="' + id + '"]').addClass('active');
                });
            }

            function saveActiveRowMapping(damodaranIds) {
                if (!$activeRow) return;
                
                clearTimeout(saveTimer);
                const $statusContainer = $('#jtw-ajax-save-status');
                const $statusP = $statusContainer.find('p');
                $statusP.text('Saving...').removeClass('error');
                $statusContainer.slideDown();

                const ticker = $activeRow.data('ticker');

                $.ajax({
                    url: jtw_mapping_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'jtw_save_company_mapping',
                        nonce: jtw_mapping_ajax.nonce,
                        ticker: ticker,
                        damodaran_industry_ids: damodaranIds
                    },
                    success: function(response) {
                        if (response.success) {
                            $statusP.text('Mapping saved!');
                        } else {
                            $statusP.text('Error saving mapping.').addClass('error');
                        }
                        saveTimer = setTimeout(() => $statusContainer.slideUp(), 2000);
                    },
                    error: function() {
                        $statusP.text('Error saving mapping.').addClass('error');
                        saveTimer = setTimeout(() => $statusContainer.slideUp(), 2000);
                    }
                });
            }
        }

        if ($('#jtw-reset-form').length) {
            const $checkbox = $('#jtw-confirm-reset-checkbox');
            const $submitButton = $('#jtw_reset_submit');
            $checkbox.on('change', function() {
                $submitButton.prop('disabled', !this.checked);
            });
        }
    });

})(jQuery);
