import type { Metadata, Viewport } from "next";
import { Inter, Noto_Sans_Bengali } from "next/font/google";
import "./globals.css";

const inter = Inter({ variable: "--font-inter", subsets: ["latin"] });
const bengali = Noto_Sans_Bengali({ variable: "--font-bengali", subsets: ["bengali"] });

const productName = process.env.NEXT_PUBLIC_PRODUCT_NAME ?? "SmartBill BD";

export const metadata: Metadata = {
  title: { default: productName, template: `%s · ${productName}` },
  description: "Create bills faster. Track every payment. Collect dues smarter.",
  applicationName: productName,
};

export const viewport: Viewport = {
  themeColor: "#0f1e3d",
  width: "device-width",
  initialScale: 1,
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className={`${inter.variable} ${bengali.variable} h-full antialiased`}>
      <body className="min-h-full">{children}</body>
    </html>
  );
}
