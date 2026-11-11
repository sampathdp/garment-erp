import type { Metadata } from "next";
import { Suspense } from "react";
import { LoadingState } from "@/components/ui/states";
import { DeliveryDashboard } from "@/features/delivery/dashboard";

export const metadata: Metadata = { title: "Deliveries" };

export default function Page() {
  return (
    <Suspense fallback={<LoadingState />}>
      <DeliveryDashboard />
    </Suspense>
  );
}
