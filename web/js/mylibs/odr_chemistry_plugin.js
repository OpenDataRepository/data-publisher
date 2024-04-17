/**
 * Takes a "plain" chemical formula...i.e. "Ni2+C31H32N4"...and attempts to convert it into a
 * formatted state...i.e. "Ni^2+^C_31_H_32_N_4_".  If the formula is already formatted, then this
 * will not return a sensible result.
 *
 * @param {string} input
 * @param {string} [subscript_delimiter]
 * @param {string} [superscript_delimiter]
 * @returns {string}
 */
function ODR_parseChemicalFormula(input, subscript_delimiter = '_', superscript_delimiter = '^') {
    let output = '';
    let chars = [];
    let num_spaces = 0;

    // If the input has '<sub>' or '<sup>' HTML tags already, then suggest replacing them with the
    //  provided sub/superscript delimiters
    input = input.replaceAll('<sub>', subscript_delimiter).replaceAll('</sub>', subscript_delimiter)
        .replaceAll('<sup>', superscript_delimiter).replaceAll('</sup>', superscript_delimiter)
        .replaceAll('&nbsp;', ' ');

    let len = input.length;
    for (let i = 0; i < len; i++) {
        chars[i] = input.charAt(i);
        if ( chars[i] === ' ' )
            num_spaces++;
    }
    // console.log(chars);  return;
/*
    // Should disable the ' ' for '[box]' substitutions if there are too many spaces in the formula
    // e.g. when the plugin is called on a Journal Title or Notes field
    let too_many_spaces = false;
    if ( num_spaces > 4 )
        too_many_spaces = true;
*/
    for (let i = 0; i < len; i++) {
        let char = chars[i];
        let is_numeric = false;
        if ( char >= '0' && char <= '9' )
            is_numeric = true;

        if ( is_numeric || char === 'x' || char === 'Σ' ) {
            // If this char is a number...
            let prev_char = '';
            if ( i-1 >= 0 )
                prev_char = chars[i-1];
            let next_char = '';
            if ( i+1 < len )
                next_char = chars[i+1];
            let next_next_char = '';
            if ( i+2 < len )
                next_next_char = chars[i+2];

            if ( prev_char === '(' || prev_char === '[' ) {
                // ...due to being preceeded by an opening parenthesis or bracket, this is
                //  likely the start of some stupid math formula e.g. (x ≈ 1/7) or (2 < x < 4)
                let sequence = char;
                do {
                    // Want to find the closing parenthesis or bracket
                    // Note that it's not trying to match a '(' with a ')'...a properly formatted
                    //  formula shouldn't have an unclosed '(' or '[' sequence
                    if ( next_char !== ')' && next_char !== ']' ) {
                        sequence += next_char;
                        i++;

                        // Don't go past the end of the string
                        if ( i+1 < len )
                            next_char = chars[i+1];
                        else
                            break;
                    }
                    else {
                        break;
                    }
                }
                while (i < len);

                // Done with this sequence, append to the output
                output += sequence;
            }
            else if ( /*!(too_many_spaces && prev_char === ' ') &&*/ (next_char === '+' || next_char === '-') && next_next_char !== 'x' ) {
                // ...due to a '+' or '-' character that's not followed by an 'x', it's most
                //  likely a valence state  e.g.  Abelsonite: "Ni2+C31H32N4" => "Ni^2+^..."
                // The condition  !(too_many_spaces && prev_char === ' ')  is an attempt to prevent
                //  this block from triggering on stuff like page numbers in a reference
                if ( prev_char === superscript_delimiter && next_next_char === superscript_delimiter ) {
                    // It looks like this sequence is already wrapped with delimiters...don't
                    //  duplicate them
                    output += char + next_char + superscript_delimiter;
                    i += 2;    // skip ahead to the character after the closing superscript
                }
                else {
                    // Wrap the sequence in delimiters
                    output += superscript_delimiter + char + next_char + superscript_delimiter;
                    i++;
                }
            }
            else {
                // ...otherwise, it's likely a numerical sequence of some sort
                let sequence = char;
                do {
                    let next_char_is_numeric = false;
                    if ( next_char >= '0' && next_char <= '9' )
                        next_char_is_numeric = true;

                    if ( next_char_is_numeric || next_char === '.' || next_char === 'x'
                        || next_char === '-' || next_char === '+' || next_char === '=' || next_char === '/'
//                        || next_char === ' ' || next_char === '~' || next_char === '≈'    // checking for these characters causes more problems than it solves
                    ) {
                        sequence += next_char;
                        i++;

                        // Don't go past the end of the string
                        if ( i+1 < len )
                            next_char = chars[i+1];
                        else
                            break;
                    }
                    else {
                        break;
                    }
                }
                while (i < len);

                // Done with this sequence, append to the output
                if ( prev_char === subscript_delimiter && next_char === subscript_delimiter ) {
                    // It looks like this sequence is already wrapped with delimiters...don't
                    //  dulicate them
                    output += sequence + subscript_delimiter;
                    i++;    // skip over the closing subscript delimiter
                }
                else if ( char === 'x' && i >= 3 && chars[i-3] === '[' && chars[i-2] === 'b' && chars[i-1] === 'o' && next_char === ']' ) {
                    // This triggered on the 'x' inside an existing '[box]' sequence...don't
                    //  interrupt with delimiters
                    output += sequence + ']';
                    i++;
                }
/*
                else if ( too_many_spaces && prev_char === ' ' ) {
                    // If the string to parse looks more like a reference than a chemical formula,
                    //  then attempt to ignore numbers that don't appear to be "attached" to
                    //  anything, like page numbers
                    output += sequence;
                }
*/
                else {
                    // Wrap the sequence in delimiters
                    output += subscript_delimiter + sequence + subscript_delimiter;
                }
            }
        }
        else if ( char === '·' || char === '⋅' || char === '•' ) {
            // U+00B7 "·" (MIDDLE DOT)
            // U+22C5 "⋅" (DOT OPERATOR)
            // U+2022 "•" (BULLET)
            // The first one is preferred, so convert the others into the first
            if ( char === '⋅' || char === '•' )
                char = '·';

            // This character is typically used to denote a collection of water molecules at the
            //  end of the formula...e.g. Abernathyite: "K(UO2)(AsO4)·3H2O"
            let next_char = '';
            if ( i+1 < len )
                next_char = chars[i+1];

            let sequence = char;
            do {
                // Need to find the next non-numeric character, to prevent subscripts from being
                //  added around any subsequent number... i.e. don't want "·_3_H2O"
                let next_char_is_numeric = false;
                if ( next_char >= '0' && next_char <= '9' )
                    next_char_is_numeric = true;

                if ( next_char_is_numeric || next_char === '.' || next_char === '-' || next_char === 'x' ) {
                    sequence += next_char;
                    i++;

                    // Don't go past the end of the string
                    if ( i+1 < len )
                        next_char = chars[i+1];
                    else
                        break;
                }
                else {
                    break;
                }
            }
            while (i < len);

            // Done with this sequence, append to the output
            output += sequence;
        }
        else if ( char === '□' || char === '▢' || char === '◻' || char === '☐' ) {
            // U+25A1 "□" (WHITE SQUARE)
            // U+25A2 "▢" (WHITE SQUARE WITH ROUNDED CORNERS)
            // U+25FB "◻" (WHITE MEDIUM SQUARE)
            // U+2610 "☐" (BALLOT BOX)

            // Replace any instance of these characters with 'box', to keep unicode out of the "plain"
            //  formula it at all possible
            output += '[box]';

            // Isn't unicode the best thing ever?
        }
        else if ( char === ' ' ) {
/*
            // Chemical formulas aren't supposed to have a lot of "[box]" sequences, but this can
            //  be run on data that contains a lot of spaces...need a way to disable this
            if ( too_many_spaces ) {
                output += ' ';
            }
            else {

 */
                // Attempting to detect spaces to insert the "[box]" sequence causes some impressive
                //  failures in a couple formulas, but works well enough on the rest of them...
                let next_char = '';
                if ( i+1 < len )
                    next_char = chars[i+1];

                // If the next character is one of these, it's unlikely to be a "[box]" sequence
                if ( next_char === '(' || next_char === '[' ) {
                    output += ' ' + next_char;
                    i++;
                }
                else {
                    // Otherwise, it has a higher chance of being a "[box]" sequence...
                    output += '[box]';

                    do {
                        // Skip ahead to the next non-space character
                        if ( next_char === ' ' ) {
                            i++;

                            // Don't go past the end of the string
                            if ( i+1 < len )
                                next_char = chars[i+1];
                            else
                                break;
                        }
                        else {
                            break;
                        }
                    }
                    while (i < len);
                }
/*
            }
 */
        }
        else {
            // Otherwise, just echo the character
            output += char;
        }
    }

    return output;
}

/**
 * Takes a "formatted" chemical formula...i.e. "Ni^2+^C_31_H_32_N_4_"...and converts it into the
 * HTML format...ie. "Ni<sup>2</sup>C<sub>31</sub>H<sub>32</sub>N<sub>4</sub>".
 *
 * @param {string} input
 * @param {boolean} is_textarea
 * @param {string} [subscript_delimiter]
 * @param {string} [superscript_delimiter]
 * @returns {string}
 */
function ODR_prettifyChemicalFormula(input, is_textarea, subscript_delimiter = '_', superscript_delimiter = '^') {
    let output = '';
    let in_superscript = false;
    let in_subscript = false;

    let len = input.length;
    for (let i = 0; i < len; i++) {
        let char = input.charAt(i);
        if ( char === superscript_delimiter ) {
            if ( !in_superscript )
                output += '<sup>';
            else
                output += '</sup>';
            in_superscript = !in_superscript;
        }
        else if ( char === subscript_delimiter ) {
            if ( !in_subscript )
                output += '<sub>';
            else
                output += '</sub>';
            in_subscript = !in_subscript;
        }
        else {
            output += char;
        }
    }

    // Replace the "[box]" sequence with U+25FB "◻" (WHITE MEDIUM SQUARE)
    output = output.replaceAll("[box]", "◻");

    if ( is_textarea ) {
        output = output.replaceAll("\n\r", "<br>").replaceAll("\r\n", "<br>")
            .replaceAll("\r", "<br>").replaceAll("\n", "<br>");
    }

    return output;
}

/**
 * Runs ODR_parseChemicalFormula() on the given input element using the given delimiters, and prints
 * the output to parsed_element.  If the remove_whitespace element is checked, then all whitespace
 * characters are stripped from the input element's value before running ODR_parseChemicalFormula()
 *
 * @param {HTMLElement} input_element
 * @param {HTMLElement} remove_whitespace_element
 * @param {HTMLElement} parsed_element
 * @param {string} [subscript_delimiter]
 * @param {string} [superscript_delimiter]
 */
function ODR_runChemistryDialog(input_element, remove_whitespace_element, parsed_element, subscript_delimiter = '_', superscript_delimiter = '^') {
    // Regardless of whether it appears to be formatted, parse the formula
    let input_value = $(input_element).val();
    if ( $(remove_whitespace_element).is(':checked') )
        input_value = input_value.replaceAll(/\s|&nbsp;/g, '');
    let output = ODR_parseChemicalFormula(input_value, subscript_delimiter, superscript_delimiter);

    // Display the parsed formula
    $(parsed_element).val(output);
    $(parsed_element).trigger('keyup');
}

/**
 * Returns false if the value in the given element does not appears to be a "formatted" chemical
 * formula...i.e. it has a number, but no format characters.
 *
 * @param {HTMLElement} input
 * @param {string} [subscript_delimiter]
 * @param {string} [superscript_delimiter]
 * @returns {boolean}
 */
function ODR_isFormulaFormatted(input, subscript_delimiter = '_', superscript_delimiter = '^') {
    let formula = $(input).val();
    let has_number = false;
    let has_format_char = false;
    for (let i = 0; i < formula.length; i++) {
        let char = formula.charAt(i);
        if ( char === subscript_delimiter || char === superscript_delimiter )
            has_format_char = true;
        if ( char >= '0' && char <= '9' )
            has_number = true;
        if (has_format_char && has_number)
            break;
    }

    // The value is definitely not formatted if it has a number, but no formatting characters
    if (has_number && !has_format_char)
        return false;

    // Otherwise, assume it's formatted
    return true;
}

/**
 * Updates the warnings about duplicate delimiter characters in the Chemistry Popup.
 *
 * @param {string} input_id
 * @param {string} [subscript_delimiter]
 * @param {string} [superscript_delimiter]
 */
function ODR_hasDuplicatedDelimiters(input_id, subscript_delimiter = '_', superscript_delimiter = '^') {
    let val = $("#" + input_id + "_parsed").val();
    if ( val.indexOf(subscript_delimiter + subscript_delimiter) !== -1 )
        $("#" + input_id + "_subscript_warning").show();
    else
        $("#" + input_id + "_subscript_warning").hide();

    if ( val.indexOf(superscript_delimiter + superscript_delimiter) !== -1 )
        $("#" + input_id + "_superscript_warning").show();
    else
        $("#" + input_id + "_superscript_warning").hide();
}
