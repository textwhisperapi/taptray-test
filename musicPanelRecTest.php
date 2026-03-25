<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Punch-In Recorder with Options</title>
  <script src="https://unpkg.com/audiobuffer-to-wav"></script>
  <style>
    body { font-family: sans-serif; background:#222; color:#fff; padding:20px; }
    button { margin: 4px; padding: 6px 12px; font-size: 14px; cursor:pointer; }
    .custom-audio-player { margin: 10px 0; background:#333; padding:10px; border-radius:8px; }
    .controls-row { display:flex; gap:6px; margin-bottom:6px; }
    .progress-bar { width:100%; }
    .time-labels { display:flex; justify-content:space-between; font-size:12px; color:#ccc; }
    .rec-options { margin:10px 0; font-size:14px; }
    .rec-options label { margin-right:12px; }
  </style>
</head>
<body>

<h2>🎙️ Punch-In Recording Test</h2>

<div class="rec-options">
  <label><input type="checkbox" id="optEcho"> Echo Cancel</label>
  <label><input type="checkbox" id="optNoise"> Noise Suppression</label>
  <label><input type="checkbox" id="optGain"> Auto Gain</label>
</div>

<button id="recordBtn">🔴 Start Recording</button>
<button id="stopBtn" disabled>⏹ Stop</button>
<button id="overdubBtn" disabled>🎧 Overdub (Punch-In)</button>
<button id="mergeBtn" disabled>🔀 Merge Takes</button>

<p id="status">Status: Idle</p>
<div id="players"></div>

<script>
let mediaRecorder, recordedChunks = [];
let firstTakeBlob = null, overdubBlob = null;
let punchInTime = null;
let activeStream = null;

window.recordingOptions = {
  echoCancellation: false,
  noiseSuppression: false,
  autoGainControl: false
};

// === Recording functions ===
async function startRecording() {
  activeStream = await navigator.mediaDevices.getUserMedia({
    audio: {
      echoCancellation: window.recordingOptions.echoCancellation,
      noiseSuppression: window.recordingOptions.noiseSuppression,
      autoGainControl: window.recordingOptions.autoGainControl
    }
  });
  recordedChunks = [];
  mediaRecorder = new MediaRecorder(activeStream);
  mediaRecorder.ondataavailable = e => { if (e.data.size > 0) recordedChunks.push(e.data); };
  mediaRecorder.onstop = () => {
    const blob = new Blob(recordedChunks, { type: "audio/webm" });
    if (!firstTakeBlob) {
      firstTakeBlob = blob;
      addPlayer(blob, "First Take", true);
      overdubBtn.disabled = false;
    } else {
      overdubBlob = blob;
      addPlayer(blob, "Overdub Take");
      mergeBtn.disabled = false;
    }
    if (activeStream) {
      activeStream.getTracks().forEach(track => track.stop());
      activeStream = null;
    }
  };
  mediaRecorder.start();
  recordBtn.disabled = true;
  stopBtn.disabled = false;
  status.textContent = "Status: Recording...";
}

function stopRecording() {
  if (mediaRecorder && mediaRecorder.state !== "inactive") {
    mediaRecorder.stop();
    recordBtn.disabled = false;
    stopBtn.disabled = true;
    status.textContent = "Status: Stopped";
  }
}

// === Custom player with Punch-In ===
function renderCustomAudioPlayer(container, url, allowPunchIn = false) {
  const playerWrap = document.createElement("div");
  playerWrap.className = "custom-audio-player";

  const audio = document.createElement("audio");
  audio.src = url;
  audio.preload = "metadata";

  const controlsRow = document.createElement("div");
  controlsRow.className = "controls-row";

  const playBtn = document.createElement("button");
  playBtn.textContent = "▶️";
  controlsRow.appendChild(playBtn);

  const progress = document.createElement("input");
  progress.type = "range";
  progress.min = 0;
  progress.step = 0.1;
  progress.value = 0;
  progress.className = "progress-bar";

  if (allowPunchIn) {
    const punchBtn = document.createElement("button");
    punchBtn.textContent = "📍 Punch-In";
    punchBtn.onclick = () => {
      punchInTime = audio.currentTime;
      alert(`Punch-In set at ${punchInTime.toFixed(2)}s`);
    };
    controlsRow.appendChild(punchBtn);
  }

  const timeLabels = document.createElement("div");
  timeLabels.className = "time-labels";
  const elapsed = document.createElement("span");
  elapsed.textContent = "0:00";
  const duration = document.createElement("span");
  duration.textContent = "0:00";
  timeLabels.appendChild(elapsed);
  timeLabels.appendChild(duration);

  playBtn.onclick = () => {
    if (audio.paused) {
      audio.play();
      playBtn.textContent = "⏸️";
    } else {
      audio.pause();
      playBtn.textContent = "▶️";
    }
  };

  audio.addEventListener("loadedmetadata", () => {
    progress.max = audio.duration || 0;
    duration.textContent = formatTime(audio.duration);
  });
  audio.addEventListener("timeupdate", () => {
    progress.value = audio.currentTime;
    elapsed.textContent = formatTime(audio.currentTime);
  });
  progress.addEventListener("input", e => {
    audio.currentTime = parseFloat(e.target.value);
  });
  audio.addEventListener("ended", () => {
    playBtn.textContent = "▶️";
  });

  playerWrap.appendChild(controlsRow);
  playerWrap.appendChild(progress);
  playerWrap.appendChild(timeLabels);
  container.appendChild(playerWrap);
}

function formatTime(sec) {
  if (!isFinite(sec)) return "0:00";
  const m = Math.floor(sec / 60);
  const s = Math.floor(sec % 60);
  return `${m}:${s.toString().padStart(2, "0")}`;
}

function addPlayer(blob, label, allowPunchIn = false) {
  const url = URL.createObjectURL(blob);
  const div = document.createElement("div");
  div.innerHTML = `<p>${label}</p>`;
  renderCustomAudioPlayer(div, url, allowPunchIn);
  players.appendChild(div);
}

// === Overdub with pre-roll ===
async function startOverdubWithContext() {
  if (!firstTakeBlob || punchInTime === null) {
    alert("❌ Please record a first take and set a Punch-In point.");
    return;
  }

  const ctx = new AudioContext();
  const arrayBuffer = await firstTakeBlob.arrayBuffer();
  const buffer = await ctx.decodeAudioData(arrayBuffer);
  const source = ctx.createBufferSource();
  source.buffer = buffer;
  source.connect(ctx.destination);

  const preRoll = 3.0; // seconds
  const playbackStart = Math.max(0, punchInTime - preRoll);

  source.start(0, playbackStart);
  status.textContent = `🎧 Pre-roll from ${playbackStart.toFixed(2)}s, punch-in at ${punchInTime.toFixed(2)}s`;

  const waitMs = (punchInTime - playbackStart) * 1000;
  setTimeout(() => {
    startRecording();
    status.textContent = "🎙️ Overdub Recording...";
  }, waitMs);

  source.stop(ctx.currentTime + buffer.duration - playbackStart);
}

// === Merge ===
async function mergeTakes() {
  const ctx = new OfflineAudioContext(1, 44100 * 120, 44100);
  const [buf1, buf2] = await Promise.all([
    decodeBlob(ctx, firstTakeBlob),
    decodeBlob(ctx, overdubBlob)
  ]);

  const resultLen = Math.max(buf1.length, punchInTime * ctx.sampleRate + buf2.length);
  const result = ctx.createBuffer(1, resultLen, ctx.sampleRate);
  const ch = result.getChannelData(0);

  ch.set(buf1.getChannelData(0).subarray(0, punchInTime * ctx.sampleRate), 0);
  ch.set(buf2.getChannelData(0), punchInTime * ctx.sampleRate);

  const wavBuffer = window.audioBufferToWav(result);
  const finalBlob = new Blob([wavBuffer], { type: "audio/wav" });
  addPlayer(finalBlob, "✅ Merged Take");
}

async function decodeBlob(ctx, blob) {
  const arrayBuffer = await blob.arrayBuffer();
  return ctx.decodeAudioData(arrayBuffer);
}

// === Bind UI ===
recordBtn.onclick = startRecording;
stopBtn.onclick = stopRecording;
overdubBtn.onclick = startOverdubWithContext;
mergeBtn.onclick = mergeTakes;

document.getElementById("optEcho").onchange = e => {
  window.recordingOptions.echoCancellation = e.target.checked;
};
document.getElementById("optNoise").onchange = e => {
  window.recordingOptions.noiseSuppression = e.target.checked;
};
document.getElementById("optGain").onchange = e => {
  window.recordingOptions.autoGainControl = e.target.checked;
};
</script>

</body>
</html>
