(function ($) {
    'use strict';

    $(document).ready(function () {

        // Logic for the interactive industry mapping page
        if ($('#jtw-mapping-ui-container').length) {
            
            let $activeRow = null;
            let saveTimer;

            // **FIXED** Helper function to properly escape attribute values for use in selectors.
            function escapeAttr(s) {
                if (typeof s !== 'string') return '';
                return s.replace(/([!"#$%&'()*+,./:;<=>?@[\\\]^`{|}~])/g, "\\$1");
            }

            // 1. Handle clicking on an Alpha Vantage row to make it active
            $('#jtw-av-industry-list').on('click', '.jtw-av-item', function() {
                if ($(this).hasClass('active')) {
                    return;
                }
                
                $('#jtw-av-industry-list .jtw-av-item').removeClass('active');
                $activeRow = $(this).addClass('active');
                updateTogglesForActiveRow();
            });

            // 2. Handle clicking on a Damodaran toggle button
            $('.jtw-damodaran-toggle').on('click', function() {
                if (!$activeRow) {
                    alert('Please select an Alpha Vantage industry from the list first.');
                    return;
                }
                $(this).toggleClass('active');
                updateTagsAndSave();
            });

            // 3. Handle removing a tag directly
            $('#jtw-av-industry-list').on('click', '.jtw-remove-tag', function(e) {
                e.stopPropagation();
                $activeRow = $(this).closest('.jtw-av-item');
                const $tagToRemove = $(this).closest('.jtw-assigned-tag');
                const damodaranIndustryToRemove = $tagToRemove.data('damodaran-industry');
                
                $tagToRemove.remove();
                // **FIXED** Use the escaped selector
                $('.jtw-damodaran-toggle[data-industry="' + escapeAttr(damodaranIndustryToRemove) + '"]').removeClass('active');
                
                const assignedIndustries = [];
                $activeRow.find('.jtw-assigned-tag').each(function() {
                    assignedIndustries.push($(this).data('damodaran-industry'));
                });
                saveActiveRowMapping(assignedIndustries);
            });

            function updateTagsAndSave() {
                if (!$activeRow) return;

                const $assignedTagsContainer = $activeRow.find('.jtw-assigned-tags');
                let assignedIndustries = [];
                
                $assignedTagsContainer.empty(); 

                $('.jtw-damodaran-toggle.active').each(function() {
                    const industryName = $(this).data('industry');
                    assignedIndustries.push(industryName);
                    const newTag = $('<span class="jtw-assigned-tag" data-damodaran-industry="' + industryName + '">' + industryName + '<button type="button" class="jtw-remove-tag">&times;</button></span>');
                    $assignedTagsContainer.append(newTag);
                });

                saveActiveRowMapping(assignedIndustries);
            }

            function updateTogglesForActiveRow() {
                $('.jtw-damodaran-toggle').removeClass('active');
                if (!$activeRow) return;

                const assignedIndustries = [];
                $activeRow.find('.jtw-assigned-tag').each(function() {
                    assignedIndustries.push($(this).data('damodaran-industry'));
                });

                assignedIndustries.forEach(function(industry) {
                    if(industry) {
                       // **FIXED** Use the escaped selector
                       $('.jtw-damodaran-toggle[data-industry="' + escapeAttr(industry) + '"]').addClass('active');
                    }
                });
            }

            function saveActiveRowMapping(damodaranIndustries) {
                if (!$activeRow) return;
                
                clearTimeout(saveTimer);
                const $statusContainer = $('#jtw-ajax-save-status');
                const $statusP = $statusContainer.find('p');
                $statusP.text('Saving...').removeClass('error');
                $statusContainer.slideDown();

                const avIndustry = $activeRow.data('av-industry');

                $.ajax({
                    url: jtw_mapping_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'jtw_save_single_mapping',
                        nonce: jtw_mapping_ajax.nonce,
                        av_industry: avIndustry,
                        damodaran_industries: damodaranIndustries
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

        // Logic for the Tools/Settings page reset confirmation
         if ($('#jtw-reset-form').length) {
            const $checkbox = $('#jtw-confirm-reset-checkbox');
            const $submitButton = $('#jtw_reset_submit');
            $checkbox.on('change', function() {
                $submitButton.prop('disabled', !this.checked);
            });
        }
    });

})(jQuery);
