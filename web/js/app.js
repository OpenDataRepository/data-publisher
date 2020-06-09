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
        return this.optional(element) || /^0$|^-?[1-9][0-9]*$/.test(value);
    }, "Please enter a valid Integer value.");
    jQuery.validator.addMethod('ODRDecimal', function(value, element) {
        // Regex matches zero, optionally followed by a decimal point then any sequence of digits
        // OR
        // an optional minus sign followed by a non-zero integer, optionally followed by a decimal point and any sequence of digits
        // OR
        // a minus sign followed by a zero and a decimal point, followed by any sequence of digits that has at least one non-zero digit
        return this.optional(element) || /^0(\.[0-9]+)?$|^-?[1-9][0-9]*(\.[0-9]+)?$|^-0\.[0-9]*[1-9]+[0-9]*$/.test(value);
    }, "Please enter a valid Decimal value.");

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
