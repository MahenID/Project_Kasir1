"use client";

import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from "react";

export type ButtonVariant = "primary" | "secondary" | "danger" | "ghost" | "outline";
export type ButtonSize = "sm" | "md" | "lg" | "xl";

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
  children: ReactNode;
}

const VARIANT_CLASS: Record<ButtonVariant, string> = {
  primary:
    "bg-primary text-primary-foreground hover:bg-primary-hover disabled:opacity-50",
  secondary:
    "bg-slate-100 text-foreground hover:bg-slate-200 border border-border disabled:opacity-50",
  danger:
    "bg-danger text-white hover:bg-danger-hover disabled:opacity-50",
  ghost:
    "bg-transparent text-foreground hover:bg-slate-100 disabled:opacity-50",
  outline:
    "bg-surface text-foreground border-2 border-primary text-primary hover:bg-blue-50 disabled:opacity-50",
};

const SIZE_CLASS: Record<ButtonSize, string> = {
  sm: "h-10 px-3 text-sm", // 40px visual (area sentuh tetap 44px via touch-target)
  md: "h-11 px-4 text-sm", // 44px
  lg: "h-12 px-6 text-base", // 48px
  xl: "h-14 px-8 text-lg font-semibold", // 56px — tombol utama POS
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = "primary", size = "lg", loading = false, disabled, className = "", children, ...rest },
  ref
) {
  return (
    <button
      ref={ref}
      disabled={disabled || loading}
      className={`touch-target inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors select-none active:scale-[0.98] ${VARIANT_CLASS[variant]} ${SIZE_CLASS[size]} ${className}`}
      {...rest}
    >
      {loading && (
        <span
          className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"
          aria-hidden="true"
        />
      )}
      {children}
    </button>
  );
});
