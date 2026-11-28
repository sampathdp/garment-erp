import type { Metadata } from "next";
import { ProductionPlanScreen } from "@/features/production-plan/production-plan-screen";

export const metadata: Metadata = { title: "Production plan" };

export default function Page() {
  return <ProductionPlanScreen />;
}
