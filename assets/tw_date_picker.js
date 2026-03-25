(function () {
  "use strict";

  let lastInsideInteraction = 0;
  const markInsideInteraction = () => {
    lastInsideInteraction = Date.now();
  };

  const ICON_SVG =
    '<svg viewBox="0 0 24 24" aria-hidden="true">' +
    '<rect x="3" y="5" width="18" height="16" rx="2" ry="2" fill="none" stroke="currentColor" stroke-width="1.5" />' +
    '<line x1="3" y1="9" x2="21" y2="9" stroke="currentColor" stroke-width="1.5" />' +
    '<line x1="8" y1="3" x2="8" y2="7" stroke="currentColor" stroke-width="1.5" />' +
    '<line x1="16" y1="3" x2="16" y2="7" stroke="currentColor" stroke-width="1.5" />' +
    '</svg>';

  const UPGRADE_SELECTOR = 'input[type="date"], input[type="datetime-local"]';

  function getBrowserLocale() {
    return (navigator.languages && navigator.languages[0]) || navigator.language || "en-US";
  }

  function getResolvedLocale(locale) {
    try {
      return new Intl.DateTimeFormat(locale).resolvedOptions().locale;
    } catch (err) {
      return "en-US";
    }
  }

  function formatDateForLocale(date, locale) {
    const resolved = getResolvedLocale(locale);
    const localeMismatch = resolved.toLowerCase() !== String(locale).toLowerCase();
    if (localeMismatch && /^is(\b|-)/i.test(locale)) {
      const day = String(date.getDate()).padStart(2, "0");
      const month = String(date.getMonth() + 1).padStart(2, "0");
      const year = date.getFullYear();
      return `${day}.${month}.${year}`;
    }
    return new Intl.DateTimeFormat(locale, {
      day: "2-digit",
      month: "2-digit",
      year: "numeric"
    }).format(date);
  }

  function formatTimeForLocale(date, locale) {
    const resolved = getResolvedLocale(locale);
    const localeMismatch = resolved.toLowerCase() !== String(locale).toLowerCase();
    if (localeMismatch && /^is(\b|-)/i.test(locale)) {
      const hours = String(date.getHours()).padStart(2, "0");
      const minutes = String(date.getMinutes()).padStart(2, "0");
      return `${hours}:${minutes}`;
    }
    return new Intl.DateTimeFormat(locale, {
      hour: "2-digit",
      minute: "2-digit"
    }).format(date);
  }

  function toISODate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, "0");
    const d = String(date.getDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
  }

  function parseDateInput(value) {
    if (!value) return null;
    const trimmed = value.trim();
    const isoMatch = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (isoMatch) {
      const year = parseInt(isoMatch[1], 10);
      const month = parseInt(isoMatch[2], 10) - 1;
      const day = parseInt(isoMatch[3], 10);
      const date = new Date(year, month, day);
      return Number.isNaN(date.getTime()) ? null : date;
    }
    const localMatch = trimmed.match(/^(\d{1,2})[./](\d{1,2})[./](\d{4})$/);
    if (localMatch) {
      const day = parseInt(localMatch[1], 10);
      const month = parseInt(localMatch[2], 10) - 1;
      const year = parseInt(localMatch[3], 10);
      const date = new Date(year, month, day);
      return Number.isNaN(date.getTime()) ? null : date;
    }
    return null;
  }

  function parseTimeInput(value) {
    if (!value) return null;
    const match = value.trim().match(/^(\d{1,2}):(\d{2})$/);
    if (!match) return null;
    const hours = Math.min(23, Math.max(0, parseInt(match[1], 10)));
    const minutes = Math.min(59, Math.max(0, parseInt(match[2], 10)));
    return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}`;
  }

  function createTimeSelects() {
    const wrapper = document.createElement("div");
    wrapper.className = "tw-date-time";

    const hourSelect = document.createElement("select");
    hourSelect.setAttribute("data-role", "time-hour");
    hourSelect.setAttribute("aria-label", "Hour");
    for (let hour = 0; hour < 24; hour += 1) {
      const value = String(hour).padStart(2, "0");
      const option = document.createElement("option");
      option.value = value;
      option.textContent = value;
      hourSelect.appendChild(option);
    }

    const minuteSelect = document.createElement("select");
    minuteSelect.setAttribute("data-role", "time-minute");
    minuteSelect.setAttribute("aria-label", "Minute");
    ["00", "15", "30", "45"].forEach((value) => {
      const option = document.createElement("option");
      option.value = value;
      option.textContent = value;
      minuteSelect.appendChild(option);
    });

    wrapper.appendChild(hourSelect);
    wrapper.appendChild(minuteSelect);
    return wrapper;
  }

  function dispatchInputEvents(input) {
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function applyDisplayInput(input, display, state) {
    const locale = getBrowserLocale();
    const rawValue = display.value;

    if (!rawValue.trim()) {
      input.value = "";
      display.value = "";
      state.selected = null;
      state.lastDisplay = "";
      state.lastValue = "";
      dispatchInputEvents(input);
      return;
    }

    if (state.type === "datetime-local") {
      const cleaned = rawValue.trim().replace("T", " ");
      const parts = cleaned.split(" ");
      const datePart = parts[0];
      const timePart = parts.slice(1).join(" ");
      const date = parseDateInput(datePart);
      const parsedTime = parseTimeInput(timePart);
      const time = parsedTime || `${state.hour}:${state.minute}` || "10:00";
      if (!date) {
        if (state.lastDisplay) display.value = state.lastDisplay;
        if (state.lastValue) input.value = state.lastValue;
        return;
      }
      state.selected = date;
      state.year = date.getFullYear();
      state.month = date.getMonth();
      const isoDate = toISODate(date);
      input.value = `${isoDate}T${time}`;
      display.value = `${formatDateForLocale(date, locale)} ${time}`;
      state.hour = time.split(":")[0];
      state.minute = time.split(":")[1];
      state.lastDisplay = display.value;
      state.lastValue = input.value;
      dispatchInputEvents(input);
      return;
    }

    const date = parseDateInput(rawValue);
    if (!date) {
      if (state.lastDisplay) display.value = state.lastDisplay;
      if (state.lastValue) input.value = state.lastValue;
      return;
    }
    state.selected = date;
    state.year = date.getFullYear();
    state.month = date.getMonth();
    input.value = toISODate(date);
    display.value = formatDateForLocale(date, locale);
    state.lastDisplay = display.value;
    state.lastValue = input.value;
    dispatchInputEvents(input);
  }

  function renderPicker(input, display, pop, state) {
    const locale = getBrowserLocale();
    const firstDay = new Date(state.year, state.month, 1);
    const startWeekday = (firstDay.getDay() + 6) % 7; // Monday start
    const daysInMonth = new Date(state.year, state.month + 1, 0).getDate();

    const monthLabel = new Intl.DateTimeFormat(locale, { month: "long" }).format(firstDay);
    const yearOptions = [];
    for (let y = state.year - 5; y <= state.year + 5; y += 1) {
      yearOptions.push(`<option value="${y}"${y === state.year ? " selected" : ""}>${y}</option>`);
    }

    const weekdayLabels = [];
    for (let i = 0; i < 7; i += 1) {
      const day = new Date(2020, 5, 1 + i);
      weekdayLabels.push(
        new Intl.DateTimeFormat(locale, { weekday: "short" }).format(day)
      );
    }

    let grid = "";
    weekdayLabels.forEach((label) => {
      grid += `<div class="is-muted">${label}</div>`;
    });

    for (let i = 0; i < startWeekday; i += 1) {
      grid += "<div class=\"is-muted\">.</div>";
    }

    for (let day = 1; day <= daysInMonth; day += 1) {
      const isSelected =
        state.selected &&
        state.selected.getFullYear() === state.year &&
        state.selected.getMonth() === state.month &&
        state.selected.getDate() === day;
      grid += `<button type="button" data-day="${day}" class="${isSelected ? "is-selected" : ""}">${day}</button>`;
    }

    pop.innerHTML = `
      <div class="tw-date-head">
        <div class="tw-date-title">${monthLabel}</div>
        <select data-role="year-select" aria-label="Year">
          ${yearOptions.join("")}
        </select>
        <div class="tw-date-nav">
          <button type="button" class="tw-date-arrow" data-action="prev" aria-label="Previous month">&lt;</button>
          <button type="button" class="tw-date-arrow" data-action="next" aria-label="Next month">&gt;</button>
        </div>
      </div>
      ${state.type === "datetime-local" ? "<div class=\"tw-date-time\"></div>" : ""}
      <div class="tw-date-grid">${grid}</div>
    `;

    if (state.type === "datetime-local") {
      const timeWrap = pop.querySelector(".tw-date-time");
      if (timeWrap) {
        const timeSelects = createTimeSelects();
        timeWrap.replaceWith(timeSelects);
        const hourSelect = timeSelects.querySelector('[data-role="time-hour"]');
        const minuteSelect = timeSelects.querySelector('[data-role="time-minute"]');
        if (hourSelect) hourSelect.value = state.hour || "10";
        if (minuteSelect) minuteSelect.value = state.minute || "00";

        const updateTime = () => {
          if (!state.selected) return;
          const isoDate = toISODate(state.selected);
          const time = `${hourSelect.value}:${minuteSelect.value}`;
          input.value = `${isoDate}T${time}`;
          display.value = `${formatDateForLocale(state.selected, locale)} ${time}`;
          state.hour = hourSelect.value;
          state.minute = minuteSelect.value;
          state.lastDisplay = display.value;
          state.lastValue = input.value;
          dispatchInputEvents(input);
        };

        hourSelect.addEventListener("change", updateTime);
        minuteSelect.addEventListener("change", updateTime);
      }
    }

    const shiftMonth = (dir) => {
      state.month += dir;
      if (state.month < 0) {
        state.month = 11;
        state.year -= 1;
      } else if (state.month > 11) {
        state.month = 0;
        state.year += 1;
      }
      pop.classList.add("is-open");
      renderPicker(input, display, pop, state);
      pop.classList.add("is-open");
    };

    pop.onclick = (event) => {
      const target = event.target;
      if (!target) return;
      const actionBtn = target.closest && target.closest("[data-action]");
      if (actionBtn) {
        event.preventDefault();
        event.stopPropagation();
        markInsideInteraction();
        const dir = actionBtn.dataset.action === "next" ? 1 : -1;
        shiftMonth(dir);
        return;
      }
      const dayBtn = target.closest && target.closest("[data-day]");
      if (dayBtn) {
        event.stopPropagation();
        markInsideInteraction();
      }
    };

    pop.onpointerdown = (event) => {
      const target = event.target;
      if (!target) return;
      const nav = target.closest && target.closest("[data-action]");
      const yearSelect = target.closest && target.closest('[data-role="year-select"]');
      if (nav || yearSelect) {
        event.stopPropagation();
        markInsideInteraction();
      }
    };

    pop.onmousedown = (event) => {
      const target = event.target;
      if (!target) return;
      const nav = target.closest && target.closest("[data-action]");
      const yearSelect = target.closest && target.closest('[data-role="year-select"]');
      if (nav || yearSelect) {
        event.stopPropagation();
        markInsideInteraction();
      }
    };

    pop.onchange = (event) => {
      const target = event.target;
      if (!target) return;
      if (target.matches && target.matches('[data-role="year-select"]')) {
        event.stopPropagation();
        markInsideInteraction();
        state.year = parseInt(target.value, 10);
        renderPicker(input, display, pop, state);
      }
    };

    pop.querySelectorAll("[data-day]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const day = parseInt(btn.dataset.day, 10);
        state.selected = new Date(state.year, state.month, day);
        const isoDate = toISODate(state.selected);
        input.value = isoDate;
        let displayValue = formatDateForLocale(state.selected, locale);
        if (state.type === "datetime-local") {
          const time = `${state.hour || "10"}:${state.minute || "00"}`;
          displayValue = `${displayValue} ${time}`;
          input.value = `${isoDate}T${time}`;
        }
        display.value = displayValue;
        state.lastDisplay = display.value;
        state.lastValue = input.value;
        pop.classList.remove("is-open");
        dispatchInputEvents(input);
      });
    });
  }

  function upgradeInput(input) {
    const type = input.getAttribute("type");
    if (type !== "date" && type !== "datetime-local") return;
    if (input.dataset.twDateEnhanced === "1") return;
    if (input.dataset.twDateIgnore === "1") return;

    input.dataset.twDateEnhanced = "1";
    const parent = input.parentNode;
    if (!parent) return;

    const wrap = document.createElement("span");
    wrap.className = "tw-date-wrap";
    parent.insertBefore(wrap, input);
    wrap.appendChild(input);

    const display = document.createElement("input");
    display.type = "text";
    display.className = `${input.className ? input.className + " " : ""}tw-date-display`;
    display.placeholder = type === "date" ? "dd.MM.yyyy" : "dd.MM.yyyy HH:mm";
    display.autocomplete = "off";
    display.spellcheck = false;
    display.inputMode = "numeric";
    if (input.required) {
      display.required = true;
      input.required = false;
    }
    if (input.disabled) display.disabled = true;
    if (input.readOnly) display.readOnly = true;
    if (input.getAttribute("aria-label")) {
      display.setAttribute("aria-label", input.getAttribute("aria-label"));
    }

    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "tw-date-btn";
    btn.innerHTML = ICON_SVG;
    btn.setAttribute("aria-label", "Open calendar");

    const pop = document.createElement("div");
    pop.className = "tw-date-pop";

    wrap.insertBefore(display, input);
    wrap.insertBefore(btn, input);
    wrap.insertBefore(pop, input);

    input.setAttribute("data-tw-original-type", type);
    input.type = "hidden";
    input.tabIndex = -1;
    input.setAttribute("aria-hidden", "true");

    const now = new Date();
    const state = {
      type,
      selected: null,
      year: now.getFullYear(),
      month: now.getMonth(),
      hour: "10",
      minute: "00",
      lastDisplay: "",
      lastValue: ""
    };

    if (input.value) {
      if (type === "date") {
        const date = parseDateInput(input.value);
        if (date) {
          state.selected = date;
          state.year = date.getFullYear();
          state.month = date.getMonth();
          display.value = formatDateForLocale(date, getBrowserLocale());
          state.lastDisplay = display.value;
          state.lastValue = input.value;
        }
      } else {
        const cleaned = input.value.replace("T", " ");
        const parts = cleaned.split(" ");
        const date = parseDateInput(parts[0]);
        const time = parseTimeInput(parts.slice(1).join(" ")) || "10:00";
        if (date) {
          state.selected = date;
          state.year = date.getFullYear();
          state.month = date.getMonth();
          state.hour = time.split(":")[0];
          state.minute = time.split(":")[1];
          display.value = `${formatDateForLocale(date, getBrowserLocale())} ${time}`;
          state.lastDisplay = display.value;
          state.lastValue = input.value;
        }
      }
    }

    btn.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      markInsideInteraction();
      applyDisplayInput(input, display, state);
      pop.classList.toggle("is-open");
      if (pop.classList.contains("is-open")) {
        renderPicker(input, display, pop, state);
      }
    });

    const stopPop = (event) => {
      event.stopPropagation();
      markInsideInteraction();
    };
    pop.addEventListener("click", stopPop);
    pop.addEventListener("pointerdown", stopPop);
    pop.addEventListener("mousedown", stopPop);

    let touchStartX = null;
    pop.addEventListener("touchstart", (event) => {
      touchStartX = event.changedTouches[0].clientX;
    }, { passive: true });
    pop.addEventListener("touchend", (event) => {
      if (touchStartX == null) return;
      const endX = event.changedTouches[0].clientX;
      const delta = endX - touchStartX;
      touchStartX = null;
      if (Math.abs(delta) < 30) return;
      const dir = delta < 0 ? 1 : -1;
      state.month += dir;
      if (state.month < 0) {
        state.month = 11;
        state.year -= 1;
      } else if (state.month > 11) {
        state.month = 0;
        state.year += 1;
      }
      renderPicker(input, display, pop, state);
    }, { passive: true });

    display.addEventListener("blur", () => applyDisplayInput(input, display, state));
    display.addEventListener("change", () => applyDisplayInput(input, display, state));

    input.addEventListener("change", () => {
      if (!input.value) {
        display.value = "";
        state.selected = null;
        state.lastDisplay = "";
        state.lastValue = "";
        return;
      }
      if (state.type === "date") {
        const date = parseDateInput(input.value);
        if (date) {
          display.value = formatDateForLocale(date, getBrowserLocale());
          state.selected = date;
          state.year = date.getFullYear();
          state.month = date.getMonth();
          state.lastDisplay = display.value;
          state.lastValue = input.value;
        }
      } else {
        const cleaned = input.value.replace("T", " ");
        const parts = cleaned.split(" ");
        const date = parseDateInput(parts[0]);
        const time = parseTimeInput(parts.slice(1).join(" ")) || `${state.hour}:${state.minute}`;
        if (date) {
          display.value = `${formatDateForLocale(date, getBrowserLocale())} ${time}`;
          state.selected = date;
          state.year = date.getFullYear();
          state.month = date.getMonth();
          state.hour = time.split(":")[0];
          state.minute = time.split(":")[1];
          state.lastDisplay = display.value;
          state.lastValue = input.value;
        }
      }
    });
  }

  function closePickersOnOutsideClick(event) {
    if (Date.now() - lastInsideInteraction < 200) return;
    const target = event.target;
    if (target && target.dataset) {
      if (target.dataset.action || target.dataset.day !== undefined || target.dataset.role === "year-select") {
        return;
      }
    }
    if (target && target.closest) {
      if (target.closest(".tw-date-wrap") || target.closest(".tw-date-pop")) return;
    }
    const path = event.composedPath ? event.composedPath() : [];
    const clickedInside = path.some((node) => {
      return node instanceof Element && (node.classList.contains("tw-date-pop") || node.classList.contains("tw-date-wrap"));
    });
    if (clickedInside) return;
    document.querySelectorAll(".tw-date-pop.is-open").forEach((pop) => {
      pop.classList.remove("is-open");
    });
  }

  function initAll(root) {
    const scope = root || document;
    scope.querySelectorAll(UPGRADE_SELECTOR).forEach((input) => {
      upgradeInput(input);
    });
  }

  function observeNewInputs() {
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
  }

  // TODO: restore outside-click closing once nav interactions are stable.
  // document.addEventListener("click", closePickersOnOutsideClick);
  // document.addEventListener("pointerdown", closePickersOnOutsideClick);
  document.addEventListener("DOMContentLoaded", () => {
    initAll();
    observeNewInputs();
  });

  window.twDatePickerInit = initAll;
})();
