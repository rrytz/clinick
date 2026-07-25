import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "CLINICK Chatbot",
  description: "CLINICK hospital assistant chatbot widget",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
