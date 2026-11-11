import type { Metadata } from "next";
import { Suspense } from "react";
import { LoadingState } from "@/components/ui/states";
import { ReportsScreen } from "@/features/reports/reports-screen";

export const metadata: Metadata = { title: "Reports" };

export default function Page() {
  return (
    <Suspense fallback={<LoadingState />}>
      <ReportsScreen />
    </Suspense>
  );
}
