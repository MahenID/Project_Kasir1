"use client";

import { useEffect, type ReactNode } from "react";

interface ModalProps {
  open: boolean;
  title: string;
  onClose: () => void;
  children: ReactNode;
  /** Konten footer (aksi). */
  footer?: ReactNode;
  /** Kelas lebar Tailwind; default "max-w-md". */
  maxWidthClass?: string;
  /** Jika false, Esc/klik luar tidak menutup (untuk alur yang tidak boleh batal). */
  dismissible?: boolean;
}

export function Modal({
  open,
  title,
  onClose,
  children,
  footer,
  maxWidthClass = "max-w-md",
  dismissible = true,
}: ModalProps) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape" && dismissible) onClose();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, dismissible, onClose]);

  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="presentation"
      onClick={dismissible ? onClose : undefined}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className={`w-full ${maxWidthClass} max-h-[90vh] overflow-y-auto rounded-xl bg-surface shadow-xl`}
        onClick={(e) => e.stopPropagation()}
      >
        <header className="flex items-center justify-between border-b border-border px-5 py-4">
          <h2 className="text-lg font-semibold">{title}</h2>
          {dismissible && (
            <button
              type="button"
              onClick={onClose}
              aria-label="Tutup"
              className="touch-target flex h-11 w-11 items-center justify-center rounded-lg text-muted-foreground hover:bg-slate-100"
            >
              ✕
            </button>
          )}
        </header>
        <div className="px-5 py-4">{children}</div>
        {footer && (
          <footer className="flex flex-wrap justify-end gap-3 border-t border-border px-5 py-4">
            {footer}
          </footer>
        )}
      </div>
    </div>
  );
}
