import type { Metadata } from "next";
import { GarmentListScreen } from "@/features/garments/garment-list-screen";

export const metadata: Metadata = { title: "Name / number list" };

export default function Page() {
  return <GarmentListScreen />;
}
