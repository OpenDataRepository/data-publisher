/**
 * Open Data Repository Data Publisher
 * Statistics Logger (JavaScript)
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Client-side logging for datarecord views and file downloads.
 */

(function() {
    'use strict';

    // Namespace
    window.ODRStatistics = window.ODRStatistics || {};

    /**
     * Log a datarecord view
     * @param {number} datarecord_id The ID of the datarecord being viewed
     * @param {boolean} is_search_result Whether this view is from a search result
     */
    function logRecordView(datarecord_id, is_search_result) {
        if (!datarecord_id || datarecord_id <= 0) {
            console.warn('ODRStatistics: Invalid datarecord_id', datarecord_id);
            return;
        }

        is_search_result = is_search_result || false;

        // Send async request to log endpoint
        fetch('/statistics/log_view', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                datarecord_id: parseInt(datarecord_id),
                is_search_result: is_search_result
            })
        })
        .then(function(response) {
            if (!response.ok) {
                console.warn('ODRStatistics: Failed to log view', response.status);
            }
            return response.json();
        })
        .then(function(data) {
            if (!data.success) {
                console.warn('ODRStatistics: Log view returned error');
            }
        })
        .catch(function(error) {
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
        fetch('/statistics/log_download', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                file_id: parseInt(file_id)
            })
        })
        .then(function(response) {
            if (!response.ok) {
                console.warn('ODRStatistics: Failed to log download', response.status);
            }
            return response.json();
        })
        .then(function(data) {
            if (!data.success) {
                console.warn('ODRStatistics: Log download returned error');
            }
        })
        .catch(function(error) {
            // Silently fail - statistics logging should not break the application
            console.debug('ODRStatistics: Error logging download', error);
        });
    }

    /**
     * Auto-log record views on page load
     * Looks for data-record-id and data-is-search-result attributes on body tag
     */
    function autoLogOnPageLoad() {
        // Check if DOM is already loaded
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                performAutoLog();
            });
        } else {
            performAutoLog();
        }
    }

    /**
     * Perform the auto-logging
     */
    function performAutoLog() {
        var body = document.body;
        var recordId = body.getAttribute('data-record-id');
        var isSearchResult = body.getAttribute('data-is-search-result') === 'true';

        if (recordId) {
            // Small delay to avoid blocking page rendering
            setTimeout(function() {
                logRecordView(parseInt(recordId), isSearchResult);
            }, 100);
        }
    }

    /**
     * Batch log multiple search result views
     * Useful for logging views of multiple records shown in search results
     * @param {Array<number>} datarecord_ids Array of datarecord IDs
     */
    function logSearchResultViews(datarecord_ids) {
        if (!Array.isArray(datarecord_ids) || datarecord_ids.length === 0) {
            return;
        }

        // Log each record with a small delay between requests to avoid overwhelming the server
        datarecord_ids.forEach(function(record_id, index) {
            setTimeout(function() {
                logRecordView(record_id, true);
            }, index * 50); // 50ms delay between each request
        });
    }

    /**
     * Debounced logging for search results
     * Only logs when user stops scrolling/interacting for specified delay
     * @param {number} datarecord_id The ID of the datarecord
     * @param {number} delay Delay in milliseconds (default 1000)
     */
    var debouncedLog = (function() {
        var timeouts = {};

        return function(datarecord_id, delay) {
            delay = delay || 1000;

            // Clear existing timeout for this record
            if (timeouts[datarecord_id]) {
                clearTimeout(timeouts[datarecord_id]);
            }

            // Set new timeout
            timeouts[datarecord_id] = setTimeout(function() {
                logRecordView(datarecord_id, true);
                delete timeouts[datarecord_id];
            }, delay);
        };
    })();

    // Public API
    window.ODRStatistics.logRecordView = logRecordView;
    window.ODRStatistics.logFileDownload = logFileDownload;
    window.ODRStatistics.logSearchResultViews = logSearchResultViews;
    window.ODRStatistics.debouncedLog = debouncedLog;

    // Auto-initialize
    autoLogOnPageLoad();

})();
