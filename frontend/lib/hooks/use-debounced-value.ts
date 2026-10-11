"use client";

import { useEffect, useState } from "react";

/**
 * Debounce nilai agar pencarian produk tidak menembak API per ketikan.
 * Mengembalikan nilai yang tertunda selama `delayMs` setelah perubahan terakhir.
 */
export function useDebouncedValue<T>(value: T, delayMs = 300): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delayMs);
    return () => clearTimeout(timer);
  }, [value, delayMs]);

  return debounced;
}
