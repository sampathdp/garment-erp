import type { Metadata } from "next";
import { PriceListsScreen } from "@/features/setup/screens";

export const metadata: Metadata = { title: "Price lists" };

export default function Page() {
  return <PriceListsScreen />;
}
