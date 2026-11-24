import type { Metadata } from "next";
import { CatalogScreen } from "@/features/orders/catalog-screen";

export const metadata: Metadata = { title: "Categories and materials" };

export default function Page() {
  return <CatalogScreen />;
}
