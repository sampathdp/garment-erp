"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { LoadingState } from "@/components/ui/states";

// Static export cannot issue server redirects, so the root sends the browser to the sign-in page.
export default function Home() {
  const router = useRouter();

  useEffect(() => {
    router.replace("/login/");
  }, [router]);

  return <LoadingState label="Opening sign in…" />;
}
