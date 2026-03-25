<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>About TextWhisper</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
  <div class="container mt-5">
    <p>
      <button class="btn btn-secondary" onclick="
        if (document.referrer && document.referrer.includes(location.hostname)) {
          history.back();
        } else {
          location.href = '/';
        }
      ">⬅️ Back to TextWhisper</button>
    </p>

    <h1 class="text-center">About TextWhisper</h1>
    <p class="lead text-center">
      TextWhisper is a rehearsal, preparation, and performance platform for choirs and performing-arts groups, combining shared scores and rehearsal lists with layered personal annotations, integrated audio/MIDI tools, event planning with calendar, full offline support, and stage-ready performance lists that replace paper scores.
    </p>

    <p><strong>TextWhisper – Smarter Memory, Anywhere</strong></p>
    
    <p>
      Welcome to <strong>TextWhisper 3.6</strong>.
    </p>
    <p>
      Established platform capabilities include:
    </p>
    <ul>
      <li><strong>Unified File Manager</strong> – create and organize items, and import files from your device, TextWhisper, Dropbox, Google Drive, and OneDrive.</li>
      <li><strong>Drag-and-drop organization</strong> – reorder items, nest files, and import content seamlessly.</li>
      <li><strong>Layered personal annotations</strong> – highlights, comments, and drawing tools per user.</li>
      <li><strong>Role-based access</strong> – secure owner/admin controls across text, files, and media.</li>
      <li><strong>1:1 direct chat</strong> – Direct member-to-member chat is now available in TW for fast private coordination.</li>
      <li><strong>Independent chat threads</strong> – From a 1:1 chat, you can add members and create independent group threads separate from list-bound chat context.</li>
      <li><strong>On-screen piano</strong> – Integrated piano for quick pitch checks, note locating, and section practice. .</li>
      <li><strong>Live Play Mode</strong> – For collaborative rehearsal and performance, a conductor can choose what the group sees in real time, so members do not have to search for the next item or score during rehearsal or on stage.</li>
      <li><strong>MusicXML player</strong> – Interactive score playback with a moving playhead, clickable notes, real-time piano feedback, voice focus, speed control, and quick start from any note or measure.</li>
      <li><strong>"My Work" ownership tracking</strong> – The 'My Work' view helps owners track where their material is used and by whom.</li>
      <li><strong>PDF optimizer</strong> – Large PDFs can be optimized for smoother tablet/mobile performance.</li>
    </ul>
    <p>
      Recent additions in this TextWhisper test build:
    </p>

    <ul>
      <li><strong>Event Planner expanded</strong> – Event Groups, Categories, category colors, recurring events, check-in/check-out, and attendance reporting.</li>
      <li><strong>Built-in Event Polls</strong> – create polls, vote live, and share polls directly in chat.</li>
      <li><strong>File Manager: My Work</strong> – see who has your items and who follows your lists (owner/admin scoped).</li>
      <li><strong>New skins and UI refresh</strong> – cleaner styling and improved readability.</li>
      <li><strong>Resizable workspace</strong> – draggable sidebar and draggable split panes on desktop text view.</li>
      <li><strong>Home dropdown navigation</strong> – jump to your profile or recently used contexts.</li>
      <li><strong>Group menu sharing</strong> – <strong>Share Group</strong> is now available in sidebar group menus (under <strong>Create new list</strong> in Main Group headers, and under <strong>General Chat</strong> in My Groups).</li>
      <li><strong>Signed-in identity cue</strong> – avatar replaces the settings gear in the header.</li>
      <li><strong>Music tab update</strong> – Add Music now includes <strong>This device</strong>.</li>
      <li><strong>Text metadata and history</strong> – shows owner/last editor and stores edit history.</li>
      <li><strong>PDF navigation and drawing updates</strong> – configurable arrows for Continuous/Paged views, cross-item boundary navigation, and improved slur/arc marking.</li>
      <li><strong>Stability updates</strong> – more reliable offline/cache and menu behavior.</li>
    </ul>


    <h2>Purpose</h2>
    <p>
      TextWhisper is a rehearsal, preparation, and performance platform for choirs, ensembles, and other performing-arts groups.
      It supports real readiness for rehearsal and stage use by keeping everyone working from the same shared material,
      while still allowing each performer to prepare in their own way.
    </p>

    <p>
      Memory, recall, and confidence are outcomes of the rehearsal process — not isolated goals.
      TextWhisper strengthens preparation by reducing friction, confusion, and duplicated work,
      so rehearsals can focus on music rather than logistics.
    </p>

    <h3>What TextWhisper Is (Canonical)</h3>
    <p>
      TextWhisper combines <strong>centralized score distribution</strong> with
      <strong>layered, individually owned annotations</strong>.
      Conductors or group leadership can publish scores, markings, comments, and rehearsal guidance
      directly into a shared source.
      Each performer then works on top of that same source using their own private highlights,
      comments, drawings, and practice tools.
    </p>

    <p>
      This layered model ensures that everyone rehearses and performs from the same authoritative material,
      while preserving personal preparation styles.
      There is no need for members to download files, upload scores into third-party apps,
      or manage multiple annotated copies of the same material.
    </p>

    <p>
      TextWhisper also includes an integrated event and rehearsal planner with
      groups, calendar, categories, and attendance check-in/check-out.
      Advanced rehearsal tools — including audio and MIDI playback, role-aware visibility,
      and full offline support — are built directly into the platform.
    </p>

    <h3>Design Principles</h3>
    <ul>
      <li><strong>One shared source of truth</strong> for scores and rehearsal material</li>
      <li><strong>Layered annotations</strong> that separate leadership input from personal notes</li>
      <li><strong>Individual ownership</strong> of private markings and preparation</li>
      <li><strong>Rehearsal-first workflows</strong>, not file management</li>
      <li><strong>Works online and offline</strong>, wherever rehearsals happen</li>
    </ul>

    <p>
      TextWhisper is designed to sit at the center of rehearsal work — where preparation,
      coordination, and musical understanding come together.
    </p>
    <p>
      Whether you're preparing a speech, memorizing lyrics, studying complex material, or directing a performance, 
      TextWhisper gives you a structured, interactive way to improve memory retention.
    </p>
    <p>
      The platform has already proven valuable in choirs learning large sets of lyrics and theater groups rehearsing scripts.  
      It's built for everyone — from everyday learners to professional educators and performers.
    </p>

    <p>
      TextWhisper is free for general users, with premium features available for organizations seeking expanded functionality.
    </p>

    <?php
    $sections = [
      "📘 Text Recall & Practice" => [
        "Smart text trimming with adjustable hint levels",
        "Dual view: full text vs. trimmed",
        "Scroll-based navigation (swipe, double-tap, pinch-to-zoom)",
        "Offline access for saved lists and items",
      ],
      "📴 Offline Mode" => [
        "Offline access for selected lists and items",
        "Offline Interface in browser or the TextWhisper app",
        "Offline preserved order of lists for performing or rehearsal",
        "Practice on the go, in airplane, train, car",
        "Perform with confidence without relying on internet access",
      ],
      "📝 PDF Integration & annotation" => [
        "Drag-and-drop or select PDF upload",
        "PDF annotation tools: pen, eraser, undo, color picker",
        "Personal annotation overlay in addition to the owner's",
        "Persistent annotations across sessions and devices",
        "IMSLP search and direct URL support",
        "Configurable navigation arrows for Continuous and Paged PDF views",
        "Boundary navigation across items in the list (previous/next PDF)",
      ],
      "📝 Text Editing" => [
        "Edit mode for owners and admins",
        "Bold, Italic, Underline formatting",
        "Dual textareas on wide screens",
        "Adjustable trim slider",
        "New, Save, Refresh, Delete, Print",
        "More stable HTML-based editor",
        "Shows owner and last editor",
        "Edit history is stored",
      ],
        
      "✏️ Text Annotations" => [
        "Private highlights, comments, and drawings",
        "Color highlights and draggable comment notes",
        "Freehand drawing anchored to the text",
        "Unified Undo across all annotation types",
        "Smooth touch selection and drawing",
        "Annotations auto-save and restore",
      ],
      
      "🎵 Music & Audio" => [
        "Upload MIDI, MP3, WAV, FLAC, M4A",
        "Paste links from YouTube, Spotify, Soundslice",
        "Add music from This device and cloud/link sources",
        "Built-in MIDI player with mute/solo, volume control, and real-time activity",
        "Floating, draggable players for focused playback",
        "Fast, reliable storage & playback via Cloudflare R2 integration"
      ],
      "📅 Event Planner & Polls" => [
        "Event Groups (with members) and Categories (labels only) for planning",
        "Category settings with custom color selection",
        "Create events per group with recurring event support",
        "Built-in event polls: create, vote, and share in chat",
        "Interactive calendar view with pop-outs",
        "Live member avatars with check-in/check-out status for members and admins",
        "Attendance reports with filtering by period, group, and category"
      ],
      "💬 List Chat & Collaboration" => [
        "Real-time chat per list with emoji and formatting",
        "End-to-end encryption",
        "Role-based access: Viewer, Commenter, Editor, Admin",
        "Invite-only participation with smart notifications",
        "Push alerts for important messages",
      ],
      "🔐 Privacy & Sharing" => [
        "List privacy levels: Public, Private, Secret",
        "Share via direct links with rich previews",
        "Session-based authentication and secure cookies",
        "Anti-spam filters and strong password enforcement",
      ],
      "🖥️ Interface & Usability" => [
        "Responsive UI for mobile and desktop",
        "Sidebar navigation with auto-collapse",
        "Resizable sidebar",
        "Draggable split panes on desktop text view",
        "Home dropdown with quick targets (profile and recent contexts)",
        "Group menu includes Share Group in sidebar group menus",
        "Signed-in avatar shown as active identity in the header",
        "Progressive Web App (PWA) support",
        "Dynamic URLs for deep linking",
        "Persistent sidebar and tab states",
      ],
    ];
    ?>

    <h2 class="mt-5">Highlighted Features</h2>
    <div class="feature-list">
      <?php foreach ($sections as $title => $features): ?>
        <div class="collapsible-section">
          <h3 class="collapsible-header"><?= htmlspecialchars($title) ?></h3>
          <ul class="collapsible-body">
            <?php foreach ($features as $item): ?>
              <li><?= htmlspecialchars($item) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>

    <script>
      document.addEventListener("DOMContentLoaded", () => {
        document.querySelectorAll(".collapsible-header").forEach(header => {
          header.addEventListener("click", () => {
            header.parentElement.classList.toggle("open");
          });
        });
      });
    </script>

    <h2 class="mt-5">Acknowledgements</h2>
    <p>
      TextWhisper is built on modern web technologies and open-source software.  
      We gratefully acknowledge the projects and services that make this possible:
    </p>

    <ul>
      <li><strong>Libraries:</strong> jQuery, Bootstrap, SortableJS, PDF.js, Lucide Icons, Tone.js, MidiPlayerJS</li>
      <li><strong>Integrations:</strong> Spotify, SoundCloud, YouTube, Soundslice, Google Drive</li>
      <li><strong>Infrastructure:</strong> Cloudflare R2 (file storage) with PHP server fallback</li>
      <li><strong>Web standards:</strong> Service Workers (offline mode), Web Push API with VAPID (notifications)</li>
    </ul>

    <p class="text-muted">
      Full list of licenses and acknowledgements is available in our 
      <a href="/includes/NOTICE.php" target="_blank">Third-Party Notices</a>.
    </p>

    <p class="text-center mt-4"><em>This is an experimental beta version — your feedback is welcome!</em></p>
    <p class="text-center"><strong>Thank you for using TextWhisper!</strong></p>
    <p class="text-center"><em>Ásgeir Þorgeirsson</em></p>

    <div class="text-center mt-4">
      <p>
        <button class="btn btn-secondary" onclick="
          if (document.referrer && document.referrer.includes(location.hostname)) {
            history.back();
          } else {
            location.href = '/';
          }
        ">⬅️ Back to TextWhisper</button>
      </p>
    </div>
  </div>
</body>
</html>
