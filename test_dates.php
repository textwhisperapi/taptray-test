<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Date Picker Test</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <!-- test-only custom picker styles -->
  <style>
    :root {
      --ink: #1f1b16;
      --muted: #6c6357;
      --accent: #2a62e2;
      --bg: #f6f1e6;
      --card: #ffffff;
      --stroke: #e5dccf;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Trebuchet MS", "Gill Sans", "Helvetica Neue", sans-serif;
      color: var(--ink);
      background: var(--bg);
      padding: 24px;
    }
    .card {
      max-width: 760px;
      margin: 0 auto;
      background: var(--card);
      border: 1px solid var(--stroke);
      border-radius: 16px;
      padding: 18px;
      display: grid;
      gap: 12px;
    }
    h1 { margin: 0 0 6px; }
    label { font-size: 12px; color: var(--muted); display: grid; gap: 6px; }
    input, select {
      border-radius: 12px;
      border: 1px solid rgba(20, 17, 12, 0.15);
      padding: 8px 12px;
      font-size: 14px;
    }
    input[type="date"],
    input[type="datetime-local"],
    input[type="time"] {
      min-height: 40px;
      padding-right: 36px;
      accent-color: var(--accent);
    }
    .row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
    .tw-simple {
      display: grid;
      gap: 8px;
      width: 220px;
    }
    .tw-simple-input-wrap {
      position: relative;
      display: block;
    }
    .tw-simple-input {
      width: 100%;
      height: 32px;
      line-height: 32px;
      padding: 0 30px 0 8px;
      border-radius: 8px;
    }
    .tw-simple-toggle {
      position: absolute;
      right: 1px;
      top: 1px;
      bottom: 1px;
      border-radius: 7px;
      border: 1px solid rgba(20, 17, 12, 0.15);
      background: #fff;
      width: 28px;
      padding: 0;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .tw-simple-toggle svg {
      width: 16px;
      height: 16px;
      display: block;
    }
    .tw-simple-input::placeholder {
      font-size: 12px;
      color: var(--muted);
    }
    .tw-simple-panel {
      border: 1px solid var(--stroke);
      border-radius: 12px;
      padding: 10px;
      background: #fff;
      box-shadow: 0 10px 24px rgba(18, 16, 12, 0.12);
      display: none;
    }
    .tw-simple-panel.is-open {
      display: block;
    }
    .tw-simple-head {
      display: grid;
      grid-template-columns: 1fr 90px;
      gap: 8px;
      align-items: center;
      margin-bottom: 8px;
    }
    .tw-simple-time {
      display: grid;
      grid-template-columns: repeat(2, minmax(80px, 1fr));
      gap: 6px;
      margin: 8px 0 4px;
    }
    .tw-simple-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
      gap: 4px;
      text-align: center;
      font-size: 12px;
    }
    .tw-simple-grid button {
      border: none;
      background: transparent;
      border-radius: 8px;
      padding: 6px 0;
      cursor: pointer;
    }
    .tw-simple-grid button:hover {
      background: rgba(42, 98, 226, 0.12);
    }
    .tw-simple-grid .is-muted {
      color: var(--muted);
    }
    .tw-simple-grid .is-selected {
      background: rgba(42, 98, 226, 0.2);
      font-weight: 600;
    }
    .mono {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: 12px;
      background: #f2efe8;
      padding: 10px;
      border-radius: 12px;
      border: 1px solid var(--stroke);
      white-space: pre-wrap;
      margin: 0;
    }
    .hint { color: var(--muted); font-size: 12px; }
  </style>
</head>
<body>
  <main class="card">
    <h1>Date Picker Test</h1>
    <div class="hint">Use this page to compare native pickers and locale formatting.</div>

    <div class="row">
      <label>
        Date
        <input type="date" id="dateInput">
      </label>
      <label>
        Time
        <input type="time" id="timeInput">
      </label>
      <label>
        Datetime local
        <input type="datetime-local" id="dateTimeInput">
      </label>
    </div>

    <div class="row">
      <label>
        Flatpickr date
        <input type="text" id="flatpickrDate" placeholder="dd.MM.yyyy">
      </label>
      <label>
        Flatpickr datetime
        <input type="text" id="flatpickrDateTime" placeholder="dd.MM.yyyy HH:mm">
      </label>
    </div>

    <div class="row">
      <label>
        Custom date (locale-aware)
        <div class="tw-simple" data-picker="customDate" data-mode="date">
          <div class="tw-simple-input-wrap">
            <input type="text" class="tw-simple-input" placeholder="dd.MM.yyyy" required>
            <button type="button" class="tw-simple-toggle" aria-label="Open calendar">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <rect x="3" y="5" width="18" height="16" rx="2" ry="2" fill="none" stroke="currentColor" stroke-width="1.5"/>
                <line x1="3" y1="9" x2="21" y2="9" stroke="currentColor" stroke-width="1.5"/>
                <line x1="8" y1="3" x2="8" y2="7" stroke="currentColor" stroke-width="1.5"/>
                <line x1="16" y1="3" x2="16" y2="7" stroke="currentColor" stroke-width="1.5"/>
              </svg>
            </button>
          </div>
          <input type="hidden" class="tw-simple-value">
          <div class="tw-simple-panel">
            <div class="tw-simple-head">
              <select data-role="month" aria-label="Month"></select>
              <select data-role="year" aria-label="Year"></select>
            </div>
            <div class="tw-simple-grid" data-role="grid"></div>
          </div>
        </div>
      </label>
      <label>
        Custom datetime (locale-aware)
        <div class="tw-simple" data-picker="customDateTime" data-mode="datetime">
          <div class="tw-simple-input-wrap">
            <input type="text" class="tw-simple-input" placeholder="dd.MM.yyyy HH:mm" required>
            <button type="button" class="tw-simple-toggle" aria-label="Open calendar">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <rect x="3" y="5" width="18" height="16" rx="2" ry="2" fill="none" stroke="currentColor" stroke-width="1.5"/>
                <line x1="3" y1="9" x2="21" y2="9" stroke="currentColor" stroke-width="1.5"/>
                <line x1="8" y1="3" x2="8" y2="7" stroke="currentColor" stroke-width="1.5"/>
                <line x1="16" y1="3" x2="16" y2="7" stroke="currentColor" stroke-width="1.5"/>
              </svg>
            </button>
          </div>
          <input type="hidden" class="tw-simple-value">
          <div class="tw-simple-panel">
            <div class="tw-simple-head">
              <select data-role="month" aria-label="Month"></select>
              <select data-role="year" aria-label="Year"></select>
            </div>
            <div class="tw-simple-time">
              <select data-role="hour" aria-label="Hour"></select>
              <select data-role="minute" aria-label="Minute">
                <option value="00">00</option>
                <option value="15">15</option>
                <option value="30">30</option>
                <option value="45">45</option>
              </select>
            </div>
            <div class="tw-simple-grid" data-role="grid"></div>
          </div>
        </div>
      </label>
    </div>

    <label>
      Intl locale override (optional)
      <select id="localeSelect">
        <option value="">(default)</option>
        <option value="is-IS">is-IS</option>
        <option value="en-US">en-US</option>
        <option value="en-GB">en-GB</option>
        <option value="de-DE">de-DE</option>
      </select>
    </label>

    <label>
      HTML lang override (optional)
      <select id="htmlLangSelect">
        <option value="">(default)</option>
        <option value="is-IS">is-IS</option>
        <option value="is">is</option>
        <option value="en-US">en-US</option>
        <option value="en">en</option>
      </select>
    </label>

    <div>
      <div class="hint">Environment</div>
      <pre class="mono" id="envOutput">Loading...</pre>
    </div>

    <div>
      <div class="hint">Formatted output</div>
      <pre class="mono" id="fmtOutput">Pick a date/time to see formatted results.</pre>
    </div>

    <div>
      <div class="hint">Intl resolved options</div>
      <pre class="mono" id="intlOutput">Loading...</pre>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script>
    const dateInput = document.getElementById("dateInput");
    const timeInput = document.getElementById("timeInput");
    const dateTimeInput = document.getElementById("dateTimeInput");
    const localeSelect = document.getElementById("localeSelect");
    const htmlLangSelect = document.getElementById("htmlLangSelect");
    const envOutput = document.getElementById("envOutput");
    const fmtOutput = document.getElementById("fmtOutput");
    const intlOutput = document.getElementById("intlOutput");
    const pickerStates = {};
    const flatpickrDate = document.getElementById("flatpickrDate");
    const flatpickrDateTime = document.getElementById("flatpickrDateTime");

    function getBrowserLocale() {
      return (navigator.languages && navigator.languages[0]) || navigator.language || "en-US";
    }

    function getLocale() {
      return localeSelect.value || getBrowserLocale();
    }

    function applyHtmlLang() {
      document.documentElement.lang = htmlLangSelect.value || "";
    }

    function setEnv() {
      const resolved = new Intl.DateTimeFormat().resolvedOptions();
      const env = {
        language: navigator.language,
        languages: navigator.languages,
        resolvedLocale: resolved.locale,
        timeZone: resolved.timeZone,
        htmlLang: document.documentElement.lang
      };
      envOutput.textContent = JSON.stringify(env, null, 2);
    }

    function setIntlResolved() {
      const samples = {
        default: new Intl.DateTimeFormat().resolvedOptions(),
        "is-IS": new Intl.DateTimeFormat("is-IS").resolvedOptions(),
        "en-US": new Intl.DateTimeFormat("en-US").resolvedOptions(),
        "en-GB": new Intl.DateTimeFormat("en-GB").resolvedOptions()
      };
      intlOutput.textContent = JSON.stringify(samples, null, 2);
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

    function buildMonthOptions(select, locale) {
      select.innerHTML = "";
      for (let i = 0; i < 12; i += 1) {
        const label = new Intl.DateTimeFormat(locale, { month: "long" }).format(new Date(2020, i, 1));
        const option = document.createElement("option");
        option.value = String(i);
        option.textContent = label;
        select.appendChild(option);
      }
    }

    function buildYearOptions(select, year) {
      select.innerHTML = "";
      for (let y = year - 5; y <= year + 5; y += 1) {
        const option = document.createElement("option");
        option.value = String(y);
        option.textContent = String(y);
        select.appendChild(option);
      }
    }

    function renderSimpleGrid(picker, state) {
      const locale = getLocale();
      const grid = picker.querySelector('[data-role="grid"]');
      if (!grid) return;
      grid.innerHTML = "";

      const firstDay = new Date(state.year, state.month, 1);
      const startWeekday = (firstDay.getDay() + 6) % 7;
      const daysInMonth = new Date(state.year, state.month + 1, 0).getDate();

      for (let i = 0; i < 7; i += 1) {
        const day = new Date(2020, 5, 1 + i);
        const label = new Intl.DateTimeFormat(locale, { weekday: "short" }).format(day);
        const cell = document.createElement("div");
        cell.className = "is-muted";
        cell.textContent = label;
        grid.appendChild(cell);
      }

      for (let i = 0; i < startWeekday; i += 1) {
        const cell = document.createElement("div");
        cell.className = "is-muted";
        cell.textContent = ".";
        grid.appendChild(cell);
      }

      for (let day = 1; day <= daysInMonth; day += 1) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.dataset.day = String(day);
        btn.textContent = String(day);
        if (
          state.selected &&
          state.selected.getFullYear() === state.year &&
          state.selected.getMonth() === state.month &&
          state.selected.getDate() === day
        ) {
          btn.classList.add("is-selected");
        }
        grid.appendChild(btn);
      }
    }

    function updateSimpleDisplay(picker, state) {
      const display = picker.querySelector(".tw-simple-input");
      const valueInput = picker.querySelector(".tw-simple-value");
      if (!display || !valueInput || !state.selected) return;
      const locale = getLocale();
      const isoDate = toISODate(state.selected);
      if (state.mode === "datetime") {
        const time = `${state.hour}:${state.minute}`;
        valueInput.value = `${isoDate}T${time}`;
        display.value = `${formatDateForLocale(state.selected, locale)} ${time}`;
      } else {
        valueInput.value = isoDate;
        display.value = formatDateForLocale(state.selected, locale);
      }
      state.lastDisplay = display.value;
      state.lastValue = valueInput.value;
      formatOutput();
    }

    function syncSimpleFromInput(picker, state, rawValue) {
      const display = picker.querySelector(".tw-simple-input");
      const valueInput = picker.querySelector(".tw-simple-value");
      if (!display || !valueInput) return;

      if (state.mode === "datetime") {
        const cleaned = rawValue.trim().replace("T", " ");
        const parts = cleaned.split(" ");
        const datePart = parts[0];
        const timePart = parts.slice(1).join(" ");
        const date = parseDateInput(datePart);
        const parsedTime = parseTimeInput(timePart);
        const time = parsedTime || `${state.hour}:${state.minute}` || "10:00";
        if (!date) {
          if (state.lastDisplay) display.value = state.lastDisplay;
          if (state.lastValue) valueInput.value = state.lastValue;
          return;
        }
        state.selected = date;
        state.year = date.getFullYear();
        state.month = date.getMonth();
        state.hour = time.split(":")[0];
        state.minute = time.split(":")[1];
        updateSimpleDisplay(picker, state);
        return;
      }

      const date = parseDateInput(rawValue);
      if (!date) {
        if (state.lastDisplay) display.value = state.lastDisplay;
        if (state.lastValue) valueInput.value = state.lastValue;
        return;
      }
      state.selected = date;
      state.year = date.getFullYear();
      state.month = date.getMonth();
      updateSimpleDisplay(picker, state);
    }

    function setupPickers() {
      document.querySelectorAll(".tw-simple").forEach((picker) => {
        const mode = picker.dataset.mode;
        const display = picker.querySelector(".tw-simple-input");
        const valueInput = picker.querySelector(".tw-simple-value");
        const panel = picker.querySelector(".tw-simple-panel");
        const toggleBtn = picker.querySelector(".tw-simple-toggle");
        const monthSelect = picker.querySelector('[data-role="month"]');
        const yearSelect = picker.querySelector('[data-role="year"]');
        const hourSelect = picker.querySelector('[data-role="hour"]');
        const minuteSelect = picker.querySelector('[data-role="minute"]');
        const today = new Date();

        pickerStates[picker.dataset.picker] = {
          mode,
          year: today.getFullYear(),
          month: today.getMonth(),
          selected: today,
          hour: "10",
          minute: "00",
          lastDisplay: "",
          lastValue: ""
        };

        const state = pickerStates[picker.dataset.picker];
        buildMonthOptions(monthSelect, getLocale());
        buildYearOptions(yearSelect, state.year);
        monthSelect.value = String(state.month);
        yearSelect.value = String(state.year);

        if (mode === "datetime" && hourSelect) {
          for (let hour = 0; hour < 24; hour += 1) {
            const value = String(hour).padStart(2, "0");
            const option = document.createElement("option");
            option.value = value;
            option.textContent = value;
            hourSelect.appendChild(option);
          }
          hourSelect.value = state.hour;
          if (minuteSelect) minuteSelect.value = state.minute;
        }

        renderSimpleGrid(picker, state);
        updateSimpleDisplay(picker, state);

        monthSelect.addEventListener("change", () => {
          state.month = parseInt(monthSelect.value, 10);
          renderSimpleGrid(picker, state);
        });
        yearSelect.addEventListener("change", () => {
          state.year = parseInt(yearSelect.value, 10);
          renderSimpleGrid(picker, state);
        });
        picker.querySelector('[data-role="grid"]').addEventListener("click", (event) => {
          event.preventDefault();
          event.stopPropagation();
          const target = event.target;
          if (!target || !target.dataset.day) return;
          const day = parseInt(target.dataset.day, 10);
          state.selected = new Date(state.year, state.month, day);
          updateSimpleDisplay(picker, state);
          renderSimpleGrid(picker, state);
          if (panel) panel.classList.remove("is-open");
          display.blur();
        });
        if (mode === "datetime" && hourSelect && minuteSelect) {
          const updateTime = () => {
            state.hour = hourSelect.value;
            state.minute = minuteSelect.value;
            updateSimpleDisplay(picker, state);
          };
          hourSelect.addEventListener("change", updateTime);
          minuteSelect.addEventListener("change", updateTime);
        }

        display.addEventListener("click", () => {
          panel?.classList.toggle("is-open");
        });
        toggleBtn?.addEventListener("click", (event) => {
          event.preventDefault();
          panel?.classList.toggle("is-open");
        });
        display.addEventListener("change", () => syncSimpleFromInput(picker, state, display.value));
        display.addEventListener("blur", () => syncSimpleFromInput(picker, state, display.value));
        if (valueInput) {
          valueInput.value = state.lastValue;
        }
      });

      document.addEventListener("click", (event) => {
        document.querySelectorAll(".tw-simple-panel.is-open").forEach((panel) => {
          if (!panel.contains(event.target) && !panel.closest(".tw-simple").contains(event.target)) {
            panel.classList.remove("is-open");
          }
        });
      });
    }

    function formatOutput() {
      const locale = getLocale();
      const browserLocale = getBrowserLocale();
      const resolvedLocale = getResolvedLocale(browserLocale);
      const localeMismatch = resolvedLocale.toLowerCase() !== String(browserLocale).toLowerCase();
      const parts = [];
      const dateValue = dateInput.value;
      const timeValue = timeInput.value;
      const dateTimeValue = dateTimeInput.value;
      const customDateValue = document.querySelector('[data-picker="customDate"] .tw-simple-value')?.value || "";
      const customDateTimeValue = document.querySelector('[data-picker="customDateTime"] .tw-simple-value')?.value || "";

      if (dateValue) {
        const date = new Date(dateValue + "T00:00");
        const fmt = `${formatDateForLocale(date, locale)} ${formatTimeForLocale(date, locale)}`;
        parts.push(`date input value: ${dateValue}`);
        parts.push(`formatted: ${fmt}`);
      }

      if (timeValue) {
        const date = new Date("1970-01-01T" + timeValue);
        const fmt = formatTimeForLocale(date, locale);
        parts.push(`time input value: ${timeValue}`);
        parts.push(`formatted: ${fmt}`);
      }

      if (dateTimeValue) {
        const date = new Date(dateTimeValue);
        const fmt = `${formatDateForLocale(date, locale)} ${formatTimeForLocale(date, locale)}`;
        parts.push(`datetime-local value: ${dateTimeValue}`);
        parts.push(`formatted: ${fmt}`);
      }

      if (customDateValue) {
        const date = new Date(customDateValue + "T00:00");
        parts.push(`custom date value: ${customDateValue}`);
        parts.push(`formatted: ${formatDateForLocale(date, locale)}`);
      }

      if (customDateTimeValue) {
        const date = new Date(customDateTimeValue);
        parts.push(`custom datetime value: ${customDateTimeValue}`);
        parts.push(`formatted: ${formatDateForLocale(date, locale)} ${formatTimeForLocale(date, locale)}`);
      }

      parts.unshift(
        `browser locale: ${browserLocale} (resolved: ${resolvedLocale})`
      );
      fmtOutput.textContent = parts.length ? parts.join("\n") : "Pick a date/time to see formatted results.";
    }

    [dateInput, timeInput, dateTimeInput, localeSelect].forEach((el) => {
      el.addEventListener("input", formatOutput);
      el.addEventListener("change", formatOutput);
    });

    htmlLangSelect.addEventListener("change", () => {
      applyHtmlLang();
      setEnv();
      setIntlResolved();
      formatOutput();
      document.querySelectorAll(".tw-simple").forEach((picker) => {
        const monthSelect = picker.querySelector('[data-role="month"]');
        const yearSelect = picker.querySelector('[data-role="year"]');
        const state = pickerStates[picker.dataset.picker];
        if (!monthSelect || !yearSelect || !state) return;
        buildMonthOptions(monthSelect, getLocale());
        buildYearOptions(yearSelect, state.year);
        monthSelect.value = String(state.month);
        yearSelect.value = String(state.year);
        renderSimpleGrid(picker, state);
        updateSimpleDisplay(picker, state);
      });
    });

    applyHtmlLang();
    setEnv();
    setIntlResolved();
    setupPickers();
    if (flatpickrDate) {
      flatpickrDate.flatpickr?.destroy?.();
      flatpickr(flatpickrDate, {
        dateFormat: "d.m.Y",
        allowInput: true
      });
    }
    if (flatpickrDateTime) {
      flatpickrDateTime.flatpickr?.destroy?.();
      flatpickr(flatpickrDateTime, {
        enableTime: true,
        time_24hr: true,
        minuteIncrement: 15,
        dateFormat: "d.m.Y H:i",
        allowInput: true
      });
    }

    formatOutput();
  </script>
</body>
</html>
