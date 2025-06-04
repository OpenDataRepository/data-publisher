/**
 * Open Data Repository Data Publisher
 * app.js
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This sets up the jQuery validate plugin?
 */

// ! Your application
window.$ = $
window.jQuery = $

var SaveTimeout = 2000;

(function($, window, document, undefined){

    // Custom validation methods: http://jqueryvalidation.org/jQuery.validator.addMethod
    jQuery.validator.addMethod('ODRInteger', function(value, element) {
        // Regex matches "0"
        // OR
        // an optional minus sign followed by a non-zero integer value
        var regex_result = /^0$|^-?[1-9][0-9]*$/.test(value);
        if ( !regex_result ) {
            // Either the element is empty, or the regular expression failed...don't parse field
            //  contents
            return this.optional(element) || regex_result;
        }
        else {
            // Doctrine (and therefore Mysql) use 4 bytes to store values for IntegerValue fields,
            //  so potential values that require more than 4 bytes to store are invalid...
            var val = parseInt(value, 10);
            var inside_range = (val >= -2147483648) && (val <= 2147483647);

            return inside_range;
        }
    }, "Please enter a valid Integer value.");
    jQuery.validator.addMethod('ODRDecimal', function(value, element) {
        // This regex matches...
        // an optional '+' or '-' sign, followed by either...
        //  - a sequence of digits followed by an optional period and more optional digits
        // OR
        //  - an optional sequence of digits followed by a mandatory period and at least one digit
        // ...which can then be followed by another optional sequence...
        // - 'e' or 'E', followed by an optional '-' or '+', followed by at least one digit
        return this.optional(element) || /^[+-]?(?:[0-9]+\.?[0-9]*|[0-9]*\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/.test(value);

    }, "Please enter a valid Decimal value.");
    jQuery.validator.addMethod('ODRAscii', function(value, element, params) {
        // https://stackoverflow.com/a/20240240  dynamic error message for jquery validate
        if ( value == '' ) {
            // No point running a regex if the field has no value
            if ( params.required == true ) {
                jQuery.validator.messages.ODRAscii = 'This field should not be empty';
                return false;
            }

            return true;
        }
        else {
            // Only permit alphanumeric characters and a couple symbols
            var found = value.match(/[^a-zA-Z0-9\-\+\(\)\[\]]/);
            if ( found !== null ) {
                jQuery.validator.messages.ODRAscii = 'The character "' + found[0] + '" probably should not be in this field';
                return false;
            }

            return true;
        }
    }, '');

    jQuery.validator.setDefaults({
        // Specify a custom html class for displaying errors...this is also applied to the error label
        errorClass: "ODRInputError",
        highlight: function(element, errorClass, validClass) {
            // Override the default highlight option to add both the errorClass and a border to the invalid element
            $(element).addClass(errorClass);
            $(element).css('border', '1px solid red');
        },
        unhighlight: function(element, errorClass, validClass) {
            // Override the default unhighlight option to remove both the errorClass and the border from what used to be the invalid element
            $(element).removeClass(errorClass);
            $(element).css('border', '');
        },
    });

    // Change default error message for a validation method: https://stackoverflow.com/a/2457053
    jQuery.extend(jQuery.validator.messages, {
        "maxlength": jQuery.validator.format("{0} characters max.")
    });

    // NOTE - This is required...not sure exactly where, why, or how
    // from plugins.js line 158
    $.fn.validationOptions = function(options) {


        return $(this).each(function() {

            // Get validation engine
            var $form = $(this),
                validator = $form.validate();

            // Handle submitHandler
            if (options.submitHandler) {
                // Store original submitHandler and given submitHandler
                var _submitHandler = validator.settings.submitHandler,
                    submitHandler = options.submitHandler;

                // Set submitHandler
                validator.settings.submitHandler = function(){
                    !!submitHandler.apply(this, arguments) && _submitHandler.apply(this, arguments);
                }

                delete options.submitHandler;
            }

            // Handle invalidHandler
            if (options.invalidHandler) {
                $form.on('invalid-form.validate', options.invalidHandler);
            }

            // Expand settings
            $.extend(validator.settings, options);


        }); // End of '$el.each(function ...)'

    }; // End of '$.fn.setValidationOptions = ...'

})(jQuery, this, document);



// // Theme Design Scroll Handler
// function initDesignScrollHandler(offset) {
//     // OnScroll is deprecated due to thread performance.
//     // Will need to update to a CSS-based solution when
//     // web standards finally support them.
//     $(window).on('scroll', function(e){
//         var $el = $('#ThemeLeftColumn');
//         var $tda = $('#ThemeDesignArea');
//         var isPositionFixed = ($el.css('position') == 'fixed');
//         if ($(this).scrollTop() > offset && !isPositionFixed){
//             $el.css({'position': 'fixed', 'top': '0px'});
//             $tda.css({'margin-left': '320px'})
//         }
//         if ($(this).scrollTop() < offset && isPositionFixed)
//         {
//             $el.css({'position': 'static', 'top': '0px'});
//             $tda.css({'margin-left': '15px'})
//         }
//     });
// }
