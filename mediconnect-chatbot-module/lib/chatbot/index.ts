import { classify, type ChatbotResult } from "./classifier";

export type { ChatbotResult };

export function respond(message: string): ChatbotResult {
  return classify(message.trim());
}
