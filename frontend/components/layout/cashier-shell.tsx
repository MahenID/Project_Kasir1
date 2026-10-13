"use client";

import { usePathname, useRouter } from "next/navigation";
import { type ReactNode } from "react";
import type { User } from "@/lib/types";
import { authApi } from "@/lib/api/auth";

interface CashierShellProps {
  user: User;
  children: ReactNode;
}

interface NavItem {
  href: string;
  label: string;
  supervisorOnly?: boolean;
}

const NAV_ITEMS: NavItem[] = [
  { href: "/pos", label: "Kasir (POS)" },
  { href: "/sales", label: "Riwayat Transaksi" },
  { href: "/returns", label: "Retur & Refund" },
  { href: "/shift", label: "Shift & Kas" },
  { href: "/products", label: "Katalog Produk", supervisorOnly: true },
  { href: "/categories", label: "Kategori", supervisorOnly: true },
  { href: "/suppliers", label: "Supplier", supervisorOnly: true },
  { href: "/customers", label: "Pelanggan" },
  { href: "/reports", label: "Laporan & Ekspor", supervisorOnly: true },
  { href: "/audit", label: "Audit Log", supervisorOnly: true },
];

export function CashierShell({ user, children }: CashierShellProps) {
  const pathname = usePathname();
  const router = useRouter();

  const isSupervisor =
    user.roles.includes("owner") || user.roles.includes("manager");

  const onLogout = async () => {
    if (!confirm("Keluar dari DragonMart POS?")) return;
    try {
      await authApi.logout();
    } catch {
      // Abaikan error logout jaringan, tetap arahkan ke login
    }
    router.push("/login");
  };

  const navFiltered = NAV_ITEMS.filter(
    (item) => !item.supervisorOnly || isSupervisor
  );

  return (
    <div className="flex h-screen flex-col bg-background text-foreground">
      {/* Header Utama Kasir */}
      <header className="flex h-16 shrink-0 items-center justify-between border-b border-border bg-surface px-4 shadow-xs">
        <div className="flex items-center gap-6">
          <div className="flex items-center gap-2">
            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary font-bold text-white">
              DM
            </span>
            <div className="leading-tight">
              <h1 className="text-base font-bold tracking-tight">DragonMart POS</h1>
              <p className="text-xs text-muted-foreground">Retail Point of Sale</p>
            </div>
          </div>

          {/* Indikator Shift Aktif */}
          {user.current_shift ? (
            <div className="hidden items-center gap-2 rounded-lg bg-green-50 px-3 py-1 text-xs font-medium text-green-800 md:flex">
              <span className="h-2 w-2 rounded-full bg-green-600 animate-pulse" />
              <span>Shift #{user.current_shift.shift_number}</span>
              {user.current_shift.terminal_code && (
                <span className="text-green-700">({user.current_shift.terminal_code})</span>
              )}
            </div>
          ) : (
            <div className="hidden items-center gap-2 rounded-lg bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800 md:flex">
              <span className="h-2 w-2 rounded-full bg-amber-600" />
              <span>Shift belum dibuka</span>
            </div>
          )}
        </div>

        {/* Info Pengguna & Logout */}
        <div className="flex items-center gap-4">
          <div className="text-right leading-tight">
            <p className="text-sm font-semibold">{user.name}</p>
            <p className="text-xs text-muted-foreground capitalize">
              {user.roles.join(", ")}
            </p>
          </div>
          <button
            type="button"
            onClick={onLogout}
            aria-label="Keluar"
            className="touch-target flex h-11 items-center justify-center rounded-lg border border-border bg-surface px-4 text-sm font-medium hover:bg-slate-100"
          >
            Keluar
          </button>
        </div>
      </header>

      {/* Navigasi Tab Kasir (Target sentuh >=44px) */}
      <nav
        aria-label="Navigasi Utama"
        className="flex shrink-0 gap-1 overflow-x-auto border-b border-border bg-surface px-4 py-1.5 scroll-thin"
      >
        {navFiltered.map((item) => {
          const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
          return (
            <button
              key={item.href}
              type="button"
              onClick={() => router.push(item.href)}
              className={`touch-target flex h-11 items-center rounded-lg px-4 text-sm font-medium whitespace-nowrap transition-colors ${
                active
                  ? "bg-primary text-white shadow-xs"
                  : "text-foreground hover:bg-slate-100"
              }`}
            >
              {item.label}
            </button>
          );
        })}
      </nav>

      {/* Konten Halaman */}
      <main className="flex-1 overflow-hidden">{children}</main>
    </div>
  );
}
