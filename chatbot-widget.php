<?php
/**
 * CLINICK MediBot AI - Chat widget partial.
 * Include right before </body> on any page:
 *   include 'chatbot-widget.php'
 */

// Detect whether AI is configured
$apiKey = '';
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            if (in_array(trim($k), ['GOOGLE_API_KEY', 'GEMINI_API_KEY'])) {
                $apiKey = trim($v);
                break;
            }
        }
    }
}
$aiEnabled = !empty($apiKey) && $apiKey !== 'your_google_api_key_here';

$chatbotApiEndpoint = '/CLINICK/chatbot-api.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$userRoleRaw = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'Patient';
$chatbotRole = match (strtolower(trim($userRoleRaw))) {
    'doctor', 'clinical staff' => 'Doctor',
    'staff', 'receptionist'     => 'Staff',
    'admin', 'administrator'   => 'Admin',
    default                    => 'Patient',
};
$roleTitle = match ($chatbotRole) {
    'Admin'  => 'AI Operations Secretary',
    'Doctor' => 'Clinical Workflow Assistant',
    'Staff'  => 'Frontdesk Assistant',
    default  => 'Personal Clinic Assistant',
};
?>
<style>
  /* --- Chatbot Trigger Button --- */
  #medibot-toggle {
    position: fixed !important; bottom: 20px !important; right: 20px !important; z-index: 9999 !important;
    width: 44px; height: 44px; border-radius: 8px;
    background: var(--primary, #0f766e); color: #fff;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 12px rgba(15,118,110,.3);
    font-size: 18px;
    transition: background .12s, box-shadow .12s;
  }
  #medibot-toggle:hover { background: #115e59; box-shadow: 0 6px 16px rgba(15,118,110,.4); }

  /* --- Unread badge --- */
  #medibot-badge {
    position: absolute; top: -5px; right: -5px;
    width: 16px; height: 16px; border-radius: 50%;
    background: #ef4444; color: #fff;
    font-size: 9px; font-weight: 700;
    display: none; align-items: center; justify-content: center;
    border: 2px solid #fff;
  }

  /* --- Chat Panel --- */
  #medibot-panel {
    position: fixed !important; bottom: 74px !important; right: 20px !important; z-index: 9999 !important;
    width: 340px; max-width: calc(100vw - 32px); height: 480px;
    background: #fff; border-radius: 10px; overflow: hidden;
    border: 1px solid #e2e8f0;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,.07), 0 8px 10px -6px rgba(0,0,0,.04);
    display: none; flex-direction: column;
    font-family: 'Inter', -apple-system, sans-serif;
    transform: translateY(8px); opacity: 0;
    transition: opacity .15s ease, transform .15s ease;
  }
  #medibot-panel.open { display: flex !important; opacity: 1; transform: translateY(0); }

  /* --- Header --- */
  #medibot-header {
    background: var(--primary, #0f766e); color: #fff;
    padding: 11px 14px; display: flex;
    align-items: center; justify-content: space-between; flex-shrink: 0;
  }
  #medibot-header-left { display: flex; align-items: center; gap: 10px; }
  #medibot-avatar {
    width: 28px; height: 28px; border-radius: 6px;
    background: rgba(255,255,255,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700; flex-shrink: 0;
  }
          <span id="medibot-title">MediBot &bull; <?= htmlspecialchars($roleTitle) ?></span>
  #medibot-status {
    font-size: .665rem; opacity: .85;
    display: flex; align-items: center; gap: 4px;
  }
  #medibot-status-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: #4ade80; flex-shrink: 0;
  }
  #medibot-header-actions { display: flex; gap: 4px; }
  .medibot-hbtn {
    background: transparent; border: none; color: rgba(255,255,255,.8);
    cursor: pointer; width: 26px; height: 26px; border-radius: 4px;
    display: flex; align-items: center; justify-content: center; font-size: 12px;
    transition: background .1s, color .1s;
  }
  .medibot-hbtn:hover { background: rgba(255,255,255,.15); color: #fff; }

  /* --- AI badge in header --- */
  #medibot-ai-pill {
    padding: 2px 7px; border-radius: 3px;
    background: rgba(255,255,255,.18);
    font-size: .6rem; font-weight: 600; letter-spacing: .04em;
  }

  /* --- Messages --- */
  #medibot-messages {
    flex: 1; overflow-y: auto; padding: 14px;
    background: #f8fafc; display: flex; flex-direction: column;
    gap: 10px; scroll-behavior: smooth;
  }
  #medibot-messages::-webkit-scrollbar { width: 4px; }
  #medibot-messages::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

  /* Message Rows & Primitives (shadcn-inspired) */
  .medibot-msg-row {
    display: flex;
    gap: 8px;
    margin-bottom: 2px;
    align-items: flex-start;
    animation: mb-in .18s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .medibot-msg-row.bot { align-self: flex-start; max-width: 90%; }
  .medibot-msg-row.user { align-self: flex-end; max-width: 90%; justify-content: flex-end; }

  .medibot-avatar {
    width: 26px; height: 26px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.7rem; flex-shrink: 0; margin-top: 2px;
  }
  .medibot-avatar.bot-avatar { background: rgba(15, 118, 110, 0.1); color: var(--primary, #0f766e); border: 1px solid rgba(15, 118, 110, 0.2); }
  .medibot-avatar.user-avatar { background: #e2e8f0; color: #475569; }

  .medibot-msg-group { display: flex; flex-direction: column; gap: 3px; }
  .medibot-msg-header { display: flex; align-items: center; gap: 6px; font-size: 0.65rem; color: #94a3b8; padding: 0 4px; }
  .medibot-msg-header.user-header { justify-content: flex-end; }
  .medibot-author { font-weight: 600; color: #64748b; }
  .medibot-time { font-size: 0.6rem; opacity: 0.85; }

  .medibot-msg {
    padding: 9px 13px; border-radius: 14px;
    font-size: .82rem; line-height: 1.5; word-break: break-word;
    white-space: pre-wrap;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition: transform .1s ease, box-shadow .1s ease;
  }
  @keyframes mb-in { from { opacity:0; transform:translateY(6px) scale(0.98); } to { opacity:1; transform:none; } }

  .medibot-msg.bot {
    background: #ffffff; border: 1px solid #e2e8f0;
    color: #0f172a; align-self: flex-start; 
    border-top-left-radius: 4px;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
  }
  .medibot-msg.user {
    background: linear-gradient(135deg, var(--primary, #0f766e), #0d9488);
    color: #ffffff; align-self: flex-end; 
    border-top-right-radius: 4px;
    box-shadow: 0 2px 8px rgba(15, 118, 110, 0.25);
    font-weight: 450;
  }

  /* Typing dots */
  .medibot-typing {
    display: flex; align-items: center; gap: 4px;
    padding: 10px 14px; background: #ffffff;
    border: 1px solid #e2e8f0; border-radius: 14px;
    border-bottom-left-radius: 4px; align-self: flex-start;
    animation: mb-in .18s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
  }
  .medibot-typing span {
    width: 6px; height: 6px; background: #94a3b8; border-radius: 50%;
    animation: mb-bounce 1.2s infinite;
  }
  .medibot-typing span:nth-child(2) { animation-delay: .2s; }
  .medibot-typing span:nth-child(3) { animation-delay: .4s; }
  @keyframes mb-bounce { 0%,60%,100% { transform:none; } 30% { transform:translateY(-4px); } }

  /* Chips */
  .medibot-chips {
    display: flex; flex-wrap: wrap; gap: 6px;
    align-self: flex-start; max-width: 95%;
    margin-top: 2px; animation: mb-in .22s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .medibot-chip {
    padding: 6px 12px; background: #ffffff;
    border: 1px solid #cbd5e1; border-radius: 20px;
    font-size: .73rem; color: var(--primary, #0f766e);
    cursor: pointer; font-family: inherit; font-weight: 500;
    transition: all .15s ease;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
  }
  .medibot-chip:hover {
    background: #f0fdf9; border-color: var(--primary, #0f766e);
    transform: translateY(-1px); box-shadow: 0 3px 8px rgba(15, 118, 110, 0.12);
  }
  .medibot-chip.used { opacity: .4; pointer-events: none; transform: none; box-shadow: none; }

  /* Source indicator */
  .medibot-source {
    font-size: .6rem; color: #94a3b8; align-self: flex-start;
    margin-top: -4px; padding-left: 2px;
    display: flex; align-items: center; gap: 3px;
  }
  .medibot-source .dot { width: 4px; height: 4px; border-radius: 50%; background: currentColor; }

  /* --- Input row --- */
  #medibot-input-row {
    display: flex; gap: 6px; padding: 10px;
    border-top: 1px solid #e2e8f0; background: #fff;
    flex-shrink: 0; align-items: center;
  }
  #medibot-input {
    flex: 1; border: 1px solid #e2e8f0; border-radius: 6px;
    padding: 7px 10px; font-size: .79rem; font-family: inherit;
    outline: none; background: #fff; color: #0f172a;
    transition: border-color .12s; line-height: 1.5;
  }
  #medibot-input::placeholder { color: #94a3b8; }
  #medibot-input:focus { border-color: var(--primary, #0f766e); }
  #medibot-send {
    background: var(--primary, #0f766e); color: #fff;
    border: none; border-radius: 6px; width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 13px; flex-shrink: 0;
    transition: background .12s;
  }
  #medibot-send:hover { background: #115e59; }
  #medibot-send:disabled { opacity: .45; cursor: not-allowed; }

  /* --- Dark mode --- */
  [data-theme="dark"] #medibot-panel { background: #1e293b; border-color: #334155; box-shadow: 0 12px 36px rgba(0, 0, 0, 0.45); }
  [data-theme="dark"] #medibot-messages { background: #0f172a; }
  [data-theme="dark"] .medibot-msg.bot { background: #1e293b; border-color: #334155; color: #f8fafc; }
  [data-theme="dark"] .medibot-author { color: #94a3b8; }
  [data-theme="dark"] .medibot-time { color: #64748b; }
  [data-theme="dark"] .medibot-avatar.user-avatar { background: #334155; color: #cbd5e1; }
  [data-theme="dark"] #medibot-input-row { background: #1e293b; border-top-color: #334155; }
  [data-theme="dark"] #medibot-input { background: #0f172a; border-color: #334155; color: #f8fafc; }
  [data-theme="dark"] .medibot-chip { background: #1e293b; border-color: #334155; color: #5eead4; }
  [data-theme="dark"] .medibot-chip:hover { background: #0f2d2b; border-color: #14b8a6; color: #2dd4bf; }
  [data-theme="dark"] .medibot-typing { background: #1e293b; border-color: #334155; }
</style>

<!-- --- Trigger button --- -->
<button id="medibot-toggle" aria-label="Open MediBot chat" title="Chat with MediBot">
  <i class="fa-solid fa-comment-medical"></i>
  <span id="medibot-badge"></span>
</button>

<!-- --- Chat panel --- -->
<div id="medibot-panel" role="dialog" aria-label="MediBot Chat Assistant">
  <div id="medibot-header">
    <div id="medibot-header-left">
      <div id="medibot-avatar">MB</div>
      <div>
        <div style="display:flex;align-items:center;gap:6px">
          <span id="medibot-title">MediBot &bull; <?= htmlspecialchars($roleTitle) ?></span>
          <?php if ($aiEnabled): ?>
          <span id="medibot-ai-pill">AI</span>
          <?php endif; ?>
        </div>
        <div id="medibot-status">
          <span id="medibot-status-dot"></span>
          <span id="medibot-status-text">
            <?= $aiEnabled ? 'Gemini AI . EN / FIL / CEB' : 'Smart Assistant . EN / FIL / CEB' ?>
          </span>
        </div>
      </div>
    </div>
    <div id="medibot-header-actions">
      <button class="medibot-hbtn" id="medibot-reset" title="New conversation" aria-label="New conversation">
        <i class="fa-solid fa-rotate-right"></i>
      </button>
      <button class="medibot-hbtn" id="medibot-close" aria-label="Close chat">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
  </div>

  <div id="medibot-messages" role="log" aria-live="polite"></div>

  <div id="medibot-input-row">
    <input
      id="medibot-input"
      type="text"
      placeholder="<?= $aiEnabled ? 'Ask me anything...' : 'English, Filipino, or Cebuano...' ?>"
      autocomplete="off"
      maxlength="500"
      aria-label="Chat message"
    />
    <button id="medibot-send" aria-label="Send message">
      <i class="fa-solid fa-paper-plane"></i>
    </button>
  </div>
</div>

<script>
(function () {
  'use strict';

  const API_ENDPOINT = <?php echo json_encode($chatbotApiEndpoint); ?>;
  const AI_ENABLED   = <?php echo $aiEnabled ? 'true' : 'false'; ?>;

  const toggle    = document.getElementById('medibot-toggle');
  const panel     = document.getElementById('medibot-panel');
  const closeBtn  = document.getElementById('medibot-close');
  const resetBtn  = document.getElementById('medibot-reset');
  const messages  = document.getElementById('medibot-messages');
  const input     = document.getElementById('medibot-input');
  const sendBtn   = document.getElementById('medibot-send');
  const badge     = document.getElementById('medibot-badge');
  const statusTxt = document.getElementById('medibot-status-text');

  let greeted    = false;
  let busy       = false;
  let sessionId  = null;
  let unread     = 0;

  const USER_ROLE = <?php echo json_encode($chatbotRole); ?>;
  const QUICK_REPLIES_BY_ROLE = {
    'Doctor':         ['Today\'s schedule', 'Write Rx guide', 'Work availability', 'Search patient'],
    'Clinical Staff': ['Today\'s schedule', 'Write Rx guide', 'Work availability', 'Search patient'],
    'Staff':          ['Patient check-in', 'Search patient', 'Queue status', 'Walk-in guide'],
    'Admin':          ['Pending approvals', 'System analytics', 'User roles', 'Audit logs'],
    'Patient':        ['Book appointment', 'Clinic hours', 'Services offered', 'My appointments']
  };
  const QUICK_REPLIES = QUICK_REPLIES_BY_ROLE[USER_ROLE] || QUICK_REPLIES_BY_ROLE['Patient'];

  /* --- Utilities --- */
  function scrollBottom() { messages.scrollTop = messages.scrollHeight; }

  function addMessage(text, role, showSource) {
    const row = document.createElement('div');
    row.className = 'medibot-msg-row ' + role;

    const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const authorName = role === 'bot' ? (USER_ROLE === 'Staff' ? 'Frontdesk Assistant' : (USER_ROLE === 'Admin' ? 'Admin Assistant' : (USER_ROLE === 'Doctor' ? 'Clinical Assistant' : 'MediBot'))) : 'You';

    if (role === 'bot') {
      row.innerHTML = `
        <div class="medibot-avatar bot-avatar"><i class="fa-solid fa-robot"></i></div>
        <div class="medibot-msg-group">
          <div class="medibot-msg-header">
            <span class="medibot-author">${authorName}</span>
            <span class="medibot-time">${timeStr}</span>
          </div>
          <div class="medibot-msg bot"></div>
        </div>
      `;
      row.querySelector('.medibot-msg.bot').textContent = text;
    } else {
      row.innerHTML = `
        <div class="medibot-msg-group">
          <div class="medibot-msg-header user-header">
            <span class="medibot-time">${timeStr}</span>
            <span class="medibot-author">You</span>
          </div>
          <div class="medibot-msg user"></div>
        </div>
        <div class="medibot-avatar user-avatar"><i class="fa-solid fa-user"></i></div>
      `;
      row.querySelector('.medibot-msg.user').textContent = text;
    }

    messages.appendChild(row);

    if (showSource && role === 'bot') {
      const src = document.createElement('div');
      src.className = 'medibot-source';
      src.innerHTML = '<span class="dot"></span>' + (AI_ENABLED ? 'Gemini AI' : 'Smart Assistant');
      messages.appendChild(src);
    }

    scrollBottom();
    return row.querySelector('.medibot-msg');
  }

  function addTyping() {
    const el = document.createElement('div');
    el.className = 'medibot-typing';
    el.innerHTML = '<span></span><span></span><span></span>';
    messages.appendChild(el);
    scrollBottom();
    return el;
  }

  function addChips(labels) {
    const row = document.createElement('div');
    row.className = 'medibot-chips';
    labels.forEach(function(label) {
      const btn = document.createElement('button');
      btn.className = 'medibot-chip';
      btn.textContent = label;
      btn.addEventListener('click', function() {
        row.querySelectorAll('.medibot-chip').forEach(function(c) { c.classList.add('used'); });
        sendMessage(label);
      });
      row.appendChild(btn);
    });
    messages.appendChild(row);
    scrollBottom();
  }

  function setBusy(val) {
    busy = val;
    sendBtn.disabled = val;
    input.disabled   = val;
    statusTxt.textContent = val ? 'Thinking...' : (AI_ENABLED ? 'Gemini AI . EN / FIL / CEB' : 'Smart Assistant . EN / FIL / CEB');
  }

  /* --- Open / Close --- */
  function openPanel() {
    panel.style.display = 'flex';
    requestAnimationFrame(function() { panel.classList.add('open'); });
    if (!greeted) { greet(); greeted = true; }
    input.focus();
    unread = 0;
    badge.style.display = 'none';
  }

  function closePanel() {
    panel.classList.remove('open');
    setTimeout(function() { panel.style.display = 'none'; }, 155);
  }

  toggle.addEventListener('click', function() {
    panel.classList.contains('open') ? closePanel() : openPanel();
  });
  closeBtn.addEventListener('click', closePanel);

  resetBtn.addEventListener('click', function() {
    messages.innerHTML = '';
    greeted = false;
    sessionId = null;
    greet();
  });

  /* --- Greeting --- */
  function greet() {
    setTimeout(function() {
      let msg = "";
      if (USER_ROLE === 'Staff') {
        msg = "Hi! I'm MediBot - your Frontdesk Assistant. I can help with patient check-in, patient lookup, queue monitoring, walk-in registration, and appointment assistance.";
      } else if (USER_ROLE === 'Doctor' || USER_ROLE === 'Clinical Staff') {
        msg = "Hello Doctor! I'm your Clinical Workflow Assistant. I can help with patient records, consultations, schedules, and prescriptions.";
      } else if (USER_ROLE === 'Admin') {
        msg = "Welcome! I'm your AI Operations Secretary. I can help with analytics, reports, pending user approvals, and clinic operations.";
      } else {
        msg = AI_ENABLED
          ? "Hi! I'm MediBot - your AI assistant powered by Gemini. I can help with appointments, clinic info, or answer questions about CLINICK. What do you need?"
          : "Hi! I'm MediBot - CLINICK's assistant. I can help you with appointments, clinic hours, and services. What do you need?";
      }
      addMessage(msg, 'bot', false);
      addChips(QUICK_REPLIES);
    }, 200);
  }

  /* --- Send message --- */
  async function sendMessage(text) {
    text = (text || '').trim();
    if (!text || busy) return;

    addMessage(text, 'user', false);
    input.value = '';
    setBusy(true);

    const typing = addTyping();

    try {
      const res = await fetch(API_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: text, session_id: sessionId }),
      });

      if (typing.parentNode) typing.remove();

      if (!res.ok) {
        addMessage("I'm having trouble connecting right now. Please try again.", 'bot', false);
        return;
      }

      const data = await res.json();
      if (data.error && !data.reply) {
        addMessage("Something went wrong. Please try again.", 'bot', false);
        return;
      }

      if (data.session_id) sessionId = data.session_id;
      const showSource = (data.source === 'ai');
      addMessage(data.reply, 'bot', showSource);
      offerFollowups(data.reply, data.intent);

    } catch (err) {
      if (typing.parentNode) typing.remove();
      addMessage("Network connection error. Please try again.", 'bot', false);
    } finally {
      setBusy(false);
      input.focus();
    }
  }

  /* --- Follow-up suggestions --- */
  function offerFollowups(text, intent) {
    if (!intent && text.length < 10) return;

    const lower = text.toLowerCase();
    if (USER_ROLE === 'Staff') {
      addChips(['Patient check-in', 'Search patient', 'Queue status', 'Walk-in guide']);
    } else if (USER_ROLE === 'Doctor' || USER_ROLE === 'Clinical Staff') {
      addChips(['Today schedule', 'Search patient', 'Prescription guide', 'Consultation workflow']);
    } else if (USER_ROLE === 'Admin') {
      addChips(['Daily analytics', 'Pending approvals', 'Audit logs', 'System reports']);
    } else {
      if (lower.includes('appointment') || intent === 'book_appointment') {
        addChips(['Check my appointments', 'See available doctors', 'How to book']);
      } else if (intent === 'farewell' || lower.includes('take care') || lower.includes('goodbye')) {
        addChips(QUICK_REPLIES);
      } else if (intent === 'fallback' || lower.includes("not sure")) {
        addChips(['Book appointment', 'Clinic hours', 'Talk to staff']);
      }
    }
  }

  /* --- Keyboard --- */
  function handleSend() { sendMessage(input.value); }
  sendBtn.addEventListener('click', handleSend);
  input.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleSend(); }
  });

})();
</script>
