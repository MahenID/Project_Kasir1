// ============================================================================
// Utilitas format bahasa Indonesia & moneter integer Rupiah
// Semua input nilai uang adalah INTEGER rupiah (bukan desimal atau float).
// ============================================================================

const IDR_FORMATTER = new Intl.NumberFormat("id-ID", {
  style: "currency",
  currency: "IDR",
  maximumFractionDigits: 0,
  minimumFractionDigits: 0,
});

const NUMBER_FORMATTER = new Intl.NumberFormat("id-ID");

export function formatRupiah(amount: number | null | undefined): string {
  if (amount === null || amount === undefined || Number.isNaN(amount)) return "Rp 0";
  return IDR_FORMATTER.format(Math.round(amount));
}

export function formatNumber(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return "0";
  return NUMBER_FORMATTER.format(value);
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return "-";
  try {
    const d = new Date(iso);
    return d.toLocaleDateString("id-ID", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      timeZone: "Asia/Makassar", // Sesuai aturan PRD: zona waktu toko
    });
  } catch {
    return iso;
  }
}

export function formatDateOnly(iso: string | null | undefined): string {
  if (!iso) return "-";
  try {
    const d = new Date(iso);
    return d.toLocaleDateString("id-ID", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      timeZone: "Asia/Makassar",
    });
  } catch {
    return iso;
  }
}
