"use client";

import { forwardRef, type InputHTMLAttributes, type TextareaHTMLAttributes } from "react";

const BASE_INPUT =
  "w-full h-11 px-3 rounded-lg border border-border bg-surface text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary disabled:opacity-50";

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  hint?: string;
  errorText?: string;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { label, hint, errorText, id, className = "", ...rest },
  ref
) {
  const inputId = id ?? rest.name ?? undefined;
  return (
    <div className="flex flex-col gap-1">
      {label && (
        <label htmlFor={inputId} className="text-sm font-medium text-foreground">
          {label}
        </label>
      )}
      <input
        id={inputId}
        ref={ref}
        className={`${BASE_INPUT} ${className}`}
        aria-invalid={Boolean(errorText) || undefined}
        aria-describedby={hint || errorText ? `${inputId}-desc` : undefined}
        {...rest}
      />
      {(hint || errorText) && (
        <p
          id={inputId ? `${inputId}-desc` : undefined}
          className={`text-xs ${errorText ? "text-danger" : "text-muted-foreground"}`}
        >
          {errorText ?? hint}
        </p>
      )}
    </div>
  );
});

interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string;
  hint?: string;
  errorText?: string;
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
  { label, hint, errorText, id, className = "", ...rest },
  ref
) {
  const inputId = id ?? rest.name ?? undefined;
  return (
    <div className="flex flex-col gap-1">
      {label && (
        <label htmlFor={inputId} className="text-sm font-medium text-foreground">
          {label}
        </label>
      )}
      <textarea
        id={inputId}
        ref={ref}
        className={`min-h-24 rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-50 ${className}`}
        aria-invalid={Boolean(errorText) || undefined}
        {...rest}
      />
      {(hint || errorText) && (
        <p className={`text-xs ${errorText ? "text-danger" : "text-muted-foreground"}`}>
          {errorText ?? hint}
        </p>
      )}
    </div>
  );
});