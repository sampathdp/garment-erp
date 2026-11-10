import type { Metadata } from "next";
import { PriceListScreen } from "@/features/setup/screens";

export const metadata: Metadata = { title: "Price list" };

export default function Page() {
  return <PriceListScreen />;
}
