/**
 * Early theme apply — FOUC oldini olish (head da, CSS dan oldin yoki Helpers dan keyin).
 */
(function () {
  try {
    var KEY = "zon_admin_theme";
    var LEGACY = "templateCustomizer-vertical-menu-template--Theme";
    var mode = localStorage.getItem(KEY) || localStorage.getItem(LEGACY) || "light";
    if (mode !== "light" && mode !== "dark" && mode !== "system") mode = "light";
    var resolved =
      mode === "system"
        ? window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches
          ? "dark"
          : "light"
        : mode;
    document.documentElement.setAttribute("data-bs-theme", resolved);
    document.documentElement.setAttribute("data-zon-theme-mode", mode);
  } catch (_) {}
})();
