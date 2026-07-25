export type Language = "en" | "fil" | "ceb" | "unknown";

export interface ChatbotResult {
  intent: string;
  language: Language;
  confidence: number;
  reply: string;
  lowConfidence: boolean;
}

interface IntentExample {
  intent: string;
  phrases: string[];
  reply: string;
}

const TRAINING: IntentExample[] = [
  {
    intent: "greeting",
    phrases: [
      "hello",
      "hi",
      "good morning",
      "good afternoon",
      "kamusta",
      "kumusta",
      "hello po",
      "hi po",
    ],
    reply: "Hello! I'm CLINICK Assistant. How can I help you today?",
  },
  {
    intent: "book_appointment",
    phrases: [
      "book appointment",
      "schedule appointment",
      "make an appointment",
      "gusto ko pong mag-book ng appointment",
      "magpa-appointment",
      "palihug buhat ug appointment",
    ],
    reply:
      "I can help you book an appointment. Please provide your preferred date, time, and the doctor or department.",
  },
  {
    intent: "cancel_appointment",
    phrases: [
      "cancel my appointment",
      "cancel appointment",
      "i want to cancel",
      "kanselarin ang appointment ko",
      "cancel nako ang appointment",
    ],
    reply:
      "I can help you cancel your appointment. Please provide your appointment reference or the date of your booking.",
  },
  {
    intent: "check_symptoms",
    phrases: [
      "i have a headache",
      "check my symptoms",
      "i feel sick",
      "masakit ang ulo ko",
      "giatay ko sakit",
    ],
    reply:
      "I'm not a doctor, but I can share general health information. For a proper diagnosis, please consult a physician or visit the clinic.",
  },
  {
    intent: "clinic_hours",
    phrases: [
      "what are your hours",
      "clinic hours",
      "what time do you open",
      "unsa oras mo mo-abre",
      "anong oras kayo nagbubukas",
    ],
    reply: "Our clinic is open Monday to Saturday, 8:00 AM to 5:00 PM.",
  },
  {
    intent: "thanks",
    phrases: ["thank you", "thanks", "salamat", "salamat po", "daghang salamat"],
    reply: "You're welcome! Let me know if you need anything else.",
  },
];

const LANG_MARKERS: Record<Language, string[]> = {
  en: ["hello", "good", "appointment", "book", "cancel", "hours", "thank", "symptom", "headache"],
  fil: ["po", "kamusta", "kumusta", "gusto", "ako", "kayo", "salamat", "oras", "appointment"],
  ceb: ["palihug", "giatay", "unsa", "mo-abre", "nako", "daghang", "sakit"],
  unknown: [],
};

const STOPWORDS = new Set([
  "a",
  "an",
  "the",
  "i",
  "my",
  "me",
  "you",
  "to",
  "of",
  "is",
  "am",
  "ko",
  "ng",
  "ang",
  "sa",
]);

function tokenize(text: string): string[] {
  return text
    .toLowerCase()
    .replace(/[^a-z0-9\s\u00C0-\u1FFF]/gi, " ")
    .split(/\s+/)
    .filter((w) => w.length > 1 && !STOPWORDS.has(w));
}

function detectLanguage(tokens: string[]): Language {
  const joined = tokens.join(" ");
  let best: Language = "unknown";
  let bestCount = 0;
  (Object.keys(LANG_MARKERS) as Language[]).forEach((lang) => {
    if (lang === "unknown") return;
    const count = LANG_MARKERS[lang].filter((m) => joined.includes(m)).length;
    if (count > bestCount) {
      bestCount = count;
      best = lang;
    }
  });
  return best;
}

function scoreIntent(tokens: string[], example: IntentExample): number {
  let hits = 0;
  example.phrases.forEach((phrase) => {
    const pTokens = tokenize(phrase);
    pTokens.forEach((pt) => {
      if (tokens.includes(pt)) hits += 1;
    });
  });
  const denom = Math.max(tokens.length, 1);
  return hits / denom;
}

export function classify(message: string): ChatbotResult {
  const tokens = tokenize(message);
  const language = detectLanguage(tokens);

  let bestIntent = "fallback";
  let bestScore = 0;
  let bestReply =
    "I'm sorry, I didn't quite understand that. You can ask me about appointments, clinic hours, or symptoms.";

  TRAINING.forEach((ex) => {
    const score = scoreIntent(tokens, ex);
    if (score > bestScore) {
      bestScore = score;
      bestIntent = ex.intent;
      bestReply = ex.reply;
    }
  });

  return {
    intent: bestIntent,
    language,
    confidence: Number(bestScore.toFixed(2)),
    reply: bestReply,
    lowConfidence: bestScore < 0.2,
  };
}
