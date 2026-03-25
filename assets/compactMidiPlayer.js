


// Add `fileUrl = null` parameter
window.renderCompactMidiPlayer = async function(container, surrogate, name, fileUrl = null) {
  if (!window.Tone || !window.MidiPlayer) {
    console.warn("⏳ Waiting for Tone.js and MidiPlayer.js to load...");
    await Promise.all([
      import('https://cdn.jsdelivr.net/npm/tone@14.7.77/build/Tone.min.js'),
      import('/assets/MidiPlayerJS-master/browser/midiplayer.js')
    ]);
    if (!window.Tone || !window.MidiPlayer) {
      console.warn("Missing Tone.js or MidiPlayer.js!");
      return;
    }
  }

  // ✅ Decide URL and FORCE cache-bust so Cloudflare returns fresh headers
  if (!fileUrl) {
    console.warn("Missing Cloudflare URL for MIDI file:", name);
    return;
  }
  let midiUrl = fileUrl;

  // Append a cache-buster to defeat Cloudflare’s stale edge cache
  midiUrl += (midiUrl.includes("?") ? "&" : "?") + "cb=" + Date.now();


  // ... your existing code, replacing only `url` with `midiUrl`
  // player.loadArrayBuffer(buffer);
  // etc.
const defaultSpeed = surrogate === "omr-test" ? 0.9 : 1.0;

container.innerHTML = `
  <div class="midi-ui">
    <div class="midi-header"> 
        <div class="midi-left">
          <span class="midi-filename">${name}</span>
        </div>
      <button class="toggle-channels-btn" title="Toggle Channels">Channels 🡇</button>
    </div>
    <div class="control-row">
		<button class="play-btn" title="Play">
		  <i data-lucide="play"></i>
		</button>
      <div class="progress-bar"><div class="progress-fill"></div></div>
      <div class="mini-controls-col">
        <button class="mini-select-btn speed-btn" title="Playback speed" type="button">
          <span class="mini-key">SPD</span>
          <span class="mini-val">1.0</span>
        </button>
        <button class="mini-select-btn metro-btn" title="Metronome mode" type="button">
          <span class="mini-key">MET</span>
          <span class="mini-val">Off</span>
        </button>
      </div>
    </div>
    <div class="channel-controls"></div>
  </div>

  <style> 
    .midi-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: nowrap;
      gap: 8px;
      margin-bottom: 4px;
    }
    
    .midi-left {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-grow: 1;
      min-width: 0;
    }
    
    .midi-filename {
      font-weight: 500;
      font-size: 12px;
      color: #444;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 100%;
    }
    
    .midi-header button {
      background: none;
      border: none;
      font-size: 12px;
      cursor: pointer;
      opacity: 0.6;
      transition: opacity 0.2s;
    }
    
    .midi-header button:hover {
      opacity: 1;
    }

    .pin-btn {
      background: none;
      border: none;
      font-size: 12px;
      cursor: pointer;
      opacity: 0.5;
      transition: opacity 0.2s;
    }
    .pin-btn:hover {
      opacity: 1;
    }
    
    .floating-midi-player {
      position: fixed;
      bottom: 60px;
      left: 12px;
      right: 12px;
      z-index: 9999;
      max-width: 600px;
      margin: auto;
      background: #fff;
      border-radius: 8px;
      box-shadow: 0 4px 14px rgba(0,0,0,0.3);
      padding: 10px;
    }


    .midi-ui {
      padding: 4px 4px;
      background: #f9f9f9;
      border: 1px solid #ccc;
      border-radius: 6px;
      font-size: 13px;
    }



    .control-row {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 6px;
    }

    .mini-controls-col {
      display: inline-flex;
      flex-direction: column;
      gap: 4px;
      align-items: stretch;
      justify-content: center;
      flex: 0 0 auto;
    }

    .play-btn {
      width: 34px;
      height: 34px;
      border: 1px solid rgba(90, 190, 120, 0.45);
      border-radius: 999px;
      background: linear-gradient(145deg, #49b36a, #2f8f51);
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.24), inset 0 1px 0 rgba(255, 255, 255, 0.24);
      font-size: 14px;
      cursor: pointer;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: transform 0.12s ease, filter 0.18s ease, box-shadow 0.18s ease;
    }

    .play-btn:hover {
      filter: brightness(1.07);
      box-shadow: 0 6px 14px rgba(0, 0, 0, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.28);
    }

    .play-btn:active {
      transform: translateY(1px) scale(0.98);
      filter: brightness(0.96);
    }

    .progress-bar {
      flex-grow: 1;
      height: 6px;
      background: #ddd;
      border-radius: 3px;
      overflow: hidden;
      cursor: pointer;
    }

    .progress-fill {
      height: 100%;
      background: #4caf50;
      width: 0%;
    }

    .mini-select-btn {
      min-width: 38px;
      border: 1px solid #666;
      border-radius: 8px;
      background: #222;
      color: #ddd;
      padding: 2px 4px;
      line-height: 1;
      display: inline-flex;
      flex-direction: row;
      align-items: center;
      justify-content: center;
      gap: 4px;
      cursor: pointer;
      transition: filter 0.16s ease, transform 0.12s ease;
      white-space: nowrap;
    }
    .mini-select-btn:hover { filter: brightness(1.08); }
    .mini-select-btn:active { transform: scale(0.98); }
    .mini-select-btn .mini-key {
      font-size: 8px;
      opacity: 0.75;
      letter-spacing: 0.2px;
      font-weight: 600;
    }
    .mini-select-btn .mini-val {
      font-size: 9px;
      font-weight: 600;
      min-width: 16px;
      text-align: center;
    }
    .speed-btn { min-width: 52px; }
    .metro-btn { min-width: 52px; }

    .channel {
      display: flex;
      flex-wrap: wrap; /* allow wrap if needed */
      align-items: center;
      gap: 4px;
      font-size: 11px;
      margin: 4px 0;
      border-bottom: 1px solid #eee;
      padding-bottom: 4px;
    }
    
    .channel > * {
      flex: 0 1 auto; /* allow shrinking/wrapping */
      white-space: nowrap;
    }
    
    .channel .ch-id {
      width: 36px;
      font-weight: bold;
      flex-shrink: 0;
    }
    
    .channel label {
      display: flex;
      align-items: center;
      gap: 2px;
      margin: 0;
    }
    
    .channel input[type="range"] {
      flex: 1 1 40px;
      min-width: 40px;
      height: 4px;
      margin: 0;
    }
    
    .channel-controls {
      display: flex;
      flex-direction: column; /* ensures multiple .channel rows stack properly */
      gap: 6px;
    }

    
    .vol-label {
      font-family: monospace;
      font-size: 11px;
      width: 34px;
      text-align: right;
    }
    
    .toggle-channels-btn {
      font-size: 11px;
      background: none;
      border: none;
      color: #555;
      cursor: pointer;
      padding: 2px 6px;
      opacity: 0.7;
      transition: opacity 0.2s ease;
      margin-left: auto;
      flex: 0 0 auto;
      white-space: nowrap;
    }
    .toggle-channels-btn:hover {
      opacity: 1;
    }
    
    .midi-ui {
      background: transparent;
      color: #ddd;
    }
    
    .midi-ui .midi-filename,
    .midi-ui .ch-id,
    .midi-ui label,
    .midi-ui .vol-label {
      color: #ccc;
    }
    
    .midi-ui input[type="checkbox"],
    .midi-ui input[type="radio"] {
      accent-color: #999;
    }
    
    .midi-ui input[type="range"] {
      background-color: #555;
    }
    
    .toggle-channels-btn {
      color: #ccc;
      opacity: 0.6;
    }
    
    .toggle-channels-btn:hover {
      opacity: 1;
    }

    /*----compactMidiPlayer.js--------*/
    
    .channel-focus {
      width: 14px;
      height: 14px;
      border: 2px solid #888;
      border-radius: 50%;
      display: inline-block;
      margin-right: 4px;  /* Less gap before label */
      cursor: pointer;
      position: relative;
      flex-shrink: 0;
      transition: border-color 0.2s ease;
    }
    
    .channel-focus:hover {
      border-color: #4af;
    }
    
    .channel-focus.active::after {
      content: "";
      position: absolute;
      top: 3px;
      left: 3px;
      width: 6px;
      height: 6px;
      background: #4af;
      border-radius: 50%;
    }
    
    label.focus-toggle {
      display: inline-flex;
      align-items: center;
      gap: 2px;         /* Nice tight spacing */
      margin-left: 10px; /* Padding from "Mute" */
      font-weight: 500;
      color: #ccc;
    }
    

.channel {
  position: relative; /* Needed to contain the absolutely-positioned pulse */
}

.channel .pulse {
  position: absolute;
  right: 4px; /* Align it where you want, e.g., right side */
  top: 50%;
  transform: translate(-500%, -50%);
  width: 30px;
  height: 6px;
  background: linear-gradient(to right, transparent 0%, #6f6 100%);
  border-radius: 3px;
  opacity: 0;
  pointer-events: none; /* So it doesn't block clicks */
  z-index: 1; /* Ensure it's above other items */
}


@keyframes pulseSlide {
  from { transform: translate(-500%, -50%); opacity: 1; }
  to   { transform: translate(50%, -50%); opacity: 0; }
}


.channel.active .pulse {
  opacity: 1;
}


.channel-name-input {
  font-size: 11px;
  background: transparent;
  border: none;
  color: #ccc;
  width: 50px;
}
.channel-name-input:focus {
  outline: none;
  background: #222;
}

.play-btn i,
.play-btn svg {
  color: #fff;
  stroke: #fff;       /* ensures the SVG stroke turns white */
}




  </style>
`;


if (window.lucide) {
  lucide.createIcons();
}



const toggleBtn = container.querySelector(".toggle-channels-btn");
const channelControls = container.querySelector(".channel-controls");

toggleBtn.addEventListener("click", () => {
  const isVisible = channelControls.style.display !== "none";
  channelControls.style.display = isVisible ? "none" : "flex";
  toggleBtn.textContent = isVisible ? "Channels 🡇" : "Channels 🡅";
});

// Optional: hide channels by default on small screens
// if (window.innerWidth < 600) {
//   channelControls.style.display = "none";
// }



  const sampler = new Tone.Sampler({
    // Denser keymap avoids extreme pitch-shifting that can sound harpsichord-like.
    urls: {
      A0: "A0.mp3",
      C1: "C1.mp3",
      "D#1": "Ds1.mp3",
      "F#1": "Fs1.mp3",
      A1: "A1.mp3",
      C2: "C2.mp3",
      "D#2": "Ds2.mp3",
      "F#2": "Fs2.mp3",
      A2: "A2.mp3",
      C3: "C3.mp3",
      "D#3": "Ds3.mp3",
      "F#3": "Fs3.mp3",
      A3: "A3.mp3",
      C4: "C4.mp3",
      "D#4": "Ds4.mp3",
      "F#4": "Fs4.mp3",
      A4: "A4.mp3",
      C5: "C5.mp3",
      "D#5": "Ds5.mp3",
      "F#5": "Fs5.mp3",
      A5: "A5.mp3",
      C6: "C6.mp3",
      "D#6": "Ds6.mp3",
      "F#6": "Fs6.mp3",
      A6: "A6.mp3",
      C7: "C7.mp3",
      "D#7": "Ds7.mp3",
      "F#7": "Fs7.mp3",
      A7: "A7.mp3",
      C8: "C8.mp3"
    },
    release: 2.8,
    baseUrl: "https://tonejs.github.io/audio/salamander/"
  }).toDestination();
  let samplerReady = false;
  let samplerLoadFailed = false;
  const fallbackSynth = new Tone.PolySynth(Tone.Synth, {
    oscillator: { type: "triangle" },
    envelope: { attack: 0.01, decay: 0.08, sustain: 0.5, release: 0.6 }
  }).toDestination();
  const metronomeSynth = new Tone.Synth({
    oscillator: { type: "square" },
    envelope: { attack: 0.001, decay: 0.04, sustain: 0, release: 0.02 },
    volume: -8
  }).toDestination();
  const ensureSamplerReady = async () => {
    if (samplerReady) return true;
    if (samplerLoadFailed) return false;
    try {
      await sampler.loaded;
      samplerReady = true;
      return true;
    } catch (err) {
      samplerLoadFailed = true;
      console.error("Sampler failed to load:", err);
      return false;
    }
  };

  let player;
  let tickMax = 1;
  const channelSettings = {};
  let focusedChannel = null;
  let channelNames = {};
  const isOmrTest = surrogate === "omr-test";
  const suppressedNoteStarts = new Set();
  const suppressedActiveNotes = new Set();
  const oneShotStarts = new Set();
  const ignoreNextOff = new Map();
  const lastAttackByChannel = new Map();
  const lastPulseAtByChannel = new Map();
  const speedBtn = container.querySelector(".speed-btn");
  const metroBtn = container.querySelector(".metro-btn");
  const speedValEl = speedBtn?.querySelector(".mini-val");
  const metroValEl = metroBtn?.querySelector(".mini-val");
  const speedOptions = [0.75, 0.9, 1, 1.25];
  const metroOptions = ["off", "all", "downbeat"];
  let speedMultiplier = Number(defaultSpeed || 1);
  let baseTempoBpm = null;
  let ticksPerBeat = 480;
  let metronomeMode = "off";
  let lastMetronomeStepKey = "";
  let lastMetronomeTick = -1;
  let lastMetronomeBarlineTick = -1;
  let timeSignatureEvents = [{ tick: 0, numerator: 4, denominator: 4 }];
  let barlineTicks = [0];
  const formatSpeed = (v) => (Number(v).toFixed(2)).replace(/\.00$/, ".0").replace(/0$/, "");
  const formatMetro = (m) => (m === "off" ? "Off" : (m === "all" ? "All" : "Db"));
  const syncMiniControls = () => {
    if (speedValEl) speedValEl.textContent = formatSpeed(speedMultiplier);
    if (metroValEl) metroValEl.textContent = formatMetro(metronomeMode);
  };
  syncMiniControls();
  const emitPlaybackEvent = (type, detail = {}) => {
    try {
      container.dispatchEvent(new CustomEvent(`midi-${type}`, {
        detail: { ...detail, name, surrogate },
        bubbles: true
      }));
    } catch {}
  };

  const playBtn = container.querySelector(".play-btn");
  const progress = container.querySelector(".progress-fill");

  const progressBar = container.querySelector(".progress-bar");
    progressBar.addEventListener("click", async (e) => {
     //Allow clicking the progress bar while playing
      if (!player || !tickMax) return;
      //if (!player || !tickMax || player.isPlaying()) return;


    
      // ✅ Ensure AudioContext is running
      if (Tone.context.state !== 'running') {
        await Tone.start();
      }
    
      // ✅ Ensure sampler is fully loaded
      await ensureSamplerReady();
    
      // ✅ Proceed to skip and play
      const rect = progressBar.getBoundingClientRect();
      const percent = (e.clientX - rect.left) / rect.width;
      const targetTick = Math.floor(tickMax * percent);
    
      player.stop();
      player.skipToTick(targetTick);
      resetMetronomePhase(targetTick);
      player.play();
		playBtn.innerHTML = '<i data-lucide="pause"></i>';
		lucide.createIcons();

    });


  function updateProgress(tick) {
    const percent = Math.min(100, (tick / tickMax) * 100);
    progress.style.width = percent + "%";
  }

  function extractTicksPerBeatFromBuffer(arrayBuffer) {
    try {
      const view = new DataView(arrayBuffer);
      const mthd =
        String.fromCharCode(view.getUint8(0)) +
        String.fromCharCode(view.getUint8(1)) +
        String.fromCharCode(view.getUint8(2)) +
        String.fromCharCode(view.getUint8(3));
      if (mthd !== "MThd") return 480;
      const division = view.getUint16(12);
      if ((division & 0x8000) !== 0) return 480; // SMPTE timing not handled here
      return Math.max(24, Number(division) || 480);
    } catch {
      return 480;
    }
  }

  function metronomeIsEnabled() {
    return metronomeMode === "all" || metronomeMode === "downbeat";
  }

  function parseTimeSignatureEventsFromPlayer() {
    const parsed = [];
    try {
      if (!player || typeof player.getEvents !== "function") return [{ tick: 0, numerator: 4, denominator: 4 }];
      const tracks = player.getEvents() || [];
      tracks.forEach((track) => {
        if (!Array.isArray(track)) return;
        track.forEach((ev) => {
          if (!ev || ev.name !== "Time Signature" || typeof ev.tick !== "number") return;
          const raw = ev.data;
          const nn = Number(raw?.[0]);
          const ddPow = Number(raw?.[1]);
          if (!Number.isFinite(nn) || nn < 1 || nn > 32 || !Number.isFinite(ddPow)) return;
          const denominator = Math.pow(2, ddPow);
          if (!Number.isFinite(denominator) || denominator <= 0) return;
          parsed.push({
            tick: Math.max(0, Math.floor(ev.tick)),
            numerator: Math.floor(nn),
            denominator: Math.floor(denominator)
          });
        });
      });
    } catch {}

    // Use 4/4 only as a true fallback when the MIDI has no signature at tick 0.
    const hasTickZeroSignature = parsed.some((ev) => Number(ev?.tick) === 0);
    if (!hasTickZeroSignature) {
      parsed.push({ tick: 0, numerator: 4, denominator: 4 });
    }
    parsed.sort((a, b) => {
      const ta = Number(a?.tick || 0);
      const tb = Number(b?.tick || 0);
      if (ta !== tb) return ta - tb;
      // For same tick, prefer non-fallback entries over fallback 4/4.
      const aIsFallback = ta === 0 && Number(a?.numerator) === 4 && Number(a?.denominator) === 4;
      const bIsFallback = tb === 0 && Number(b?.numerator) === 4 && Number(b?.denominator) === 4;
      return aIsFallback === bIsFallback ? 0 : (aIsFallback ? 1 : -1);
    });
    return parsed.length ? parsed : [{ tick: 0, numerator: 4, denominator: 4 }];
  }

  function parseBarlineTicksFromPlayer() {
    const ticks = new Set([0]);
    try {
      if (!player || typeof player.getEvents !== "function") return [0];
      const tracks = player.getEvents() || [];
      tracks.forEach((track) => {
        if (!Array.isArray(track)) return;
        track.forEach((ev) => {
          if (!ev || typeof ev.tick !== "number") return;
          const t = Math.max(0, Math.floor(ev.tick));
          if (ev.name === "Time Signature") {
            ticks.add(t);
            return;
          }
          if (ev.name === "Marker" || ev.name === "Cue Point") {
            const label = String(ev.string || "").trim().toLowerCase();
            const looksLikeBarline =
              !label ||
              /^\d+$/.test(label) ||
              /^(bar|measure|m)\s*[\d]+$/.test(label) ||
              /\b(bar|measure|barline)\b/.test(label);
            if (looksLikeBarline) ticks.add(t);
            return;
          }
          if (ev.name === "Text Event" || ev.name === "Lyric") {
            const label = String(ev.string || "").trim().toLowerCase();
            const looksLikeBarline =
              /^\|+$/.test(label) ||
              /^\[\s*\d+\s*\]$/.test(label) ||
              /^(bar|measure|m)\s*[\d]+$/.test(label) ||
              /\b(bar|measure|barline)\b/.test(label);
            if (looksLikeBarline) ticks.add(t);
          }
        });
      });
    } catch {}
    return Array.from(ticks).sort((a, b) => a - b);
  }

  function getTimeSignatureAtTick(tick) {
    const safeTick = Math.max(0, Number(tick || 0));
    let active = timeSignatureEvents[0] || { tick: 0, numerator: 4, denominator: 4 };
    for (let i = 0; i < timeSignatureEvents.length; i++) {
      const ev = timeSignatureEvents[i];
      if (!ev || !Number.isFinite(ev.tick)) continue;
      if (ev.tick <= safeTick) active = ev;
      else break;
    }
    return active;
  }

  function resetMetronomePhase(seedTick = 0) {
    lastMetronomeStepKey = "";
    lastMetronomeTick = Number(seedTick || 0) - 1;
    lastMetronomeBarlineTick = -1;
    if (metronomeIsEnabled()) {
      // Prime key so first following metronome step triggers cleanly after seek/start.
      const safeTick = Math.max(0, Number(seedTick || 0));
      const sig = getTimeSignatureAtTick(safeTick);
      const beatUnitTicks = getBeatUnitTicks(sig);
      const sigStartTick = Math.max(0, Number(sig.tick || 0));
      const localStep = Math.floor(Math.max(0, safeTick - sigStartTick) / beatUnitTicks);
      lastMetronomeStepKey = `${sigStartTick}:${Math.max(0, localStep - 1)}`;
    }
  }

  function getBeatUnitTicks(sig) {
    const numerator = Math.max(1, Number(sig?.numerator || 4));
    const denominator = Math.max(1, Number(sig?.denominator || 4));
    const quarterBasedTicks = ticksPerBeat * (4 / denominator);
    // Compound-meter heuristic: in x/8 with divisible-by-3 numerators over 3,
    // click dotted quarter beats (e.g. 6/8, 9/8, 12/8).
    if (denominator === 8 && numerator > 3 && numerator % 3 === 0) {
      return Math.max(1, quarterBasedTicks * 3);
    }
    return Math.max(1, quarterBasedTicks);
  }

  function tickMetronome(tick) {
    if (!metronomeIsEnabled()) return;
    const safeTick = Math.max(0, Number(tick || 0));
    const prevTick = Number(lastMetronomeTick);
    lastMetronomeTick = safeTick;
    const start = Math.floor(Math.min(prevTick, safeTick));
    const end = Math.floor(Math.max(prevTick, safeTick));

    // Prefer explicit/derived barline crossings for irregular bars.
    let crossedBarlineTick = -1;
    if (Array.isArray(barlineTicks) && barlineTicks.length >= 2 && end > start) {
      for (let i = 0; i < barlineTicks.length; i++) {
        const t = Number(barlineTicks[i]);
        if (!Number.isFinite(t)) continue;
        if (t > start && t <= end && t > lastMetronomeBarlineTick) {
          crossedBarlineTick = t;
        }
      }
    }

    const sig = getTimeSignatureAtTick(safeTick);
    const stepTicks = getBeatUnitTicks(sig);
    const sigStartTick = Math.max(0, Number(sig.tick || 0));
    const localStep = Math.floor(Math.max(0, safeTick - sigStartTick) / stepTicks);
    const stepKey = `${sigStartTick}:${localStep}`;

    const beatsPerBar = Math.max(1, Number(sig.numerator || 4));
    const computedDownbeat = localStep % beatsPerBar === 0;

    const isCrossedBarline = crossedBarlineTick >= 0;
    const triggerKey = isCrossedBarline ? `b:${crossedBarlineTick}` : `s:${stepKey}`;
    if (triggerKey === lastMetronomeStepKey) return;
    lastMetronomeStepKey = triggerKey;
    if (isCrossedBarline) {
      lastMetronomeBarlineTick = crossedBarlineTick;
    }

    const isDownbeat = isCrossedBarline || computedDownbeat;
    if (metronomeMode === "downbeat" && !isDownbeat) return;
    const hz = isDownbeat ? 1760 : 1320;
    const vel = isDownbeat ? 0.5 : 0.34;
    try {
      metronomeSynth.triggerAttackRelease(hz, "32n", undefined, vel);
    } catch {}
  }

  function animateChannelPulse(ch) {
    const chEl = container.querySelector(`.channel[data-ch="${ch}"]`);
    if (!chEl) return;
    const pulse = chEl.querySelector(".pulse");
    if (!pulse) return;

    // Throttle pulse restarts on dense passages to reduce main-thread jank.
    const now = performance.now();
    const last = lastPulseAtByChannel.get(ch) || 0;
    if ((now - last) < 28) return;
    lastPulseAtByChannel.set(ch, now);

    try {
      const animations = pulse.getAnimations?.() || [];
      animations.forEach((anim) => anim.cancel());
      pulse.animate(
        [
          { transform: "translate(-500%, -50%)", opacity: 1 },
          { transform: "translate(50%, -50%)", opacity: 0 }
        ],
        { duration: 400, easing: "ease-out" }
      );
      return;
    } catch {}

    pulse.style.animation = "none";
    requestAnimationFrame(() => {
      pulse.style.animation = "pulseSlide 0.4s ease-out";
    });
  }

  function buildOmrArtifactSuppression() {
    if (!isOmrTest || !player || typeof player.getEvents !== "function") return;
    try {
      suppressedNoteStarts.clear();
      oneShotStarts.clear();
      const tracks = player.getEvents();
      const stacks = new Map();
      const notes = [];
      tracks.forEach(track => {
        track.forEach(ev => {
          if (!ev || typeof ev.tick !== "number" || typeof ev.noteNumber !== "number") return;
          const ch = Number(ev.channel || 1);
          const n = Number(ev.noteNumber);
          const key = `${ch}:${n}`;
          if (ev.name === "Note on" && (ev.velocity || 0) > 0) {
            if (!stacks.has(key)) stacks.set(key, []);
            stacks.get(key).push(ev.tick);
          } else if (ev.name === "Note off" || (ev.name === "Note on" && (ev.velocity || 0) === 0)) {
            const arr = stacks.get(key);
            if (!arr || !arr.length) return;
            const start = arr.pop();
            notes.push({ ch, n, start, dur: Math.max(0, ev.tick - start) });
          }
        });
      });
      const total = Number(player.totalTicks || 1);
      const shortDur = Math.max(6, Math.floor(total * 0.004));
      notes.forEach(({ ch, n, start, dur }) => {
        // OMR ornaments/noise often become tiny, out-of-choir-range blips.
        if (dur <= shortDur && (n < 45 || n > 88)) {
          suppressedNoteStarts.add(`${ch}:${n}:${start}`);
        }
      });

      // Dense same-tick clusters are often ornament misreads.
      // Keep only outer voices (lowest + highest) sounding together.
      const onsByTickCh = new Map();
      tracks.forEach(track => {
        track.forEach(ev => {
          if (!ev || ev.name !== "Note on" || (ev.velocity || 0) <= 0) return;
          if (typeof ev.tick !== "number" || typeof ev.noteNumber !== "number") return;
          const ch = Number(ev.channel || 1);
          const n = Number(ev.noteNumber);
          const t = Number(ev.tick);
          const key = `${ch}:${t}`;
          if (!onsByTickCh.has(key)) onsByTickCh.set(key, []);
          onsByTickCh.get(key).push(n);
        });
      });
      onsByTickCh.forEach((arr, key) => {
        if (!Array.isArray(arr) || arr.length < 3) return;
        const [ch, t] = key.split(":");
        const sorted = [...arr].sort((a, b) => a - b);
        const lo = sorted[0];
        const hi = sorted[sorted.length - 1];
        oneShotStarts.add(`${ch}:${lo}:${t}`);
        oneShotStarts.add(`${ch}:${hi}:${t}`);
        arr.forEach((n) => {
          if (n !== lo && n !== hi) {
            suppressedNoteStarts.add(`${ch}:${n}:${t}`);
          }
        });
      });
    } catch (err) {
      console.warn("OMR artifact filter setup skipped:", err);
    }
  }

function renderChannelControls() {

  const containerEl = container.querySelector(".channel-controls");
  containerEl.innerHTML = '';

  for (const [ch, { muted, volume }] of Object.entries(channelSettings)) {
    const div = document.createElement("div");
    div.className = "channel";
    div.dataset.ch = ch;
    div.style.display = "flex";
    div.style.alignItems = "center";
    div.style.gap = "8px"; // space between each group
    

    
    // Mute toggle
    const muteLabel = document.createElement("label");
    const muteCheckbox = document.createElement("input");
    muteCheckbox.type = "checkbox";
    muteCheckbox.checked = muted;
    muteCheckbox.onchange = () => {
      div.dispatchEvent(new CustomEvent("mute-toggle", { detail: ch }));
    };
    muteLabel.appendChild(muteCheckbox);
    muteLabel.append(" Mute");
    
    // Focus toggle styled as a visual radio
    const focusLabel = document.createElement("label");
    focusLabel.className = "focus-toggle";
    focusLabel.style.display = "inline-flex";
    focusLabel.style.alignItems = "center";
    focusLabel.style.gap = "4px";
    
    const focusBtn = document.createElement("div");
    focusBtn.className = "channel-focus";
    if (focusedChannel == ch) focusBtn.classList.add("active");
    focusBtn.title = "Click to focus this channel";
    focusBtn.onclick = (e) => {
      e.stopPropagation();
      div.dispatchEvent(new CustomEvent("focus-toggle", { detail: +ch }));
    };
    
    focusLabel.appendChild(focusBtn);
    focusLabel.append("Focus");


    // Volume slider
    const volumeSlider = document.createElement("input");
    volumeSlider.type = "range";
    volumeSlider.min = "0";
    volumeSlider.max = "1";
    volumeSlider.step = "0.01";
    volumeSlider.value = volume;
    volumeSlider.oninput = () => {
      div.dispatchEvent(new CustomEvent("volume-change", {
        detail: { ch, vol: volumeSlider.value }
      }));
    };

    const volLabel = document.createElement("div");
    volLabel.className = "vol-label";
    volLabel.textContent = `${Math.round(volume * 100)}%`;

    // CH label
const chLabel = document.createElement("input");
chLabel.type = "text";
chLabel.className = "channel-name-input";
chLabel.value = channelNames[ch] || `CH ${ch}`;
chLabel.onchange = () => {
  const newName = chLabel.value.trim();
  channelNames[ch] = newName;
  //saveChannelNamesToLocal(name, channelNames);
  saveChannelNamesToServer(surrogate, name, channelNames);

};



    
    const pulse = document.createElement("div");
    pulse.className = "pulse";

    // Append
    div.appendChild(chLabel);
    div.appendChild(muteLabel);
    div.appendChild(focusLabel); // ✅ this line replaces div.appendChild(focusBtn)
    div.appendChild(volumeSlider);
    div.appendChild(volLabel);
    div.appendChild(pulse);

    // Events
    div.addEventListener("mute-toggle", (e) => {
      const ch = e.detail;
      channelSettings[ch].muted = !channelSettings[ch].muted;
      renderChannelControls();
    });

    div.addEventListener("focus-toggle", (e) => {
      const ch = e.detail;
      const isFocusing = focusedChannel !== ch;
      focusedChannel = isFocusing ? ch : null;
    
      Object.entries(channelSettings).forEach(([id, c]) => {
        if (focusedChannel === null) {
          c.volume = 1; // 🔁 Always full volume when no focus
        } else if (+id === focusedChannel) {
          c.volume = 1; // ✅ Full for focused
        } else {
          c.volume = 0.3; // 🔇 Lower for others
        }
      });
    
      renderChannelControls();
    });



    div.addEventListener("volume-change", (e) => {
      const { ch, vol } = e.detail;
      const v = parseFloat(vol);
      channelSettings[ch].userVolume = v;
      channelSettings[ch].volume = v;
      renderChannelControls();
    });

    containerEl.appendChild(div);
  }
}

  
  
//---------------
  const res = await fetch(midiUrl);
  const buffer = await res.arrayBuffer();
  ticksPerBeat = extractTicksPerBeatFromBuffer(buffer);
    
    let sustainActive = false;
    const heldNotes = new Set();
    
//channelNames = loadChannelNamesFromLocal(name);
channelNames = await loadChannelNamesFromServer(surrogate, name);


if (Object.keys(channelNames).length === 0) {
  channelNames = extractChannelNamesFromBuffer(buffer.slice(0));
}
    
    player = new MidiPlayer.Player(event => {
      if (event.name === "Note on" && event.velocity > 0) {
        const ch = event.channel;
        const midiNote = Number(event.noteNumber || 0);
        const evTick = Number(event.tick || 0);
        const startKey = `${ch}:${midiNote}:${evTick}`;
        if (suppressedNoteStarts.has(startKey)) {
          suppressedActiveNotes.add(`${ch}:${midiNote}`);
          return;
        }
        // Ignore likely OMR artifacts and percussion-style events in this piano player.
        if (ch === 10 || midiNote < 21 || midiNote > 108) return;

        // Test-only: suppress only duplicate retriggers of the SAME pitch.
        // Keep simultaneous upper/lower voice notes.
        if (isOmrTest) {
          const prev = lastAttackByChannel.get(ch);
          if (prev && Math.abs(evTick - prev.tick) <= 8 && midiNote === prev.note) {
            suppressedActiveNotes.add(`${ch}:${midiNote}`);
            return;
          }
          lastAttackByChannel.set(ch, { tick: evTick, note: midiNote });
        }

        const note = Tone.Frequency(event.noteNumber, "midi").toNote();
    
        if (!channelSettings[ch]) {
  const isFocused = focusedChannel === null || ch === focusedChannel;
  const baseVol = isFocused ? 1 : 0.3;

  channelSettings[ch] = {
    muted: false,
    volume: baseVol,
    userVolume: 1
  };

  renderChannelControls();
        }

        const vel = Math.max(0.08, Math.min(1, ((event.velocity || 96) / 127) * channelSettings[ch].volume));
        if (!channelSettings[ch].muted) {
          if (isOmrTest && oneShotStarts.has(startKey)) {
            try {
              if (samplerReady) sampler.triggerAttackRelease(note, "64n", undefined, vel);
              else fallbackSynth.triggerAttackRelease(note, "64n", undefined, vel);
            } catch (err) {
              console.warn("One-shot playback skipped:", err);
            }
            const k = `${ch}:${midiNote}`;
            ignoreNextOff.set(k, (ignoreNextOff.get(k) || 0) + 1);
          } else {
            try {
              if (samplerReady) sampler.triggerAttack(note, undefined, vel);
              else fallbackSynth.triggerAttack(note, undefined, vel);
            } catch (err) {
              console.warn("triggerAttack skipped:", err);
            }
            heldNotes.add(note);
          }
        }
        emitPlaybackEvent("noteon", {
          tick: event.tick ?? player?.getCurrentTick?.() ?? 0,
          noteNumber: event.noteNumber,
          channel: ch,
          velocity: event.velocity || 0
        });
        
        animateChannelPulse(ch);


        
      }
    
      if (event.name === "Note off") {
        const ch = event.channel;
        const midiNote = Number(event.noteNumber || 0);
        const activeKey = `${ch}:${midiNote}`;
        const ignoreCount = ignoreNextOff.get(activeKey) || 0;
        if (ignoreCount > 0) {
          if (ignoreCount === 1) ignoreNextOff.delete(activeKey);
          else ignoreNextOff.set(activeKey, ignoreCount - 1);
          return;
        }
        if (suppressedActiveNotes.has(activeKey)) {
          suppressedActiveNotes.delete(activeKey);
          return;
        }
        if (ch === 10 || midiNote < 21 || midiNote > 108) return;
        const note = Tone.Frequency(event.noteNumber, "midi").toNote();
        if (!sustainActive) {
          try {
            if (samplerReady) sampler.triggerRelease(note);
            else fallbackSynth.triggerRelease(note);
          } catch (err) {
            console.warn("triggerRelease skipped:", err);
          }
          heldNotes.delete(note);
        }
        emitPlaybackEvent("noteoff", {
          tick: event.tick ?? player?.getCurrentTick?.() ?? 0,
          noteNumber: event.noteNumber,
          channel: event.channel
        });
      }
    
      if (event.name === "Controller Change" && event.controllerType === 64) {
        sustainActive = event.value >= 64;
        if (!sustainActive) {
          heldNotes.forEach(note => {
            try {
              if (samplerReady) sampler.triggerRelease(note);
              else fallbackSynth.triggerRelease(note);
            } catch {}
          });
          heldNotes.clear();
        }
      }

      if (event.name === "Set Tempo") {
        baseTempoBpm = Number(event.data || baseTempoBpm || 120) || 120;
        player.setTempo(baseTempoBpm * speedMultiplier);
      }
      if (event.name === "Time Signature") {
        timeSignatureEvents = parseTimeSignatureEventsFromPlayer();
        barlineTicks = parseBarlineTicksFromPlayer();
      }
    });


  player.on("fileLoaded", () => {
    tickMax = player.totalTicks || 1;
    baseTempoBpm = Number(player.tempo || player.defaultTempo || 120) || 120;
    player.setTempo(baseTempoBpm * speedMultiplier);
    timeSignatureEvents = parseTimeSignatureEventsFromPlayer();
    barlineTicks = parseBarlineTicksFromPlayer();
    resetMetronomePhase(0);
    buildOmrArtifactSuppression();
    emitPlaybackEvent("loaded", { totalTicks: tickMax });
  });

 // player.on("playing", data => updateProgress(data.tick));
    let lastUpdate = 0;
    player.on("playing", data => {
      const now = performance.now();
      if (now - lastUpdate > 16) {
        updateProgress(data.tick);
        tickMetronome(data.tick);
        emitPlaybackEvent("playing", { tick: data.tick || 0, totalTicks: tickMax });
        lastUpdate = now;
      }
    });


  player.on("endOfFile", () => {
	playBtn.innerHTML = '<i data-lucide="play"></i>';
	lucide.createIcons();

    progress.style.width = "0%";
    resetMetronomePhase(0);
    emitPlaybackEvent("ended", { totalTicks: tickMax });
  });

  player.loadArrayBuffer(buffer);

  speedBtn?.addEventListener("click", () => {
    const idx = speedOptions.findIndex((v) => Math.abs(v - speedMultiplier) < 0.001);
    const next = speedOptions[(idx + 1 + speedOptions.length) % speedOptions.length];
    speedMultiplier = Number(next || 1);
    syncMiniControls();
    if (player && baseTempoBpm) {
      player.setTempo(baseTempoBpm * speedMultiplier);
    }
  });

  metroBtn?.addEventListener("click", () => {
    const idx = metroOptions.indexOf(metronomeMode);
    metronomeMode = metroOptions[(idx + 1 + metroOptions.length) % metroOptions.length] || "off";
    syncMiniControls();
    const currentTick = Number(player?.getCurrentTick?.() || 0);
    resetMetronomePhase(currentTick);
    if (player?.isPlaying?.()) tickMetronome(currentTick);
  });

// 	const playBtn = container.querySelector(".play-btn");

	playBtn.addEventListener("click", async () => {
	  await Tone.start();
	  await ensureSamplerReady();
	  if (!player) return;

	  if (player.isPlaying()) {
		player.pause();
		playBtn.innerHTML = '<i data-lucide="play"></i>';
		lucide.createIcons();
        emitPlaybackEvent("paused", { tick: player.getCurrentTick?.() || 0, totalTicks: tickMax });
	  } else {
		player.play();
        resetMetronomePhase(player.getCurrentTick?.() || 0);
		playBtn.innerHTML = '<i data-lucide="pause"></i>';
		lucide.createIcons();
        emitPlaybackEvent("started", { tick: player.getCurrentTick?.() || 0, totalTicks: tickMax });

		if (window._activeMidiPlayer && window._activeMidiPlayer !== player) {
		  window._activeMidiPlayer.stop();
		}
		window._activeMidiPlayer = player;
	  }
	});


  


    if (window._activeMidiPlayer && window._activeMidiPlayer !== player) {
      window._activeMidiPlayer.stop();
    }
    window._activeMidiPlayer = player;

  
  return container; // Or wrapper, depending on structure

};

function extractChannelNamesFromBuffer(buffer) {
  const names = {};
  const textDecoder = new TextDecoder();

  const readVarIntAt = (bytes, start) => {
    let value = 0;
    let i = start;
    while (i < bytes.length) {
      const b = bytes[i++];
      value = (value << 7) | (b & 0x7f);
      if ((b & 0x80) === 0) break;
    }
    return { value, next: i };
  };

  const readAscii = (bytes, start, len) => {
    try {
      return textDecoder.decode(bytes.subarray(start, start + len)).trim();
    } catch {
      return "";
    }
  };

  try {
    const bytes = new Uint8Array(buffer);
    if (bytes.length < 14) return names;
    const sig = String.fromCharCode(bytes[0], bytes[1], bytes[2], bytes[3]);
    if (sig !== "MThd") return names;

    let p = 14;
    let trackIndex = 0;

    while (p + 8 <= bytes.length) {
      const chunkSig = String.fromCharCode(bytes[p], bytes[p + 1], bytes[p + 2], bytes[p + 3]);
      const len = ((bytes[p + 4] << 24) >>> 0) | (bytes[p + 5] << 16) | (bytes[p + 6] << 8) | bytes[p + 7];
      p += 8;
      if (p + len > bytes.length) break;

      if (chunkSig !== "MTrk") {
        p += len;
        continue;
      }

      const end = p + len;
      let i = p;
      let runningStatus = 0;
      let channelPrefix = null; // 1..16 when present
      let trackName = "";
      let instrumentName = "";
      const channelsInTrack = new Set();

      while (i < end) {
        const dv = readVarIntAt(bytes, i);
        i = dv.next;
        if (i >= end) break;

        let status = bytes[i];
        if (status < 0x80) {
          // Running status
          if (!runningStatus) break;
          status = runningStatus;
        } else {
          i++;
          runningStatus = status;
        }

        if (status === 0xff) {
          if (i >= end) break;
          const metaType = bytes[i++];
          const ml = readVarIntAt(bytes, i);
          const metaLen = ml.value;
          i = ml.next;
          if (i + metaLen > end) break;

          if (metaType === 0x03) {
            const s = readAscii(bytes, i, metaLen);
            if (s) trackName = s;
          } else if (metaType === 0x04) {
            const s = readAscii(bytes, i, metaLen);
            if (s) instrumentName = s;
          } else if (metaType === 0x20 && metaLen >= 1) {
            channelPrefix = Number(bytes[i]) + 1; // MIDI file is 0-based; app uses 1-based
          }

          i += metaLen;
          continue;
        }

        if (status === 0xf0 || status === 0xf7) {
          const sl = readVarIntAt(bytes, i);
          i = sl.next + sl.value;
          continue;
        }

        const statusHi = status & 0xf0;
        const channel = (status & 0x0f) + 1; // convert to 1..16
        channelsInTrack.add(channel);

        if (statusHi === 0xc0 || statusHi === 0xd0) i += 1;
        else i += 2;
      }

      const label = (instrumentName || trackName || "").trim();
      if (label) {
        if (channelPrefix && !names[channelPrefix]) {
          names[channelPrefix] = label;
        }
        channelsInTrack.forEach((ch) => {
          if (!names[ch]) names[ch] = label;
        });
      } else if (channelPrefix && !names[channelPrefix]) {
        names[channelPrefix] = `CH ${channelPrefix}`;
      }

      p = end;
      trackIndex++;
      if (trackIndex > 128) break;
    }
  } catch (err) {
    console.warn("MIDI channel-name extraction failed:", err);
  }

  return names;
}

function saveChannelNamesToLocal(filename, data) {
  const key = `midiNames:${filename}`;
  localStorage.setItem(key, JSON.stringify(data));
}

function loadChannelNamesFromLocal(filename) {
  const key = `midiNames:${filename}`;
  try {
    return JSON.parse(localStorage.getItem(key)) || {};
  } catch {
    return {};
  }
}


function saveChannelNamesToServer(surrogate, name, channelNames) {
  fetch("/File_saveMeta.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      surrogate,
      name,
      type: "midi",
      data: channelNames
    })
  }).then(res => res.json())
    .then(result => {
      if (result.status !== "success") {
        console.warn("Failed to save MIDI metadata:", result.error);
      }
    });
}

async function loadChannelNamesFromServer(surrogate, name) {
  try {
    const res = await fetch(`/File_loadMeta.php?surrogate=${encodeURIComponent(surrogate)}&name=${encodeURIComponent(name)}&type=midi`);
    const json = await res.json();
    return json.status === "success" ? json.data : {};
  } catch (err) {
    console.warn("Failed to load MIDI metadata:", err);
    return {};
  }
}
