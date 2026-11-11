import type { Metadata } from "next";
import { IssueScreen } from "@/features/issues/issue-screen";

export const metadata: Metadata = { title: "Production issue" };

export default function Page() {
  return <IssueScreen />;
}
