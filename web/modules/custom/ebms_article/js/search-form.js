// Prepare the callbacks for the journal maintenance form.
jQuery(document).ready(function () {

  // Add the classes
  jQuery("#edit-filters-unpublished").addClass("early-box");
  jQuery("#edit-filters-not-listed").addClass("early-box");
  jQuery("#edit-filters-rejected").addClass("early-box");
  jQuery("#edit-filters-only-unpublished").addClass("only-box");
  jQuery("#edit-filters-only-not-listed").addClass("only-box");
  jQuery("#edit-filters-only-rejected").addClass("only-box");

  // Make the "only" boxes exclusive (as if they were radio buttons).
  jQuery("#edit-filters input.only-box").click(function() {
    if (jQuery(this).prop("checked")) {
      jQuery("#edit-filters input.only-box").prop("checked", false);
      jQuery("#edit-filters input.early-box").prop("checked", false);
      jQuery(this).prop("checked", true);
    }
  });

  // Turn off the "only" boxes if any of the "early" boxes got checked.
  jQuery("#edit-filters input.early-box").click(function() {
    if (jQuery(this).prop("checked")) {
      jQuery("#edit-filters input.only-box").prop("checked", false);
    }
  });

});
