"use client";

import { useAuth, AuthProvider } from "@/lib/auth-context";
import { CashierShell } from "@/components/layout/cashier-shell";

function ShellContent({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="flex h-screen items-center justify-center bg-background">
        <div className="flex flex-col items-center gap-3 text-muted-foreground">
          <span
            className="h-8 w-8 animate-spin rounded-full border-4 border-primary border-t-transparent"
            aria-hidden="true"
          />
          <p className="text-sm">Memuat sesi…</p>
        </div>
      </div>
    );
  }

  if (!user) {
    // Route guard di AuthProvider akan mengalihkan ke /login.
    return null;
  }

  return <CashierShell user={user}>{children}</CashierShell>;
}

export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    <AuthProvider>
      <ShellContent>{children}</ShellContent>
    </AuthProvider>
  );
}
