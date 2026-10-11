import type { Metadata, Viewport } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: {
    default: "DragonMart POS",
    template: "%s · DragonMart POS",
  },
  description: "Sistem Point of Sale DragonMart — kasir, shift, retur, dan laporan.",
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  // Tablet landscape adalah target utama; cegah zoom pinch yang mengganggu kasir.
  maximumScale: 1,
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="id"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="min-h-full flex flex-col">{children}</body>
    </html>
  );
}
