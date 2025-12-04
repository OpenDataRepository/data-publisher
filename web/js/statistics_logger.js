/**
 * Open Data Repository Data Publisher
 * Statistics Logger (JavaScript)
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Client-side logging for datarecord views and file downloads.
 */

jQuery(document).ready(function () {
        'use strict';

        console.log('ODRStatistics: Initializing...');
        console.log('Site base URL: ' + site_baseurl);
        let logger_baseurl = site_baseurl;
        try {
            if(odr_wordpress_integrated) {
                console.log('Wordpress Site base URL: ' + wordpress_site_baseurl);
                console.log('Wordpress Integrated: ' + odr_wordpress_integrated);
                logger_baseurl = wordpress_site_baseurl;
            }
        } catch (e) {}

        /**
         * Log a datarecord view
         * @param {number} datarecord_id The ID of the datarecord being viewed
         * @param {number} datatype_id The ID of the datatype
         * @param {boolean} is_search_result Whether this view is from a search result
         */
        function logRecordView(datarecord_id, datatype_id, is_search_result) {
            console.log('ODRStatistics: logRecordView called - datarecord_id:', datarecord_id, 'datatype_id:', datatype_id, 'is_search_result:', is_search_result);

            if (!datarecord_id || datarecord_id <= 0) {
                console.warn('ODRStatistics: Invalid datarecord_id', datarecord_id);
                return;
            }

            if (!datatype_id || datatype_id <= 0) {
                console.warn('ODRStatistics: Invalid datatype_id', datatype_id);
                return;
            }

            is_search_result = is_search_result || false;

            console.log('ODRStatistics: Sending log request to:', logger_baseurl + '/statistics/log_view');

            // Send async request to log endpoint
            fetch(logger_baseurl + '/statistics/log_view', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    datarecord_id: parseInt(datarecord_id),
                    datatype_id: parseInt(datatype_id),
                    is_search_result: is_search_result
                })
            })
                .then(function (response) {
                    if (!response.ok) {
                        console.warn('ODRStatistics: Failed to log view', response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (!data.success) {
                        console.warn('ODRStatistics: Log view returned error');
                    } else {
                        console.log('ODRStatistics: Successfully logged view');
                    }
                })
                .catch(function (error) {
                    // Silently fail - statistics logging should not break the application
                    console.debug('ODRStatistics: Error logging view', error);
                });
        }

        /**
         * Log a file download
         * @param {number} file_id The ID of the file being downloaded
         */
        function logFileDownload(file_id) {
            if (!file_id || file_id <= 0) {
                console.warn('ODRStatistics: Invalid file_id', file_id);
                return;
            }

            // Send async request to log endpoint
            fetch(logger_baseurl + '/statistics/log_download', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    file_id: parseInt(file_id)
                })
            })
                .then(function (response) {
                    if (!response.ok) {
                        console.warn('ODRStatistics: Failed to log download', response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (!data.success) {
                        console.warn('ODRStatistics: Log download returned error');
                    }
                })
                .catch(function (error) {
                    // Silently fail - statistics logging should not break the application
                    console.debug('ODRStatistics: Error logging download', error);
                });
        }

        /**
         * Auto-log record views on page load
         * Detects both search results and full record views from DOM structure
         */
        function autoLogOnPageLoad() {
            console.log('ODRStatistics: autoLogOnPageLoad called, readyState:', document.readyState);

            // Since we're inside jQuery ready, DOM is already loaded
            // Call immediately
            checkAndLogResults();
            setupResultsWatcher();
        }

        /**
         * Check if we're viewing results and log appropriately
         * Handles both search results (ODRShortResults) and full record views (ODRResults)
         */
        function checkAndLogResults() {
            console.log('ODRStatistics: checkAndLogResults called');

            // First, check for search results (ODRShortResults class elements)
            var searchResults = document.querySelectorAll('.ODRShortResults');
            console.log('ODRStatistics: Found', searchResults.length, 'search result elements');

            if (searchResults.length > 0) {
                // Find the SearchResultsDataType_ element to get the datatype ID (applies to all results)
                var datatypeElem = document.querySelector('[id^="SearchResultsDataType_"]');
                console.log('ODRStatistics: SearchResultsDataType element:', datatypeElem ? datatypeElem.id : 'NOT FOUND');

                if (!datatypeElem || !datatypeElem.id) {
                    console.warn('ODRStatistics: Found search results but no SearchResultsDataType_ element');
                    return;
                }

                var datatypeId = parseInt(datatypeElem.id.replace('SearchResultsDataType_', ''));
                console.log('ODRStatistics: Extracted datatype_id:', datatypeId);

                if (isNaN(datatypeId) || datatypeId <= 0) {
                    console.warn('ODRStatistics: Invalid datatype_id from SearchResultsDataType_', datatypeId);
                    return;
                }

                // Extract datarecord IDs from search results (all have same datatype_id)
                var records = [];

                searchResults.forEach(function (elem) {
                    var id = elem.id;
                    if (id && id.indexOf('ShortResults_') === 0) {
                        var recordId = parseInt(id.replace('ShortResults_', ''));

                        if (!isNaN(recordId) && recordId > 0) {
                            records.push({
                                datarecord_id: recordId,
                                datatype_id: datatypeId
                            });
                        }
                    }
                });

                console.log('ODRStatistics: Prepared', records.length, 'search result records to log');

                // Log all search result views in batch
                if (records.length > 0) {
                    console.log('ODRStatistics: Scheduling batch log for search results');
                    setTimeout(function () {
                        logSearchResultViews(records);
                    }, 200);
                } else {
                    console.warn('ODRStatistics: No valid search result records to log');
                }
            } else {
                // No search results, check for full record view (ODRResults class)
                var fullRecordView = document.querySelector('.ODRResults');
                console.log('ODRStatistics: ODRResults element:', fullRecordView ? 'FOUND' : 'NOT FOUND');

                if (fullRecordView) {
                    // Look for first DataType_[Number] ID to get the datatype ID
                    var datatypeElem = fullRecordView.querySelector('[id^="DataType_"]');
                    console.log('ODRStatistics: DataType element:', datatypeElem ? datatypeElem.id : 'NOT FOUND');

                    // Look for first FieldArea_[Number] ID to get the record ID
                    var fieldAreaElem = fullRecordView.querySelector('[id^="FieldArea_"]');
                    console.log('ODRStatistics: FieldArea element:', fieldAreaElem ? fieldAreaElem.id : 'NOT FOUND');

                    if (datatypeElem && datatypeElem.id && fieldAreaElem && fieldAreaElem.id) {
                        var datatypeId = parseInt(datatypeElem.id.replace('DataType_', ''));
                        var recordId = parseInt(fieldAreaElem.id.replace('FieldArea_', ''));

                        console.log('ODRStatistics: Extracted IDs - datatype_id:', datatypeId, 'datarecord_id:', recordId);

                        if (!isNaN(datatypeId) && datatypeId > 0 && !isNaN(recordId) && recordId > 0) {
                            console.log('ODRStatistics: Scheduling single record view log');
                            // Small delay to avoid blocking page rendering
                            setTimeout(function () {
                                logRecordView(recordId, datatypeId, false);
                            }, 200);
                        } else {
                            console.warn('ODRStatistics: Invalid IDs extracted - datatype_id:', datatypeId, 'datarecord_id:', recordId);
                        }
                    } else {
                        console.warn('ODRStatistics: Missing required elements for full record view');
                    }
                } else {
                    console.log('ODRStatistics: No search results or full record view detected');
                }
            }
        }

        /**
         * Set up a MutationObserver to watch for changes to results containers
         * This handles cases where results are dynamically loaded via AJAX
         * Watches both #ODRSearchContent (search results) and #odr_content (full record views)
         */
        function setupResultsWatcher() {
            console.log('ODRStatistics: Setting up MutationObserver');

            var searchContent = document.getElementById('ODRSearchContent');
            var mainContent = document.getElementById('odr_content');

            console.log('ODRStatistics: ODRSearchContent element:', searchContent ? 'FOUND' : 'NOT FOUND');
            console.log('ODRStatistics: odr_content element:', mainContent ? 'FOUND' : 'NOT FOUND');

            // Create a MutationObserver to watch for changes
            var observer = new MutationObserver(function (mutations) {
                console.log('ODRStatistics: MutationObserver detected', mutations.length, 'mutations');
                // Debounce the check - only run after changes have stopped for 500ms
                clearTimeout(window.ODRStatsTimeout);
                window.ODRStatsTimeout = setTimeout(function () {
                    console.log('ODRStatistics: MutationObserver triggering checkAndLogResults');
                    checkAndLogResults();
                }, 500);
            });

            // Watch search content if it exists
            if (searchContent) {
                console.log('ODRStatistics: Observing #ODRSearchContent for changes');
                observer.observe(searchContent, {
                    childList: true,    // Watch for addition/removal of child elements
                    subtree: true       // Watch all descendants, not just direct children
                });
            }

            // Watch main content for full record views
            if (mainContent) {
                console.log('ODRStatistics: Observing #odr_content for changes');
                observer.observe(mainContent, {
                    childList: true,
                    subtree: true
                });
            }

            if (!searchContent && !mainContent) {
                console.warn('ODRStatistics: No containers found to observe!');
            }
        }

        /**
         * Batch log multiple search result views
         * Useful for logging views of multiple records shown in search results
         * @param {Array<Object>} records Array of {datarecord_id, datatype_id} objects
         */
        function logSearchResultViews(records) {
            console.log('ODRStatistics: logSearchResultViews called with', records.length, 'records');

            if (!Array.isArray(records) || records.length === 0) {
                console.warn('ODRStatistics: No records to log in batch');
                return;
            }

            console.log('ODRStatistics: Sending batch log request to:', logger_baseurl + '/statistics/log_batch_view');
            console.log('ODRStatistics: Records:', records);

            // Send all records in a single batch request
            fetch(logger_baseurl + '/statistics/log_batch_view', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    records: records,
                    is_search_result: true
                })
            })
                .then(function (response) {
                    if (!response.ok) {
                        console.warn('ODRStatistics: Failed to batch log views', response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (!data.success) {
                        console.warn('ODRStatistics: Batch log view returned error');
                    } else {
                        console.log('ODRStatistics: Successfully logged ' + data.logged + ' of ' + data.total + ' search results');
                    }
                })
                .catch(function (error) {
                    // Silently fail - statistics logging should not break the application
                    console.debug('ODRStatistics: Error batch logging views', error);
                });
        }

        /**
         * Debounced logging for search results
         * Only logs when user stops scrolling/interacting for specified delay
         * @param {number} datarecord_id The ID of the datarecord
         * @param {number} delay Delay in milliseconds (default 1000)
         */
        var debouncedLog = (function () {
            var timeouts = {};

            return function (datarecord_id, delay) {
                delay = delay || 1000;

                // Clear existing timeout for this record
                if (timeouts[datarecord_id]) {
                    clearTimeout(timeouts[datarecord_id]);
                }

                // Set new timeout
                timeouts[datarecord_id] = setTimeout(function () {
                    logRecordView(datarecord_id, true);
                    delete timeouts[datarecord_id];
                }, delay);
            };
        })();

        // Auto-initialize
        autoLogOnPageLoad();
});
