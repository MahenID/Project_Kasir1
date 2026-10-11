"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { authApi } from "@/lib/api/auth";
import { ApiError } from "@/lib/api";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await authApi.login({ email: email.trim(), password });
      router.push("/pos");
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError("Terjadi kesalahan tak terduga. Silakan coba lagi.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-background p-4">
      <div className="w-full max-w-sm rounded-2xl border border-border bg-surface p-8 shadow-lg">
        <div className="mb-6 flex flex-col items-center gap-2">
          <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary text-2xl font-bold text-white">
            DM
          </span>
          <h1 className="text-xl font-bold">DragonMart POS</h1>
          <p className="text-sm text-muted-foreground">Masuk untuk mulai bertransaksi</p>
        </div>

        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <Input
            label="Email"
            type="email"
            name="email"
            autoComplete="username"
            inputMode="email"
            required
            autoFocus
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="kasir@dragonmart.id"
          />
          <Input
            label="Kata Sandi"
            type="password"
            name="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="••••••••"
          />

          {error && (
            <div
              role="alert"
              className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-danger"
            >
              {error}
            </div>
          )}

          <Button type="submit" size="xl" loading={submitting} className="mt-2 w-full">
            {submitting ? "Memproses…" : "Masuk"}
          </Button>
        </form>
      </div>
    </div>
  );
}
