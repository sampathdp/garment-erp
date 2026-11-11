import type { Metadata } from "next";
import { IssueList } from "@/features/issues/list";

export const metadata: Metadata = { title: "Production issues" };

export default function Page() {
  return <IssueList />;
}
