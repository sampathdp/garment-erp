import type { Metadata } from "next";
import { CatalogScreen } from "@/features/orders/catalog-screen";

export const metadata: Metadata = { title: "Order types and categories" };

export default function Page() {
  return <CatalogScreen />;
}
