(function () {
  if (window.TWPianoDock) return;

  const STORAGE_KEY = "twPianoDockOpen";
  const SCALE_STORAGE_KEY = "twPianoScale";
  const CLEF_STORAGE_KEY = "twPianoClefMode";
  const XML_HIDE_OTHER_VOICES_STORAGE_KEY = "twXmlHideOtherVoices";
  const XML_SHRINK_OTHER_VOICES_STORAGE_KEY = "twXmlShrinkOtherVoices";
  const NOTE_MIN = 21;  // A0
  const NOTE_MAX = 108; // C8

  const state = {
    parent: null,
    host: null,
    dock: null,
    scroller: null,
    keyboard: null,
    swipeStrip: null,
    scaleBtn: null,
    scaleMenu: null,
    scaleSig: null,
    playScaleBtn: null,
    sampler: null,
    scoreSampler: null,
    samplerReady: false,
    scoreSamplerReady: false,
    samplerLoadFailed: false,
    scoreSamplerLoadFailed: false,
    activeNotes: new Set(),
    activeSamplerKindByMidi: new Map(),
    downNotes: new Set(),
    pendingRelease: new Set(),
    releaseTimers: new Map(),
    scalePreviewTimers: [],
    keyByMidi: new Map(),
    isOpen: false,
    userPositioned: false,
    keyLayoutSig: "",
    sustainLevel: 2,
    accidentalMode: "flat",
    scaleValue: "off",
    lastTouchedMidi: 60,
    clefMode: "G",
    externalPreviewMidis: new Set(),
    externalActiveMidiCounts: new Map(),
    externalPulseTimers: new Map(),
    xmlScaleApplyTimer: 0,
    xmlPianoToggleBtn: null,
    xmlRewindBtn: null,
    xmlOsmdPanel: null,
    xmlOsmdJson: null,
    xmlOsmdStatus: null,
    xmlOsmdCatalog: null,
    xmlOsmdLiveApplyTimer: 0
  };

  function injectStyles() {
    if (document.getElementById("twPianoDockStyles")) return;
    const style = document.createElement("style");
    style.id = "twPianoDockStyles";
    style.textContent = `
      #twPianoDockHost {
        position: fixed;
        left: 0;
        width: 100%;
        bottom: calc(var(--tw-footer-offset, 40px) + env(safe-area-inset-bottom, 0px));
        z-index: 5000;
        background: rgba(8, 12, 20, 0.9);
        border-top: 1px solid rgba(255, 255, 255, 0.12);
        backdrop-filter: blur(3px);
      }

      body.tw-piano-open #mainContentWrapper {
        z-index: 2000 !important;
      }

      body.tw-piano-open #twPianoDockHost {
        z-index: 5001 !important;
      }

      #twPianoDock {
        display: none;
        border-top: 1px solid rgba(255, 255, 255, 0.12);
        background: linear-gradient(180deg, #111827, #0b1220);
      }

      #twPianoDock.tw-open,
      #twPianoDock.tw-xml-visible {
        display: block;
      }

      #twPianoDock.tw-strips-only .tw-piano-head,
      #twPianoDock.tw-strips-only #twPianoSwipeStrip,
      #twPianoDock.tw-strips-only .tw-piano-scroll {
        display: none;
      }

      .tw-piano-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 3px 7px;
        color: #e5e7eb;
        font-size: 10px;
        touch-action: none;
        user-select: none;
        cursor: ew-resize;
      }

      .tw-piano-head-main {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
      }

      .tw-piano-head-main strong {
        white-space: nowrap;
      }

      .tw-piano-head-controls {
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        gap: 6px;
        flex-wrap: nowrap;
        min-width: 0;
      }

      .tw-piano-head button,
      .tw-piano-pedal,
      .tw-piano-xml-endbtn,
      .tw-piano-xml-tailbtn {
        min-width: 46px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: transparent;
        color: #e5e7eb;
        border-radius: 8px;
        padding: 1px 6px;
        font-size: 9px;
      }

      .tw-piano-xml-bubble {
        width: 30px;
        height: 30px;
        min-width: 30px;
        margin: -4px 1px -4px 0;
        align-self: center;
        padding: 0;
        border-radius: 999px;
        border: 1px solid rgba(147, 220, 154, 0.72) !important;
        background: radial-gradient(circle at 35% 35%, rgba(126, 231, 135, 0.96), rgba(52, 168, 83, 0.96)) !important;
        color: #f0fff4 !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 14px !important;
        font-weight: 700;
        line-height: 1;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25), 0 2px 8px rgba(0, 0, 0, 0.28);
      }

      .tw-piano-xml-bubble svg {
        width: 16px;
        height: 16px;
        stroke-width: 2.5;
      }

      .tw-piano-xml-bubble.is-playing {
        background: radial-gradient(circle at 35% 35%, rgba(74, 222, 128, 0.98), rgba(22, 163, 74, 0.98)) !important;
        border-color: rgba(187, 247, 208, 0.92) !important;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.28), 0 0 0 2px rgba(34, 197, 94, 0.18), 0 2px 10px rgba(0, 0, 0, 0.32);
      }

      .tw-piano-xml-bubble.tw-piano-xml-rewind {
        width: 22px;
        height: 22px;
        min-width: 22px;
        margin: 0 0 0 1px;
        align-self: center;
        border: 1px solid rgba(255, 255, 255, 0.12) !important;
        background: rgba(15, 23, 42, 0.92) !important;
        color: #e5e7eb !important;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06), 0 1px 4px rgba(0, 0, 0, 0.22);
      }

      .tw-piano-xml-bubble.tw-piano-xml-rewind svg {
        width: 13px;
        height: 13px;
      }

      .tw-piano-xml-bubble.tw-piano-xml-rewind:hover {
        background: rgba(30, 41, 59, 0.96) !important;
        border-color: rgba(255, 255, 255, 0.18) !important;
      }

      .tw-piano-xml-mixbar {
        display: none;
        align-items: center;
        gap: 5px;
        min-height: 20px;
        padding: 1px 7px;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        background: rgba(15, 23, 42, 0.82);
        overflow: visible;
      }

      .tw-piano-xml-statusbar {
        display: none;
        align-items: center;
        gap: 4px;
        min-width: 0;
        width: 100%;
        padding: 4px 8px;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        background: rgba(15, 23, 42, 0.9);
        box-sizing: border-box;
        user-select: none;
      }

      .tw-piano-xml-beatbadge {
        flex: 0 0 auto;
        min-width: 20px;
        height: 20px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 7px;
        border-radius: 999px;
        border: 1px solid rgba(96, 165, 250, 0.36);
        background: rgba(30, 41, 59, 0.82);
        color: #dbeafe;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 0.01em;
        line-height: 1;
        box-sizing: border-box;
        opacity: 1;
        white-space: nowrap;
      }
      .tw-piano-xml-repeat-onbar {
        position: absolute;
        left: 0;
        top: 50%;
        transform: translate(-50%, -50%);
        min-width: 24px;
        height: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 4px;
        border-radius: 999px;
        border: 1px solid rgba(251, 146, 60, 0.38);
        background: rgba(124, 45, 18, 0.6);
        color: #ffedd5;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 0.01em;
        line-height: 1;
        box-sizing: border-box;
        opacity: 0;
        pointer-events: none;
        z-index: 3;
        white-space: nowrap;
      }
      .tw-piano-xml-repeat-onbar.is-end {
        min-width: 18px;
        padding: 0 3px;
        transform: translate(-100%, -50%);
        border-color: rgba(148, 163, 184, 0.45);
        background: rgba(30, 41, 59, 0.7);
        color: #e2e8f0;
      }

      .tw-piano-xml-statusscale {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        min-width: 0;
        padding-left: 1px;
        color: rgba(226, 232, 240, 0.82);
        font-size: 9px;
        white-space: nowrap;
      }

      .tw-piano-xml-statusscale input[type="range"] {
        width: 62px;
        accent-color: #60a5fa;
        cursor: pointer;
      }

      .tw-piano-xml-statusscale .tw-piano-xml-scale-value {
        min-width: 30px;
      }

      .tw-piano-xml-progresswrap {
        position: relative;
        flex: 1 1 auto;
        min-width: 0;
        padding-top: 10px;
      }

      .tw-piano-xml-markers {
        position: absolute;
        left: 0;
        right: 0;
        top: 0;
        height: 9px;
        pointer-events: none;
        overflow: visible;
      }

      .tw-piano-xml-close {
        flex: 0 0 auto;
        width: 18px;
        height: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(255, 255, 255, 0.16);
        border-radius: 4px;
        padding: 0;
        background: rgba(255, 255, 255, 0.04);
        color: rgba(226, 232, 240, 0.88);
        font-size: 14px;
        font-weight: 400;
        font-family: "Segoe UI Symbol", "Segoe UI", Tahoma, sans-serif;
        line-height: 1;
        cursor: pointer;
        transition: background 120ms ease, border-color 120ms ease, color 120ms ease;
      }

      .tw-piano-xml-close:hover {
        color: #ffffff;
        border-color: rgba(248, 113, 113, 0.45);
        background: rgba(127, 29, 29, 0.42);
      }

      .tw-piano-xml-marker {
        position: absolute;
        transform: translateX(-50%);
        font-size: 8px;
        line-height: 1;
        font-weight: 700;
        letter-spacing: 0.01em;
        color: rgba(226, 232, 240, 0.68);
        white-space: nowrap;
      }

      .tw-piano-xml-marker.is-current {
        color: #f8fafc;
        opacity: 1;
      }

      .tw-piano-xml-statusbar.is-visible {
        display: flex;
      }

      .tw-piano-xml-mixbar.is-visible {
        display: flex;
      }

      .tw-piano-xml-mixitems {
        flex: 1 1 auto;
        min-width: 0;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        overflow-x: auto;
        overflow-y: visible;
        scrollbar-width: none;
        -ms-overflow-style: none;
      }

      .tw-piano-xml-mixitems::-webkit-scrollbar {
        display: none;
      }

      .tw-piano-xml-mixlabel {
        flex: 0 0 auto;
        font-size: 9px;
        color: rgba(226, 232, 240, 0.72);
        letter-spacing: 0.02em;
      }

      .tw-piano-xml-speedbtn {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        height: 20px;
        min-height: 20px;
        box-sizing: border-box;
        border: 1px solid rgba(96, 165, 250, 0.36);
        background: rgba(30, 41, 59, 0.82);
        color: #dbeafe;
        border-radius: 999px;
        padding: 0 8px;
        font-size: 9px;
        line-height: 1;
        cursor: pointer;
        white-space: nowrap;
      }

      .tw-piano-pedal-wrap.tw-piano-xml-speedwrap {
        display: inline-flex;
        align-items: center;
        align-self: center;
      }

      .tw-piano-xml-speedbtn strong {
        display: inline-block;
        font-weight: 700;
        line-height: 1;
        color: #eff6ff;
      }

      .tw-piano-xml-settingsbtn {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 20px;
        box-sizing: border-box;
        border: 1px solid rgba(96, 165, 250, 0.34);
        background: rgba(15, 23, 42, 0.74);
        color: #dbeafe;
        border-radius: 999px;
        padding: 0 8px;
        font-size: 9px;
        line-height: 1;
        cursor: pointer;
        white-space: nowrap;
      }

      .tw-piano-xml-osmdpanel {
        position: fixed;
        right: 8px;
        bottom: calc(var(--tw-footer-offset, 40px) + env(safe-area-inset-bottom, 0px) + 86px);
        width: min(560px, calc(100vw - 16px));
        max-height: min(72vh, 560px);
        display: flex;
        flex-direction: column;
        gap: 6px;
        padding: 8px;
        border-radius: 10px;
        border: 1px solid rgba(148, 163, 184, 0.36);
        background: rgba(15, 23, 42, 0.96);
        box-shadow: 0 10px 26px rgba(2, 6, 23, 0.6);
        z-index: 6000;
      }

      .tw-piano-xml-osmdpanel[hidden] {
        display: none !important;
      }

      .tw-piano-xml-osmd-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        color: #e2e8f0;
        font-size: 12px;
        font-weight: 700;
      }

      .tw-piano-xml-osmd-help {
        color: rgba(226, 232, 240, 0.74);
        font-size: 10px;
        line-height: 1.35;
      }

      .tw-piano-xml-osmd-catalog {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 6px;
        max-height: 190px;
        overflow: auto;
        padding: 2px;
      }

      .tw-piano-xml-osmd-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        min-height: 28px;
        border: 1px solid rgba(148, 163, 184, 0.24);
        border-radius: 8px;
        background: rgba(30, 41, 59, 0.5);
        padding: 4px 8px;
      }

      .tw-piano-xml-osmd-rowlabel {
        font-size: 10px;
        color: #dbeafe;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .tw-piano-xml-osmd-rowctrl {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        gap: 6px;
      }

      .tw-piano-xml-osmd-rowctrl input[type="text"],
      .tw-piano-xml-osmd-rowctrl input[type="number"],
      .tw-piano-xml-osmd-rowctrl select {
        width: 112px;
        max-width: 32vw;
        border-radius: 6px;
        border: 1px solid rgba(148, 163, 184, 0.28);
        background: rgba(2, 6, 23, 0.68);
        color: #e2e8f0;
        font-size: 10px;
        line-height: 1.1;
        padding: 2px 5px;
      }

      .tw-piano-xml-osmd-json {
        width: 100%;
        min-height: 220px;
        max-height: calc(72vh - 120px);
        resize: vertical;
        box-sizing: border-box;
        border-radius: 8px;
        border: 1px solid rgba(148, 163, 184, 0.32);
        background: rgba(2, 6, 23, 0.74);
        color: #e2e8f0;
        font: 11px/1.45 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        padding: 8px;
      }

      .tw-piano-xml-osmd-actions {
        display: flex;
        align-items: center;
        gap: 6px;
      }

      .tw-piano-xml-osmd-actions button {
        border: 1px solid rgba(148, 163, 184, 0.28);
        background: rgba(30, 41, 59, 0.78);
        color: #e2e8f0;
        border-radius: 8px;
        padding: 3px 8px;
        font-size: 10px;
        cursor: pointer;
      }

      .tw-piano-xml-osmd-status {
        margin-left: auto;
        color: rgba(226, 232, 240, 0.74);
        font-size: 10px;
        white-space: nowrap;
      }

      .tw-piano-xml-osmd-advanced {
        border: 1px solid rgba(148, 163, 184, 0.24);
        border-radius: 8px;
        background: rgba(15, 23, 42, 0.56);
        overflow: hidden;
      }

      .tw-piano-xml-osmd-advanced > summary {
        cursor: pointer;
        padding: 6px 8px;
        color: #dbeafe;
        font-size: 10px;
        user-select: none;
      }

      .tw-piano-xml-osmd-advanced-body {
        padding: 0 8px 8px 8px;
      }

      .tw-piano-xml-fxbtn {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        border: 1px solid rgba(244, 114, 182, 0.3);
        background: rgba(30, 41, 59, 0.78);
        color: #fce7f3;
        border-radius: 999px;
        padding: 2px 8px;
        font-size: 9px;
        line-height: 1;
        cursor: pointer;
        white-space: nowrap;
      }

      .tw-piano-xml-fxbtn.is-off {
        border-color: rgba(148, 163, 184, 0.24);
        color: rgba(226, 232, 240, 0.72);
        background: rgba(15, 23, 42, 0.56);
      }

      .tw-piano-xml-scale {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 0 2px;
        color: rgba(226, 232, 240, 0.88);
        font-size: 9px;
        white-space: nowrap;
      }

      .tw-piano-xml-scale input[type="range"] {
        width: 78px;
        accent-color: #60a5fa;
        cursor: pointer;
      }

      .tw-piano-xml-scale-value {
        min-width: 34px;
        text-align: right;
        font-size: 8px;
        color: rgba(226, 232, 240, 0.7);
      }

      .tw-piano-xml-mixbtn {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        min-height: 20px;
        box-sizing: border-box;
        border: 1px solid rgba(148, 163, 184, 0.28);
        background: rgba(30, 41, 59, 0.78);
        color: #e2e8f0;
        border-radius: 999px;
        padding: 2px 8px;
        font-size: 9px;
        line-height: 1;
        cursor: pointer;
      }

      .tw-piano-xml-mixbtn.is-focus {
        border-color: rgba(251, 146, 60, 0.72);
        background: rgba(249, 115, 22, 0.24);
        color: #fff7ed;
      }

      .tw-piano-xml-trackpicker {
        flex: 0 0 auto;
        position: relative;
        display: inline-block;
      }

      .tw-piano-xml-trackpickbtn {
        min-width: 54px;
        max-width: min(32vw, 118px);
        color: #e5e7eb;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .tw-piano-xml-trackmenu {
        min-width: 116px;
        max-width: min(70vw, 220px);
        z-index: 24;
      }

      .tw-piano-xml-endbtn {
        flex: 0 0 auto;
        cursor: pointer;
      }

      .tw-piano-xml-tailbtn {
        flex: 0 0 auto;
        cursor: pointer;
        font-size: 14px;
        line-height: 1;
      }

      .tw-piano-xml-mixpct {
        font-size: 8px;
        opacity: 0.74;
      }

      .tw-piano-xml-hidevoices {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        min-height: 20px;
        box-sizing: border-box;
        border: 1px solid rgba(148, 163, 184, 0.28);
        background: rgba(15, 23, 42, 0.66);
        color: rgba(226, 232, 240, 0.9);
        border-radius: 999px;
        padding: 2px 8px;
        font-size: 9px;
        line-height: 1;
        white-space: nowrap;
        cursor: pointer;
        user-select: none;
      }

      .tw-piano-xml-hidevoices input[type="checkbox"] {
        width: 12px;
        height: 12px;
        margin: 0;
        accent-color: #60a5fa;
        cursor: pointer;
      }

      .tw-piano-xml-hidevoices + .tw-piano-xml-hidevoices {
        margin-left: -2px;
      }

      .tw-piano-xml-hidevoices + .tw-piano-xml-settingsbtn {
        margin-left: -2px;
      }

      .tw-piano-xml-transpose {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        gap: 4px;
      }

      .tw-piano-xml-transposelabel {
        font-size: 10px;
        color: rgba(226, 232, 240, 0.88);
        white-space: nowrap;
      }

      .tw-piano-xml-transposestepper {
        display: inline-flex;
        align-items: center;
        border: 1px solid rgba(148, 163, 184, 0.28);
        border-radius: 999px;
        overflow: hidden;
        background: rgba(30, 41, 59, 0.78);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
      }

      .tw-piano-xml-transposearrow {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 15px;
        min-width: 15px;
        min-height: 20px;
        box-sizing: border-box;
        border: 0;
        background: transparent;
        color: rgba(226, 232, 240, 0.88);
        padding: 0;
        font-size: 10px;
        font-weight: 700;
        line-height: 1;
        cursor: pointer;
        transition: background-color 120ms ease, color 120ms ease;
      }

      .tw-piano-head .tw-piano-xml-transposearrow {
        min-width: 15px;
        width: 15px;
        padding: 0;
        border: 0;
        border-radius: 0;
        font-size: 10px;
      }

      .tw-piano-xml-transposearrow:hover {
        background: rgba(59, 130, 246, 0.18);
        color: #ffffff;
      }

      .tw-piano-xml-transposearrow:focus-visible {
        outline: none;
        background: rgba(59, 130, 246, 0.22);
        color: #ffffff;
      }

      .tw-piano-xml-transposefield {
        width: 28px;
        min-height: 20px;
        box-sizing: border-box;
        border: 0;
        border-left: 1px solid rgba(148, 163, 184, 0.18);
        border-right: 1px solid rgba(148, 163, 184, 0.18);
        background: transparent;
        color: #e2e8f0;
        border-radius: 0;
        padding: 2px 4px;
        font-size: 10px;
        line-height: 1;
        text-align: center;
        appearance: textfield;
        -moz-appearance: textfield;
      }

      .tw-piano-xml-transposefield:focus {
        outline: none;
        background: rgba(15, 23, 42, 0.22);
      }

      .tw-piano-xml-transposefield::-webkit-outer-spin-button,
      .tw-piano-xml-transposefield::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
      }

      .tw-piano-scale-option.is-score-base {
        border-color: rgba(96, 165, 250, 0.38);
        color: #dbeafe;
      }

      .tw-piano-scale-option.is-score-base::after {
        content: "Base";
        margin-left: 6px;
        font-size: 9px;
        opacity: 0.72;
      }

      @media (max-width: 700px) {
        .tw-piano-xml-mixpct {
          display: none;
        }

        /* Reduce progress marker density on narrow screens. */
        .tw-piano-xml-markers .tw-piano-xml-marker:nth-child(2n) {
          display: none;
        }

        .tw-piano-xml-markers .tw-piano-xml-marker.is-current {
          display: inline-block;
        }

        /* Keep progress line visible on narrow screens: compact speed + scale controls. */
        .tw-piano-xml-speedbtn > span {
          display: none;
        }

        .tw-piano-xml-statusscale {
          font-size: 0;
          gap: 3px;
        }

        .tw-piano-xml-statusscale input[type="range"] {
          width: 52px;
        }

        .tw-piano-xml-statusscale .tw-piano-xml-scale-value {
          min-width: 24px;
          font-size: 8px;
        }
      }

      @media (max-width: 560px) {
        .tw-piano-xml-markers .tw-piano-xml-marker {
          display: none;
        }

        .tw-piano-xml-markers .tw-piano-xml-marker:nth-child(3n + 1) {
          display: inline-block;
        }

        .tw-piano-xml-markers .tw-piano-xml-marker.is-current {
          display: inline-block;
        }

        .tw-piano-xml-statusscale {
          display: none;
        }
      }

      .tw-piano-pedal {
        background: rgba(255, 255, 255, 0.07);
        border-color: rgba(255, 255, 255, 0.24);
      }

      .tw-piano-pedal.active {
        background: rgba(64, 160, 255, 0.32);
        border-color: rgba(120, 200, 255, 0.7);
        color: #eef7ff;
      }

      .tw-piano-pedal-wrap {
        position: relative;
        display: inline-block;
      }

      .tw-piano-scale-wrap {
        position: relative;
        display: inline-grid;
        grid-auto-flow: column;
        align-items: center;
        column-gap: 0;
      }

      .tw-piano-scale-btn {
        min-width: 70px;
        text-align: left;
      }

      .tw-piano-scale-sig {
        position: relative;
        display: inline-block;
        width: 58px;
        height: 20px;
        border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        background: rgba(255, 255, 255, 0.06);
        vertical-align: middle;
        overflow: hidden;
        cursor: pointer;
        isolation: isolate;
        clip-path: inset(0 round 8px);
        z-index: 8;
      }

      .tw-piano-scale-sig-clef {
        position: absolute;
        left: 3px;
        top: -2px;
        font-size: 17px;
        line-height: 1;
        color: #dbeafe;
        font-family: "Times New Roman", serif;
        z-index: 1;
      }

      .tw-piano-scale-sig-line {
        position: absolute;
        left: 14px;
        right: 3px;
        height: 1px;
        background: rgba(219, 234, 254, 0.38);
      }

      .tw-piano-scale-sig-note {
        position: absolute;
        font-size: 11px;
        line-height: 1;
        color: #dbeafe;
        font-family: "Times New Roman", serif;
      }

      .tw-piano-pedal-menu {
        position: absolute;
        right: 0;
        top: calc(100% + 4px);
        min-width: 94px;
        max-height: min(44vh, 220px);
        overflow-y: auto;
        overflow-x: hidden;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
        touch-action: pan-y;
        scrollbar-width: none;
        -ms-overflow-style: none;
        background: rgba(15, 23, 42, 0.96);
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 8px;
        box-shadow: 0 8px 18px rgba(0, 0, 0, 0.35);
        padding: 4px;
        z-index: 5;
      }

      .tw-piano-pedal-menu::-webkit-scrollbar {
        width: 0;
        height: 0;
      }

      .tw-piano-scale-wrap .tw-piano-pedal-menu {
        max-height: min(56vh, 280px);
      }

      .tw-piano-pedal-option {
        display: block;
        width: 100%;
        margin: 0;
        padding: 4px 6px;
        border-radius: 6px;
        border: 1px solid transparent;
        text-align: left;
        font-size: 10px;
        color: #e5e7eb;
        background: transparent;
      }

      .tw-piano-pedal-option:hover {
        background: rgba(255, 255, 255, 0.1);
      }

      .tw-piano-pedal-option.active {
        background: rgba(64, 160, 255, 0.24);
        border-color: rgba(120, 200, 255, 0.65);
        color: #eef7ff;
      }

      .tw-piano-swipe-strip {
        height: 12px;
        line-height: 12px;
        text-align: center;
        font-size: 9px;
        letter-spacing: 0.02em;
        color: #b6c2d8;
        background: rgba(255, 255, 255, 0.06);
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        user-select: none;
        touch-action: none;
        cursor: ew-resize;
      }

      .tw-piano-scroll {
        overflow-x: auto;
        overflow-y: hidden;
        padding: 0 8px 1px;
        cursor: grab;
      }

      .tw-piano-keys {
        position: relative;
        height: 96px;
        user-select: none;
        touch-action: none;
      }

      .tw-piano-key {
        position: absolute;
        border: 1px solid #111;
        cursor: pointer;
        box-sizing: border-box;
        margin: 0;
        padding: 0;
        appearance: none;
        -webkit-appearance: none;
        border-radius: 0;
      }

      .tw-piano-key.white {
        width: var(--tw-white-w, 24px);
        height: 86px;
        background: linear-gradient(180deg, #ffffff, #e7e7e7);
        border-radius: 0 0 4px 4px;
        z-index: 1;
      }

      .tw-piano-key.black {
        width: var(--tw-black-w, 16px);
        height: 48px;
        background: linear-gradient(180deg, #2d2d2d, #070707);
        border-radius: 0 0 3px 3px;
        z-index: 2;
      }

      .tw-piano-key.white.tw-active {
        background: linear-gradient(180deg, #93c5fd, #60a5fa);
      }

      .tw-piano-key.black.tw-active {
        background: linear-gradient(180deg, #38bdf8, #0ea5e9);
      }

      .tw-piano-key.white.tw-ext-active:not(.tw-active) {
        background: linear-gradient(180deg, #fdba74, #fb923c);
      }

      .tw-piano-key.black.tw-ext-active:not(.tw-active) {
        background: linear-gradient(180deg, #fb923c, #ea580c);
      }

      .tw-piano-key.tw-ext-pulse {
        animation: tw-piano-ext-pulse 160ms ease-out;
      }

      @keyframes tw-piano-ext-pulse {
        0% {
          box-shadow: 0 0 0 0 rgba(251, 146, 60, 0);
          filter: brightness(1);
        }
        25% {
          box-shadow: 0 0 0 2px rgba(251, 146, 60, 0.95);
          filter: brightness(1.16);
        }
        100% {
          box-shadow: 0 0 0 0 rgba(251, 146, 60, 0);
          filter: brightness(1);
        }
      }

      .tw-piano-key.white.tw-preview:not(.tw-active) {
        background: linear-gradient(180deg, #fdba74, #fb923c);
      }

      .tw-piano-key.black.tw-preview:not(.tw-active) {
        background: linear-gradient(180deg, #fb923c, #ea580c);
      }

      .tw-piano-key.white.tw-scale-out:not(.tw-active) {
        background: linear-gradient(180deg, #f1f1f1, #d9d9d9);
        opacity: 0.92;
      }

      .tw-piano-key.black.tw-scale-out:not(.tw-active) {
        background: linear-gradient(180deg, #565656, #3a3a3a);
        opacity: 1;
      }

      .tw-note-label {
        position: absolute;
        bottom: 2px;
        left: 50%;
        transform: translateX(-50%);
        font-size: 7px;
        line-height: 1;
        font-weight: 700;
        letter-spacing: 0.02em;
        pointer-events: none;
      }

      .tw-note-label.white {
        color: #111827;
      }

      .tw-note-label.black {
        bottom: 2px;
        font-size: 6px;
        color: #e5e7eb;
      }

      .tw-piano-key.tw-scale-out .tw-note-label.white {
        color: #4b5563;
      }

      .tw-piano-key.tw-scale-out .tw-note-label.black {
        color: #9ca3af;
      }

      .tw-octave-label {
        position: absolute;
        top: 2px;
        left: 50%;
        transform: translateX(-50%);
        padding: 0px 4px;
        border-radius: 10px;
        background: rgba(15, 23, 42, 0.12);
        color: #0f172a;
        font-size: 6px;
        font-weight: 700;
        pointer-events: none;
      }

      @media (max-width: 520px) {
        .tw-piano-head {
          padding: 1px 6px;
          font-size: 9px;
        }

        .tw-piano-head button,
        .tw-piano-pedal-option {
          font-size: 9px;
          padding: 1px 5px;
        }

        .tw-piano-swipe-strip {
          height: 10px;
          line-height: 10px;
          font-size: 8px;
        }

        .tw-piano-scroll {
          padding: 0 6px 0;
        }

        .tw-piano-keys {
          height: 80px;
        }

        .tw-piano-key.white {
          height: 72px;
        }

        .tw-piano-key.black {
          height: 40px;
        }

        .tw-note-label.white {
          font-size: 6px;
        }

        .tw-note-label.black {
          font-size: 5px;
        }

        .tw-octave-label {
          top: 1px;
          font-size: 5px;
          padding: 0 3px;
        }
      }

      @media (min-width: 521px) {
        .tw-piano-head {
          font-size: 12px;
        }

        .tw-piano-head button,
        .tw-piano-pedal-option {
          font-size: 11px;
        }

        .tw-piano-swipe-strip {
          font-size: 11px;
        }

        .tw-note-label.white {
          font-size: 8px;
        }

        .tw-note-label.black {
          font-size: 7px;
        }

        .tw-octave-label {
          font-size: 7px;
        }
      }

    `;
    document.head.appendChild(style);
  }

  function updateDockBottomOffset() {
    if (!state.host) return;
    const footer = document.getElementById("footerMenu");
    const footerHeight = Math.max(0, Math.round(footer?.offsetHeight || 40));
    state.host.style.setProperty("--tw-footer-offset", `${footerHeight}px`);
  }

  function updateDockHorizontalBounds() {
    if (!state.host || !state.parent) return;
    const activeTab = String(window.currentActiveTab || "");
    const activeAnchor =
      (activeTab === "textTab" ? document.getElementById("textTabContent") : null) ||
      (activeTab === "pdfTab" ? document.getElementById("pdfTabContent") : null);
    const anchor =
      activeAnchor ||
      document.querySelector(".main-tab-content.active") ||
      document.getElementById("mainContentWrapper") ||
      state.parent;
    const rect = anchor.getBoundingClientRect();
    state.host.style.left = `${Math.max(0, Math.round(rect.left))}px`;
    state.host.style.width = `${Math.max(0, Math.round(rect.width))}px`;
  }

  function syncExternalOffsets() {
    const height = state.isOpen && state.host ? Math.max(0, Math.round(state.host.offsetHeight || 0)) : 0;
    document.body.style.setProperty("--tw-piano-open-height", `${height}px`);
  }

  function updateDockGeometry() {
    updateDockBottomOffset();
    updateDockHorizontalBounds();
    syncExternalOffsets();
  }

  function createSampler(releaseSeconds) {
    return new Tone.Sampler({
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
      release: releaseSeconds,
      baseUrl: "https://tonejs.github.io/audio/salamander/"
    }).toDestination();
  }

  function getSamplerByKind(kind) {
    return kind === "score" ? state.scoreSampler : state.sampler;
  }

  function triggerImmediateReleaseForKind(midi, kind) {
    try { getSamplerByKind(kind)?.triggerRelease?.(midiToToneNote(midi)); } catch {}
  }

  async function ensureSamplerReady(strictTiming = false) {
    if (!window.Tone) return false;
    if (strictTiming) {
      if (state.scoreSamplerReady) return true;
      if (state.scoreSamplerLoadFailed) return false;
      if (!state.scoreSampler) {
        state.scoreSampler = createSampler(0.03);
      }
      try {
        await state.scoreSampler.loaded;
        state.scoreSamplerReady = true;
        return true;
      } catch (err) {
        console.error("TWPianoDock score sampler failed to load:", err);
        state.scoreSamplerLoadFailed = true;
        return false;
      }
    }

    if (state.samplerReady) return true;
    if (state.samplerLoadFailed) return false;

    if (!state.sampler) {
      // Keep release stable; pedal levels control extra hold time.
      state.sampler = createSampler(0.36);
    }

    try {
      await state.sampler.loaded;
      state.samplerReady = true;
      return true;
    } catch (err) {
      console.error("TWPianoDock sampler failed to load:", err);
      state.samplerLoadFailed = true;
      return false;
    }
  }

  function midiToToneNote(midi) {
    return Tone.Frequency(midi, "midi").toNote();
  }

  function mapSharpToFlat(letter) {
    const map = {
      "C#": "Db",
      "D#": "Eb",
      "F#": "Gb",
      "G#": "Ab",
      "A#": "Bb"
    };
    return map[letter] || letter;
  }

  function noteNameToPitchClass(name) {
    const map = {
      C: 0, "C#": 1, Db: 1,
      D: 2, "D#": 3, Eb: 3,
      E: 4,
      F: 5, "F#": 6, Gb: 6,
      G: 7, "G#": 8, Ab: 8,
      A: 9, "A#": 10, Bb: 10,
      B: 11
    };
    return map[name];
  }

  function getScalePitchClasses(value) {
    if (!value || value === "off") return null;
    const [rootName, mode] = String(value).split(":");
    const root = noteNameToPitchClass(rootName);
    if (!Number.isInteger(root)) return null;
    const steps = mode === "min" ? [0, 2, 3, 5, 7, 8, 10] : [0, 2, 4, 5, 7, 9, 11];
    return new Set(steps.map((s) => (root + s) % 12));
  }

  function getScaleIntervals(mode) {
    const base = mode === "min" ? [0, 2, 3, 5, 7, 8, 10] : [0, 2, 4, 5, 7, 9, 11];
    return [...base, ...base.map((v) => v + 12), 24];
  }

  function getRootMidiNearLastTouched(rootPc) {
    const last = Number.isFinite(state.lastTouchedMidi) ? state.lastTouchedMidi : 60;
    const lastOct = Math.floor((last / 12) - 1);
    // Default scale preview starts one octave lower than the touched area.
    let rootMidi = ((lastOct) * 12) + rootPc;

    while (rootMidi < NOTE_MIN) rootMidi += 12;
    while (rootMidi + 24 > NOTE_MAX) rootMidi -= 12;
    return Math.max(NOTE_MIN, Math.min(NOTE_MAX - 24, rootMidi));
  }

  function clearScalePreviewTimers() {
    state.scalePreviewTimers.forEach((id) => {
      try { clearTimeout(id); } catch {}
    });
    state.scalePreviewTimers = [];
    state.keyByMidi.forEach((el) => {
      el.classList.remove("tw-preview");
    });
  }

  function flashScalePreviewKey(midi, durationMs = 180) {
    const el = state.keyByMidi.get(midi);
    if (!el) return;
    el.classList.add("tw-preview");
    const timer = setTimeout(() => {
      el.classList.remove("tw-preview");
    }, durationMs);
    state.scalePreviewTimers.push(timer);
  }

  function clearExternalPreviewMidi() {
    Array.from(state.externalPreviewMidis || []).forEach((midi) => {
      const noteMidi = Number(midi);
      if (!Number.isFinite(noteMidi)) return;
      const prevEl = state.keyByMidi.get(noteMidi);
      if (prevEl) prevEl.classList.remove("tw-preview");
    });
    state.externalPreviewMidis = new Set();
  }

  function clearExternalActiveMidis() {
    state.externalActiveMidiCounts.forEach((_, midi) => {
      const noteMidi = Number(midi);
      if (!Number.isFinite(noteMidi)) return;
      const el = state.keyByMidi.get(noteMidi);
      if (el) {
        el.classList.remove("tw-ext-active");
        el.classList.remove("tw-ext-pulse");
      }
    });
    state.externalPulseTimers.forEach((timerId) => {
      try { clearTimeout(timerId); } catch {}
    });
    state.externalPulseTimers = new Map();
    state.externalActiveMidiCounts = new Map();
  }

  function pulseExternalActiveMidi(noteMidi, el) {
    if (!el) return;
    const priorTimer = state.externalPulseTimers.get(noteMidi);
    if (priorTimer) {
      try { clearTimeout(priorTimer); } catch {}
      state.externalPulseTimers.delete(noteMidi);
    }
    el.classList.remove("tw-ext-pulse");
    void el.offsetWidth;
    el.classList.add("tw-ext-pulse");
    const timer = setTimeout(() => {
      el.classList.remove("tw-ext-pulse");
      state.externalPulseTimers.delete(noteMidi);
    }, 190);
    state.externalPulseTimers.set(noteMidi, timer);
  }

  function setExternalActiveMidi(midi, on) {
    const noteMidi = Math.max(NOTE_MIN, Math.min(NOTE_MAX, Math.round(Number(midi) || 0)));
    if (!Number.isFinite(noteMidi)) return false;
    const el = state.keyByMidi.get(noteMidi);
    if (!el) return false;
    const current = Math.max(0, Number(state.externalActiveMidiCounts.get(noteMidi) || 0));
    const next = on ? current + 1 : Math.max(0, current - 1);
    if (next > 0) {
      state.externalActiveMidiCounts.set(noteMidi, next);
      el.classList.add("tw-ext-active");
      pulseExternalActiveMidi(noteMidi, el);
    } else {
      state.externalActiveMidiCounts.delete(noteMidi);
      el.classList.remove("tw-ext-active");
      pulseExternalActiveMidi(noteMidi, el);
    }
    return true;
  }

  function setExternalPreviewMidis(midis) {
    const next = Array.from(new Set(
      (Array.isArray(midis) ? midis : [])
        .map((midi) => Math.max(NOTE_MIN, Math.min(NOTE_MAX, Math.round(Number(midi) || 0))))
        .filter((midi) => Number.isFinite(midi))
    ));
    clearExternalPreviewMidi();
    if (!next.length) return false;
    next.forEach((noteMidi) => {
      const el = state.keyByMidi.get(noteMidi);
      if (!el) return;
      state.externalPreviewMidis.add(noteMidi);
      el.classList.add("tw-preview");
    });
    return state.externalPreviewMidis.size > 0;
  }

  function setExternalPreviewMidi(midi) {
    const noteMidi = Math.max(NOTE_MIN, Math.min(NOTE_MAX, Math.round(Number(midi) || 0)));
    if (!Number.isFinite(noteMidi)) return false;
    return setExternalPreviewMidis([noteMidi]);
  }

  async function autoplayScaleTwoOctaves(scaleValue) {
    clearScalePreviewTimers();
    if (!scaleValue || scaleValue === "off" || !window.Tone) return;
    const [rootName, mode] = String(scaleValue).split(":");
    const rootPc = noteNameToPitchClass(rootName);
    if (!Number.isInteger(rootPc)) return;

    await Tone.start();
    const ok = await ensureSamplerReady();
    if (!ok) return;

    const rootMidi = getRootMidiNearLastTouched(rootPc);
    const intervals = getScaleIntervals(mode);
    const stepMs = 230;

    intervals.forEach((interval, i) => {
      const timer = setTimeout(() => {
        const midi = rootMidi + interval;
        const note = midiToToneNote(midi);
        flashScalePreviewKey(midi, 160);
        try {
          state.sampler?.triggerAttackRelease(note, 0.22, undefined, 0.82);
        } catch {}
      }, i * stepMs);
      state.scalePreviewTimers.push(timer);
    });
  }

  function getScaleLabel(value) {
    if (!value || value === "off") return "Scale Off";
    const [rootName, mode] = String(value).split(":");
    const suffix = mode === "min" ? "Min" : "Maj";
    return `${rootName} ${suffix}`;
  }

  function getCurrentXmlBaseScale() {
    const viewer = document.getElementById("pdfTabXmlViewer");
    const value = String(viewer?._twXmlScaleValue || "").trim();
    return value && value !== "off" ? value : "";
  }

  function transposeScaleValue(scaleValue, semitones = 0) {
    const value = String(scaleValue || "").trim();
    if (!value || value === "off") return value;
    const [rootName, mode] = value.split(":");
    const rootPc = noteNameToPitchClass(rootName);
    if (!Number.isInteger(rootPc)) return value;
    const nextPc = ((rootPc + Math.round(Number(semitones) || 0)) % 12 + 12) % 12;
    const preferFlat =
      String(rootName || "").includes("b") ||
      getScaleSignatureSpec(value).type === "flat";
    const sharpNames = ["C", "C#", "D", "Eb", "E", "F", "F#", "G", "Ab", "A", "Bb", "B"];
    const flatNames = ["C", "Db", "D", "Eb", "E", "F", "Gb", "G", "Ab", "A", "Bb", "B"];
    const nextRoot = (preferFlat ? flatNames : sharpNames)[nextPc] || rootName;
    return `${nextRoot}:${mode === "min" ? "min" : "maj"}`;
  }

  function getDisplayedScaleValue() {
    const selectedScale = String(state.scaleValue || "").trim();
    const transpose = Number(window.twMusicXml?.getXmlRenderTransposeSemitones?.() || 0);
    if (selectedScale && selectedScale !== "off") {
      return transposeScaleValue(selectedScale, transpose);
    }
    const baseScale = getCurrentXmlBaseScale();
    if (baseScale) {
      return transposeScaleValue(baseScale, transpose);
    }
    return state.scaleValue;
  }

  function updateScaleButtonState() {
    if (!state.scaleBtn) return;
    const displayScale = getDisplayedScaleValue();
    const label = getScaleLabel(displayScale);
    const baseScale = getCurrentXmlBaseScale();
    state.scaleBtn.textContent = `${label} ▾`;
    state.scaleBtn.classList.toggle("active", displayScale !== "off");
    state.scaleBtn.title = baseScale
      ? `Scale highlight. Score base: ${getScaleLabel(baseScale)}`
      : "Scale highlight";
    renderScaleSignatureBadge(state.scaleSig, displayScale);
    state.scaleMenu?.querySelectorAll(".tw-piano-scale-option").forEach((btn) => {
      btn.classList.toggle("active", btn.dataset.scale === displayScale);
      btn.classList.toggle("is-score-base", !!baseScale && btn.dataset.scale === baseScale);
    });
  }

  function updateXmlTransposeButtonState() {
    const input = state.host?.querySelector?.("[data-xml-transpose-input='1']");
    if (!input) return;
    const semitones = Math.max(-12, Math.min(12, Number(window.twMusicXml?.getXmlRenderTransposeSemitones?.() || 0)));
    input.value = String(semitones);
  }

  function setScaleValue(value, opts = {}) {
    const next = typeof value === "string" ? value : "off";
    if (next === state.scaleValue && opts?.force !== true) return;
    state.scaleValue = next;
    state.accidentalMode = getAccidentalModeFromScale(next);
    if (opts?.persist !== false) {
      try {
        localStorage.setItem(SCALE_STORAGE_KEY, next);
      } catch {}
    }
    updateScaleButtonState();
    updateXmlTransposeButtonState();
    refreshKeysForViewport(true);
  }

  function getScaleSignatureSpec(value) {
    const map = {
      "off": { type: "none", count: 0 },
      "C:maj": { type: "none", count: 0 },
      "G:maj": { type: "sharp", count: 1 },
      "D:maj": { type: "sharp", count: 2 },
      "A:maj": { type: "sharp", count: 3 },
      "E:maj": { type: "sharp", count: 4 },
      "F:maj": { type: "flat", count: 1 },
      "Bb:maj": { type: "flat", count: 2 },
      "Eb:maj": { type: "flat", count: 3 },
      "A:min": { type: "none", count: 0 },
      "E:min": { type: "sharp", count: 1 },
      "D:min": { type: "flat", count: 1 },
      "G:min": { type: "flat", count: 2 }
    };
    const direct = map[String(value || "off")];
    if (direct) return direct;
    const [rootName, mode] = String(value || "").split(":");
    const fifthsByMajor = {
      Cb: -7, Gb: -6, Db: -5, Ab: -4, Eb: -3, Bb: -2, F: -1,
      C: 0, G: 1, D: 2, A: 3, E: 4, B: 5, "F#": 6, "C#": 7
    };
    const fifthsByMinor = {
      Ab: -7, Eb: -6, Bb: -5, F: -4, C: -3, G: -2, D: -1,
      A: 0, E: 1, B: 2, "F#": 3, "C#": 4, "G#": 5, "D#": 6, "A#": 7
    };
    const fifths = mode === "min"
      ? fifthsByMinor[rootName]
      : fifthsByMajor[rootName];
    if (!Number.isFinite(fifths)) return { type: "none", count: 0 };
    if (fifths === 0) return { type: "none", count: 0 };
    return {
      type: fifths < 0 ? "flat" : "sharp",
      count: Math.abs(fifths)
    };
  }

  function renderScaleSignatureBadge(el, scaleValue) {
    if (!el) return;
    const spec = getScaleSignatureSpec(scaleValue);
    const lineYs = [3, 6, 9, 12, 15];
    const isBass = state.clefMode === "F";
    // Clef-specific vertical anchors for key signatures (left-to-right symbols).
    // G clef: F# on top line, Bb on middle line.
    // F clef: F# on second-from-top line, Bb on second-from-bottom line.
    const sharpYs = isBass
      ? [6, 10, 5, 9, 13, 8, 12]
      : [3, 7, 2, 6, 10, 5, 9];
    const flatYs = isBass
      ? [12, 9, 15, 11, 14, 8, 13]
      : [9, 6, 12, 8, 14, 5, 11];

    el.innerHTML = "";
    const clef = document.createElement("span");
    clef.className = "tw-piano-scale-sig-clef";
    clef.textContent = isBass ? "𝄢" : "𝄞";
    clef.style.top = isBass ? "0px" : "1px";
    el.appendChild(clef);

    lineYs.forEach((y) => {
      const line = document.createElement("span");
      line.className = "tw-piano-scale-sig-line";
      line.style.top = `${y}px`;
      el.appendChild(line);
    });

    if (spec.count <= 0 || spec.type === "none") {
      const nat = document.createElement("span");
      nat.className = "tw-piano-scale-sig-note";
      nat.textContent = "♮";
      nat.style.left = "25px";
      nat.style.top = "5px";
      el.appendChild(nat);
      return;
    }

    const symbol = spec.type === "flat" ? "♭" : "♯";
    const ys = spec.type === "flat" ? flatYs : sharpYs;
    const symbolYOffset = spec.type === "flat" ? 7 : 5;
    for (let i = 0; i < Math.min(spec.count, ys.length); i += 1) {
      const note = document.createElement("span");
      note.className = "tw-piano-scale-sig-note";
      note.textContent = symbol;
      note.style.left = `${20 + (i * 7)}px`;
      note.style.top = `${ys[i] - symbolYOffset}px`;
      el.appendChild(note);
    }
  }

  function getAccidentalModeFromScale(value) {
    return getScaleSignatureSpec(value).type === "flat" ? "flat" : "sharp";
  }

  function parseNoteParts(midi) {
    const full = midiToToneNote(midi);
    const m = /^([A-G]#?)(-?\d+)$/.exec(full);
    const letter = m ? m[1] : full;
    const octave = m ? m[2] : "";
    const displayLetter = state.accidentalMode === "flat" ? mapSharpToFlat(letter) : letter;
    return {
      full,
      letter,
      octave,
      displayLetter,
      displayFull: `${displayLetter}${octave}`
    };
  }

  function setKeyActive(midi, on) {
    const el = state.keyByMidi.get(midi);
    if (!el) return;
    el.classList.toggle("tw-active", !!on);
  }

  function getSustainProfile(level) {
    // Pedal levels control extra hold before release.
    const profiles = [
      { holdMs: 0 },   // Off
      { holdMs: 170 }, // Low
      { holdMs: 320 }, // Med (default)
      { holdMs: 520 }  // High
    ];
    return profiles[Math.max(0, Math.min(3, Number(level) || 0))] || profiles[2];
  }

  async function noteOn(midi, velocity = 0.85, opts = {}) {
    if (!window.Tone) return false;
    const strictTiming = opts?.strictTiming === true;
    const samplerKind = strictTiming ? "score" : "main";
    state.lastTouchedMidi = midi;
    if (state.scaleSig) renderScaleSignatureBadge(state.scaleSig, getDisplayedScaleValue());
    await Tone.start();
    const ok = await ensureSamplerReady(strictTiming);
    if (!ok) return false;
    const sampler = getSamplerByKind(samplerKind);
    const gain = Math.max(0.05, Math.min(1, Number(velocity) || 0.85));
    // If key was tapped/released before sampler became ready, still emit a short note.
    if (!state.downNotes.has(midi)) {
      const noteLate = midiToToneNote(midi);
      sampler?.triggerAttackRelease(noteLate, strictTiming ? 0.08 : 0.18, undefined, gain);
      return !!sampler;
    }

    if (state.activeNotes.has(midi)) return true;
    state.activeNotes.add(midi);
    state.activeSamplerKindByMidi.set(midi, samplerKind);
    setKeyActive(midi, true);

    const note = midiToToneNote(midi);
    sampler?.triggerAttack(note, undefined, gain);
    return !!sampler;
  }

  function noteOff(midi, opts = {}) {
    state.downNotes.delete(midi);
    if (!state.activeNotes.has(midi)) return;
    const samplerKind = state.activeSamplerKindByMidi.get(midi) || "main";
    const strictTiming = opts?.strictTiming === true || samplerKind === "score";
    const ready = strictTiming ? state.scoreSamplerReady : state.samplerReady;
    if (!ready) return;
    const sustain = (opts?.ignoreSustain || strictTiming) ? { holdMs: 0 } : getSustainProfile(state.sustainLevel);
    if (sustain.holdMs > 0) {
      state.pendingRelease.add(midi);
      if (state.releaseTimers.has(midi)) {
        clearTimeout(state.releaseTimers.get(midi));
      }
      const timer = setTimeout(() => {
        state.releaseTimers.delete(midi);
        if (state.downNotes.has(midi)) return;
        if (!state.activeNotes.has(midi)) return;
        state.activeNotes.delete(midi);
        state.activeSamplerKindByMidi.delete(midi);
        state.pendingRelease.delete(midi);
        setKeyActive(midi, false);
        triggerImmediateReleaseForKind(midi, samplerKind);
      }, sustain.holdMs);
      state.releaseTimers.set(midi, timer);
      return;
    }
    state.activeNotes.delete(midi);
    state.activeSamplerKindByMidi.delete(midi);
    state.pendingRelease.delete(midi);
    setKeyActive(midi, false);
    triggerImmediateReleaseForKind(midi, samplerKind);
  }

  function stopAllNotes() {
    clearScalePreviewTimers();
    state.releaseTimers.forEach((timer) => {
      try { clearTimeout(timer); } catch {}
    });
    state.releaseTimers.clear();
    Array.from(state.activeNotes).forEach((midi) => {
      triggerImmediateReleaseForKind(midi, state.activeSamplerKindByMidi.get(midi) || "main");
      setKeyActive(midi, false);
    });
    state.activeNotes.clear();
    state.activeSamplerKindByMidi.clear();
    state.downNotes.clear();
    state.pendingRelease.clear();
  }

  function centerOnMidi(midi, smooth = false) {
    const key = state.keyByMidi.get(midi);
    const scroller = state.scroller;
    if (!key || !scroller) return;
    const target = key.offsetLeft + (key.offsetWidth / 2) - (scroller.clientWidth / 2);
    const maxLeft = Math.max(0, scroller.scrollWidth - scroller.clientWidth);
    const left = Math.max(0, Math.min(maxLeft, target));
    scroller.scrollTo({ left, behavior: smooth ? "smooth" : "auto" });
  }

  function bindSwipeStrip() {
    const strip = state.swipeStrip;
    const header = state.host?.querySelector?.(".tw-piano-head");
    const dock = state.dock;
    const keys = state.keyboard;
    const scroller = state.scroller;
    if (!strip || !scroller || strip.dataset.bound === "1") return;
    strip.dataset.bound = "1";

    let dragActive = false;
    let pendingDrag = false;
    let startX = 0;
    let startY = 0;
    let startScroll = 0;
    let pointerType = "mouse";

    const beginDrag = (clientX) => {
      dragActive = true;
      pendingDrag = false;
      startX = clientX;
      startScroll = scroller.scrollLeft;
      strip.style.cursor = "grabbing";
      if (header) header.style.cursor = "grabbing";
      if (keys) keys.style.cursor = "grabbing";
      if (dock) dock.style.cursor = "grabbing";
      stopAllNotes();
    };

    const maybeBeginDrag = (clientX, clientY) => {
      if (!pendingDrag || dragActive) return;
      const dx = clientX - startX;
      const dy = clientY - startY;
      const minDx = pointerType === "touch" ? 16 : 10;
      // Horizontal gesture only: ignore small/vertical motion.
      if (Math.abs(dx) < minDx || Math.abs(dx) <= (Math.abs(dy) * 1.25)) return;
      beginDrag(startX);
      moveDrag(clientX);
    };

    const moveDrag = (clientX) => {
      if (!dragActive) return;
      const dx = clientX - startX;
      scroller.scrollLeft = startScroll - dx;
      state.userPositioned = true;
    };

    const endDrag = () => {
      dragActive = false;
      pendingDrag = false;
      strip.style.cursor = "ew-resize";
      if (header) header.style.cursor = "ew-resize";
      if (keys) keys.style.cursor = "";
      if (dock) dock.style.cursor = "";
    };

    const hasPointerEvents = typeof window !== "undefined" && "PointerEvent" in window;

    const bindDragSurface = (surface) => {
      if (!surface) return;

      // Don't hijack clicks on explicit controls and piano keys.
      // Keys manage their own drag-pan so note up/down remains reliable.
      const isControl = (target) =>
        !!target?.closest?.(".tw-piano-head button, .tw-piano-key, a, input, select, textarea, [data-lucide]");

      if (hasPointerEvents) {
        surface.addEventListener("pointerdown", (e) => {
          if (isControl(e.target)) return;
          if (e.pointerType === "mouse" && e.button !== 2) return;
          pointerType = e.pointerType || "mouse";
          pendingDrag = true;
          startX = e.clientX;
          startY = e.clientY;
          startScroll = scroller.scrollLeft;
          try { surface.setPointerCapture(e.pointerId); } catch {}
          if (e.cancelable) e.preventDefault();
        });
        surface.addEventListener("pointermove", (e) => {
          maybeBeginDrag(e.clientX, e.clientY);
          moveDrag(e.clientX);
          if (dragActive && e.cancelable) e.preventDefault();
        });
        surface.addEventListener("pointerup", endDrag);
        surface.addEventListener("pointercancel", endDrag);
        surface.addEventListener("lostpointercapture", endDrag);
        return;
      }

      surface.addEventListener("touchstart", (e) => {
        if (isControl(e.target)) return;
        const t = e.touches && e.touches[0];
        if (!t) return;
        pointerType = "touch";
        pendingDrag = true;
        startX = t.clientX;
        startY = t.clientY;
        startScroll = scroller.scrollLeft;
        if (e.cancelable) e.preventDefault();
      }, { passive: false });
      surface.addEventListener("touchmove", (e) => {
        const t = e.touches && e.touches[0];
        if (!t) return;
        maybeBeginDrag(t.clientX, t.clientY);
        moveDrag(t.clientX);
        if (e.cancelable) e.preventDefault();
      }, { passive: false });
      surface.addEventListener("touchend", endDrag, { passive: true });
      surface.addEventListener("touchcancel", endDrag, { passive: true });

      surface.addEventListener("mousedown", (e) => {
        if (isControl(e.target)) return;
        if (e.button !== 2) return;
        pointerType = "mouse";
        pendingDrag = true;
        startX = e.clientX;
        startY = e.clientY;
        startScroll = scroller.scrollLeft;
        if (e.cancelable) e.preventDefault();
      });
    };

    if (hasPointerEvents) {
      // Pointer events (modern browsers)
      bindDragSurface(strip);
      bindDragSurface(header);
      bindDragSurface(dock);
      bindDragSurface(keys);
      return;
    }

    // Touch + mouse fallback (older iOS/Safari)
    bindDragSurface(strip);
    bindDragSurface(header);
    bindDragSurface(dock);
    bindDragSurface(keys);
    window.addEventListener("mousemove", (e) => moveDrag(e.clientX));
    window.addEventListener("mouseup", endDrag);
  }

  function getKeyLayoutForViewport() {
    const vw = Math.max(window.innerWidth || 0, document.documentElement?.clientWidth || 0);
    if (vw <= 520) return { whiteW: 19, blackW: 12, blackOffset: 6, sig: "phone" };
    if (vw <= 1024) return { whiteW: 28, blackW: 18, blackOffset: 9, sig: "tablet" };
    return { whiteW: 28, blackW: 18, blackOffset: 9, sig: "wide" };
  }

  function buildKeys() {
    state.keyByMidi.clear();
    const keysEl = state.keyboard;
    if (!keysEl || !window.Tone) return;

    keysEl.innerHTML = "";

    const keyLayout = getKeyLayoutForViewport();
    const whiteW = keyLayout.whiteW;
    const blackOffset = keyLayout.blackOffset;
    keysEl.style.setProperty("--tw-white-w", `${keyLayout.whiteW}px`);
    keysEl.style.setProperty("--tw-black-w", `${keyLayout.blackW}px`);
    const scalePitchClasses = getScalePitchClasses(getDisplayedScaleValue());
    let whiteIndex = 0;

    for (let midi = NOTE_MIN; midi <= NOTE_MAX; midi += 1) {
      const parts = parseNoteParts(midi);
      const isBlack = parts.letter.includes("#");
      if (!isBlack) whiteIndex += 1;

      const key = document.createElement("button");
      key.type = "button";
      key.className = `tw-piano-key ${isBlack ? "black" : "white"}`;
      key.dataset.midi = String(midi);
      key.title = parts.displayFull;
      if (scalePitchClasses) {
        const pitchClass = ((midi % 12) + 12) % 12;
        key.classList.add(scalePitchClasses.has(pitchClass) ? "tw-scale-in" : "tw-scale-out");
      }

      if (isBlack) {
        key.style.left = `${(whiteIndex * whiteW) - blackOffset}px`;
      } else {
        key.style.left = `${(whiteIndex - 1) * whiteW}px`;
      }

      const noteLabel = document.createElement("span");
      noteLabel.className = `tw-note-label ${isBlack ? "black" : "white"}`;
      noteLabel.textContent = parts.displayLetter;
      key.appendChild(noteLabel);

      if (!isBlack && parts.letter === "C") {
        const octave = document.createElement("span");
        octave.className = "tw-octave-label";
        octave.textContent = `C${parts.octave}`;
        key.appendChild(octave);
      }

      let pressStartX = 0;
      let pressStartY = 0;
      let pressStartScroll = 0;
      let pressMoved = false;
      let noteStarted = false;
      let panOnly = false;
      const beginNote = () => {
        if (noteStarted) return;
        state.downNotes.add(midi);
        state.pendingRelease.delete(midi);
        if (state.releaseTimers.has(midi)) {
          clearTimeout(state.releaseTimers.get(midi));
          state.releaseTimers.delete(midi);
        }
        noteStarted = true;
        noteOn(midi);
      };

      const end = () => {
        if (noteStarted) {
          noteOff(midi);
          noteStarted = false;
        }
      };

      key.addEventListener("pointerdown", (e) => {
        if (e.pointerType === "mouse" && e.button !== 0) return;
        pressStartX = e.clientX;
        pressStartY = e.clientY;
        pressStartScroll = state.scroller ? state.scroller.scrollLeft : 0;
        pressMoved = false;
        noteStarted = false;
        const supportsPanZone = e.pointerType === "touch" || e.pointerType === "pen";
        const keyHeight = Math.max(1, Number(key.clientHeight || 0));
        const upperPanZone = isBlack
          ? Math.max(10, Math.round(keyHeight * 0.35))
          : Math.max(14, Math.round(keyHeight * 0.28));
        panOnly = supportsPanZone && Number(e.offsetY) <= upperPanZone;
        if (!panOnly) beginNote();
        try { key.setPointerCapture(e.pointerId); } catch {}
        if (e.cancelable) e.preventDefault();
      });

      key.addEventListener("pointermove", (e) => {
        const allowKeyDragPan = e.pointerType !== "mouse";
        if (!allowKeyDragPan) return;
        const dx = Math.abs(e.clientX - pressStartX);
        const dy = Math.abs(e.clientY - pressStartY);
        const dragMinDx = panOnly
          ? (e.pointerType === "touch" ? 8 : 12)
          : (e.pointerType === "touch" ? 14 : 24);
        const horizontalDrag = dx > dragMinDx && dx > (dy * (e.pointerType === "touch" ? 1.2 : 1.55));
        if (!pressMoved && horizontalDrag) {
          pressMoved = true;
          state.downNotes.delete(midi);
          if (noteStarted) {
            noteOff(midi);
            noteStarted = false;
          }
        }
        if (pressMoved && state.scroller) {
          const moveDx = e.clientX - pressStartX;
          state.scroller.scrollLeft = pressStartScroll - moveDx;
          state.userPositioned = true;
          if (e.cancelable) e.preventDefault();
        }
      });

      key.addEventListener("pointerup", end);
      key.addEventListener("pointercancel", end);
      key.addEventListener("lostpointercapture", end);
      key.addEventListener("pointerup", () => { panOnly = false; });
      key.addEventListener("pointercancel", () => { panOnly = false; });
      key.addEventListener("lostpointercapture", () => { panOnly = false; });

      state.keyByMidi.set(midi, key);
      keysEl.appendChild(key);
    }

    keysEl.style.width = `${whiteIndex * whiteW}px`;
  }

  function refreshKeysForViewport(force = false) {
    const next = getKeyLayoutForViewport();
    if (!force && next.sig === state.keyLayoutSig) return;
    const scroller = state.scroller;
    const maxBefore = scroller ? Math.max(1, scroller.scrollWidth - scroller.clientWidth) : 1;
    const ratio = scroller ? (scroller.scrollLeft / maxBefore) : 0;
    state.keyLayoutSig = next.sig;
    buildKeys();
    requestAnimationFrame(() => {
      if (!state.scroller) return;
      if (!state.userPositioned) {
        centerOnMidi(60, false);
        return;
      }
      const maxAfter = Math.max(0, state.scroller.scrollWidth - state.scroller.clientWidth);
      state.scroller.scrollLeft = Math.max(0, Math.min(maxAfter, Math.round(ratio * maxAfter)));
    });
  }

  function setOpen(nextOpen) {
    state.isOpen = !!nextOpen;
    if (!state.dock) return;
    updateDockGeometry();

    state.dock.classList.toggle("tw-open", state.isOpen);
    state.dock.setAttribute("aria-hidden", state.isOpen ? "false" : "true");
    document.body.classList.toggle("tw-piano-open", state.isOpen);
    requestAnimationFrame(updateDockGeometry);
    if (state.isOpen && !state.userPositioned) {
      requestAnimationFrame(() => centerOnMidi(60, false)); // C4
    }
    renderXmlMixer(window.currentSurrogate);

    if (!state.isOpen) stopAllNotes();

    try {
      localStorage.setItem(STORAGE_KEY, state.isOpen ? "1" : "0");
    } catch {}
  }

  function getActiveMainTab() {
    const fromState = typeof window.currentActiveTab === "string" ? window.currentActiveTab : "";
    if (fromState) return fromState;
    const fromFooter = document.querySelector(".footer-tab-btn.active[data-target]")?.getAttribute("data-target") || "";
    if (fromFooter) return fromFooter;
    return "";
  }

  function isAllowedTabForPiano() {
    const active = getActiveMainTab();
    const baseAllowed = active === "textTab" || active === "pdfTab";
    if (!baseAllowed) return false;

    // Block piano whenever overlay/panel UI is on top of the main text/pdf work area.
    const homeActive = !!document.getElementById("homeTabContent")?.classList.contains("active");
    if (homeActive) return false;

    const musicVisible = !!document.getElementById("musicTabContent")?.classList.contains("visible");
    if (musicVisible) return false;

    const chat = document.getElementById("chatContainer");
    const chatVisible = !!(chat && chat.style.display && chat.style.display !== "none");
    if (chatVisible) return false;

    const driveImport = document.getElementById("driveImportOverlay");
    const importVisible = !!(driveImport && driveImport.style.display && driveImport.style.display !== "none");
    if (importVisible) return false;

    return true;
  }

  function updateDockVisibilityForTab() {
    if (!state.host) return;
    const allowed = isAllowedTabForPiano();
    state.host.style.display = allowed ? "" : "none";
    if (!allowed && state.isOpen) {
      setOpen(false);
    }
  }

  async function renderXmlMixer(surrogate) {
    const bar = state.xmlMixBar;
    const items = state.xmlMixItems;
    const dock = state.dock;
    const syncAfterLayout = () => {
      if (!state.host) return;
      requestAnimationFrame(updateDockGeometry);
    };
    if (!bar || !items || !dock) return;
    const safeSurrogate = String(surrogate || window.currentSurrogate || "").trim();
    const xmlModule = window.twMusicXml;
    if (!safeSurrogate) {
      updateXmlTransposeButtonState();
      bar.classList.remove("is-visible");
      state.xmlStatusBar?.classList.remove("is-visible");
      dock.classList.remove("tw-xml-visible", "tw-strips-only");
      items.innerHTML = "";
      if (state.xmlOsmdPanel) state.xmlOsmdPanel.hidden = true;
      syncAfterLayout();
      return;
    }

    const xmlOpenForCurrent =
      !!window._pdfXmlViewState?.active &&
      String(window._pdfXmlViewState?.surrogate || "") === safeSurrogate;
    let hasXml = xmlOpenForCurrent && !!window._pdfXmlViewState?.file?.url;
    if (!hasXml) {
      try {
        const file = await xmlModule?.getPrimaryMusicXmlFile?.(safeSurrogate);
        hasXml = !!file?.url;
      } catch {}
    }

    if (!hasXml) {
      updateXmlTransposeButtonState();
      state.xmlStatusBar?.classList.remove("is-visible");
      bar.classList.remove("is-visible");
      dock.classList.remove("tw-xml-visible", "tw-strips-only");
      items.innerHTML = "";
      if (state.xmlOsmdPanel) state.xmlOsmdPanel.hidden = true;
      syncAfterLayout();
      return;
    }

    state.xmlStatusBar?.classList.add("is-visible");
    bar.classList.add("is-visible");
    syncAfterLayout();
    if (safeSurrogate) {
      try {
        await xmlModule?.ensureTrackStatesReady?.(safeSurrogate);
      } catch {}
    }
    const tracks = xmlModule?.getTrackPlaybackStates?.(safeSurrogate) || [];
    if (!tracks.length) {
      items.innerHTML = "";
      syncAfterLayout();
      return;
    }

    const allNearFull = tracks.every((track) => !track.mute && Number(track.volume || 0) >= 0.95);
    const focused = allNearFull
      ? null
      : (tracks.find((track) => Number(track.volume || 0) >= 0.95 && !track.mute) || null);
    const hideOtherVoices = (() => {
      try {
        return String(localStorage.getItem(XML_HIDE_OTHER_VOICES_STORAGE_KEY) || "") === "1";
      } catch {
        return false;
      }
    })();
    const layoutScale = Math.max(0.8, Math.min(1.6, Number(xmlModule?.getXmlLayoutScale?.() || 1)));
    const layoutPct = `${Math.round(layoutScale * 100)}%`;
    const speedInfo = xmlModule?.getXmlPlaybackSpeedInfo?.(safeSurrogate) || {};
    const quarterBpm = Number(speedInfo?.quarterBpm);
    const speedLabel = Number.isFinite(quarterBpm) && quarterBpm > 0
      ? `Q ${Math.round(quarterBpm)}`
      : `Q x${Number(speedInfo?.speed || 1).toFixed(Number(speedInfo?.speed || 1) % 1 ? 2 : 0)}`;
    dock.classList.toggle("tw-xml-visible", xmlOpenForCurrent && !state.isOpen);
    dock.classList.toggle("tw-strips-only", xmlOpenForCurrent && !state.isOpen);
    if (state.xmlPianoToggleBtn) {
      state.xmlPianoToggleBtn.innerHTML = "&#127929;";
      state.xmlPianoToggleBtn.title = state.isOpen ? "Hide piano" : "Show piano";
      state.xmlPianoToggleBtn.setAttribute("aria-label", state.isOpen ? "Hide piano" : "Show piano");
    }
    if (state.xmlEndToggleBtn) {
      state.xmlEndToggleBtn.textContent = xmlOpenForCurrent ? "PDF" : "XML";
      state.xmlEndToggleBtn.title = xmlOpenForCurrent ? "Back to PDF" : "Open playable score";
      state.xmlEndToggleBtn.setAttribute("aria-label", xmlOpenForCurrent ? "Back to PDF" : "Open playable score");
    }

    const escapeHtml = (value) => String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;");
    const parseTrackVoiceInfo = (track) => {
      const rawId = String(track?.id || "").trim();
      const sep = rawId.indexOf("::voice::");
      const partId = sep > 0 ? rawId.slice(0, sep) : rawId;
      const voice = sep > 0
        ? (rawId.slice(sep + 9).trim() || String(track?.voice || "1").trim() || "1")
        : (String(track?.voice || "1").trim() || "1");
      return { rawId, partId, voice };
    };
    const voicesByPartId = {};
    tracks.forEach((track) => {
      const info = parseTrackVoiceInfo(track);
      const key = String(info.partId || "").trim() || String(info.rawId || "").trim();
      if (!voicesByPartId[key]) voicesByPartId[key] = new Set();
      voicesByPartId[key].add(String(info.voice || "1"));
    });
    const getTrackLabel = (track, fallbackIndex = 0) => {
      const info = parseTrackVoiceInfo(track);
      const fallbackLabel = `Track ${Math.max(1, Number(fallbackIndex || 0) + 1)}`;
      const rawName = String(track?.name || "").trim() || String(track?.id || "").trim() || fallbackLabel;
      const key = String(info.partId || "").trim() || String(info.rawId || "").trim();
      const voiceCount = Number(voicesByPartId[key]?.size || 0);
      if (voiceCount <= 1) {
        const suffix = ` ${info.voice}`;
        if (rawName.endsWith(suffix)) {
          const base = rawName.slice(0, -suffix.length).trim();
          return base || rawName;
        }
      }
      return rawName;
    };

    const trackControlsMarkup = `
      <button class="tw-piano-xml-mixbtn ${allNearFull ? "is-focus" : ""}" type="button" data-xml-mix-all="1">
        <span>All</span>
        <span class="tw-piano-xml-mixpct">100%</span>
      </button>
      ${tracks.map((track, index) => {
        const key = String(track.id || "");
        const pct = `${Math.round(Math.max(0, Math.min(1, Number(track.volume || 0))) * 100)}%`;
        const isFocus = !!focused && String(focused.id || "") === key;
        return `
          <button class="tw-piano-xml-mixbtn ${isFocus ? "is-focus" : ""}" type="button" data-xml-track="${escapeHtml(key)}">
            <span>${escapeHtml(getTrackLabel(track, index))}</span>
            <span class="tw-piano-xml-mixpct">${pct}</span>
          </button>
        `;
      }).join("")}
      <label class="tw-piano-xml-hidevoices" title="Hide non-focused voices in score UI">
        <input type="checkbox" data-xml-hide-other="1" ${hideOtherVoices ? "checked" : ""}>
        <span>Hide others</span>
      </label>
      <button class="tw-piano-xml-settingsbtn" type="button" data-xml-osmd-settings-toggle="1" title="Open OSMD settings panel" aria-label="Open OSMD settings panel">OSMD</button>
    `;

    items.innerHTML = trackControlsMarkup;
    window.twMusicXmlView?.applyXmlTrackFocusVisual?.(document.getElementById("pdfTabXmlViewer"), safeSurrogate);
    const statusSpeedValue = state.xmlStatusBar?.querySelector?.("[data-xml-speed-value='1']");
    if (statusSpeedValue) statusSpeedValue.textContent = speedLabel;
    const statusScaleSlider = state.xmlStatusBar?.querySelector?.("[data-xml-layout-scale='1']");
    const statusScaleValue = state.xmlStatusBar?.querySelector?.("[data-xml-layout-scale-value='1']");
    if (statusScaleSlider) statusScaleSlider.value = String(Math.round(layoutScale * 100));
    if (statusScaleValue) statusScaleValue.textContent = layoutPct;
    updateScaleButtonState();
    syncOsmdSettingsPanelFromStore();
    updateXmlTransposeButtonState();
    syncAfterLayout();
  }

  function getOsmdSettingsStatusEl() {
    return state.xmlOsmdStatus || state.xmlOsmdPanel?.querySelector?.("[data-xml-osmd-status='1']") || null;
  }

  function setOsmdSettingsStatus(message, isError = false) {
    const el = getOsmdSettingsStatusEl();
    if (!el) return;
    el.textContent = String(message || "");
    el.style.color = isError ? "#fca5a5" : "rgba(226, 232, 240, 0.74)";
  }

  function formatOsmdJson(value) {
    return JSON.stringify(value || {}, null, 2);
  }

  function readOsmdJsonFromPanel() {
    const input = String(state.xmlOsmdJson?.value || "").trim();
    if (!input) return {};
    const parsed = JSON.parse(input);
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) return {};
    return parsed;
  }

  function writeOsmdJsonToPanel(obj) {
    if (!state.xmlOsmdJson) return;
    state.xmlOsmdJson.value = formatOsmdJson(obj);
  }

  function renderCatalogControl(value, meta) {
    const safeKey = String(meta?.key || "");
    const safeType = String(meta?.type || "text");
    if (!safeKey) return "";
    if (safeType === "boolean") {
      const checked = value === true ? "checked" : "";
      return `<input type="checkbox" data-xml-osmd-catalog-key="${safeKey}" data-xml-osmd-catalog-type="boolean" ${checked}>`;
    }
    if (safeType === "number") {
      const val = Number.isFinite(Number(value)) ? Number(value) : "";
      return `<input type="number" step="any" data-xml-osmd-catalog-key="${safeKey}" data-xml-osmd-catalog-type="number" value="${val}">`;
    }
    if (safeType === "select") {
      const opts = Array.isArray(meta?.options) ? meta.options : [];
      const current = String(value || "");
      const optionsHtml = opts.map((opt) => {
        const o = String(opt || "");
        const sel = o === current ? "selected" : "";
        return `<option value="${o}" ${sel}>${o}</option>`;
      }).join("");
      return `<select data-xml-osmd-catalog-key="${safeKey}" data-xml-osmd-catalog-type="select">${optionsHtml}</select>`;
    }
    const textVal = String(value || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
    return `<input type="text" data-xml-osmd-catalog-key="${safeKey}" data-xml-osmd-catalog-type="text" value="${textVal}">`;
  }

  function renderOsmdSettingsCatalog() {
    if (!state.xmlOsmdCatalog) return;
    const xmlModule = window.twMusicXml;
    const catalog = Array.isArray(xmlModule?.getOsmdSettingsCatalog?.())
      ? xmlModule.getOsmdSettingsCatalog()
      : [];
    const defaults = xmlModule?.getDefaultOsmdRenderOptions?.() || {};
    let current = {};
    try {
      current = readOsmdJsonFromPanel();
    } catch {
      current = xmlModule?.getStoredOsmdRenderOptions?.() || {};
    }
    const effective = { ...defaults, ...current };
    const rows = catalog.map((meta) => {
      const key = String(meta?.key || "");
      if (!key) return "";
      const label = String(meta?.label || key);
      const control = renderCatalogControl(effective[key], meta);
      return `
        <label class="tw-piano-xml-osmd-row" title="${key}">
          <span class="tw-piano-xml-osmd-rowlabel">${label}</span>
          <span class="tw-piano-xml-osmd-rowctrl">${control}</span>
        </label>
      `;
    }).join("");
    state.xmlOsmdCatalog.innerHTML = rows || `<div class="tw-piano-xml-osmd-help">No catalog available.</div>`;
  }

  function updateOsmdJsonFromCatalogInput(inputEl) {
    if (!inputEl) return;
    const key = String(inputEl.getAttribute("data-xml-osmd-catalog-key") || "").trim();
    const type = String(inputEl.getAttribute("data-xml-osmd-catalog-type") || "").trim();
    if (!key) return;
    const json = readOsmdJsonFromPanel();
    if (type === "boolean") {
      json[key] = !!inputEl.checked;
      writeOsmdJsonToPanel(json);
      return;
    }
    if (type === "number") {
      const n = Number(inputEl.value);
      if (!Number.isFinite(n)) {
        delete json[key];
      } else {
        json[key] = n;
      }
      writeOsmdJsonToPanel(json);
      return;
    }
    const text = String(inputEl.value || "").trim();
    if (!text) {
      delete json[key];
    } else {
      json[key] = text;
    }
    writeOsmdJsonToPanel(json);
  }

  function syncOsmdSettingsPanelFromStore() {
    if (!state.xmlOsmdJson) return;
    const xmlModule = window.twMusicXml;
    const current = xmlModule?.getStoredOsmdRenderOptions?.() || {};
    writeOsmdJsonToPanel(current);
    renderOsmdSettingsCatalog();
    setOsmdSettingsStatus("");
  }

  async function applyOsmdSettingsFromPanel() {
    const xmlModule = window.twMusicXml;
    const parsed = readOsmdJsonFromPanel();
    await xmlModule?.setOsmdRenderOptions?.(parsed, { merge: false, rerender: true });
    syncOsmdSettingsPanelFromStore();
    setOsmdSettingsStatus("Applied");
  }

  function scheduleOsmdLiveApply() {
    if (state.xmlOsmdLiveApplyTimer) {
      try { clearTimeout(state.xmlOsmdLiveApplyTimer); } catch {}
      state.xmlOsmdLiveApplyTimer = 0;
    }
    state.xmlOsmdLiveApplyTimer = setTimeout(async () => {
      state.xmlOsmdLiveApplyTimer = 0;
      try {
        await applyOsmdSettingsFromPanel();
      } catch {
        setOsmdSettingsStatus("Apply failed", true);
      }
    }, 140);
  }

  function applyXmlTrackFocus(trackId) {
    const safeSurrogate = String(window.currentSurrogate || "").trim();
    const xmlModule = window.twMusicXml;
    const tracks = xmlModule?.getTrackPlaybackStates?.(safeSurrogate) || [];
    if (!safeSurrogate || !tracks.length) return;
    const targetId = String(trackId || "");
    tracks.forEach((track) => {
      const key = String(track.id || "");
      const isTarget = key === targetId;
      xmlModule?.setTrackMute?.(safeSurrogate, key, false);
      xmlModule?.setTrackVolume?.(safeSurrogate, key, isTarget ? 1 : 0.3);
    });
    if (!window._twXmlUiFocusedTrackIdBySurrogate || typeof window._twXmlUiFocusedTrackIdBySurrogate !== "object") {
      window._twXmlUiFocusedTrackIdBySurrogate = {};
    }
    window._twXmlUiFocusedTrackIdBySurrogate[safeSurrogate] = targetId;
    renderXmlMixer(safeSurrogate);
    const hideOthers = (() => {
      try {
        return String(localStorage.getItem(XML_HIDE_OTHER_VOICES_STORAGE_KEY) || "") === "1";
      } catch {
        return false;
      }
    })();
    if (hideOthers) {
      Promise.resolve(xmlModule?.rerenderOpenMusicXmlView?.()).catch(() => {});
    }
    window.twMusicXmlView?.applyXmlTrackFocusVisual?.(document.getElementById("pdfTabXmlViewer"), safeSurrogate);
  }

  function resetXmlTrackFocus() {
    const safeSurrogate = String(window.currentSurrogate || "").trim();
    const xmlModule = window.twMusicXml;
    const tracks = xmlModule?.getTrackPlaybackStates?.(safeSurrogate) || [];
    if (!safeSurrogate || !tracks.length) return;
    tracks.forEach((track) => {
      const key = String(track.id || "");
      xmlModule?.setTrackMute?.(safeSurrogate, key, false);
      xmlModule?.setTrackVolume?.(safeSurrogate, key, 1);
    });
    if (!window._twXmlUiFocusedTrackIdBySurrogate || typeof window._twXmlUiFocusedTrackIdBySurrogate !== "object") {
      window._twXmlUiFocusedTrackIdBySurrogate = {};
    }
    delete window._twXmlUiFocusedTrackIdBySurrogate[safeSurrogate];
    renderXmlMixer(safeSurrogate);
    const hideOthers = (() => {
      try {
        return String(localStorage.getItem(XML_HIDE_OTHER_VOICES_STORAGE_KEY) || "") === "1";
      } catch {
        return false;
      }
    })();
    if (hideOthers) {
      Promise.resolve(xmlModule?.rerenderOpenMusicXmlView?.()).catch(() => {});
    }
    window.twMusicXmlView?.applyXmlTrackFocusVisual?.(document.getElementById("pdfTabXmlViewer"), safeSurrogate);
  }

  function scheduleXmlLayoutScaleApply(value) {
    const xmlModule = window.twMusicXml;
    const sliderValue = Math.max(80, Math.min(160, Math.round(Number(value) || 100)));
    const pctLabel = `${sliderValue}%`;
    const valueEl =
      state.xmlStatusBar?.querySelector?.("[data-xml-layout-scale-value='1']") ||
      state.host?.querySelector?.("[data-xml-layout-scale-value='1']");
    if (valueEl) valueEl.textContent = pctLabel;
    if (state.xmlScaleApplyTimer) {
      try { clearTimeout(state.xmlScaleApplyTimer); } catch {}
      state.xmlScaleApplyTimer = 0;
    }
    state.xmlScaleApplyTimer = setTimeout(() => {
      state.xmlScaleApplyTimer = 0;
      Promise.resolve(xmlModule?.setXmlLayoutScale?.(sliderValue / 100)).catch((err) => {
        console.warn("MusicXML layout scale apply failed:", err);
      });
    }, 120);
  }

  function ensureDOM() {
    if (state.host) return;

    injectStyles();

    const parent = document.body;
    const host = document.createElement("div");
    host.id = "twPianoDockHost";
    host.innerHTML = `
        <div id="twPianoDock" aria-hidden="true">
        <div id="twPianoXmlStatusBar" class="tw-piano-xml-statusbar" aria-label="MusicXML playback status">
          <div data-tw-xml-system-indicator="1" style="flex:0 0 auto; min-width:18px; margin-right:1px; text-align:center; white-space:nowrap; font-size:9px; font-weight:700; letter-spacing:0.01em; line-height:1; color:#e2e8f0; opacity:0.72;">-</div>
          <div data-tw-xml-measure-indicator="1" style="flex:0 0 auto; min-width:38px; margin-right:-1px; white-space:nowrap; font-size:9px; font-weight:700; letter-spacing:0.01em; line-height:1; color:#e2e8f0; opacity:0.72;">-</div>
          <div data-tw-xml-playhead-label="1" class="tw-piano-xml-beatbadge">--</div>
          <button id="twPianoXmlRewindBtn" class="tw-piano-xml-bubble tw-piano-xml-rewind" type="button" aria-label="Rewind MusicXML to start" title="Rewind to start"><i data-lucide="arrow-left-to-line"></i></button>
          <div class="tw-piano-xml-progresswrap">
            <div data-tw-xml-measure-markers="1" class="tw-piano-xml-markers" aria-hidden="true"></div>
            <div data-tw-xml-playhead-track="1" style="position:relative; min-width:0; height:8px; border-radius:999px; background:rgba(148,163,184,0.32); box-shadow:inset 0 0 0 1px rgba(226,232,240,0.18); overflow:hidden;">
              <div data-tw-xml-playhead-fill="1" style="width:0%; height:100%; border-radius:999px; background:linear-gradient(90deg,#f97316 0%,#fb923c 100%); box-shadow:0 0 10px rgba(249,115,22,0.35); opacity:0.18; transition:width 80ms linear, opacity 80ms ease;"></div>
              <div data-tw-xml-playhead-head="1" style="position:absolute; top:-2px; left:-5px; width:10px; height:12px; border-radius:999px; background:#fff7ed; box-shadow:0 0 0 2px rgba(249,115,22,0.95), 0 0 8px rgba(249,115,22,0.35); opacity:0.38; transition:left 80ms linear, opacity 80ms ease;"></div>
              <div data-tw-xml-repeat-onbar="1" class="tw-piano-xml-repeat-onbar" aria-label="Repeat pass"></div>
              <div data-tw-xml-repeat-end-onbar="1" class="tw-piano-xml-repeat-onbar is-end" aria-hidden="true"></div>
            </div>
          </div>
          <div class="tw-piano-pedal-wrap tw-piano-xml-speedwrap">
            <button class="tw-piano-xml-speedbtn" type="button" data-xml-speed="1" title="Playback speed by quarter note">
              <span>Speed</span>
              <strong data-xml-speed-value="1">Q 120</strong>
            </button>
          </div>
          <label class="tw-piano-xml-statusscale" title="System layout scale">
            <span>Scale</span>
            <input type="range" min="80" max="160" step="5" value="100" data-xml-layout-scale="1">
            <span class="tw-piano-xml-scale-value" data-xml-layout-scale-value="1">100%</span>
          </label>
          <button class="tw-piano-xml-close" type="button" data-xml-status-close="1" title="Close playable score" aria-label="Close playable score">&times;</button>
        </div>
        <div id="twPianoXmlMixBar" class="tw-piano-xml-mixbar" aria-label="MusicXML channel focus">
          <button id="twPianoXmlPlayBtn" class="tw-piano-xml-bubble" type="button" aria-label="Play MusicXML"><i data-lucide="play"></i></button>
          <div id="twPianoXmlMixItems" class="tw-piano-xml-mixitems"></div>
          <button id="twPianoXmlPianoToggleBtn" class="tw-piano-xml-tailbtn" type="button" aria-label="Show piano">&#127929;</button>
          <button id="twPianoXmlEndToggleBtn" class="tw-piano-xml-endbtn" type="button" aria-label="Open playable score">XML</button>
        </div>
        <div id="twPianoXmlOsmdPanel" class="tw-piano-xml-osmdpanel" hidden>
          <div class="tw-piano-xml-osmd-head">
            <span>OSMD settings</span>
            <button type="button" data-xml-osmd-close="1" aria-label="Close OSMD settings panel">&times;</button>
          </div>
          <div class="tw-piano-xml-osmd-help">
            Adjust OpenSheetMusicDisplay options below. Changes apply live and persist.
          </div>
          <div class="tw-piano-xml-osmd-catalog" data-xml-osmd-catalog="1"></div>
          <textarea class="tw-piano-xml-osmd-json" data-xml-osmd-json="1" spellcheck="false" style="display:none;"></textarea>
          <div class="tw-piano-xml-osmd-actions">
            <button type="button" data-xml-osmd-apply="1">Apply</button>
            <button type="button" data-xml-osmd-reset="1">Reset defaults</button>
            <button type="button" data-xml-osmd-reload="1">Reload</button>
            <span class="tw-piano-xml-osmd-status" data-xml-osmd-status="1"></span>
          </div>
        </div>
        <div class="tw-piano-head">
          <div class="tw-piano-head-main">
            <strong>TW Piano</strong>
          </div>
          <div class="tw-piano-head-controls">
            <div class="tw-piano-xml-transpose" title="Transpose score and playback in semitones">
              <span class="tw-piano-xml-transposelabel">Transpose</span>
              <div class="tw-piano-xml-transposestepper">
                <button class="tw-piano-xml-transposearrow" type="button" data-xml-transpose-step="-1" aria-label="Transpose down one semitone" title="Transpose down one semitone">-</button>
                <input class="tw-piano-xml-transposefield" type="number" min="-12" max="12" step="1" value="0" data-xml-transpose-input="1" aria-label="Transpose in semitones">
                <button class="tw-piano-xml-transposearrow" type="button" data-xml-transpose-step="1" aria-label="Transpose up one semitone" title="Transpose up one semitone">+</button>
              </div>
            </div>
            <button id="twPianoPlayScaleBtn" class="tw-piano-pedal" type="button">Play</button>
            <div class="tw-piano-scale-wrap">
              <button id="twPianoScaleBtn" class="tw-piano-pedal tw-piano-scale-btn" type="button">Scale Off ▾</button>
              <span id="twPianoScaleSig" class="tw-piano-scale-sig" aria-label="Key signature">-</span>
              <div id="twPianoScaleMenu" class="tw-piano-pedal-menu" hidden>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="off" type="button">Scale Off</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="C:maj" type="button">C Maj</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="A:min" type="button">A Min</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="G:maj" type="button">G Maj</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="E:min" type="button">E Min</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="D:maj" type="button">D Maj</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="B:min" type="button">B Min</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="A:maj" type="button">A Maj</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="F#:min" type="button">F# Min</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="E:maj" type="button">E Maj</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="C#:min" type="button">C# Min</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="F:maj" type="button">F Maj</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="D:min" type="button">D Min</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="Bb:maj" type="button">Bb Maj</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="G:min" type="button">G Min</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="Eb:maj" type="button">Eb Maj</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="C:min" type="button">C Min</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="F:min" type="button">F Min</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="Bb:min" type="button">Bb Min</button>
                <button class="tw-piano-pedal-option tw-piano-scale-option" data-scale="Eb:min" type="button">Eb Min</button>
              </div>
            </div>
            <button id="twPianoCloseBtn" type="button">Close</button>
          </div>
        </div>
        <div id="twPianoSwipeStrip" class="tw-piano-swipe-strip">Swipe octaves ◀ ▶</div>
        <div class="tw-piano-scroll">
          <div id="twPianoKeys" class="tw-piano-keys"></div>
        </div>
      </div>
    `;

    parent.appendChild(host);

    state.parent = parent;
    state.host = host;
    state.dock = host.querySelector("#twPianoDock");
    state.scroller = host.querySelector(".tw-piano-scroll");
    state.keyboard = host.querySelector("#twPianoKeys");
    state.swipeStrip = host.querySelector("#twPianoSwipeStrip");
    state.scaleBtn = host.querySelector("#twPianoScaleBtn");
    state.scaleMenu = host.querySelector("#twPianoScaleMenu");
    state.scaleSig = host.querySelector("#twPianoScaleSig");
    state.playScaleBtn = host.querySelector("#twPianoPlayScaleBtn");
    state.xmlRewindBtn = host.querySelector("#twPianoXmlRewindBtn");
    state.xmlPlayBtn = host.querySelector("#twPianoXmlPlayBtn");
    state.xmlStatusBar = host.querySelector("#twPianoXmlStatusBar");
    state.xmlMixBar = host.querySelector("#twPianoXmlMixBar");
    state.xmlMixItems = host.querySelector("#twPianoXmlMixItems");
    state.xmlPianoToggleBtn = host.querySelector("#twPianoXmlPianoToggleBtn");
    state.xmlEndToggleBtn = host.querySelector("#twPianoXmlEndToggleBtn");
    state.xmlOsmdPanel = host.querySelector("#twPianoXmlOsmdPanel");
    state.xmlOsmdJson = host.querySelector("[data-xml-osmd-json='1']");
    state.xmlOsmdStatus = host.querySelector("[data-xml-osmd-status='1']");
    state.xmlOsmdCatalog = host.querySelector("[data-xml-osmd-catalog='1']");
    syncOsmdSettingsPanelFromStore();
    try {
      state.scaleValue = localStorage.getItem(SCALE_STORAGE_KEY) || "off";
    } catch {
      state.scaleValue = "off";
    }
    try {
      const savedClef = localStorage.getItem(CLEF_STORAGE_KEY);
      state.clefMode = savedClef === "F" ? "F" : "G";
    } catch {
      state.clefMode = "G";
    }
    state.accidentalMode = getAccidentalModeFromScale(state.scaleValue);
    updateDockGeometry();

    refreshKeysForViewport(true);
    bindSwipeStrip();
    // Warm both samplers early so first XML playback does not lose the opening notes.
    ensureSamplerReady().catch(() => {});
    ensureSamplerReady(true).catch(() => {});
    state.scroller?.addEventListener("scroll", () => {
      state.userPositioned = true;
    }, { passive: true });

    host.querySelector("#twPianoCloseBtn")?.addEventListener("click", () => setOpen(false));
    const openMenuAdaptive = (menu, wrap) => {
      if (!menu || !wrap) return;
      const isTrackMenu = menu.hasAttribute("data-xml-track-menu");
      menu.hidden = false;
      menu.style.visibility = "hidden";
      const rect = wrap.getBoundingClientRect();
      const menuH = Math.min(menu.scrollHeight || 0, menu.offsetHeight || menu.getBoundingClientRect().height || 0);
      const menuW = Math.min(menu.scrollWidth || 0, menu.offsetWidth || menu.getBoundingClientRect().width || 0);
      const gap = 8;
      const spaceBelow = window.innerHeight - rect.bottom - gap;
      const spaceAbove = rect.top - gap;
      const needUp = spaceBelow < Math.min(menuH || 160, 220) && spaceAbove > spaceBelow;
      if (isTrackMenu) {
        menu.style.position = "fixed";
        menu.style.right = "auto";
        menu.style.bottom = "auto";
        menu.style.top = `${Math.round(needUp ? Math.max(gap, rect.top - (menuH || 160) - 4) : rect.bottom + 4)}px`;
        const idealLeft = rect.left;
        const maxLeft = Math.max(gap, window.innerWidth - Math.max(menuW || 0, 120) - gap);
        menu.style.left = `${Math.round(Math.max(gap, Math.min(idealLeft, maxLeft)))}px`;
        menu.style.zIndex = "5005";
      } else {
        menu.style.position = "";
        menu.style.left = "0";
        menu.style.right = "auto";
        menu.style.top = "calc(100% + 4px)";
        menu.style.bottom = "auto";
        if (needUp) {
          menu.style.top = "auto";
          menu.style.bottom = "calc(100% + 4px)";
        } else {
          menu.style.top = "calc(100% + 4px)";
          menu.style.bottom = "auto";
        }
        const rightEdge = rect.left + Math.max(0, menuW);
        const leftAlignedFits = rightEdge <= (window.innerWidth - gap);
        if (!leftAlignedFits) {
          const leftFromRight = rect.right - Math.max(0, menuW);
          if (leftFromRight >= gap) {
            menu.style.left = "auto";
            menu.style.right = "0";
          } else {
            const offsetLeft = Math.max(gap - rect.left, Math.min(0, (window.innerWidth - gap - Math.max(0, menuW)) - rect.left));
            menu.style.left = `${Math.round(offsetLeft)}px`;
            menu.style.right = "auto";
          }
        }
      }
      menu.style.visibility = "";
    };

    const updateScaleButton = updateScaleButtonState;
    if (!state.scaleMenu?.querySelector(`.tw-piano-scale-option[data-scale="${state.scaleValue}"]`)) {
      state.scaleValue = "off";
      state.accidentalMode = getAccidentalModeFromScale(state.scaleValue);
    }
    updateScaleButton();
    state.scaleBtn?.addEventListener("click", (e) => {
      e.stopPropagation();
      const wrap = state.scaleBtn?.closest(".tw-piano-scale-wrap");
      if (!state.scaleMenu) return;
      const willOpen = state.scaleMenu.hidden;
      if (willOpen) openMenuAdaptive(state.scaleMenu, wrap);
      else state.scaleMenu.hidden = true;
    });
    state.playScaleBtn?.addEventListener("click", (e) => {
      e.stopPropagation();
      autoplayScaleTwoOctaves(getDisplayedScaleValue()).catch(() => {});
    });
    state.host?.addEventListener("click", (e) => {
      const transposeStepBtn = e.target?.closest?.("[data-xml-transpose-step]");
      if (!transposeStepBtn) return;
      e.stopPropagation();
      const delta = Math.round(Number(transposeStepBtn.dataset.xmlTransposeStep) || 0);
      const current = Math.max(-12, Math.min(12, Number(window.twMusicXml?.getXmlRenderTransposeSemitones?.() || 0)));
      const next = Math.max(-12, Math.min(12, current + delta));
      const pending = Promise.resolve(window.twMusicXml?.setXmlRenderTransposeSemitones?.(next));
      updateXmlTransposeButtonState();
      pending.then(() => {
        updateScaleButtonState();
        updateXmlTransposeButtonState();
        refreshKeysForViewport(true);
        renderXmlMixer(window.currentSurrogate);
      }).catch((err) => {
        updateXmlTransposeButtonState();
        console.warn("MusicXML transpose update failed:", err);
      });
    });
    state.host?.addEventListener("change", (e) => {
      const input = e.target?.closest?.("[data-xml-transpose-input='1']");
      if (!input) return;
      e.stopPropagation();
      const next = Math.max(-12, Math.min(12, Math.round(Number(input.value) || 0)));
      const pending = Promise.resolve(window.twMusicXml?.setXmlRenderTransposeSemitones?.(next));
      updateXmlTransposeButtonState();
      pending.then(() => {
        updateScaleButtonState();
        updateXmlTransposeButtonState();
        refreshKeysForViewport(true);
        renderXmlMixer(window.currentSurrogate);
      }).catch((err) => {
        updateXmlTransposeButtonState();
        console.warn("MusicXML transpose update failed:", err);
      });
    });
    state.host?.addEventListener("input", (e) => {
      const input = e.target?.closest?.("[data-xml-transpose-input='1']");
      if (!input) return;
      const next = Math.max(-12, Math.min(12, Math.round(Number(input.value) || 0)));
      input.value = String(next);
    });
    state.xmlPlayBtn?.addEventListener("click", async (e) => {
      e.stopPropagation();
      const current = String(window.currentSurrogate || "").trim();
      if (!current) return;
      const xmlModule = window.twMusicXml;
      if (xmlModule?.isXmlPlaybackActive?.(current)) {
        xmlModule?.pauseXmlPlayback?.() || xmlModule?.stopXmlPlayback?.();
        return;
      }
      const xmlAlreadyOpen =
        !!window._pdfXmlViewState?.active &&
        String(window._pdfXmlViewState?.surrogate || "") === current;
      if (!xmlAlreadyOpen) {
        const opened = await xmlModule?.openMusicXmlInPdfTab?.(current);
        if (opened) {
          try { await xmlModule?.ensureTrackStatesReady?.(current); } catch {}
          renderXmlMixer(current);
        }
        return;
      }
      await xmlModule?.playXmlSequence?.(current);
    });
    state.xmlRewindBtn?.addEventListener("click", (e) => {
      e.stopPropagation();
      const current = String(window.currentSurrogate || "").trim();
      const xmlModule = window.twMusicXml;
      if (!current || !xmlModule?.setXmlPlaybackPositionByProgress) return;
      if (xmlModule?.isXmlPlaybackActive?.(current)) {
        xmlModule?.stopXmlPlayback?.();
      }
      Promise.resolve(
        xmlModule.setXmlPlaybackPositionByProgress(0, {
          surrogate: current,
          commit: false,
          resumePlayback: false,
          resetRepeatOverride: true
        })
      ).catch((err) => {
        console.warn("MusicXML rewind failed:", err);
      });
    });
    state.xmlStatusBar?.addEventListener("pointerdown", (e) => {
      const track = e.target?.closest?.("[data-tw-xml-playhead-track='1']");
      const xmlModule = window.twMusicXml;
      const current = String(window.currentSurrogate || "").trim();
      if (!track || !xmlModule?.setXmlPlaybackPositionByProgress || !current) return;
      e.preventDefault();
      e.stopPropagation();
      const pointerId = Number(e.pointerId || 0);
      const xmlViewer = document.getElementById("pdfTabXmlViewer");
      const stateHost = xmlViewer?._twXmlStateHost || xmlViewer;
      const currentRepeatPass = Math.max(1, Number(stateHost?._twXmlCurrentRepeatPass || 1));
      const resumePlayback = !!xmlModule?.isXmlPlaybackActive?.(current);
      if (resumePlayback) {
        xmlModule?.stopXmlPlayback?.();
      }
      const applySeek = (event, commit) => {
        const rect = track.getBoundingClientRect();
        const width = Math.max(1, Number(rect.width || 0));
        const progress = (Number(event.clientX || 0) - Number(rect.left || 0)) / width;
        Promise.resolve(
          xmlModule.setXmlPlaybackPositionByProgress(progress, {
            surrogate: current,
            commit,
            resumePlayback: commit && resumePlayback,
            repeatPassOverride: (resumePlayback && currentRepeatPass > 1) ? currentRepeatPass : null
          })
        ).catch((err) => {
          console.warn("MusicXML seek failed:", err);
        });
      };
      applySeek(e, false);
      try { track.setPointerCapture(pointerId); } catch {}
      const cleanup = () => {
        track.removeEventListener("pointermove", onMove);
        track.removeEventListener("pointerup", onUp);
        track.removeEventListener("pointercancel", onCancel);
      };
      const onMove = (event) => {
        if (Number(event.pointerId || 0) !== pointerId) return;
        applySeek(event, false);
      };
      const onUp = (event) => {
        if (Number(event.pointerId || 0) !== pointerId) return;
        cleanup();
        applySeek(event, true);
      };
      const onCancel = (event) => {
        if (Number(event.pointerId || 0) !== pointerId) return;
        cleanup();
        applySeek(event, true);
      };
      track.addEventListener("pointermove", onMove);
      track.addEventListener("pointerup", onUp);
      track.addEventListener("pointercancel", onCancel);
    });
    state.xmlStatusBar?.addEventListener("input", (e) => {
      const slider = e.target?.closest?.("[data-xml-layout-scale='1']");
      if (!slider) return;
      e.stopPropagation();
      scheduleXmlLayoutScaleApply(slider.value);
    });
    state.xmlStatusBar?.addEventListener("change", (e) => {
      const slider = e.target?.closest?.("[data-xml-layout-scale='1']");
      if (!slider) return;
      e.stopPropagation();
      scheduleXmlLayoutScaleApply(slider.value);
    });
    state.xmlEndToggleBtn?.addEventListener("click", (e) => {
      e.stopPropagation();
      document.getElementById("pdfXmlToggleBtn")?.click?.();
      setTimeout(() => renderXmlMixer(window.currentSurrogate), 120);
    });
    state.xmlPianoToggleBtn?.addEventListener("click", (e) => {
      e.stopPropagation();
      const current = String(window.currentSurrogate || "").trim();
      const xmlOpenForCurrent =
        !!window._pdfXmlViewState?.active &&
        String(window._pdfXmlViewState?.surrogate || "") === current;
      const nextOpen = !state.isOpen;
      setOpen(nextOpen);
      if (!nextOpen && xmlOpenForCurrent && state.dock) {
        state.dock.classList.add("tw-xml-visible", "tw-strips-only");
        state.dock.setAttribute("aria-hidden", "false");
      }
    });
    state.xmlMixBar?.addEventListener("click", (e) => {
      const osmdSettingsToggle = e.target?.closest?.("[data-xml-osmd-settings-toggle='1']");
      if (osmdSettingsToggle) {
        e.stopPropagation();
        if (state.xmlOsmdPanel) {
          state.xmlOsmdPanel.hidden = !state.xmlOsmdPanel.hidden;
          if (!state.xmlOsmdPanel.hidden) {
            syncOsmdSettingsPanelFromStore();
            state.xmlOsmdJson?.focus?.();
          }
        }
        return;
      }
      const allBtn = e.target?.closest?.("[data-xml-mix-all='1']");
      if (allBtn) {
        e.stopPropagation();
        resetXmlTrackFocus();
        return;
      }
      const trackBtn = e.target?.closest?.("[data-xml-track]");
      if (!trackBtn) return;
      e.stopPropagation();
      applyXmlTrackFocus(trackBtn.getAttribute("data-xml-track") || "");
    });
    state.xmlMixBar?.addEventListener("change", (e) => {
      const toggle = e.target?.closest?.("[data-xml-hide-other='1']");
      if (!toggle) return;
      const next = !!toggle.checked;
      try {
        localStorage.setItem(XML_HIDE_OTHER_VOICES_STORAGE_KEY, next ? "1" : "0");
        localStorage.setItem(XML_SHRINK_OTHER_VOICES_STORAGE_KEY, next ? "1" : "0");
      } catch {}
      const current = String(window.currentSurrogate || "").trim();
      Promise.resolve(window.twMusicXml?.rerenderOpenMusicXmlView?.()).then(() => {
        window.twMusicXmlView?.applyXmlTrackFocusVisual?.(document.getElementById("pdfTabXmlViewer"), current);
      }).catch(() => {
        window.twMusicXmlView?.applyXmlTrackFocusVisual?.(document.getElementById("pdfTabXmlViewer"), current);
      });
    });
    state.xmlStatusBar?.addEventListener("click", (e) => {
      const closeBtn = e.target?.closest?.("[data-xml-status-close='1']");
      if (closeBtn) {
        e.stopPropagation();
        const current = String(window.currentSurrogate || "").trim();
        const xmlOpenForCurrent =
          !!window._pdfXmlViewState?.active &&
          String(window._pdfXmlViewState?.surrogate || "") === current;
        window.twMusicXml?.stopXmlPlayback?.();
        setOpen(false);
        state.xmlStatusBar?.classList.remove("is-visible");
        state.xmlMixBar?.classList.remove("is-visible");
        state.dock?.classList.remove("tw-xml-visible", "tw-strips-only");
        requestAnimationFrame(updateDockGeometry);
        if (xmlOpenForCurrent) {
          document.getElementById("pdfXmlToggleBtn")?.click?.();
        }
        return;
      }
      const speedBtn = e.target?.closest?.("[data-xml-speed='1']");
      if (!speedBtn) return;
      e.stopPropagation();
      const xmlModule = window.twMusicXml;
      const safeSurrogate = String(window.currentSurrogate || "").trim();
      const speedInfo = xmlModule?.getXmlPlaybackSpeedInfo?.(safeSurrogate) || {};
      const currentQuarter = Math.max(20, Math.round(Number(speedInfo?.quarterBpm || 120)));
      const currentSpeed = Math.max(0.5, Number(speedInfo?.speed || 1));
      const baseQuarter = Math.max(
        20,
        Number(speedInfo?.baseQuarterBpm || 0) || (currentQuarter / currentSpeed)
      );
      const allowedSpeeds = [0.5, 0.75, 1, 1.25, 1.5, 2];
      const speedTargets = [60, 90, 120, 240];
      const speedOptions = [];
      for (const target of speedTargets) {
        const requestedMultiplier = Math.max(0.5, Math.min(2, target / baseQuarter));
        const multiplier = allowedSpeeds.reduce((best, option) =>
          Math.abs(option - requestedMultiplier) < Math.abs(best - requestedMultiplier) ? option : best,
        allowedSpeeds[0]);
        if (speedOptions.some((entry) => Math.abs(entry.multiplier - multiplier) < 0.0001)) continue;
        const actualQuarter = Math.max(20, Math.round(baseQuarter * multiplier));
        speedOptions.push({ target, multiplier, actualQuarter });
      }
      if (!speedOptions.length) return;
      let nextOption = speedOptions[0];
      const currentIndex = speedOptions.findIndex((entry) => Math.abs(entry.multiplier - currentSpeed) < 0.03);
      if (currentIndex >= 0) {
        nextOption = speedOptions[(currentIndex + 1) % speedOptions.length];
      } else {
        nextOption =
          speedOptions.find((entry) => entry.actualQuarter > currentQuarter) ||
          speedOptions[0];
      }
      if (Number.isFinite(nextOption.multiplier)) {
        xmlModule?.setXmlPlaybackSpeedMultiplier?.(nextOption.multiplier, { surrogate: safeSurrogate });
      }
    });
    state.xmlOsmdPanel?.addEventListener("click", async (e) => {
      const closeBtn = e.target?.closest?.("[data-xml-osmd-close='1']");
      if (closeBtn) {
        e.stopPropagation();
        state.xmlOsmdPanel.hidden = true;
        return;
      }
      const applyBtn = e.target?.closest?.("[data-xml-osmd-apply='1']");
      if (applyBtn) {
        e.stopPropagation();
        try {
          await applyOsmdSettingsFromPanel();
        } catch (err) {
          setOsmdSettingsStatus("Invalid JSON", true);
        }
        return;
      }
      const resetBtn = e.target?.closest?.("[data-xml-osmd-reset='1']");
      if (resetBtn) {
        e.stopPropagation();
        try {
          await window.twMusicXml?.resetOsmdRenderOptions?.({ rerender: true });
          syncOsmdSettingsPanelFromStore();
          setOsmdSettingsStatus("Defaults restored");
        } catch {
          setOsmdSettingsStatus("Reset failed", true);
        }
        return;
      }
      const reloadBtn = e.target?.closest?.("[data-xml-osmd-reload='1']");
      if (reloadBtn) {
        e.stopPropagation();
        syncOsmdSettingsPanelFromStore();
        setOsmdSettingsStatus("Reloaded");
      }
    });
    state.xmlOsmdPanel?.addEventListener("input", (e) => {
      if (e.target?.matches?.("[data-xml-osmd-json='1']")) {
        try {
          renderOsmdSettingsCatalog();
          setOsmdSettingsStatus("");
        } catch {
          setOsmdSettingsStatus("Invalid JSON", true);
        }
        return;
      }
      const ctrl = e.target?.closest?.("[data-xml-osmd-catalog-key]");
      if (!ctrl) return;
      try {
        updateOsmdJsonFromCatalogInput(ctrl);
        renderOsmdSettingsCatalog();
        setOsmdSettingsStatus("Applying...");
        scheduleOsmdLiveApply();
      } catch {
        setOsmdSettingsStatus("Invalid JSON", true);
      }
    });
    state.xmlMixBar?.addEventListener("input", (e) => {
      const slider = e.target?.closest?.("[data-xml-layout-scale='1']");
      if (!slider) return;
      e.stopPropagation();
      scheduleXmlLayoutScaleApply(slider.value);
    });
    state.xmlMixBar?.addEventListener("change", (e) => {
      const slider = e.target?.closest?.("[data-xml-layout-scale='1']");
      if (!slider) return;
      e.stopPropagation();
      scheduleXmlLayoutScaleApply(slider.value);
    });
    state.scaleSig?.addEventListener("click", (e) => {
      e.stopPropagation();
      state.clefMode = state.clefMode === "F" ? "G" : "F";
      try { localStorage.setItem(CLEF_STORAGE_KEY, state.clefMode); } catch {}
      renderScaleSignatureBadge(state.scaleSig, getDisplayedScaleValue());
    });
    state.scaleMenu?.addEventListener("click", (e) => {
      const btn = e.target.closest(".tw-piano-scale-option");
      if (!btn) return;
      setScaleValue(btn.dataset.scale || "off");
      state.scaleMenu.hidden = true;
      e.stopPropagation();
    });
    document.addEventListener("click", (e) => {
      if (state.scaleMenu && !state.scaleMenu.hidden && !e.target?.closest?.(".tw-piano-scale-wrap")) {
        state.scaleMenu.hidden = true;
      }
      if (
        state.xmlOsmdPanel &&
        !state.xmlOsmdPanel.hidden &&
        !e.target?.closest?.("#twPianoXmlOsmdPanel") &&
        !e.target?.closest?.("[data-xml-osmd-settings-toggle='1']")
      ) {
        state.xmlOsmdPanel.hidden = true;
      }
      state.xmlMixBar?.querySelectorAll?.("[data-xml-track-menu='1']").forEach((menu) => {
        if (!e.target?.closest?.(".tw-piano-xml-trackpicker")) {
          menu.hidden = true;
        }
      });
      if (e.target?.closest?.(".list-sub-item")) {
        setTimeout(() => {
          renderXmlMixer(window.currentSurrogate);
        }, 60);
      }
    });

    renderXmlMixer(window.currentSurrogate);

    // Only show piano on text/pdf tabs.
    updateDockVisibilityForTab();
    window.twMusicXml?.syncPdfXmlPlayButton?.(window.currentSurrogate);
    document.addEventListener("click", (e) => {
      if (e.target?.closest?.(".footer-tab-btn[data-target]")) {
        setTimeout(updateDockVisibilityForTab, 0);
      }
    });
    const tabObserver = new MutationObserver(() => {
      updateDockGeometry();
      updateDockVisibilityForTab();
    });
    const textTab = document.getElementById("textTabContent");
    const pdfTab = document.getElementById("pdfTabContent");
    if (textTab) tabObserver.observe(textTab, { attributes: true, attributeFilter: ["class"] });
    if (pdfTab) tabObserver.observe(pdfTab, { attributes: true, attributeFilter: ["class"] });
    document.querySelectorAll(".footer-tab-btn[data-target]").forEach((btn) => {
      tabObserver.observe(btn, { attributes: true, attributeFilter: ["class"] });
    });
    const homeTab = document.getElementById("homeTabContent");
    if (homeTab) tabObserver.observe(homeTab, { attributes: true, attributeFilter: ["class", "style"] });
    const musicPanel = document.getElementById("musicTabContent");
    if (musicPanel) tabObserver.observe(musicPanel, { attributes: true, attributeFilter: ["class", "style"] });
    const chat = document.getElementById("chatContainer");
    if (chat) tabObserver.observe(chat, { attributes: true, attributeFilter: ["class", "style"] });
    const driveImport = document.getElementById("driveImportOverlay");
    if (driveImport) tabObserver.observe(driveImport, { attributes: true, attributeFilter: ["class", "style"] });
    const footerMenu = document.getElementById("footerMenu");
    if (footerMenu) tabObserver.observe(footerMenu, { attributes: true, attributeFilter: ["class", "style"] });
    if (typeof window.switchTab === "function" && !window.switchTab.__twPianoWrapped) {
      const originalSwitchTab = window.switchTab;
      const wrapped = function (...args) {
        const out = originalSwitchTab.apply(this, args);
        setTimeout(() => {
          updateDockGeometry();
          updateDockVisibilityForTab();
          renderXmlMixer(window.currentSurrogate);
        }, 0);
        return out;
      };
      wrapped.__twPianoWrapped = true;
      window.switchTab = wrapped;
    }

    // Keep piano gestures from leaking to global PDF swipe handlers.
    host.addEventListener("contextmenu", (e) => {
      e.preventDefault();
    });
    host.addEventListener("touchstart", (e) => {
      e.stopPropagation();
    }, { passive: true });
    host.addEventListener("touchmove", (e) => {
      e.stopPropagation();
    }, { passive: false });
    host.addEventListener("touchend", (e) => {
      e.stopPropagation();
    }, { passive: true });

    window.addEventListener("blur", stopAllNotes);
    window.addEventListener("resize", () => {
      updateDockGeometry();
      refreshKeysForViewport(false);
    }, { passive: true });
    window.addEventListener("scroll", updateDockGeometry, { passive: true });

    const saved = localStorage.getItem(STORAGE_KEY) === "1";
    setOpen(saved);
    if (saved) {
      requestAnimationFrame(() => centerOnMidi(60, false)); // C4 centered by default
    }
  }

  window.TWPianoDock = {
    open() {
      ensureDOM();
      if (!isAllowedTabForPiano()) return;
      setOpen(true);
      requestAnimationFrame(() => {
        if (!state.userPositioned) centerOnMidi(60, false); // C4
      });
    },
    close() { ensureDOM(); setOpen(false); },
    toggle() { ensureDOM(); setOpen(!state.isOpen); },
    isOpen() { return !!state.isOpen; },
    previewMidi(midi, opts = {}) {
      ensureDOM();
      if (opts.open === true && isAllowedTabForPiano()) {
        setOpen(true);
      }
      return setExternalPreviewMidi(midi);
    },
    previewMidiNotes(midis, opts = {}) {
      ensureDOM();
      if (opts.open === true && isAllowedTabForPiano()) {
        setOpen(true);
      }
      return setExternalPreviewMidis(midis);
    },
    refreshXmlMixer(surrogate) {
      ensureDOM();
      renderXmlMixer(surrogate);
    },
    async prepareScheduledPlayback(opts = {}) {
      ensureDOM();
      if (!window.Tone) return false;
      if (opts.open === true && isAllowedTabForPiano()) {
        setOpen(true);
      }
      await Tone.start();
      return !!(await ensureSamplerReady(opts.strictTiming === true));
    },
    scheduleMidiAt(midi, opts = {}) {
      ensureDOM();
      if (!window.Tone) return false;
      const noteMidi = Math.max(NOTE_MIN, Math.min(NOTE_MAX, Math.round(Number(midi) || 0)));
      if (!Number.isFinite(noteMidi) || noteMidi < NOTE_MIN || noteMidi > NOTE_MAX) return false;
      const strictTiming = opts.strictTiming === true;
      const ready = strictTiming ? state.scoreSamplerReady : state.samplerReady;
      if (!ready) return false;
      const sampler = getSamplerByKind(strictTiming ? "score" : "main");
      if (!sampler) return false;
      const at = Math.max(window.Tone.now(), Number(opts.at) || window.Tone.now());
      const durationSec = Math.max(0.01, Math.min(8, Number(opts.durationSec) || 0.12));
      const velocity = Math.max(0.05, Math.min(1, Number(opts.velocity) || 0.85));
      try {
        sampler.triggerAttackRelease(midiToToneNote(noteMidi), durationSec, at, velocity);
        return true;
      } catch {
        return false;
      }
    },
    clearPreviewMidi() {
      ensureDOM();
      clearExternalPreviewMidi();
    },
    clearPlayingMidiVisuals() {
      ensureDOM();
      clearExternalActiveMidis();
    },
    setPlayingMidiVisual(midi, on = true) {
      ensureDOM();
      return setExternalActiveMidi(midi, on !== false);
    },
    setScaleValue(value, opts = {}) {
      ensureDOM();
      setScaleValue(value, opts);
      return true;
    },
    setScale(value, opts = {}) {
      return window.TWPianoDock.setScaleValue(value, opts);
    },
    async startMidi(midi, opts = {}) {
      ensureDOM();
      const noteMidi = Math.max(NOTE_MIN, Math.min(NOTE_MAX, Math.round(Number(midi) || 0)));
      if (!Number.isFinite(noteMidi) || noteMidi < NOTE_MIN || noteMidi > NOTE_MAX) return false;

      const velocity = Math.max(0.05, Math.min(1, Number(opts.velocity) || 0.85));
      const shouldOpen = opts.open === true;
      const shouldCenter = opts.center !== false;
      const shouldRetrigger = opts.retrigger !== false;

      if (shouldOpen && isAllowedTabForPiano()) {
        setOpen(true);
      }
      if (shouldCenter && state.isOpen) {
        requestAnimationFrame(() => centerOnMidi(noteMidi, true));
      }

      clearExternalPreviewMidi();
      state.downNotes.add(noteMidi);
      state.pendingRelease.delete(noteMidi);
      if (state.releaseTimers.has(noteMidi)) {
        clearTimeout(state.releaseTimers.get(noteMidi));
        state.releaseTimers.delete(noteMidi);
      }

      if (shouldRetrigger && state.activeNotes.has(noteMidi)) {
        triggerImmediateReleaseForKind(noteMidi, state.activeSamplerKindByMidi.get(noteMidi) || "main");
        state.activeNotes.delete(noteMidi);
        state.activeSamplerKindByMidi.delete(noteMidi);
        state.pendingRelease.delete(noteMidi);
        setKeyActive(noteMidi, false);
      }

      return !!(await noteOn(noteMidi, velocity, { strictTiming: opts.strictTiming === true }));
    },
    stopMidi(midi, opts = {}) {
      ensureDOM();
      const noteMidi = Math.max(NOTE_MIN, Math.min(NOTE_MAX, Math.round(Number(midi) || 0)));
      if (!Number.isFinite(noteMidi) || noteMidi < NOTE_MIN || noteMidi > NOTE_MAX) return false;
      noteOff(noteMidi, {
        ignoreSustain: opts.ignoreSustain !== false,
        strictTiming: opts.strictTiming === true
      });
      return true;
    },
    async playMidi(midi, opts = {}) {
      ensureDOM();
      const noteMidi = Math.max(NOTE_MIN, Math.min(NOTE_MAX, Math.round(Number(midi) || 0)));
      if (!Number.isFinite(noteMidi) || noteMidi < NOTE_MIN || noteMidi > NOTE_MAX) return false;

      const durationMs = Math.max(80, Math.min(4000, Number(opts.durationMs || 420)));
      const velocity = Math.max(0.05, Math.min(1, Number(opts.velocity) || 0.85));
      const shouldOpen = opts.open === true;
      const shouldCenter = opts.center !== false;
      const shouldRetrigger = opts.retrigger !== false;
      const ignoreSustain = opts.ignoreSustain === true;

      if (shouldOpen && isAllowedTabForPiano()) {
        setOpen(true);
      }
      if (shouldCenter && state.isOpen) {
        requestAnimationFrame(() => centerOnMidi(noteMidi, true));
      }

      clearExternalPreviewMidi();
      setKeyActive(noteMidi, true);
      state.downNotes.add(noteMidi);
      state.pendingRelease.delete(noteMidi);
      if (state.releaseTimers.has(noteMidi)) {
        clearTimeout(state.releaseTimers.get(noteMidi));
        state.releaseTimers.delete(noteMidi);
      }

      if (shouldRetrigger && state.activeNotes.has(noteMidi)) {
        triggerImmediateReleaseForKind(noteMidi, state.activeSamplerKindByMidi.get(noteMidi) || "main");
        state.activeNotes.delete(noteMidi);
        state.activeSamplerKindByMidi.delete(noteMidi);
        state.pendingRelease.delete(noteMidi);
        setKeyActive(noteMidi, false);
      }

      const started = await noteOn(noteMidi, velocity);
      if (!started) {
        state.downNotes.delete(noteMidi);
        state.pendingRelease.delete(noteMidi);
        setKeyActive(noteMidi, false);
        return false;
      }
      setTimeout(() => {
        noteOff(noteMidi, { ignoreSustain });
      }, durationMs);
      return true;
    }
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", ensureDOM, { once: true });
  } else {
    ensureDOM();
  }
})();
