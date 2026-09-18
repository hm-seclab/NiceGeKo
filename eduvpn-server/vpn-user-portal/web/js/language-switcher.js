"use strict";

/*
 * Use JavaScript to enhance the "Language Switcher".
 *
 * When the browser has JS enabled, we hide the "Switch" button, and 
 * immediately switch the language when the user selects another language in 
 * the selection drop down.
 */
document.addEventListener("DOMContentLoaded", function() {
    // hide the "Switch" button, we show it by default when no JS is available
    var langSwitchButton = document.querySelector("header.page form#langSwitch button");
    langSwitchButton.style.display = "none";

    // submit "langSwitch" form when language is switched
    var langSwitchSelect = document.querySelector("header.page form#langSwitch select");
    langSwitchSelect.addEventListener("change", function(e) {
        document.querySelector("header.page form#langSwitch").submit();
    });
});
