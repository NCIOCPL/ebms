/*
Doesn't work with phpunit tests.
jQuery(document).ready(function () {
  jQuery("#user-profile-menu-link").parent().addClass("user-profile-menu-link-wrapper");
});
*/
(function () {
  let menu_item = document.getElementById("user-profile-menu-link");
  if (menu_item) {
    menu_item.parentNode.classList.add("user-profile-menu-link-wrapper");
  }
})();
