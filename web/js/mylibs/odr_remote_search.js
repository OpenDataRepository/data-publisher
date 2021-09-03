/**
 * Universal Module Definition without dependencies from
 *  https://github.com/umdjs/umd/blob/master/templates/returnExports.js
 */
(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        // AMD. Register as an anonymous module.
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        // Node. Does not work with strict CommonJS, but
        // only CommonJS-like environments that support module.exports,
        // like Node.
        module.exports = factory();
    } else {
        // Browser globals (root is window)
        // root.returnExports = factory();
        root.ODRRemoteSearch = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {

    /**
     * ODRRemoteSearch is a utility to create base64 ODR search keys, and then opens a new tab with
     * the eqivalent ODR search results.
     * @param opts
     * @param {string} opts.baseurl
     * @param {number} opts.datatype_id
     * @param {string} opts.submit_button
     * @param {Object} opts.mapping
     * @constructor
     */
    function ODRRemoteSearch(opts) {
        /**
         * is initialized?
         * @type {Boolean}
         */
        this.initialized = false;

        /**
         * Current options
         * @type {Object}
         */
        this.opts = opts;
    }

    ODRRemoteSearch.prototype = {
        initialize: function () {
            // All options are required
            let problems = [];

            if (this.opts.baseurl == null || this.opts.baseurl === '')
                problems.push('"baseurl" must not be empty');
            if (this.opts.datatype_id == null || this.opts.datatype_id === '' || isNaN(parseInt(this.opts.datatype_id)))
                problems.push('"datatype_id" must not be numeric');
            if (this.opts.submit_button == null || this.opts.submit_button === '' || document.getElementById(this.opts.submit_button) == null)
                problems.push('"submit_button" must refer to an HTML element');

            if (this.opts.mapping == null || this.opts.mapping.count === 0) {
                problems.push('"mapping" must not be empty');
            } else {
                // Verify the provided mapping makes sense
                // TODO - bool? dates? radio? tags?
                each(this.opts.mapping, function (df_id, element_id) {
                    let element = document.getElementById(element_id);
                    if (element == null)
                        problems.push('"mapping" for datafield ' + df_id + ' references an element with the id "' + element_id + '", but that HTML element does not exist');
                });
            }

            // If any problems were found, print them out
            if (problems.length > 0) {
                each(problems, function (key, value) {
                    console.log('ODRRemoteSearch Error:', value);
                });
                return;
            }

            // If no errors encounted by this point, then the module should work
            this.initialized = true;

            // Using arrow function "=>" instead of standard "function(e)" because this.opts is accessible
            let submit_button = document.getElementById(this.opts.submit_button);
            submit_button.addEventListener("click", (e) => {
                // Don't trigger default form events
                e.preventDefault();
                e.stopPropagation();

                // Don't run this if there was an error with the configuration
                if (!this.initialized)
                    return;

                // Build the search key and redirect to the correct URL in ODR
                ODRRemoteSearch.triggerSearch(this.opts);
            });
        },
    }

    /**
     * Converts the values in the mapped fields into a URL for a new tab
     *
     * @param opts
     */
    function triggerSearch(opts) {
        // Going to need these values from the config
        let baseurl = opts.baseurl;
        let datatype_id = opts.datatype_id;
        let mapping = opts.mapping;

        // Build a string from the given parameters
        let search_key = '{"dt_id":"' + datatype_id + '"';
        each(mapping, function (df_id, element_id) {
            let value = document.getElementById(element_id).value.trim().replaceAll('"', '\\\"');
            if (value !== '')
                search_key += ',"' + df_id + '":"' + value + '"';
        });
        search_key += '}';

        // TODO - bool? dates? radio? tags?

        // The string needs to be base64 encoded
        let encoded_search_key = ODRRemoteSearch.encodeSearchKey(search_key);

        // Open a new browser tab with the link to the search results
        let new_url = baseurl + '#\/search\/display\/0\/' + encoded_search_key;
        window.open(new_url, '_blank');
    }
    ODRRemoteSearch.triggerSearch = triggerSearch;

    /**
     * Converts the given search key into the equivalent ODR base64 encoded search key.
     *
     * @param search_key
     * @returns {string}
     */
    function encodeSearchKey(search_key) {
        // Convert the string into a base64 string, and convert or remove several characters so it's more URL-friendly
        return btoa(search_key).replaceAll('=', '').replaceAll('+', '-').replaceAll('/', '_');
    }
    ODRRemoteSearch.encodeSearchKey = encodeSearchKey;

    /**
     * Performs a callback on each element of an array or object.
     *
     * @param {Array|Object} obj
     * @param {Function} callback
     */
    function each(obj, callback) {
        if (!obj)
            return;

        let key;
        if (typeof (obj.length) !== 'undefined') {
            // obj is an array
            for (key = 0; key < obj.length; key++) {
                if (callback.call(this, key, obj[key]) === false) {
                    return;
                }
            }
        } else {
            // obj is an object
            for (key in obj) {
                if (obj.hasOwnProperty(key) && callback.call(this, key, obj[key]) === false) {
                    return;
                }
            }
        }
    }
    ODRRemoteSearch.each = each;

    return ODRRemoteSearch;
}));

