(() => {
  "use strict";

  const UPGRADE_SELECTOR = 'input[type="date"], input[type="datetime-local"]';

  const hasFlatpickr = () => typeof window.flatpickr === "function";

  const getResolvedLocale = () => {
    try {
      return new Intl.DateTimeFormat().resolvedOptions().locale || "en-US";
    } catch (err) {
      return "en-US";
    }
  };

  const getUserLocale = () => {
    return (navigator.languages && navigator.languages[0]) || navigator.language || getResolvedLocale();
  };

  const getLocaleFormatParts = (locale) => {
    const sample = new Date(2001, 1, 3); // 2001-02-03
    const parts = new Intl.DateTimeFormat(locale, {
      day: "2-digit",
      month: "2-digit",
      year: "numeric"
    }).formatToParts(sample);
    return parts;
  };

  const toFlatpickrDateFormat = (locale) => {
    const resolved = getResolvedLocale();
    if (/^is(\b|-)/i.test(locale) && !/^is(\b|-)/i.test(resolved)) {
      return "d.m.Y";
    }
    const parts = getLocaleFormatParts(locale);
    const order = [];
    const literals = [];
    parts.forEach((part) => {
      if (part.type === "day") order.push("d");
      if (part.type === "month") order.push("m");
      if (part.type === "year") order.push("Y");
      if (part.type === "literal") literals.push(part.value);
    });
    const forcedSep = /^is(\b|-)/i.test(locale) ? "." : null;
    const sep = forcedSep || literals.find((lit) => /[./-]/.test(lit)) || ".";
    return order.join(sep);
  };

  const getTimeFormat = () => {
    return { time_24hr: true, timeFormat: "H:i" };
  };

  const buildOptions = (input) => {
    const locale = getUserLocale();
    const dateFormat = toFlatpickrDateFormat(locale);
    const { time_24hr, timeFormat } = getTimeFormat();
    const isDateTime = input.getAttribute("type") === "datetime-local";

    return {
      allowInput: true,
      enableTime: isDateTime,
      time_24hr,
      minuteIncrement: 15,
      dateFormat: isDateTime ? "Y-m-d H:i" : "Y-m-d",
      altInput: true,
      altFormat: isDateTime ? `${dateFormat} ${timeFormat}` : dateFormat
    };
  };

  const upgradeInput = (input) => {
    if (!hasFlatpickr()) return;
    if (input.dataset.twFlatpickr === "1") return;
    if (input.dataset.twDateIgnore === "1") return;

    input.dataset.twFlatpickr = "1";
    const options = buildOptions(input);
    window.flatpickr(input, options);
  };

  const initAll = (root) => {
    const scope = root || document;
    scope.querySelectorAll(UPGRADE_SELECTOR).forEach((input) => {
      upgradeInput(input);
    });
  };

  const observeNewInputs = () => {
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (!(node instanceof Element)) return;
          if (node.matches && node.matches(UPGRADE_SELECTOR)) {
            upgradeInput(node);
          }
          if (node.querySelectorAll) {
            node.querySelectorAll(UPGRADE_SELECTOR).forEach((input) => upgradeInput(input));
          }
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  };

  document.addEventListener("DOMContentLoaded", () => {
    initAll();
    observeNewInputs();
  });

  window.twFlatpickrInit = initAll;
})();
