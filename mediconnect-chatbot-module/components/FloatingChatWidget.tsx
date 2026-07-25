"use client";

import { useEffect, useRef, useState } from "react";

interface ChatMessage {
  role: "user" | "bot";
  text: string;
  source?: string;
}

interface FloatingChatWidgetProps {
  role?: "Patient" | "Doctor" | "Staff" | "Admin";
  userId?: number;
}

// CLINICK PHP Chatbot API Endpoint
const ENDPOINT = "/CLINICK/chatbot-api.php";

const CHIPS_BY_ROLE: Record<string, string[]> = {
  Doctor: ["Today's schedule", "Write Rx guide", "Work availability", "Search patient"],
  Staff:  ["Patient check-in", "Search patient", "Queue status", "Walk-in guide"],
  Admin:  ["Pending approvals", "System analytics", "User roles", "Audit logs"],
  Patient: ["Book appointment", "Clinic hours", "Services offered", "My appointments"],
};

export default function FloatingChatWidget({ role = "Patient", userId }: FloatingChatWidgetProps) {
  const [open, setOpen] = useState(false);
  const [messages, setMessages] = useState<ChatMessage[]>([
    {
      role: "bot",
      text: `Hi! I'm MediBot — your ${role} Assistant powered by Gemini. How can I help you today?`,
    },
  ]);
  const [input, setInput] = useState("");
  const [busy, setBusy] = useState(false);
  const [sessionId, setSessionId] = useState<string | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);

  const quickChips = CHIPS_BY_ROLE[role] || CHIPS_BY_ROLE["Patient"];

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight });
  }, [messages]);

  async function send(messageText?: string) {
    const text = (messageText || input).trim();
    if (!text || busy) return;

    setMessages((m) => [...m, { role: "user", text }]);
    setInput("");
    setBusy(true);

    let reply = "I'm having trouble connecting right now. Please try again in a moment.";
    let source = "fast";

    try {
      const res = await fetch(ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ message: text, session_id: sessionId, role, userId }),
      });
      const data = await res.json();
      if (data?.reply) {
        reply = data.reply;
        source = data.source || "fast";
      }
      if (data?.session_id) {
        setSessionId(data.session_id);
      }
    } catch {
      // fallback response
    }

    setMessages((m) => [...m, { role: "bot", text: reply, source }]);
    setBusy(false);
  }

  return (
    <div id="chatbot-widget-container">
      <div className={`chat-widget-card ${open ? "active" : ""}`}>
        <div className="chat-widget-header" style={{ background: 'var(--primary, #0f766e)', color: '#ffffff', padding: '12px 16px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <div style={{ background: 'rgba(255,255,255,0.2)', width: '28px', height: '28px', borderRadius: '6px', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 'bold', fontSize: '0.75rem' }}>
              MB
            </div>
            <div>
              <h3 style={{ margin: 0, fontSize: '0.875rem', fontWeight: 600 }}>MediBot · {role} Assistant</h3>
              <span style={{ fontSize: '0.65rem', opacity: 0.85 }}>Online · Gemini AI</span>
            </div>
          </div>
          <button 
            onClick={() => setOpen(false)}
            style={{ background: 'none', border: 'none', color: '#fff', cursor: 'pointer', fontSize: '1.25rem' }}
          >
            ×
          </button>
        </div>

        <div ref={scrollRef} id="chat_messages" style={{ flex: 1, padding: '12px', overflowY: 'auto', background: '#f8fafc', display: 'flex', flexDirection: 'column', gap: '8px' }}>
          {messages.map((m, i) => (
            <div key={i} style={{ alignSelf: m.role === 'user' ? 'flex-end' : 'flex-start', maxWidth: '85%' }}>
              <div
                style={{
                  background: m.role === 'user' ? 'var(--primary, #0f766e)' : '#ffffff',
                  color: m.role === 'user' ? '#ffffff' : '#0f172a',
                  padding: '8px 12px',
                  borderRadius: '8px',
                  border: m.role === 'bot' ? '1px solid #e2e8f0' : 'none',
                  fontSize: '0.8125rem',
                  lineHeight: '1.4',
                }}
              >
                {m.text}
              </div>
              {m.source === 'ai' && m.role === 'bot' && (
                <span style={{ fontSize: '0.6rem', color: '#94a3b8', display: 'block', marginTop: '2px', paddingLeft: '2px' }}>
                  • Gemini AI ({role})
                </span>
              )}
            </div>
          ))}
          {busy && (
            <div style={{ background: '#ffffff', padding: '8px 12px', borderRadius: '8px', border: '1px solid #e2e8f0', alignSelf: 'flex-start', fontSize: '0.8125rem', color: '#94a3b8' }}>
              Thinking…
            </div>
          )}
        </div>

        {/* Quick Chips */}
        <div style={{ padding: '6px 12px', background: '#ffffff', borderTop: '1px solid #e2e8f0', display: 'flex', gap: '4px', overflowX: 'auto' }}>
          {quickChips.map((chip, idx) => (
            <button
              key={idx}
              onClick={() => send(chip)}
              disabled={busy}
              style={{ padding: '3px 8px', fontSize: '0.7rem', background: '#f0fdf9', color: '#0f766e', border: '1px solid #cbd5e1', borderRadius: '4px', whiteSpace: 'nowrap', cursor: 'pointer' }}
            >
              {chip}
            </button>
          ))}
        </div>

        <div className="chat-widget-input-row" style={{ display: 'flex', padding: '8px', background: '#ffffff', borderTop: '1px solid #e2e8f0' }}>
          <input
            className="form-control"
            style={{ border: '1px solid #e2e8f0', borderRadius: '6px', flex: 1, padding: '8px 12px', fontSize: '0.8125rem' }}
            value={input}
            placeholder={`Ask ${role} Assistant…`}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={(e) => e.key === "Enter" && send()}
          />
          <button 
            style={{ background: 'var(--primary, #0f766e)', color: '#ffffff', border: 'none', borderRadius: '6px', marginLeft: '6px', padding: '0 14px', cursor: 'pointer', fontSize: '0.8125rem' }} 
            onClick={() => send()} 
            disabled={busy}
          >
            Send
          </button>
        </div>
      </div>
      
      <button
        id="chat-widget-trigger"
        style={{
          display: open ? 'none' : 'flex',
          position: 'fixed',
          bottom: '20px',
          right: '20px',
          width: '44px',
          height: '44px',
          borderRadius: '8px',
          background: 'var(--primary, #0f766e)',
          color: '#ffffff',
          border: 'none',
          alignItems: 'center',
          justifyContent: 'center',
          cursor: 'pointer',
          boxShadow: '0 4px 12px rgba(15, 118, 110, 0.3)',
          zIndex: 9999,
        }}
        onClick={() => setOpen(true)}
        aria-label="Toggle chat"
      >
        💬
      </button>
    </div>
  );
}
