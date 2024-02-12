"use strict";
document.querySelectorAll("#ebms-import-form, #import-internal-articles").forEach(form => {
  form.addEventListener("submit", (e) => {
    if (form.classList.contains("is-submitting")) {
      e.preventDefault();
    }
    console.log("adding is-submitting class")
    form.classList.add("is-submitting");
    document.querySelectorAll(".is-submitting input[type='submit']").forEach(button => {
      button.setAttribute("disabled", "");
    })
  });
});
