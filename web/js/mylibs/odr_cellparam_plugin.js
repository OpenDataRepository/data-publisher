/**
 * Updates the point groups displayed by the given popup for the Cellparam plugin to match the
 * currently selected crystal system in the same popup.
 *
 * @param {string} input_id
 */
function ODR_updatePointGroups(input_id) {
    var point_group_select = $("#" + input_id + "_point_group");

    // Get the currently selected crystal system...
    var selected_crystal_system = $("#" + input_id + "_crystal_system").children(':selected').val();
    if ( selected_crystal_system == '' ) {
        // No crystal system selected, show all point groups
        $(point_group_select).children().removeClass('ODRHidden');
    }
    else {
        // Otherwise, only show the point groups related to the selected crystal system
        $(point_group_select).children().not(':first-child').addClass('ODRHidden');
        $(point_group_select).children('[rel=' + selected_crystal_system + ']').removeClass('ODRHidden');
    }
}

/**
 * Updates the space groups displayed by the given popup for the Cellparam plugin to match the
 * currently selected point group in the same popup.
 *
 * @param {string} input_id
 */
function ODR_updateSpaceGroups(input_id) {
    var point_group_select = $("#" + input_id + "_point_group");
    var space_group_select = $("#" + input_id + "_space_group");

    // Get the currently selected point group...
    var selected_point_group = $(point_group_select).children(':selected').val();
    // ...since the '/' character isn't allowed in HTML id/class/attr/rel/etc strings, it's been
    //   substituted with the 's' character
    selected_point_group = selected_point_group.replaceAll('/', 's');

    if ( selected_point_group == '' ) {
        // No point group selected...determine whether a crystal system is selected
        var selected_crystal_system = $("#" + input_id + "_crystal_system").val();
        if ( selected_crystal_system == '' ) {
            // ...if no crystal system selected, then show all space groups
            $(space_group_select).children().removeClass('ODRHidden');
        }
        else {
            // ...otherwise, only show the space groups that are related to the point groups belonging
            //  to the selected crystal system
            $(space_group_select).children().not(':first-child').addClass('ODRHidden');
            $(point_group_select).children('[rel=' + selected_crystal_system + ']').each(function(index,elem) {
                var pg = $(elem).val().replaceAll('/', 's');
                $(space_group_select).children('[rel=' + pg + ']').removeClass('ODRHidden');
            });
        }
    }
    else {
        // Otherwise, only show the space groups related to the selected point group
        $(space_group_select).children().not(':first-child').addClass('ODRHidden');
        $(space_group_select).children('[rel=' + selected_point_group + ']').removeClass('ODRHidden');
    }
}
