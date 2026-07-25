<?php
/**
 * Chatbot widget partial.
 *
 * USAGE (plain PHP):
 *   <?php include 'chatbot-widget.php'; ?>
 *   ...right before </body> in every page that should show the widget.
 *
 * USAGE (Laravel Blade):
 *   Rename this file to chatbot-widget.blade.php, place it in
 *   resources/views/partials/, then in your main layout (e.g.
 *   resources/views/layouts/app.blade.php) add before </body>:
 *   @include('partials.chatbot-widget')
 *
 * IMPORTANT: Set the endpoint below to match whichever backend you set up —
 * either the plain chatbot-api.php path, or the Laravel /api/chatbot route.
 */
$chatbotEndpoint = '/chatbot-api.php'; // <-- change to '/api/chatbot' if using the Laravel route
?>
<style>
  #medibot-toggle {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    width: 56px; height: 56px; border-radius: 999px;
    background: #0E5C56; color: #EFF5F3; border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 8px 30px -12px rgba(15,42,46,0.35);
    font-size: 24px;
  }
  #medibot-panel {
    position: fixed; bottom: 90px; right: 24px; z-index: 9999;
    width: 340px; max-width: calc(100vw - 32px); height: 480px;
    background: #fff; border-radius: 20px; overflow: hidden;
    box-shadow: 0 8px 30px -12px rgba(15,42,46,0.35);
    display: none; flex-direction: column;
    font-family: -apple-system, "Segoe UI", Roboto, sans-serif;
  }
  #medibot-panel.open { display: flex; }
  #medibot-header {
    background: #0E5C56; color: #EFF5F3; padding: 14px 16px;
    font-weight: 600; font-size: 15px;
  }
  #medibot-messages {
    flex: 1; overflow-y: auto; padding: 12px; background: #EFF5F3;
    display: flex; flex-direction: column; gap: 8px;
  }
  .medibot-msg { max-width: 80%; padding: 8px 12px; border-radius: 14px; font-size: 13px; line-height: 1.4; }
  .medibot-msg.bot { background: #fff; border: 1px solid #DCE7E4; align-self: flex-start; color: #0F2A2E; }
  .medibot-msg.user { background: #0E5C56; color: #EFF5F3; align-self: flex-end; }
  #medibot-input-row { display: flex; gap: 6px; padding: 10px; border-top: 1px solid #DCE7E4; background: #fff; }
  #medibot-input {
    flex: 1; border: 1px solid #DCE7E4; border-radius: 999px;
    padding: 8px 12px; font-size: 13px; outline: none;
  }
  #medibot-send {
    background: #0E5C56; color: #EFF5F3; border: none; border-radius: 999px;
    padding: 8px 14px; font-size: 13px; cursor: pointer;
  }
</style>

<button id="medibot-toggle" aria-label="Open chat assistant">💬</button>
<div id="medibot-panel">
  <div id="medibot-header">MediBot — Bilingual Assistant</div>
  <div id="medibot-messages"></div>
  <div id="medibot-input-row">
    <input id="medibot-input" type="text" placeholder="English, Filipino, or Cebuano…" />
    <button id="medibot-send">Send</button>
  </div>
</div>

<script>
(function () {
  const ENDPOINT = <?php echo json_encode($chatbotEndpoint); ?>;
  const toggle = document.getElementById('medibot-toggle');
  const panel = document.getElementById('medibot-panel');
  const messages = document.getElementById('medibot-messages');
  const input = document.getElementById('medibot-input');
  const sendBtn = document.getElementById('medibot-send');

  let greeted = false;

  function addMessage(text, role) {
    const div = document.createElement('div');
    div.className = 'medibot-msg ' + role;
    div.textContent = text;
    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
  }

  toggle.addEventListener('click', function () {
    panel.classList.toggle('open');
    if (!greeted) {
      addMessage("Hi! I'm MediBot. Ask me in English, Filipino, or Cebuano.", 'bot');
      greeted = true;
    }
  });

  async function send() {
    const text = input.value.trim();
    if (!text) return;
    addMessage(text, 'user');
    input.value = '';

    try {
      const res = await fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: text }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Unknown error');
      addMessage(data.reply, 'bot');
    } catch (err) {
      addMessage("Sorry, I'm having trouble responding right now. Please try again.", 'bot');
    }
  }

  sendBtn.addEventListener('click', send);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') send();
  });
})();
</script>
