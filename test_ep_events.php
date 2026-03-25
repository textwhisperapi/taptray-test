<?php
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Event Category Test</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    .ep-category-wrap {
      position: relative;
      display: inline-block;
    }
    .ep-category-input {
      width: 240px;
      padding: 6px 8px;
    }
    .ep-category-list {
      position: absolute;
      left: 0;
      right: 0;
      top: calc(100% + 4px);
      border: 1px solid #ccc;
      background: #fff;
      max-height: 200px;
      overflow: auto;
      display: none;
      z-index: 5;
    }
    .ep-category-list.is-open {
      display: block;
    }
    .ep-category-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 6px 8px;
      gap: 8px;
      cursor: pointer;
    }
    .ep-category-row:hover {
      background: #f3f3f3;
    }
    .ep-category-row.is-active {
      background: #e7f0ff;
    }
    .ep-category-remove {
      border: none;
      background: none;
      color: #c0392b;
      cursor: pointer;
      font-size: 14px;
    }
  </style>
</head>
<body>
  <form id="epCategoryForm">
    <label>
      Category (editable dropdown):
      <span class="ep-category-wrap">
        <input type="text" name="category" class="ep-category-input" placeholder="Choose or type" autocomplete="off">
        <div class="ep-category-list" id="epCategoryList"></div>
      </span>
    </label>
  </form>
  <script>
    const listEl = document.getElementById("epCategoryList");
    const inputEl = document.querySelector('input[name="category"]');
    const storageKey = "epCategoryOptions";

    function loadOptions() {
      const raw = localStorage.getItem(storageKey);
      const options = raw ? JSON.parse(raw) : [];
      listEl.innerHTML = options.map((opt) => `
        <div class="ep-category-row" data-value="${opt}">
          <span>${opt}</span>
          <button type="button" class="ep-category-remove" data-remove="${opt}">x</button>
        </div>
      `).join("");
      listEl.classList.toggle("is-open", options.length > 0);
      const query = inputEl.value.trim().toLowerCase().replace(/\s+/g, " ");
      if (!query) return;
      const rows = Array.from(listEl.querySelectorAll("[data-value]"));
      rows.forEach((row) => row.classList.remove("is-active"));
      const match = rows.find((row) => {
        const value = (row.dataset.value || "").trim().toLowerCase().replace(/\s+/g, " ");
        return value.includes(query);
      });
      if (match) {
        match.classList.add("is-active");
        requestAnimationFrame(() => match.scrollIntoView({ block: "nearest" }));
      }
    }

    function saveOption(value) {
      const next = value.trim();
      if (!next) return;
      const raw = localStorage.getItem(storageKey);
      const options = raw ? JSON.parse(raw) : [];
      if (options.includes(next)) return;
      options.push(next);
      localStorage.setItem(storageKey, JSON.stringify(options));
      loadOptions();
    }

    function removeOption(value) {
      const target = value.trim();
      if (!target) return;
      const raw = localStorage.getItem(storageKey);
      const options = raw ? JSON.parse(raw) : [];
      const next = options.filter((opt) => opt !== target);
      localStorage.setItem(storageKey, JSON.stringify(next));
      loadOptions();
    }

    inputEl.addEventListener("focus", loadOptions);
    inputEl.addEventListener("input", loadOptions);
    inputEl.addEventListener("change", (e) => {
      saveOption(e.target.value);
      listEl.classList.remove("is-open");
    });
    inputEl.addEventListener("keydown", (e) => {
      if (e.key !== "Enter") return;
      e.preventDefault();
      const first = listEl.querySelector("[data-value]");
      if (first) {
        inputEl.value = first.dataset.value || "";
      }
      saveOption(inputEl.value);
      listEl.classList.remove("is-open");
    });
    inputEl.addEventListener("blur", (e) => {
      saveOption(e.target.value);
      setTimeout(() => listEl.classList.remove("is-open"), 100);
    });
    listEl.addEventListener("click", (e) => {
      const remove = e.target.closest("[data-remove]");
      if (remove) {
        removeOption(remove.dataset.remove || "");
        listEl.classList.add("is-open");
        return;
      }
      const row = e.target.closest("[data-value]");
      if (row) {
        inputEl.value = row.dataset.value || "";
        listEl.classList.remove("is-open");
      }
    });
    document.addEventListener("click", (e) => {
      if (!e.target.closest(".ep-category-wrap")) {
        listEl.classList.remove("is-open");
      }
    });
    loadOptions();
  </script>
</body>
</html>
