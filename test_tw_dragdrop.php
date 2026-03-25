<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>TW Audio Drag – Visual Test</title>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

<style>
  body {
    font-family: system-ui, sans-serif;
    display: flex;
    gap: 24px;
    padding: 24px;
  }

  .pane {
    width: 360px;
    border: 1px solid #ccc;
    padding: 12px;
    border-radius: 6px;
  }

  h3 {
    margin: 0 0 12px 0;
    font-size: 16px;
  }

  ul {
    list-style: none;
    padding-left: 0;
    margin: 0;
  }

  li {
    padding: 6px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 6px;
    cursor: grab;
    background: #fafafa;
  }

  .pdf {
    background: #f1f4ff;
  }

  .audio {
    background: #f1fff4;
  }

  /* TW styles */
  .list-sub-item {
    background: #eef1ff;
    margin-bottom: 6px;
  }

  .tw-audio-children {
    margin-left: 18px;
    margin-top: 4px;
  }

  .tw-audio-line {
    font-size: 13px;
    padding: 4px 6px;
    margin-bottom: 4px;
    background: #eefbea;
    border: 1px solid #cde8d3;
    border-radius: 4px;
  }
</style>
</head>

<body>

<!-- ================= DRIVE ================= -->
<div class="pane">
  <h3>Google Drive (mock)</h3>
  <ul id="drive">
    <li class="pdf" data-type="pdf" data-name="Rehearsal Notes.pdf">
      📄 Rehearsal Notes.pdf
    </li>
    <li class="audio" data-type="audio" data-name="Verse.mp3">
      🎵 Verse.mp3
    </li>
    <li class="audio" data-type="audio" data-name="Chorus.mp3">
      🎵 Chorus.mp3
    </li>
  </ul>
</div>

<!-- ================= TW ================= -->
<div class="pane">
  <h3>TextWhisper (mock)</h3>
  <ul id="tw" class="list-contents">
    <li class="list-sub-item" data-value="101">
      📄 Existing PDF
      <div class="tw-audio-children"></div>
    </li>
  </ul>
</div>

<script>
/* ================= DRIVE ================= */

new Sortable(document.getElementById("drive"), {
  group: {
    name: "files",
    pull: "clone",
    put: false
  },
  sort: false,
  animation: 150
});

/* ================= TW ================= */

new Sortable(document.getElementById("tw"), {
  group: {
    name: "files",
    pull: false,
    put: true
  },
  sort: true,
  animation: 150,

  onAdd(evt) {
    const li   = evt.item;
    const type = li.dataset.type;
    const name = li.dataset.name;

    /* ========== AUDIO DROP ========== */
    if (type === "audio") {
      // const pdfEl = evt.target.closest(".list-sub-item");
      const list = evt.to;
      const dropIndex = evt.newIndex;

      // Find the PDF item ABOVE the drop position
      const pdfEl = list.children[dropIndex - 1];

      if (!pdfEl) {
        alert("Drop audio onto a PDF");
        li.remove();
        return;
      }

      const audioRow = document.createElement("div");
      audioRow.className = "tw-audio-line";
      audioRow.textContent = "🎵 " + name;

      pdfEl.querySelector(".tw-audio-children")
           .appendChild(audioRow);

      li.remove();
      return;
    }

    /* ========== PDF DROP ========== */
    if (type === "pdf") {
      const pdfRow = document.createElement("li");
      pdfRow.className = "list-sub-item";
      pdfRow.dataset.value = Math.random().toString(36).slice(2);
      pdfRow.innerHTML = `
        📄 ${name}
        <div class="tw-audio-children"></div>
      `;

      evt.to.appendChild(pdfRow);
      li.remove();
      return;
    }

    li.remove();
  }
});
</script>

</body>
</html>
