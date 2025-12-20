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

        // Configuration: Set to true to use standalone PHP endpoint (faster, bypasses Symfony/WordPress)
        // Set to false to use Symfony routes (default, maintains compatibility)
        var USE_STANDALONE_ENDPOINT = true;

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

        console.log('ODRStatistics: Using standalone endpoint:', USE_STANDALONE_ENDPOINT);

        /**
         * Add a logging tracker to mark content as logged
         * @param {HTMLElement} container The container element to append the tracker to
         */
        function addLoggingTracker(container) {
            // Remove any existing tracker first
            var existingTracker = document.getElementById('logging_tracker');
            if (existingTracker) {
                existingTracker.remove();
            }

            // Create new tracker
            var tracker = document.createElement('input');
            tracker.type = 'hidden';
            tracker.id = 'logging_tracker';
            tracker.value = 'logged_' + Date.now();

            // Append to container
            if (container) {
                container.appendChild(tracker);
                console.log('ODRStatistics: Added logging tracker to', container.id || container.className);
            } else {
                console.warn('ODRStatistics: No container provided for logging tracker');
            }
        }

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

            // Choose endpoint based on configuration
            var endpoint = USE_STANDALONE_ENDPOINT
                ? site_baseurl + '/log_view.php'
                : logger_baseurl + '/statistics/log_view';

            console.log('ODRStatistics: Sending log request to:', endpoint);

            // Send async request to log endpoint
            fetch(endpoint, {
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
                    console.log('ODRStatistics: Log view response:', data);

                    if (data && data.success === true) {
                        console.log('ODRStatistics: Successfully logged view');

                        // Add logging tracker to prevent re-logging on mutations
                        // Must be placed INSIDE #ODRSearchContent so it's removed when content is replaced
                        var searchContent = document.getElementById('ODRSearchContent');
                        if (searchContent) {
                            addLoggingTracker(searchContent);
                        } else {
                            console.warn('ODRStatistics: Could not find #ODRSearchContent to add tracker');
                        }
                    } else {
                        console.warn('ODRStatistics: Log view returned error or unexpected response');
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
         * Collect all datatype/record pairs from the DOM
         * Finds all DataType_X divs and their DIRECT CHILD FieldArea_Y divs (siblings at the same level)
         * @param {HTMLElement} container The container element to search within
         * @returns {Array<Object>} Array of {datatype_id, datarecord_id} objects
         */
        function collectAllDatatypeRecordPairs(container) {
            console.log('ODRStatistics: collectAllDatatypeRecordPairs called');
            var pairs = [];

            if (!container) {
                console.warn('ODRStatistics: No container provided to collectAllDatatypeRecordPairs');
                return pairs;
            }

            // Find all DataType_ divs within the container
            var datatypeDivs = container.querySelectorAll('[id^="DataType_"]');
            console.log('ODRStatistics: Found', datatypeDivs.length, 'DataType divs');

            datatypeDivs.forEach(function(datatypeDiv) {
                var datatypeId = parseInt(datatypeDiv.id.replace('DataType_', ''));

                if (isNaN(datatypeId) || datatypeId <= 0) {
                    return; // Skip invalid datatype IDs
                }

                // Find only DIRECT child FieldArea divs of this DataType
                // We need to find FieldAreas that are children of this DataType but NOT nested inside another DataType
                var childNodes = datatypeDiv.children;

                for (var i = 0; i < childNodes.length; i++) {
                    var child = childNodes[i];

                    // Check if this is a FieldArea div
                    if (child.id && child.id.indexOf('FieldArea_') === 0) {
                        var recordId = parseInt(child.id.replace('FieldArea_', ''));

                        if (!isNaN(recordId) && recordId > 0) {
                            pairs.push({
                                datatype_id: datatypeId,
                                datarecord_id: recordId
                            });
                        }
                    } else {
                        // Recursively search this child for FieldAreas, but only until we hit another DataType
                        findFieldAreasForDataType(child, datatypeId, pairs);
                    }
                }
            });

            console.log('ODRStatistics: Collected', pairs.length, 'datatype/record pairs');
            return pairs;
        }

        /**
         * Helper function to find FieldArea divs for a specific datatype
         * Stops when it encounters another DataType div (because that would be a child datatype)
         * @param {HTMLElement} element The element to search within
         * @param {number} datatypeId The datatype ID we're collecting FieldAreas for
         * @param {Array<Object>} pairs The array to add pairs to
         */
        function findFieldAreasForDataType(element, datatypeId, pairs) {
            if (!element || !element.children) {
                return;
            }

            for (var i = 0; i < element.children.length; i++) {
                var child = element.children[i];

                // If we hit another DataType div, stop - that's a child datatype with its own records
                if (child.id && child.id.indexOf('DataType_') === 0) {
                    continue; // Don't recurse into child datatypes
                }

                // If this is a FieldArea div, add it to our pairs
                if (child.id && child.id.indexOf('FieldArea_') === 0) {
                    var recordId = parseInt(child.id.replace('FieldArea_', ''));

                    if (!isNaN(recordId) && recordId > 0) {
                        pairs.push({
                            datatype_id: datatypeId,
                            datarecord_id: recordId
                        });
                    }
                } else {
                    // Continue searching in this child's descendants
                    findFieldAreasForDataType(child, datatypeId, pairs);
                }
            }
        }

        /**
         * Check if we're viewing results and log appropriately
         * Handles both search results (ODRShortResults) and full record views (ODRResults)
         */
        function checkAndLogResults() {
            console.log('ODRStatistics: checkAndLogResults called');

            // Check if content has already been logged by looking for the tracker
            var existingTracker = document.getElementById('logging_tracker');
            if (existingTracker) {
                console.log('ODRStatistics: Content already logged (tracker found), skipping');
                return;
            }

            // First, check for search results (ODRShortResults class elements)
            var searchResults = document.querySelectorAll('.ODRShortResults');
            console.log('ODRStatistics: Found', searchResults.length, 'search result elements');

            if (searchResults.length > 0) {
                // For search results, we need to collect all datatype/record pairs from the search container
                var searchContainer = document.getElementById('ODRSearchContent');

                if (!searchContainer) {
                    console.warn('ODRStatistics: Found search results but no ODRSearchContent container');
                    return;
                }

                // Collect all datatype/record pairs from search results (including child datatypes)
                var records = collectAllDatatypeRecordPairs(searchContainer);

                console.log('ODRStatistics: Prepared', records.length, 'search result records to log (including child datatypes)');

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
                    // Collect all datatype/record pairs from the full record view (including child datatypes)
                    var records = collectAllDatatypeRecordPairs(fullRecordView);

                    console.log('ODRStatistics: Prepared', records.length, 'full record view records to log (including child datatypes)');

                    if (records.length > 0) {
                        console.log('ODRStatistics: Scheduling batch log for full record view');
                        // Small delay to avoid blocking page rendering
                        setTimeout(function () {
                            logSearchResultViews(records);
                        }, 200);
                    } else {
                        console.warn('ODRStatistics: No valid records to log in full record view');
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

            // Choose endpoint based on configuration
            var endpoint = USE_STANDALONE_ENDPOINT
                ? site_baseurl + '/log_view.php'
                : logger_baseurl + '/statistics/log_batch_view';

            console.log('ODRStatistics: Sending batch log request to:', endpoint);
            console.log('ODRStatistics: Records:', records);

            // Send all records in a single batch request
            fetch(endpoint, {
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
                    console.log('ODRStatistics: Batch log view response:', data);

                    if (data && data.success === true) {
                        console.log('ODRStatistics: Successfully logged ' + data.logged + ' of ' + data.total + ' search results');

                        // Add logging tracker to prevent re-logging on mutations
                        // Must be placed INSIDE #ODRSearchContent so it's removed when content is replaced
                        var searchContent = document.getElementById('ODRSearchContent');
                        if (searchContent) {
                            addLoggingTracker(searchContent);
                        } else {
                            console.warn('ODRStatistics: Could not find #ODRSearchContent to add tracker');
                        }
                    } else {
                        console.warn('ODRStatistics: Batch log view returned error or unexpected response');
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
