<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Third-Party Notices – TextWhisper</title>
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

    <h1 class="text-center">Third-Party Notices for TextWhisper</h1>

    <p class="mt-4">
      TextWhisper makes use of the following open-source libraries, platforms, and services.  
      We thank the developers and organizations that make these available.
    </p>

    <hr>

    <h2>Open-Source Libraries</h2>
    <ul>
      <li>jQuery — MIT License</li>
      <li>Bootstrap — MIT License</li>
      <li>SortableJS — MIT License</li>
      <li>PDF.js (Mozilla) — Apache License 2.0</li>
      <li>Lucide Icons — ISC License</li>
      <li>Tone.js — MIT License</li>
      <li>MidiPlayerJS — MIT License</li>
      <li>compactMidiPlayer.js — MIT License</li>
      <li>OpenSheetMusicDisplay — BSD License</li>
      <li>JSZip — MIT License</li>
    </ul>

    <h2 class="mt-4">External Platforms & Embeds</h2>
    <p>TextWhisper supports integration with third-party media platforms:</p>
    <ul>
      <li>Spotify (music embeds)</li>
      <li>SoundCloud (audio embeds)</li>
      <li>YouTube (video embeds)</li>
      <li>Soundslice (interactive sheet music)</li>
      <li>Google Drive (PDF hosting & preview)</li>
    </ul>
    <p class="text-muted">
      Users must comply with the respective terms of service of these platforms when using embedded content.
    </p>

    <h2 class="mt-4">Infrastructure & Hosting</h2>
    <ul>
      <li>Cloudflare R2 — File and media storage (uploads, audio, PDFs)</li>
      <li>JustHost / PHP backend — Fallback file serving and APIs</li>
    </ul>

    <h2 class="mt-4">Web Standards</h2>
    <ul>
      <li>Service Workers — Offline caching & synchronization</li>
      <li>Web Push API (VAPID) — Push notifications</li>
    </ul>

    <h2 class="mt-4">License Notice</h2>
    <p>
      All open-source libraries are used under their respective licenses.  
      The full license text for this project is included in  
      <a href="./LICENSE.md">/includes/LICENSE.txt</a>.  
      No modifications to licensing terms are made by TextWhisper.
    </p>

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
