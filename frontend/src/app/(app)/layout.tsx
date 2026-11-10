import { AppShell } from "@/features/navigation/app-shell";

// Server Component wrapper; AppShell (client) checks the session and renders navigation.
export default function AuthenticatedLayout({ children }: LayoutProps<"/">) {
  return <AppShell>{children}</AppShell>;
}
