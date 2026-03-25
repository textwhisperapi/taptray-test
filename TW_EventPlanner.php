<?php
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/functions.php';

sec_session_start();
$assetVersion = (@filemtime(__DIR__ . '/img/wrt.png') ?: time()) + 1;
?>

<style>
:root {
  --tw-ink: #14110c;
  --tw-muted: #5f5a53;
  --tw-accent: #285fe6;
  --tw-accent-warm: #f15a2a;
  --tw-bg-1: #f6f1e6;
  --tw-bg-2: #eef6ff;
  --tw-card: #ffffff;
  --tw-stroke: #e6ddcf;
  --tw-shadow: 0 14px 40px rgba(19, 17, 12, 0.12);
}

.tw-event {
  max-width: 980px;
  margin: 20px auto;
  padding: clamp(20px, 4vw, 44px);
  font-family: "Trebuchet MS", "Gill Sans", "Helvetica Neue", sans-serif;
  line-height: 1.6;
  color: var(--tw-ink);
  display: flex;
  flex-direction: column;
  gap: 18px;
  position: relative;
  overflow: hidden;
  border-radius: 26px;
  background: linear-gradient(145deg, var(--tw-bg-1), var(--tw-bg-2));
}

.tw-event::before,
.tw-event::after {
  content: "";
  position: absolute;
  width: 320px;
  height: 320px;
  border-radius: 50%;
  filter: blur(40px);
  opacity: 0.32;
  z-index: 0;
}

.tw-event::before {
  top: -120px;
  right: -80px;
  background: radial-gradient(circle, #ffd7a8, rgba(255, 215, 168, 0));
}

.tw-event::after {
  bottom: -140px;
  left: -100px;
  background: radial-gradient(circle, #b7d8ff, rgba(183, 216, 255, 0));
}

.tw-event > section {
  position: relative;
  z-index: 1;
  animation: tw-fade-up 0.7s ease both;
}

@keyframes tw-fade-up {
  from {
    opacity: 0;
    transform: translateY(14px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.tw-event-hero {
  padding: clamp(14px, 3vw, 24px);
  background: rgba(255, 255, 255, 0.76);
  border: 1px solid rgba(230, 221, 207, 0.7);
  border-radius: 20px;
  box-shadow: var(--tw-shadow);
  display: flex;
  align-items: center;
  gap: 14px;
}

.tw-event-logo {
  width: clamp(42px, 8vw, 58px);
  height: auto;
  opacity: 0.96;
}

.tw-event-hero h1 {
  font-family: "Bodoni MT", "Didot", "Garamond", "Georgia", serif;
  font-size: clamp(26px, 4.2vw, 40px);
  font-weight: 600;
  margin: 0;
}

.tw-event-tagline {
  font-size: clamp(14px, 2.2vw, 16px);
  color: var(--tw-muted);
  margin-top: 4px;
}

.tw-event-card {
  padding: clamp(14px, 3vw, 22px);
  background: rgba(255, 255, 255, 0.9);
  border: 1px solid var(--tw-stroke);
  border-radius: 18px;
}

.tw-event-card h2 {
  font-family: "Bodoni MT", "Didot", "Garamond", "Georgia", serif;
  font-size: clamp(18px, 2.8vw, 22px);
  margin: 0 0 10px;
}

.tw-event-card p {
  margin: 6px 0 0;
  font-size: 15px;
  color: var(--tw-muted);
}

.tw-event-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.1fr) minmax(0, 1.9fr);
  gap: 16px;
}

.tw-event-panel {
  padding: clamp(14px, 3vw, 22px);
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid var(--tw-stroke);
  border-radius: 18px;
  box-shadow: 0 10px 22px rgba(19, 17, 12, 0.08);
}

.tw-event-panel h3 {
  font-family: "Bodoni MT", "Didot", "Garamond", "Georgia", serif;
  font-size: 18px;
  margin: 0 0 10px;
}

.tw-event-panel p {
  margin: 6px 0 0;
  font-size: 14px;
  color: var(--tw-muted);
}

.tw-event-chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.tw-event-chip {
  padding: 6px 12px;
  border-radius: 999px;
  border: 1px solid rgba(40, 95, 230, 0.25);
  background: #ffffff;
  font-size: 13px;
  color: var(--tw-accent);
  font-weight: 600;
}

.tw-event-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.tw-event-row {
  border: 1px solid rgba(20, 17, 12, 0.12);
  background: #fff;
  border-radius: 16px;
  padding: 12px 14px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
}

.tw-event-title {
  font-weight: 600;
  font-size: 15px;
}

.tw-event-meta {
  font-size: 12px;
  color: var(--tw-muted);
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 4px;
}

.tw-event-avatars {
  display: inline-flex;
  align-items: center;
  gap: 0;
}

.tw-event-avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 2px solid #fff;
  background: #e3e3e3;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 700;
  color: #3b3a36;
  margin-left: -8px;
}

.tw-event-avatar:first-child {
  margin-left: 0;
}

.tw-event-check {
  padding: 6px 12px;
  border-radius: 999px;
  border: 1px solid rgba(241, 90, 42, 0.6);
  background: linear-gradient(135deg, #fff6ed, #ffffff);
  color: #b0421d;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}

.tw-event-expand {
  margin-top: 10px;
  border-top: 1px dashed rgba(20, 17, 12, 0.15);
  padding-top: 10px;
  display: none;
}

.tw-event-row[open] .tw-event-expand {
  display: block;
}

.tw-event-row summary {
  list-style: none;
  cursor: pointer;
}

.tw-event-row summary::-webkit-details-marker {
  display: none;
}

.tw-event-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.tw-event-chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.tw-event-chip {
  padding: 6px 12px;
  border-radius: 999px;
  border: 1px solid rgba(40, 95, 230, 0.25);
  background: #ffffff;
  font-size: 13px;
  color: var(--tw-accent);
  font-weight: 600;
}

@media (max-width: 720px) {
  .tw-event {
    margin: 0;
    border-radius: 0;
    padding: 12px 10px 76px;
  }
  .tw-event-hero {
    flex-direction: column;
    align-items: flex-start;
  }
  .tw-event-grid {
    grid-template-columns: 1fr;
  }
  .tw-event-row {
    grid-template-columns: 1fr;
  }
  .tw-event-actions {
    justify-content: space-between;
  }
}
</style>

<div class="tw-event" id="event-planner">
  <section class="tw-event-hero">
    <img src="/img/wrt.png?v=<?= $assetVersion ?>" alt="TextWhisper" class="tw-event-logo">
    <div>
      <h1>Event Planner</h1>
      <div class="tw-event-tagline">Organize rehearsals, programs, and deadlines</div>
    </div>
  </section>

  <section class="tw-event-card">
    <h2>Quick draft</h2>
    <p>
      Each event is one line. Members check in like a poll, and avatars stack
      like a Messenger poll. Click an event to expand details.
    </p>
  </section>

  <section class="tw-event-grid">
    <div class="tw-event-panel">
      <h3>Messuhopar</h3>
      <p>Define groups and who belongs to each.</p>
      <div class="tw-event-chip-row">
        <span class="tw-event-chip">Alt</span>
        <span class="tw-event-chip">Tenorar</span>
        <span class="tw-event-chip">Bassar</span>
        <span class="tw-event-chip">Solistar</span>
      </div>
      <p style="margin-top: 12px;">
        Connect groups to events to build the attendance poll.
      </p>
    </div>

    <div class="tw-event-panel">
      <h3>Events</h3>
      <div class="tw-event-list">
        <details class="tw-event-row">
          <summary>
            <div class="tw-event-title">Sunnudagsmessa 10:00</div>
            <div class="tw-event-meta">
              <span>Hallgrimskirkja</span>
              <span>Groups: Alt, Tenorar</span>
            </div>
          </summary>
          <div class="tw-event-expand">
            <p>Poll: mark in/out for your group.</p>
            <div class="tw-event-actions">
              <div class="tw-event-avatars" title="Checked in">
                <span class="tw-event-avatar">AF</span>
                <span class="tw-event-avatar">BB</span>
                <span class="tw-event-avatar">KR</span>
                <span class="tw-event-avatar">MJ</span>
              </div>
              <button class="tw-event-check" type="button">Check in</button>
            </div>
          </div>
        </details>

        <details class="tw-event-row">
          <summary>
            <div class="tw-event-title">Midvikudagsrehearsal 19:00</div>
            <div class="tw-event-meta">
              <span>Salurinn</span>
              <span>Groups: Bassar</span>
            </div>
          </summary>
          <div class="tw-event-expand">
            <p>Attendance for Bassar.</p>
            <div class="tw-event-actions">
              <div class="tw-event-avatars" title="Checked in">
                <span class="tw-event-avatar">SS</span>
                <span class="tw-event-avatar">OJ</span>
              </div>
              <button class="tw-event-check" type="button">Check in</button>
            </div>
          </div>
        </details>
      </div>
    </div>
  </section>
</div>
