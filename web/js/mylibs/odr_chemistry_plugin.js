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

    let len = input.length;
    for (let i = 0; i < len; i++) {
        chars[i] = input.charAt(i);
        if ( chars[i] === ' ' )
            num_spaces++;
    }
    // console.log(chars);  return;

    let too_many_spaces = false;
    if ( num_spaces > 3 )
        too_many_spaces = true;

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

                // Done with this sequence, continue looking
                output += sequence;
            }
            else if ( (next_char === '+' || next_char === '-') && next_next_char !== 'x' ) {
                // ...due to a '+' or '-' character that's not followed by an 'x', it's most
                //  likely a valence state  e.g.  Abelsonite: "Ni2+C31H32N4" => "Ni^2+^..."
                output += superscript_delimiter + char + next_char + superscript_delimiter;
                i++;
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

                // Done with this sequence, continue looking
                output += subscript_delimiter + sequence + subscript_delimiter;
            }
        }
        else if ( char === '·' ) {
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

            // Done with this sequence, continue looking
            output += sequence;
        }
        else if ( char === ' ' ) {
            // Chemical formulas aren't supposed to have a lot of "[box]" sequences, but this can
            //  be run on data that contains a lot of spaces...need a way to disable this
            if ( too_many_spaces ) {
                output += ' ';
            }
            else {
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
            }
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

    if ( is_textarea ) {
        output = output.replaceAll("\n\r", "<br>").replaceAll("\r\n", "<br>")
            .replaceAll("\r", "<br>").replaceAll("\n", "<br>");
    }

    return output;
}

/**
 * Updates the "prettified" chemical formula
 *
 * @param {string} input_id
 * @param {boolean} is_textarea
 * @param {string} [subscript_delimiter]
 * @param {string} [superscript_delimiter]
 */
function ODR_updatePrettifiedFormula(input_id, is_textarea, subscript_delimiter = '_', superscript_delimiter = '^') {
    var output = ODR_prettifyChemicalFormula( $("#" + input_id + "_parsed").val(), is_textarea, subscript_delimiter, superscript_delimiter );
    $("#" + input_id + "_prettified").html( output );
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

