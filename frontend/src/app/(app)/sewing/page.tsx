import type { Metadata } from "next";
import { SewingScreen } from "@/features/sewing/sewing-screen";

export const metadata: Metadata = { title: "Sewing" };

export default function Page() {
  return <SewingScreen />;
}
