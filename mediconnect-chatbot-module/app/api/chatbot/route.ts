import { NextRequest, NextResponse } from "next/server";
import { respond } from "@/lib/chatbot";

export async function POST(req: NextRequest) {
  try {
    const body = await req.json();
    const message = body?.message;
    if (typeof message !== "string" || message.trim() === "") {
      return NextResponse.json(
        { error: "A non-empty 'message' string is required." },
        { status: 400 }
      );
    }
    if (message.length > 500) {
      return NextResponse.json(
        { error: "Message too long (max 500 characters)." },
        { status: 400 }
      );
    }
    return NextResponse.json(respond(message));
  } catch {
    return NextResponse.json(
      { error: "Something went wrong processing your message." },
      { status: 500 }
    );
  }
}
