logStep("JSDrawingXMLPlay.js executed");

(() => {
  const core = window.__twXmlPlayInternals || {};
  const xmlPlaybackState = core.xmlPlaybackState;
  const XML_PLAYBACK_SPEED_STORAGE_KEY = "twXmlPlaybackSpeed";
  const XML_PLAYBACK_SPEED_DEFAULT = 1;
  const XML_PLAYBACK_SPEED_OPTIONS = [0.5, 0.75, 1, 1.25, 1.5, 2];
  let webAudioCtx = null;

  function getXmlPlaybackTransposeSemitones() {
    return Math.max(
      -12,
      Math.min(12, Math.round(Number(core.getXmlRenderTransposeSemitones?.() || 0)))
    );
  }

  function transposeXmlPlaybackMidi(midi) {
    const noteNumber = Math.round(Number(midi) || 0);
    if (!Number.isFinite(noteNumber)) return noteNumber;
    const transpose = getXmlPlaybackTransposeSemitones();
    return Math.max(0, Math.min(127, noteNumber + transpose));
  }

  function clampXmlPlaybackSpeed(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return XML_PLAYBACK_SPEED_DEFAULT;
    return Math.max(0.5, Math.min(2, n));
  }

  function getXmlPlaybackSpeedMultiplier() {
    try {
      return clampXmlPlaybackSpeed(localStorage.getItem(XML_PLAYBACK_SPEED_STORAGE_KEY) || XML_PLAYBACK_SPEED_DEFAULT);
    } catch {
      return XML_PLAYBACK_SPEED_DEFAULT;
    }
  }

  function getNearestXmlPlaybackSpeedOption(value) {
    const target = clampXmlPlaybackSpeed(value);
    return XML_PLAYBACK_SPEED_OPTIONS.reduce((best, option) =>
      Math.abs(option - target) < Math.abs(best - target) ? option : best,
    XML_PLAYBACK_SPEED_OPTIONS[0]);
  }

  function getNextXmlPlaybackSpeedOption(current) {
    const normalized = getNearestXmlPlaybackSpeedOption(current);
    const idx = XML_PLAYBACK_SPEED_OPTIONS.findIndex((option) => Math.abs(option - normalized) < 0.0001);
    if (idx < 0) return XML_PLAYBACK_SPEED_OPTIONS[0];
    return XML_PLAYBACK_SPEED_OPTIONS[(idx + 1) % XML_PLAYBACK_SPEED_OPTIONS.length];
  }

  function getQuarterTempoBpm(parsed, multiplier = null) {
    const tempoBpm = Math.max(20, Number(parsed?.tempoBpm || 96));
    const speed = clampXmlPlaybackSpeed(multiplier == null ? getXmlPlaybackSpeedMultiplier() : multiplier);
    return Math.max(20, tempoBpm * speed);
  }

  function setStoredXmlPlaybackSpeed(value) {
    const next = getNearestXmlPlaybackSpeedOption(value);
    try {
      localStorage.setItem(XML_PLAYBACK_SPEED_STORAGE_KEY, String(next));
    } catch {}
    return next;
  }

  async function ensureTrackStatesReady(surrogate) {
    const safeSurrogate = String(surrogate || "").trim();
    if (!safeSurrogate) return [];
    const knownTracks = core.getKnownTracksForSurrogate?.(safeSurrogate) || [];
    if (knownTracks.length) {
      return core.ensureTrackPlaybackStates?.(safeSurrogate, knownTracks) || [];
    }
    const existing = getTrackPlaybackStates(safeSurrogate, []);
    if (existing.length) return existing;
    try {
      const file = await core.getPrimaryMusicXmlFile?.(safeSurrogate);
      if (!file?.url) return [];
      const xmlText = await core.fetchMusicXmlTextFromUrl?.(file.url);
      const parsed = core.parseMusicXmlPlaybackEvents?.(xmlText);
      const tracks =
        Array.isArray(parsed?.playbackTracks) && parsed.playbackTracks.length
          ? parsed.playbackTracks
          : (Array.isArray(parsed?.tracks) ? parsed.tracks : []);
      return core.ensureTrackPlaybackStates?.(safeSurrogate, tracks) || [];
    } catch (err) {
      console.warn("MusicXML track preload failed:", err);
      return [];
    }
  }

  function getTrackPlaybackStates(surrogate, tracks = []) {
    const resolvedTracks = Array.isArray(tracks) && tracks.length
      ? tracks
      : (core.getKnownTracksForSurrogate?.(surrogate) || []);
    const states = core.ensureTrackPlaybackStates?.(surrogate, resolvedTracks) || [];
    return states.map((state) => ({ ...state }));
  }

  function setTrackMute(surrogate, trackId, mute) {
    const safeSurrogate = String(surrogate || "").trim();
    const key = String(trackId || "").trim();
    if (!safeSurrogate || !key) return false;
    const store = core.getXmlTrackStateStore?.();
    if (!store?.[safeSurrogate] || !store[safeSurrogate][key]) return false;
    store[safeSurrogate][key].mute = !!mute;
    return true;
  }

  function setTrackVolume(surrogate, trackId, volume) {
    const safeSurrogate = String(surrogate || "").trim();
    const key = String(trackId || "").trim();
    if (!safeSurrogate || !key) return false;
    const store = core.getXmlTrackStateStore?.();
    if (!store?.[safeSurrogate] || !store[safeSurrogate][key]) return false;
    store[safeSurrogate][key].volume = Math.max(0, Math.min(1, Number(volume) || 0));
    return true;
  }

  function getAudioContext() {
    if (webAudioCtx) return webAudioCtx;
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return null;
    webAudioCtx = new Ctx();
    return webAudioCtx;
  }

  async function playMidiPitch(midi, opts = {}) {
    const n = transposeXmlPlaybackMidi(midi);
    if (!Number.isFinite(n)) return false;
    const durationMs = Math.max(90, Math.min(4000, Number(opts.durationMs || 420)));
    const velocity = Math.max(0.05, Math.min(1, Number(opts.velocity) || 0.85));
    const shouldOpen = opts.open !== false;
    const shouldCenter = opts.center !== false;
    const ignoreSustain = opts.ignoreSustain !== false;
    if (window.TWPianoDock?.playMidi) {
      try {
        const played = await window.TWPianoDock.playMidi(n, {
          open: shouldOpen,
          center: shouldCenter,
          durationMs,
          velocity,
          ignoreSustain
        });
        if (played) return true;
      } catch (err) {
        console.warn("MusicXML piano click playback failed, falling back to WebAudio:", err);
      }
    }

    const ctx = getAudioContext();
    if (!ctx) return false;
    if (ctx.state === "suspended") {
      try { await ctx.resume(); } catch {}
    }
    const now = ctx.currentTime;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    const freq = 440 * Math.pow(2, (n - 69) / 12);
    osc.type = "triangle";
    osc.frequency.setValueAtTime(freq, now);
    gain.gain.setValueAtTime(0.0001, now);
    gain.gain.exponentialRampToValueAtTime(Math.max(0.0001, velocity * 0.12), now + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + durationMs / 1000);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start(now);
    osc.stop(now + durationMs / 1000 + 0.03);
    return true;
  }

  function isXmlPlaybackActive(surrogate) {
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    return !!xmlPlaybackState?.active && String(xmlPlaybackState?.surrogate || "") === safeSurrogate && !!safeSurrogate;
  }

  function getXmlPlaybackSpeedInfo(surrogate = null) {
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    const speed = getXmlPlaybackSpeedMultiplier();
    let parsed = null;
    if (
      xmlPlaybackState?.model &&
      (!safeSurrogate || String(xmlPlaybackState.surrogate || "") === safeSurrogate)
    ) {
      parsed = xmlPlaybackState.model;
    } else {
      const viewer = document.getElementById("pdfTabXmlViewer");
      if (
        viewer?._twXmlPlaybackModel &&
        (!safeSurrogate || String(window._pdfXmlViewState?.surrogate || "") === safeSurrogate)
      ) {
        parsed = viewer._twXmlPlaybackModel;
      }
    }
    const baseQuarterBpm = parsed ? Math.max(20, Number(parsed?.tempoBpm || 96)) : null;
    const quarterBpm = parsed ? Math.round(getQuarterTempoBpm(parsed, speed) || 0) : null;
    return { speed, quarterBpm, baseQuarterBpm };
  }

  function syncXmlPlaybackSpeedConsumers(surrogate = null) {
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    window.TWPianoDock?.refreshXmlMixer?.(safeSurrogate);
  }

  function getCurrentXmlOverallProgress() {
    const roots = [
      document.getElementById("twPianoXmlStatusBar"),
      document.getElementById("pdfTabXmlViewer")
    ].filter(Boolean);
    for (const root of roots) {
      const fill = root?.querySelector?.('[data-tw-xml-playhead-fill="1"]');
      if (!fill) continue;
      const widthPct = parseFloat(String(fill.style.width || "0"));
      if (Number.isFinite(widthPct)) return Math.max(0, Math.min(1, widthPct / 100));
    }
    return 0;
  }

  function setExplicitXmlStartTick(tick) {
    const viewer = document.getElementById("pdfTabXmlViewer");
    const host = viewer?._twXmlStateHost || viewer;
    if (!host) return false;
    host._twXmlSelectedStartTick = Math.max(0, Number(tick || 0));
    host._twXmlSelectedSourceIndex = null;
    host._twXmlHasExplicitStart = true;
    host._twXmlSelectedSystemBlockKey = "";
    return true;
  }

  function clearPlaybackTimers() {
    (xmlPlaybackState?.timers || []).forEach((id) => {
      try { clearTimeout(id); } catch {}
    });
    xmlPlaybackState.timers = [];
    if (xmlPlaybackState.endTimer) {
      try { clearTimeout(xmlPlaybackState.endTimer); } catch {}
      xmlPlaybackState.endTimer = 0;
    }
    if (xmlPlaybackState.playheadRaf) {
      try { cancelAnimationFrame(xmlPlaybackState.playheadRaf); } catch {}
      xmlPlaybackState.playheadRaf = 0;
    }
  }

  function clearXmlStartedNotes() {
    if (!(xmlPlaybackState?.activeNotesByKey instanceof Map) || !xmlPlaybackState.activeNotesByKey.size) {
      xmlPlaybackState.activeNotesByKey = new Map();
      xmlPlaybackState.activeNoteIds = new Set();
      xmlPlaybackState.activeMidiCounts = new Map();
      xmlPlaybackState.lastAttackTickByMidi = new Map();
      xmlPlaybackState.lastHighlightTickByTrack = new Map();
      return;
    }
    xmlPlaybackState.activeNotesByKey.forEach((entries) => {
      (Array.isArray(entries) ? entries : []).forEach((entry) => {
        const midi = Number(entry?.noteNumber);
        if (!Number.isFinite(midi)) return;
        try {
          window.TWPianoDock?.stopMidi?.(midi, { ignoreSustain: true });
        } catch {}
      });
    });
    xmlPlaybackState.activeNotesByKey = new Map();
    xmlPlaybackState.activeNoteIds = new Set();
    xmlPlaybackState.activeMidiCounts = new Map();
    xmlPlaybackState.lastAttackTickByMidi = new Map();
    xmlPlaybackState.lastHighlightTickByTrack = new Map();
  }

  function stopXmlPlayback() {
    clearPlaybackTimers();
    const player = xmlPlaybackState.player;
    xmlPlaybackState.active = false;
    const current = String(xmlPlaybackState.surrogate || "").trim();
    xmlPlaybackState.player = null;
    xmlPlaybackState.pendingStartByKey = new Map();
    clearXmlStartedNotes();
    if (player) {
      try { player.stop(); } catch {}
      if (window._activeMidiPlayer === player) window._activeMidiPlayer = null;
    }
    xmlPlaybackState.surrogate = "";
    xmlPlaybackState.model = null;
    xmlPlaybackState.activeMeasureKey = "";
    xmlPlaybackState.currentMeasureIndex = null;
    xmlPlaybackState.currentSystemBlockKey = "";
    xmlPlaybackState.currentMeasureStartTick = 0;
    xmlPlaybackState.currentMeasureEndTick = 0;
    xmlPlaybackState.measureTimeline = [];
    xmlPlaybackState.playheadAdjustmentTicks = 0;
    xmlPlaybackState.playbackStartPerf = 0;
    xmlPlaybackState.playbackStartAudio = 0;
    xmlPlaybackState.currentAbsoluteTick = 0;
    xmlPlaybackState.startSourceIndex = null;
    const viewer = document.getElementById("pdfTabXmlViewer");
    core.clearActiveMeasureHighlight?.(viewer);
    core.hideXmlPlayhead?.(viewer);
    core.updateXmlPlaybackMeasureIndicator?.(viewer);
    core.updateXmlPlayheadIndicator?.(viewer, { active: false, overallProgress: 0, measureProgress: 0, measureIndex: 0 });
    window.TWPianoDock?.clearPreviewMidi?.();
    window.TWPianoDock?.clearPlayingMidiVisuals?.();
    window.twMusicXmlView?.syncPdfXmlPlayButton?.(current || window.currentSurrogate);
  }

  function pauseXmlPlayback() {
    const current = String(xmlPlaybackState.surrogate || window.currentSurrogate || "").trim();
    const resumeProgress = getCurrentXmlOverallProgress();
    const player = xmlPlaybackState.player;
    clearPlaybackTimers();
    xmlPlaybackState.active = false;
    xmlPlaybackState.player = null;
    xmlPlaybackState.pendingStartByKey = new Map();
    clearXmlStartedNotes();
    if (player) {
      try { player.stop(); } catch {}
      if (window._activeMidiPlayer === player) window._activeMidiPlayer = null;
    }
    xmlPlaybackState.surrogate = "";
    xmlPlaybackState.model = null;
    xmlPlaybackState.activeMeasureKey = "";
    xmlPlaybackState.currentMeasureIndex = null;
    xmlPlaybackState.currentSystemBlockKey = "";
    xmlPlaybackState.currentMeasureStartTick = 0;
    xmlPlaybackState.currentMeasureEndTick = 0;
    xmlPlaybackState.measureTimeline = [];
    xmlPlaybackState.playheadAdjustmentTicks = 0;
    xmlPlaybackState.playbackStartPerf = 0;
    xmlPlaybackState.playbackStartAudio = 0;
    xmlPlaybackState.currentAbsoluteTick = 0;
    xmlPlaybackState.startSourceIndex = null;
    window.TWPianoDock?.clearPreviewMidi?.();
    window.TWPianoDock?.clearPlayingMidiVisuals?.();
    Promise.resolve(core.setXmlPlaybackPositionByProgress?.(resumeProgress, {
      surrogate: current,
      commit: false,
      resumePlayback: false
    })).catch((err) => {
      console.warn("MusicXML pause position update failed:", err);
    }).finally(() => {
      window.twMusicXmlView?.syncPdfXmlPlayButton?.(current || window.currentSurrogate);
    });
  }

  function setXmlPlaybackSpeedMultiplier(value, opts = {}) {
    const next = setStoredXmlPlaybackSpeed(value);
    const safeSurrogate = String(opts?.surrogate || window.currentSurrogate || "").trim();
    const affectsActivePlayback =
      xmlPlaybackState.active &&
      (!safeSurrogate || String(xmlPlaybackState.surrogate || "") === safeSurrogate);
    if (affectsActivePlayback) {
      const resumeTick = Math.max(
        0,
        Number.isFinite(Number(xmlPlaybackState.currentAbsoluteTick))
          ? Number(xmlPlaybackState.currentAbsoluteTick)
          : 0
      );
      stopXmlPlayback();
      setExplicitXmlStartTick(resumeTick);
      Promise.resolve(
        playXmlSequence(safeSurrogate || window.currentSurrogate || "", window._pdfXmlViewState?.file || null)
      ).catch((err) => {
        console.warn("MusicXML speed restart failed:", err);
      });
      syncXmlPlaybackSpeedConsumers(safeSurrogate);
      return next;
    }
    syncXmlPlaybackSpeedConsumers(safeSurrogate);
    return next;
  }

  function cycleXmlPlaybackSpeed(surrogate = null) {
    const current = getXmlPlaybackSpeedMultiplier();
    const next = getNextXmlPlaybackSpeedOption(current);
    return setXmlPlaybackSpeedMultiplier(next, { surrogate });
  }

  async function toggleXmlPlayback(surrogate = null) {
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    if (!safeSurrogate) return false;
    if (isXmlPlaybackActive(safeSurrogate)) {
      pauseXmlPlayback();
      return true;
    }
    const file = await core.getPrimaryMusicXmlFile?.(safeSurrogate);
    if (!file?.url) return false;
    return !!(await playXmlSequence(safeSurrogate, file));
  }

  async function playXmlSequence(surrogate, file = null) {
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    if (!safeSurrogate) return false;

    const targetFile = file || (await core.getPrimaryMusicXmlFile?.(safeSurrogate));
    if (!targetFile?.url) return false;

    const xmlViewer = document.getElementById("pdfTabXmlViewer");
    const selectionHost = xmlViewer?._twXmlStateHost || xmlViewer;
    let parsed = xmlViewer?._twXmlPlaybackModel || null;
    if (!parsed) {
      const xmlText = await core.fetchMusicXmlTextFromUrl?.(targetFile.url);
      parsed = core.parseMusicXmlPlaybackEvents?.(xmlText);
      if (xmlViewer) xmlViewer._twXmlPlaybackModel = parsed;
    }
    const tracks =
      Array.isArray(parsed?.playbackTracks) && parsed.playbackTracks.length
        ? parsed.playbackTracks
        : (Array.isArray(parsed?.tracks) ? parsed.tracks : []);
    const timeSlices = Array.isArray(parsed?.timeSlices) ? parsed.timeSlices : [];
    if (!timeSlices.length) return false;
    const midiData = core.buildMidiFromPlaybackModel?.(parsed);
    if (!midiData?.bytes?.length) return false;

    stopXmlPlayback();
    xmlPlaybackState.active = true;
    xmlPlaybackState.surrogate = safeSurrogate;
    xmlPlaybackState.model = parsed;
    xmlPlaybackState.currentMeasureIndex = null;
    xmlPlaybackState.currentSystemBlockKey = "";
    xmlPlaybackState.currentMeasureStartTick = 0;
    xmlPlaybackState.currentMeasureEndTick = 0;
    const rawSelectedSourceIndex = selectionHost?._twXmlSelectedSourceIndex;
    const selectedSourceIndex = Number(rawSelectedSourceIndex);
    const hasExplicitStart = !!selectionHost?._twXmlHasExplicitStart;
    const selectedStartTickValue = selectionHost?._twXmlSelectedStartTick;
    const hasSelectedStartTick =
      hasExplicitStart &&
      selectedStartTickValue !== null &&
      typeof selectedStartTickValue !== "undefined" &&
      Number.isFinite(Number(selectedStartTickValue));
    const rawSelectedStartTick = hasSelectedStartTick ? Number(selectedStartTickValue) : null;
    xmlPlaybackState.startSourceIndex =
      !hasExplicitStart || rawSelectedSourceIndex === null || typeof rawSelectedSourceIndex === "undefined"
        ? null
        : (Number.isFinite(selectedSourceIndex) ? selectedSourceIndex : null);
    xmlPlaybackState.pendingStartByKey = new Map();
    xmlPlaybackState.activeNotesByKey = new Map();
    xmlPlaybackState.activeNoteIds = new Set();
    xmlPlaybackState.activeMidiCounts = new Map();
    xmlPlaybackState.lastAttackTickByMidi = new Map();
    xmlPlaybackState.lastHighlightTickByTrack = new Map();
    xmlPlaybackState.measureTimeline = [];
    xmlPlaybackState.playheadAdjustmentTicks = 0;
    xmlPlaybackState.playbackStartPerf = 0;
    xmlPlaybackState.playbackStartAudio = 0;
    xmlPlaybackState.currentAbsoluteTick = 0;
    if (xmlViewer && !xmlViewer._twXmlMeasureStats) {
      xmlViewer._twXmlMeasureStats = core.getXmlMeasureStats?.(parsed);
    }
    core.updateXmlPlaybackMeasureIndicator?.(xmlViewer);
    window.twMusicXmlView?.syncPdfXmlPlayButton?.(safeSurrogate);

    const startTick =
      rawSelectedStartTick !== null
        ? Math.max(0, rawSelectedStartTick)
        : Number.isFinite(xmlPlaybackState.startSourceIndex)
        ? Math.max(0, Number(midiData.sourceIndexToStartTick[String(Number(xmlPlaybackState.startSourceIndex))] || 0))
        : 0;
    const quarterBpm = Math.max(20, Number(midiData.tempoBpm || 120));
    const msPerTick = 60000 / quarterBpm / Math.max(1, Number(core.XML_PLAYBACK_DIVISION || 480));
    let hasOpened = false;

    let events = (Array.isArray(parsed.events) ? parsed.events : [])
      .map((ev) => {
        const tick = Math.max(0, Math.floor(Number(ev?.startUnit || 0)));
        return {
          ...ev,
          startTickAbs: tick,
          startTick: Math.max(0, tick - startTick),
          durationTick: Math.max(1, Math.floor(Number(ev?.durationUnit || 1)))
        };
      })
      .filter((ev) => Number(ev.startTickAbs || 0) >= startTick)
      .sort((a, b) =>
        Number(a.startTick || 0) - Number(b.startTick || 0) ||
        Number(a.partIndex || 0) - Number(b.partIndex || 0) ||
        Number(a.midi || 0) - Number(b.midi || 0) ||
        Number(a.sourceIndex || 0) - Number(b.sourceIndex || 0)
      );
    if (!events.length) {
      stopXmlPlayback();
      return false;
    }
    const playheadMeta = xmlViewer?._twXmlPlayheadMeta || null;
    xmlPlaybackState.measureTimeline = Array.isArray(playheadMeta?.measureTimeline) && playheadMeta.measureTimeline.length
      ? playheadMeta.measureTimeline
      : core.buildXmlMeasureTimeline?.(events, parsed);
    xmlPlaybackState.playheadAdjustmentTicks = 0;
    const firstEvent = events[0] || null;
    const fullTotalTicks = Math.max(
      1,
      Number(playheadMeta?.totalTicks || 0) || events.reduce((max, ev) =>
        Math.max(max, Number(ev.startTickAbs || 0) + Math.max(1, Number(ev.durationTick || 1))), 0)
    );
    const playbackTickBase = Math.max(0, startTick);
    const pickupLeadTicks = 0;
    const visualTotalTicks = Math.max(1, fullTotalTicks);
    const displayMeasureTimeline = Array.isArray(playheadMeta?.displayMeasureTimeline) && playheadMeta.displayMeasureTimeline.length
      ? playheadMeta.displayMeasureTimeline
      : xmlPlaybackState.measureTimeline;
    const displayTotalTicks = Math.max(1, Number(playheadMeta?.displayTotalTicks || visualTotalTicks));
    const displayStartByMeasureIndex = {};
    const displayEndByMeasureIndex = {};
    displayMeasureTimeline.forEach((entry) => {
      const key = String(Math.max(0, Number(entry?.measureIndex || 0)));
      if (!(key in displayStartByMeasureIndex)) {
        displayStartByMeasureIndex[key] = Math.max(0, Number(entry?.startTick || 0));
        displayEndByMeasureIndex[key] = Math.max(
          Number(displayStartByMeasureIndex[key] || 0) + 1,
          Number(entry?.endTick || (Number(displayStartByMeasureIndex[key] || 0) + 1))
        );
      }
    });
    let initialMeasureTimelineIndex = 0;
    let initialMeasure = xmlPlaybackState.measureTimeline[0] || null;
    for (let i = 1; i < xmlPlaybackState.measureTimeline.length; i += 1) {
      if (Number(xmlPlaybackState.measureTimeline[i]?.startTick || 0) <= playbackTickBase) {
        initialMeasureTimelineIndex = i;
        initialMeasure = xmlPlaybackState.measureTimeline[i];
      } else {
        break;
      }
    }
    if (!initialMeasure && firstEvent) {
      initialMeasure =
        xmlPlaybackState.measureTimeline.find((entry) =>
          Math.max(0, Number(entry?.measureIndex || 0)) === Math.max(0, Number(firstEvent?.measureIndex || 0))
        ) || null;
    }
    if (initialMeasure) {
      const initialMeasureIndex = Math.max(0, Number(initialMeasure.measureIndex || 0));
      xmlPlaybackState.currentMeasureIndex = initialMeasureIndex;
      core.updateXmlPlaybackMeasureIndicator?.(xmlViewer, { measureIndex: initialMeasureIndex });
    }
    const selectedSystemBlockKey = String(selectionHost?._twXmlSelectedSystemBlockKey || "");
    const initialMeasureIndex = Math.max(0, Number(initialMeasure?.measureIndex || 0));
    const initialMeasureStart = Math.max(0, Number(initialMeasure?.startTick || 0));
    const initialMeasureEnd = Math.max(initialMeasureStart + 1, Number(initialMeasure?.endTick || (initialMeasureStart + 1)));
    const initialMeasureSpan = Math.max(1, initialMeasureEnd - initialMeasureStart);
    const initialAbsoluteTick = playbackTickBase;
    xmlPlaybackState.currentAbsoluteTick = initialAbsoluteTick;
    const initialVisualTick = Math.max(0, initialAbsoluteTick);
    let initialVisualMeasureTimelineIndex = initialMeasureTimelineIndex;
    let initialVisualMeasure = xmlPlaybackState.measureTimeline[0] || initialMeasure || null;
    for (let i = 1; i < xmlPlaybackState.measureTimeline.length; i += 1) {
      if (Number(xmlPlaybackState.measureTimeline[i]?.startTick || 0) <= initialVisualTick) {
        initialVisualMeasureTimelineIndex = i;
        initialVisualMeasure = xmlPlaybackState.measureTimeline[i];
      } else {
        break;
      }
    }
    const initialVisualMeasureIndex = Math.max(0, Number(initialVisualMeasure?.measureIndex || initialMeasureIndex || 0));
    const initialVisualMeasureStart = Math.max(0, Number(initialVisualMeasure?.startTick || 0));
    const initialVisualMeasureEnd = Math.max(
      initialVisualMeasureStart + 1,
      Number(initialVisualMeasure?.endTick || (initialVisualMeasureStart + 1))
    );
    const initialVisualMeasureSpan = Math.max(1, initialVisualMeasureEnd - initialVisualMeasureStart);
    const initialMeasureProgress = Math.max(
      0,
      Math.min(1, (initialVisualTick - initialVisualMeasureStart) / initialVisualMeasureSpan)
    );
    xmlPlaybackState.currentSystemBlockKey =
      core.resolveXmlSystemBlockKeyForMeasure?.(
        xmlViewer,
        selectedSystemBlockKey || "0",
        initialVisualMeasureIndex,
        xmlViewer
      ) ||
      selectedSystemBlockKey ||
      "0";
    const hasExplicitStartAnchor = Number.isFinite(xmlPlaybackState.startSourceIndex);
    if (hasExplicitStartAnchor) {
      core.updateXmlPlayheadFromSourceIndex?.(
        xmlViewer,
        Math.max(0, Number(xmlPlaybackState.startSourceIndex || 0)),
        { active: true, showValue: true }
      );
    } else {
      core.positionXmlPlayheadAtProgress?.(
        xmlViewer,
        xmlPlaybackState.currentSystemBlockKey,
        initialVisualMeasureIndex,
        initialMeasureProgress
      );
    }
    core.updateXmlPlayheadIndicator?.(xmlViewer, {
      active: true,
      absoluteTick: initialVisualTick,
      overallProgress: (() => {
        const key = String(initialVisualMeasureIndex);
        const s = Math.max(0, Number(displayStartByMeasureIndex[key] || 0));
        const e = Math.max(s + 1, Number(displayEndByMeasureIndex[key] || (s + 1)));
        const displayTick = s + (initialMeasureProgress * Math.max(1, e - s));
        return Math.max(0, Math.min(1, displayTick / displayTotalTicks));
      })(),
      measureProgress: initialMeasureProgress,
      measureIndex: initialVisualMeasureIndex
    });

    const startupBufferMs = 320;
    const postPrepareSettleMs = 80;
    await window.TWPianoDock?.prepareScheduledPlayback?.({ open: true, strictTiming: true });
    if (postPrepareSettleMs > 0) await new Promise((resolve) => setTimeout(resolve, postPrepareSettleMs));

    const audioGroups = Array.from(
      events.reduce((map, ev) => {
        const noteNumber = transposeXmlPlaybackMidi(ev.midi);
        const tick = Math.max(0, Number(ev.startTick || 0));
        const key = `${noteNumber}:${tick}`;
        if (!map.has(key)) {
          map.set(key, {
            key,
            noteNumber,
            startTick: tick,
            startMs: tick * msPerTick,
            durationTick: Math.max(1, Number(ev.durationTick || 1)),
            members: [ev]
          });
        } else {
          const entry = map.get(key);
          entry.durationTick = Math.max(entry.durationTick, Math.max(1, Number(ev.durationTick || 1)));
          entry.members.push(ev);
        }
        return map;
      }, new Map()).values()
    ).sort((a, b) => Number(a.startTick || 0) - Number(b.startTick || 0) || Number(a.noteNumber || 0) - Number(b.noteNumber || 0));
    const previewNotesByTick = events.reduce((map, ev) => {
      const tick = Math.max(0, Number(ev.startTick || 0));
      if (!map.has(tick)) map.set(tick, new Set());
      map.get(tick).add(transposeXmlPlaybackMidi(ev.midi));
      return map;
    }, new Map());
    const previewAppliedTicks = new Set();

    const audioLeadSec = 0.1 + (startupBufferMs / 1000);
    const audioLeadMs = Math.max(startupBufferMs, Math.round(audioLeadSec * 1000));
    const audioLookaheadMs = 120;
    const audioPollMs = 25;
    const playheadLagMs = 85;
    const schedulerStartPerf = performance.now();
    xmlPlaybackState.playbackStartPerf = schedulerStartPerf + audioLeadMs;
    const schedulerStartAudio = (window.Tone?.now?.() || 0) + audioLeadSec;
    xmlPlaybackState.playbackStartAudio = schedulerStartAudio;
    let nextAudioGroupIndex = 0;
    const stateHost = xmlViewer?._twXmlStateHost || xmlViewer;
    const systemKeys = Object.keys(stateHost?._twXmlSystemFramesByBlockKey || {})
      .map((key) => Number(key))
      .filter((value) => Number.isFinite(value) && value >= 0);
    const maxSystemKey = systemKeys.length ? Math.max(...systemKeys) : -1;
    const timeline = Array.isArray(xmlPlaybackState.measureTimeline) ? xmlPlaybackState.measureTimeline : [];
    let timelineCursor = Math.max(0, Math.min(timeline.length - 1, Number(initialVisualMeasureTimelineIndex || 0)));

    const scheduleAudioWindow = () => {
      if (!xmlPlaybackState.active || String(xmlPlaybackState.surrogate || "") !== safeSurrogate) return;
      const elapsedMs = Math.max(0, performance.now() - schedulerStartPerf);
      const horizonMs = elapsedMs + audioLookaheadMs;
      const liveTrackStateById = core.getTrackStateLookup?.(safeSurrogate, tracks);
      while (nextAudioGroupIndex < audioGroups.length) {
        const group = audioGroups[nextAudioGroupIndex];
        const startMs = Number(group?.startMs || 0);
        if (startMs > horizonMs) break;
        nextAudioGroupIndex += 1;
        let velocity = 0;
        let hasAnyActive = false;
        (Array.isArray(group.members) ? group.members : []).forEach((member) => {
          const state = liveTrackStateById.get(String(member.trackId || "")) || { mute: false, volume: 1 };
          if (state.mute || Number(state.volume || 0) <= 0) return;
          hasAnyActive = true;
          velocity = Math.max(velocity, Math.max(0.05, Math.min(1, Number(state.volume || 1))));
        });
        if (!hasAnyActive) continue;
        window.TWPianoDock?.scheduleMidiAt?.(group.noteNumber, {
          at: schedulerStartAudio + (startMs / 1000),
          durationSec: Math.max(0.012, (Math.max(1, Number(group.durationTick || 1)) * msPerTick) / 1000),
          velocity,
          strictTiming: true
        });
      }
    };
    scheduleAudioWindow();
    const schedulerTimer = setInterval(scheduleAudioWindow, audioPollMs);
    xmlPlaybackState.timers.push(schedulerTimer);

    let lastAutoScrolledSystemBlockKey = "";
    const stepPlayheadUi = () => {
      if (!xmlPlaybackState.active || String(xmlPlaybackState.surrogate || "") !== safeSurrogate) {
        xmlPlaybackState.playheadRaf = 0;
        return;
      }
      const frameNowMs = performance.now();
      const toneNow = Number(window.Tone?.now?.());
      const elapsedMs = Number.isFinite(toneNow) && Number.isFinite(Number(xmlPlaybackState.playbackStartAudio))
        ? Math.max(0, (toneNow - Number(xmlPlaybackState.playbackStartAudio || 0)) * 1000)
        : Math.max(0, frameNowMs - Number(xmlPlaybackState.playbackStartPerf || 0));
      const visualElapsedMs = Math.max(0, elapsedMs - playheadLagMs);
      const currentTick = Math.max(0, visualElapsedMs / msPerTick);
      const absoluteTick = Math.max(0, playbackTickBase + currentTick);
      xmlPlaybackState.currentAbsoluteTick = absoluteTick;
      const visualAbsoluteTick = absoluteTick;
      if (timeline.length > 0) {
        while (
          timelineCursor + 1 < timeline.length &&
          Number(timeline[timelineCursor + 1]?.startTick || 0) <= visualAbsoluteTick
        ) {
          timelineCursor += 1;
        }
        while (
          timelineCursor > 0 &&
          Number(timeline[timelineCursor]?.startTick || 0) > visualAbsoluteTick
        ) {
          timelineCursor -= 1;
        }
      }
      const currentMeasure = timeline[Math.max(0, timelineCursor)] || timeline[0] || null;
      const measureStart = Math.max(0, Number(currentMeasure?.startTick || 0));
      const measureEnd = Math.max(measureStart + 1, Number(currentMeasure?.endTick || (measureStart + 1)));
      const measureSpan = Math.max(1, measureEnd - measureStart);
      const currentMeasureIndex = Math.max(0, Number(currentMeasure?.measureIndex || 0));
      const displayMeasureProgress = Math.max(
        0,
        Math.min(1, (visualAbsoluteTick - measureStart) / measureSpan)
      );
      const displayOverallProgress = (() => {
        const key = String(currentMeasureIndex);
        const s = Math.max(0, Number(displayStartByMeasureIndex[key] || 0));
        const e = Math.max(s + 1, Number(displayEndByMeasureIndex[key] || (s + 1)));
        const displayTick = s + (displayMeasureProgress * Math.max(1, e - s));
        return Math.max(0, Math.min(1, displayTick / displayTotalTicks));
      })();
      const resolvedSystemBlockKey = core.resolveXmlSystemBlockKeyForMeasure?.(
        xmlViewer,
        xmlPlaybackState.currentSystemBlockKey || String(xmlViewer?._twXmlSelectedSystemBlockKey || "") || "0",
        currentMeasureIndex,
        xmlViewer
      ) || xmlPlaybackState.currentSystemBlockKey || String(xmlViewer?._twXmlSelectedSystemBlockKey || "") || "0";
      const nextSystemBlockKey = String(resolvedSystemBlockKey || "0");
      const prevMeasureIndex = Number(xmlPlaybackState.currentMeasureIndex);
      const measureChanged = xmlPlaybackState.currentMeasureIndex !== currentMeasureIndex;
      const repeatJumpBack = Number.isFinite(prevMeasureIndex) && prevMeasureIndex >= 0 && currentMeasureIndex < prevMeasureIndex;
      if (measureChanged) {
        xmlPlaybackState.currentMeasureIndex = currentMeasureIndex;
        core.updateXmlPlaybackMeasureIndicator?.(xmlViewer, { measureIndex: currentMeasureIndex });
      }
      xmlPlaybackState.currentSystemBlockKey = nextSystemBlockKey;
      core.positionXmlPlayheadAtProgress?.(
        xmlViewer,
        xmlPlaybackState.currentSystemBlockKey,
        currentMeasureIndex,
        displayMeasureProgress
      );
      if (nextSystemBlockKey !== lastAutoScrolledSystemBlockKey || repeatJumpBack) {
        lastAutoScrolledSystemBlockKey = nextSystemBlockKey;
        const isLastSystem = Number(nextSystemBlockKey) >= 0 && maxSystemKey >= 0 && Number(nextSystemBlockKey) >= maxSystemKey;
        if ((repeatJumpBack || Number(nextSystemBlockKey) > 0) && !isLastSystem) {
          requestAnimationFrame(() => {
            core.scrollXmlPlayheadIntoView?.(xmlViewer, {
              extraBottom: 99,
              topPad: 24,
              systemBlockKey: nextSystemBlockKey,
              centerOnSystemChange: true,
              smoothFast: true
            });
          });
        }
      }
      core.updateXmlPlayheadIndicator?.(xmlViewer, {
        active: true,
        absoluteTick: visualAbsoluteTick,
        overallProgress: displayOverallProgress,
        measureProgress: displayMeasureProgress,
        measureIndex: currentMeasureIndex
      });
      xmlPlaybackState.playheadRaf = requestAnimationFrame(stepPlayheadUi);
    };
    xmlPlaybackState.playheadRaf = requestAnimationFrame(stepPlayheadUi);

    events.forEach((ev) => {
      const noteNumber = transposeXmlPlaybackMidi(ev.midi);
      const channel = Math.max(1, Math.min(16, Number(ev.partIndex || 0) + 1));
      const tick = Math.max(0, Number(ev.startTick || 0));
      const startDelayMs = audioLeadMs + Math.max(0, Math.round(tick * msPerTick));
      const durationMs = Math.max(8, Math.round(Number(ev.durationTick || 1) * msPerTick));
      const onTimer = setTimeout(() => {
        if (!xmlPlaybackState.active || String(xmlPlaybackState.surrogate || "") !== safeSurrogate) return;
        const measureIndex = Math.max(0, Number(ev.measureIndex || 0));
        const measureScale =
          String(xmlViewer?._twXmlMeasureScaleByIndex?.[String(measureIndex)] || "") ||
          String(parsed?.measureScaleByIndex?.[String(measureIndex)] || "");
        if (measureScale) window.TWPianoDock?.setScale?.(measureScale, { persist: false });

        const liveTrackStateById = core.getTrackStateLookup?.(safeSurrogate, tracks);
        const trackState = liveTrackStateById.get(String(ev.trackId || "")) || { mute: false, volume: 1 };
        if (core.isXmlPianoVisualsEnabled?.() && !previewAppliedTicks.has(tick)) {
          previewAppliedTicks.add(tick);
          window.TWPianoDock?.previewMidiNotes?.(Array.from(previewNotesByTick.get(tick) || []), { open: false });
        }
        if (trackState.mute || Number(trackState.volume || 0) <= 0) return;
        window.TWPianoDock?.setPlayingMidiVisual?.(noteNumber, true);

        const startKey = `${channel}:${noteNumber}:${tick}`;
        const noteId = `${startKey}:${Number(ev.sourceIndex || 0)}`;
        xmlPlaybackState.activeNoteIds.add(noteId);
        const activeKey = `${channel}:${noteNumber}`;
        if (!xmlPlaybackState.activeNotesByKey.has(activeKey)) xmlPlaybackState.activeNotesByKey.set(activeKey, []);
        xmlPlaybackState.activeNotesByKey.get(activeKey).push({ ...ev, noteId, noteNumber });
        const activeMidiCount = Number(xmlPlaybackState.activeMidiCounts.get(noteNumber) || 0) + 1;
        xmlPlaybackState.activeMidiCounts.set(noteNumber, activeMidiCount);
        if (!hasOpened) hasOpened = true;
      }, startDelayMs);
      xmlPlaybackState.timers.push(onTimer);

      const offTimer = setTimeout(() => {
        if (!xmlPlaybackState.active || String(xmlPlaybackState.surrogate || "") !== safeSurrogate) return;
        const activeKey = `${channel}:${noteNumber}`;
        const stack = xmlPlaybackState.activeNotesByKey.get(activeKey) || [];
        const activeMeta = stack.shift() || null;
        if (stack.length) xmlPlaybackState.activeNotesByKey.set(activeKey, stack);
        else xmlPlaybackState.activeNotesByKey.delete(activeKey);
        if (!activeMeta) return;
        xmlPlaybackState.activeNoteIds.delete(String(activeMeta.noteId || ""));
        const remainingMidiCount = Math.max(0, Number(xmlPlaybackState.activeMidiCounts.get(noteNumber) || 0) - 1);
        if (remainingMidiCount > 0) xmlPlaybackState.activeMidiCounts.set(noteNumber, remainingMidiCount);
        else xmlPlaybackState.activeMidiCounts.delete(noteNumber);
        window.TWPianoDock?.setPlayingMidiVisual?.(noteNumber, false);
      }, startDelayMs + durationMs);
      xmlPlaybackState.timers.push(offTimer);
    });

    const totalDurationMs = Math.max(
      50,
      Math.round(
        events.reduce((max, ev) =>
          Math.max(max, Number(ev.startTick || 0) + Number(ev.durationTick || 0)), 0
        ) * msPerTick
      ) + 40
    );
    xmlPlaybackState.endTimer = setTimeout(() => {
      if (xmlPlaybackState.active && String(xmlPlaybackState.surrogate || "") === safeSurrogate) {
        stopXmlPlayback();
      }
    }, startupBufferMs + totalDurationMs);
    return true;
  }

  const playApi = {
    getNextXmlPlaybackSpeedOption,
    getQuarterTempoBpm,
    setStoredXmlPlaybackSpeed,
    ensureTrackStatesReady,
    getTrackPlaybackStates,
    setTrackMute,
    setTrackVolume,
    playMidiPitch,
    isXmlPlaybackActive,
    getXmlPlaybackSpeedMultiplier,
    getXmlPlaybackSpeedInfo,
    syncXmlPlaybackSpeedConsumers,
    getCurrentXmlOverallProgress,
    setXmlPlaybackSpeedMultiplier,
    cycleXmlPlaybackSpeed,
    toggleXmlPlayback,
    clearPlaybackTimers,
    clearXmlStartedNotes,
    pauseXmlPlayback,
    stopXmlPlayback,
    playXmlSequence
  };

  window.twMusicXmlPlay = Object.assign(window.twMusicXmlPlay || {}, playApi);
  window.twMusicXml = Object.assign(window.twMusicXml || {}, playApi);
})();
