<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>About Edge - TextWhisper</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      --tw-ink: #0f172a;
      --tw-muted: #475569;
      --tw-bg: #f8fafc;
      --tw-card: #ffffff;
      --tw-accent: #0f766e;
      --tw-accent-soft: #ccfbf1;
      --tw-border: #e2e8f0;
      --ok: #166534;
      --no: #991b1b;
      --partial: #92400e;
    }

    body {
      background:
        radial-gradient(1200px 500px at 10% -20%, #cffafe 0%, transparent 60%),
        radial-gradient(900px 400px at 90% -10%, #dbeafe 0%, transparent 55%),
        var(--tw-bg);
      color: var(--tw-ink);
      font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
    }

    .edge-wrap {
      max-width: 1100px;
      margin: 2.5rem auto;
      padding: 0 1rem;
    }

    .edge-hero {
      background: linear-gradient(135deg, #ffffff 0%, #f0fdfa 100%);
      border: 1px solid var(--tw-border);
      border-radius: 16px;
      padding: 1.2rem 1.2rem 1rem;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
    }

    .edge-kicker {
      display: inline-block;
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      font-weight: 700;
      color: var(--tw-accent);
      background: var(--tw-accent-soft);
      padding: 0.25rem 0.55rem;
      border-radius: 999px;
      margin-bottom: 0.7rem;
    }

    .edge-title {
      font-size: clamp(1.4rem, 2.5vw, 2rem);
      font-weight: 700;
      margin-bottom: 0.4rem;
    }

    .edge-sub {
      color: var(--tw-muted);
      margin-bottom: 0;
    }

    .legend {
      margin-top: 0.8rem;
      color: var(--tw-muted);
      font-size: 0.95rem;
    }

    .table-card {
      margin-top: 1rem;
      background: var(--tw-card);
      border: 1px solid var(--tw-border);
      border-radius: 14px;
      overflow-x: auto;
      overflow-y: visible;
      -webkit-overflow-scrolling: touch;
      box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
    }

    .edge-table {
      margin-bottom: 0;
      width: 100%;
    }

    .edge-table thead th {
      background: #f1f5f9;
      color: #0b1220;
      font-weight: 700;
      border-bottom: 1px solid var(--tw-border);
    }

    .edge-table td,
    .edge-table th {
      padding: 0.82rem 0.9rem;
      vertical-align: middle;
      border-color: var(--tw-border);
    }

    .edge-table th:first-child,
    .edge-table td:first-child {
      width: 74%;
    }

    .edge-table th:nth-child(2),
    .edge-table td:nth-child(2),
    .edge-table th:nth-child(3),
    .edge-table td:nth-child(3) {
      width: 13%;
      white-space: nowrap;
    }

    .feature-col {
      font-weight: 500;
    }

    .tw-feature-detail summary {
      cursor: pointer;
      font-weight: 600;
      list-style: none;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
    }

    .tw-feature-detail summary::-webkit-details-marker {
      display: none;
    }

    .tw-feature-detail summary::before {
      content: "▸";
      color: var(--tw-accent);
      font-size: 0.95rem;
      line-height: 1;
      transform: translateY(-1px);
      transition: transform 0.18s ease;
    }

    .tw-feature-detail[open] summary::before {
      transform: rotate(90deg) translateX(1px);
    }

    .tw-feature-detail {
      position: relative;
      display: block;
    }

    .tw-detail-body {
      display: none;
      position: static;
      width: auto;
      margin-top: 0.42rem;
      color: var(--tw-muted);
      font-size: 0.9rem;
      line-height: 1.42;
      background: #ffffff;
      border: 1px solid var(--tw-border);
      border-radius: 10px;
      box-shadow: none;
      padding: 0.62rem 0.72rem;
      z-index: 12;
    }

    .tw-feature-detail[open] .tw-detail-body {
      display: block;
    }

    .status {
      display: inline-block;
      min-width: 132px;
      text-align: center;
      font-size: 0.86rem;
      font-weight: 700;
      border-radius: 999px;
      padding: 0.22rem 0.55rem;
      border: 1px solid transparent;
      white-space: nowrap;
    }

    .s-ok {
      color: var(--ok);
      border-color: #86efac;
      background: #f0fdf4;
    }

    .s-no {
      color: var(--no);
      border-color: #fca5a5;
      background: #fef2f2;
    }

    .s-partial {
      color: var(--partial);
      border-color: #fcd34d;
      background: #fffbeb;
    }

    @media (max-width: 760px) {
      .edge-table {
        table-layout: fixed;
      }

      .edge-table td,
      .edge-table th {
        padding: 0.55rem 0.5rem;
        font-size: 0.9rem;
      }

      .feature-col {
        width: 62%;
      }

      .tw-detail-body {
        position: static;
        width: auto;
        margin-top: 0.42rem;
        box-shadow: none;
      }

      .status {
        min-width: 2.2rem;
        padding: 0.2rem 0.45rem;
        font-size: 0;
        line-height: 1;
      }

      .status::before {
        font-size: 0.9rem;
        font-weight: 700;
      }

      .status.s-ok::before { content: "✅"; }
      .status.s-no::before { content: "❌"; }
      .status.s-partial::before { content: "◐"; }
    }

  </style>
</head>
<body>
  <div class="edge-wrap">
    <div class="mb-3" id="edgeBackWrap">
      <button class="btn btn-secondary" onclick="
        if (document.referrer && document.referrer.includes(location.hostname)) {
          history.back();
        } else {
          location.href = '/';
        }
      ">⬅️ Back to TextWhisper</button>
    </div>

    <section class="edge-hero">
      <span class="edge-kicker">Comparison Snapshot</span>
      <h1 class="edge-title">Where TextWhisper Has Edge</h1>
      <p class="edge-sub">A sharing community for performing arts.</p>
      <p class="legend">Legend: ✅ included, ❌ not included, ◐ planned/partial</p>
    </section>

    <section class="table-card table-responsive">
      <table class="table edge-table align-middle">
        <thead>
          <tr>
            <th>Feature</th>
            <th class="text-center">TextWhisper</th>
            <th class="text-center">Other solutions</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="feature-col">Live Play Mode</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Playable notes (MusicXML)</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Visual list tree workflow</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Drag, reorder, and nest content</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">PDF annotation layers</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Text and lyrics learning tools</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Performance order - Seamless navigation</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Rehearsal text annotations</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Open content sharing</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Cross-choir borrowing</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Public-domain growth</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">List chat</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Chat polls</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Media embeds in chat</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Event planner + attendance</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Check-in/check-out</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Offline mode</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">1:1 direct chat</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Independent chat threads</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Group chat management</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Score-select instant playback</td>
            <td class="text-center"><span class="status s-partial">◐ Planned</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">On-screen piano</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Cross-platform file manager</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">"My Work" ownership tracking</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Audio loop modes</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">Native MIDI playback</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">MIDI channel mixer</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
          <tr>
            <td class="feature-col">PDF optimizer</td>
            <td class="text-center"><span class="status s-ok">✅ Included</span></td>
            <td class="text-center"><span class="status s-no">❌ Not Included</span></td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
  <script>
    document.addEventListener("DOMContentLoaded", () => {
      // Hide "Back" when rendered inside an embedded frame (e.g. TW_Home advantages panel).
      if (window.self !== window.top) {
        const backWrap = document.getElementById("edgeBackWrap");
        if (backWrap) backWrap.style.display = "none";
      }

      const twDetails = {
        "Visual list tree workflow":
          "Sidebar tree view makes list structure immediately visible, including items and multi-level sublists. Lists can be viewed in alphabetical order or rehearsal/performance order, and each list provides direct menu access for management, sharing, security/privacy controls, plus chat bubble access into list chat.",
        "Drag, reorder, and nest content":
          "Lists and items are draggable, reorderable, and nestable (including deeper sublevels). This enables fast set reshaping without manual rebuilds.",
        "Open content sharing":
          "TW supports direct links for both lists and single items. These links can be shared outside closed group boundaries, including across common social media channels, making reuse easy across choirs, schools, and collaborators without re-upload workflows.",
        "Cross-choir borrowing":
          "Shared content can be borrowed into another choir or group workflow. This reduces duplicated prep work and lets one group build on material already prepared by another. Borrowed items include their linked objects, such as text, PDFs, audio/music links, and related rehearsal assets. Owners can always track who has their material and for how long.",
        "Public-domain growth":
          "Open sharing enables a growing reusable layer of public-domain repertoire. Over time this creates a practical community library effect instead of each group curating in isolation.",
        "Performance order":
          "Lists can follow real rehearsal or concert set order. Members practice in the same sequence they will perform, which improves transitions and reduces stage friction.",
        "List chat":
          "Each rehearsal/performance list has its own dedicated chat context. Discussion stays tied to the exact material being prepared instead of spreading across unrelated threads.",
        "Chat polls":
          "Polls can be injected directly into chat and voted inline. This keeps scheduling and decision-making inside the same conversation where context already exists.",
        "Media embeds in chat":
          "Links such as YouTube, Spotify, and SoundCloud can render inline as playable embeds. Members can review references immediately without leaving the rehearsal discussion.",
        "Event planner + attendance":
          "The planner combines groups, categories, recurring events, and attendance in one workflow. It reduces tool switching between planning, communication, and follow-up.",
        "Check-in/check-out":
          "Members/admins can register presence directly in event flow with simple status updates. This supports practical rehearsal tracking without extra admin overhead.",
        "Offline mode":
          "Selected lists/items remain usable offline for rehearsal and performance continuity. This is critical for unstable venue networks and mobile/tablet-first use.",
        "1:1 direct chat":
          "Direct member-to-member chat is now available in TW for fast private coordination.",
        "Independent chat threads":
          "From a 1:1 chat, you can add members and create independent group threads separate from list-bound chat context.",
        "Group chat management":
          "Manage each thread with practical controls: rename chat, add/remove members, pause chat participation, and leave chat when needed.",
        "Score-select instant playback":
          "Planned feature: select notation on screen to trigger immediate playback for faster part-checking during rehearsal. Scores are processed in the background into MusicXML and MIDI with location parameters, enabling instant playback from the selected score position.",
        "Live Play Mode":
          "Live Play Mode keeps a group aligned during rehearsal or performance by letting a conductor, teacher, or leader choose what everyone sees in real time. Members do not have to search for the next item or score because the current material is opened for them, ready to practise or perform.",
        "Playable notes (MusicXML)":
          "The MusicXML player provides an interactive score view for practice and checking. It combines audible playback with visual guidance on the notation itself, including a moving playhead, measure progress, part-aware playback, and real-time note display on the onboard piano. It is designed to make a score immediately usable for rehearsal, isolating lines, and starting from a specific note or measure without leaving the main score view.",
        "On-screen piano":
          "Integrated piano for quick pitch checks, note locating, and section practice. Includes key/scale selection, clef-aware visual key-signature display, scale highlighting (with out-of-scale note dimming), and one-tap scale playback for ear training.",
        "Cross-platform file manager":
          "A unified file manager supports cross-source organization and cross-group workflows. Content management is designed for practical reuse at choir scale, not one-off file handling.",
        "\"My Work\" ownership tracking":
          "The 'My Work' view helps owners track where their material is used and by whom. It adds governance and visibility to shared-content distribution over time.",
        "Audio loop modes":
          "Loop modes support both repeating the current track and continuing through list flow. This fits both micro-practice and full run-through rehearsal styles.",
        "Native MIDI playback":
          "MIDI files can be uploaded and played natively without conversion. That preserves flexibility for part-focused rehearsal and arranger/conductor workflows.",
        "MIDI channel mixer":
          "Per-channel controls include mute, solo, level shaping, and activity feedback. Singers can isolate lines and rehearse their part with precision.",
        "Performance order - Seamless navigation":
          "Set your program once, then move through it in perfect sequence—page to page and piece to piece—in a smooth, uninterrupted flow using your desired navigation method, whether that’s swiping left or right, using arrow buttons, tapping or swiping from the corners, working in Continuous or Paged mode, or selecting directly from the sidebar list or tree.",
        "PDF optimizer":
          "Large PDFs can be optimized for smoother tablet/mobile performance. A rollback copy is preserved so optimization can be reversed safely if needed.",
        "PDF annotation layers":
          "Shared owner/admin markings and each member's private notes can coexist on the same score. This keeps one authoritative source while preserving personal rehearsal markings, with no need to download and re-upload files across multiple apps just to make notes.",
        "Text and lyrics learning tools":
          "TW's original core workflow: trim and cloak text for recall practice. Users can progressively hide full words and rehearse from partial cues, shifting from reading to true memory retrieval.",
        "Rehearsal text annotations":
          "Text tab supports private per-user annotations including highlights, comments, and drawings. Anchors are restored robustly with hybrid SimHash fingerprinting so notes survive text changes more reliably."
      };

      document.querySelectorAll(".edge-table tbody .feature-col").forEach((cell) => {
        const feature = (cell.textContent || "").trim();
        const detail = twDetails[feature];
        if (!detail) return;

        const detailsEl = document.createElement("details");
        detailsEl.className = "tw-feature-detail";

        const summaryEl = document.createElement("summary");
        summaryEl.textContent = feature;

        const bodyEl = document.createElement("div");
        bodyEl.className = "tw-detail-body";
        bodyEl.textContent = detail;

        detailsEl.appendChild(summaryEl);
        detailsEl.appendChild(bodyEl);
        cell.textContent = "";
        cell.appendChild(detailsEl);
      });
    });
  </script>
</body>
</html>
