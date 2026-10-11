"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import type {
  PaymentMethod,
  ProductCashier,
  Quote,
  Sale,
} from "@/lib/types";
import { checkoutApi, saleApi } from "@/lib/api/pos";
import { ApiError, uuidv4 } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { ProductSearch } from "@/components/pos/product-search";
import { Button } from "@/components/ui/button";
import { Modal } from "@/components/ui/modal";
import { Input } from "@/components/ui/input";
import { formatRupiah } from "@/lib/format";

interface CartItem {
  product_id: number;
  sku: string;
  name: string;
  unit: string;
  unit_price: number;
  quantity: number;
}

const QUOTE_TTL_MS = 120_000;
const REFRESH_AHEAD_MS = 15_000; // refresh 15 detik sebelum kedaluwarsa

export default function PosPage() {
  const { user } = useAuth();
  const [cart, setCart] = useState<CartItem[]>([]);
  const [quote, setQuote] = useState<Quote | null>(null);
  const [quoteExpiresAt, setQuoteExpiresAt] = useState<number>(0);
  const [quoteError, setQuoteError] = useState<string | null>(null);
  const [quoting, setQuoting] = useState(false);
  const [payOpen, setPayOpen] = useState(false);
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>("cash");
  const [tendered, setTendered] = useState("");
  const [reference, setReference] = useState("");
  const [payError, setPayError] = useState<string | null>(null);
  const [paying, setPaying] = useState(false);
  const [completedSale, setCompletedSale] = useState<Sale | null>(null);

  // Kunci idempotensi per keranjang: tetap sama selama payload tidak berubah.
  const idempotencyKeyRef = useRef<string>(uuidv4());
  const cartSignature = useMemo(
    () => cart.map((i) => `${i.product_id}:${i.quantity}`).join(","),
    [cart]
  );
  const prevSignatureRef = useRef<string>("");

  const refreshQuote = useCallback(
    async (items: CartItem[]) => {
      if (items.length === 0) {
        setQuote(null);
        setQuoteExpiresAt(0);
        return;
      }
      setQuoting(true);
      setQuoteError(null);
      try {
        const q = await checkoutApi.createQuote({
          items: items.map((i) => ({
            product_id: i.product_id,
            quantity: i.quantity,
          })),
        });
        setQuote(q);
        setQuoteExpiresAt(Date.now() + QUOTE_TTL_MS);
      } catch (err) {
        setQuote(null);
        setQuoteExpiresAt(0);
        setQuoteError(
          err instanceof ApiError ? err.message : "Gagal membuat kutipan harga."
        );
      } finally {
        setQuoting(false);
      }
    },
    []
  );

  // Refresh quote saat keranjang berubah (perubahan payload memicu re-quote
  // sekaligus memutar kunci idempotensi agar tidak terjadi IDEMPOTENCY_CONFLICT).
  useEffect(() => {
    if (prevSignatureRef.current === cartSignature) return;
    prevSignatureRef.current = cartSignature;
    idempotencyKeyRef.current = uuidv4();
    refreshQuote(cart);
  }, [cartSignature, cart, refreshQuote]);

  // Jaga quote tetap segar mendekati kedaluwarsa 120 detik.
  useEffect(() => {
    if (!quote || cart.length === 0) return;
    const delay = quoteExpiresAt - Date.now() - REFRESH_AHEAD_MS;
    const timer = setTimeout(() => {
      refreshQuote(cart);
    }, Math.max(delay, 1_000));
    return () => clearTimeout(timer);
  }, [quote, quoteExpiresAt, cart, refreshQuote]);

  const addItem = useCallback((product: ProductCashier) => {
    setCart((prev) => {
      const existing = prev.find((i) => i.product_id === product.id);
      if (existing) {
        return prev.map((i) =>
          i.product_id === product.id ? { ...i, quantity: i.quantity + 1 } : i
        );
      }
      return [
        ...prev,
        {
          product_id: product.id,
          sku: product.sku,
          name: product.name,
          unit: product.unit,
          unit_price: product.selling_price,
          quantity: 1,
        },
      ];
    });
  }, []);

  const setQty = useCallback((productId: number, qty: number) => {
    setCart((prev) => {
      if (qty <= 0) return prev.filter((i) => i.product_id !== productId);
      return prev.map((i) =>
        i.product_id === productId ? { ...i, quantity: qty } : i
      );
    });
  }, []);

  const grandTotal = quote?.calculation.grand_total ?? 0;
  const tenderedAmount = tendered === "" ? null : Math.max(0, parseInt(tendered, 10) || 0);
  const change =
    paymentMethod === "cash" && tenderedAmount !== null
      ? tenderedAmount - grandTotal
      : null;

  const canPay =
    cart.length > 0 &&
    !quoting &&
    !quoteError &&
    quote !== null &&
    (paymentMethod !== "cash" ||
      (tenderedAmount !== null && tenderedAmount >= grandTotal));

  const onPay = async () => {
    if (!quote) return;
    setPaying(true);
    setPayError(null);
    try {
      const sale = await saleApi.checkout({
        quoteId: quote.quote_id,
        paymentMethod,
        tenderedAmount: paymentMethod === "cash" ? tenderedAmount : null,
        paymentReference: paymentMethod !== "cash" ? reference || null : null,
        idempotencyKey: idempotencyKeyRef.current,
      });
      setCompletedSale(sale);
      setPayOpen(false);
      setCart([]);
      setQuote(null);
      setQuoteExpiresAt(0);
      setTendered("");
      setReference("");
      idempotencyKeyRef.current = uuidv4();
    } catch (err) {
      if (err instanceof ApiError && err.code === "IDEMPOTENCY_CONFLICT") {
        // Payload berubah setelah kunci dikirim; minta quote segar & coba lagi.
        await refreshQuote(cart);
        setPayError("Kutipan berubah. Silakan tekan Bayar kembali.");
      } else if (err instanceof ApiError && err.code === "QUOTE_CHANGED") {
        await refreshQuote(cart);
        setPayError("Kutipan kedaluwarsa atau berubah. Kutipan baru dibuat.");
      } else {
        setPayError(err instanceof ApiError ? err.message : "Pembayaran gagal diproses.");
      }
    } finally {
      setPaying(false);
    }
  };

  return (
    <div className="grid h-full grid-cols-[1fr_420px] gap-3 p-3">
      <ProductSearch onPick={addItem} />

      {/* Panel Keranjang */}
      <aside className="flex min-h-0 flex-col rounded-xl border border-border bg-surface">
        <header className="border-b border-border px-4 py-3">
          <h2 className="text-base font-semibold">Keranjang</h2>
          <p className="text-xs text-muted-foreground">
            Kasir: {user?.name} · Shift: {user?.current_shift?.shift_number ?? "—"}
          </p>
        </header>

        <div className="flex-1 overflow-y-auto scroll-thin px-3 py-2">
          {cart.length === 0 && (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Keranjang kosong. Cari atau scan produk untuk menambahkan.
            </p>
          )}
          <ul className="flex flex-col gap-2">
            {cart.map((item) => (
              <li
                key={item.product_id}
                className="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2"
              >
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{item.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {formatRupiah(item.unit_price)} / {item.unit}
                  </p>
                </div>
                <div className="flex items-center gap-1">
                  <button
                    type="button"
                    onClick={() => setQty(item.product_id, item.quantity - 1)}
                    aria-label="Kurangi"
                    className="touch-target flex h-10 w-10 items-center justify-center rounded-lg border border-border text-lg font-bold hover:bg-slate-100"
                  >
                    −
                  </button>
                  <span className="w-10 text-center text-sm font-semibold">
                    {item.quantity}
                  </span>
                  <button
                    type="button"
                    onClick={() => setQty(item.product_id, item.quantity + 1)}
                    aria-label="Tambah"
                    className="touch-target flex h-10 w-10 items-center justify-center rounded-lg border border-border text-lg font-bold hover:bg-slate-100"
                  >
                    +
                  </button>
                </div>
              </li>
            ))}
          </ul>
        </div>

        <footer className="border-t border-border px-4 py-3">
          <div className="mb-3 flex items-center justify-between">
            <span className="text-sm text-muted-foreground">
              Total {quote ? `(${quote.calculation.total_quantity} item)` : ""}
            </span>
            <span className="text-2xl font-bold text-primary">
              {formatRupiah(grandTotal)}
            </span>
          </div>
          {quoteError && (
            <p role="alert" className="mb-2 text-xs text-danger">
              {quoteError}
            </p>
          )}
          {quoting && (
            <p className="mb-2 text-xs text-muted-foreground">
              Menghitung kutipan…
            </p>
          )}
          <Button
            size="xl"
            className="w-full"
            disabled={!canPay}
            onClick={() => setPayOpen(true)}
          >
            Bayar
          </Button>
        </footer>
      </aside>

      {/* Dialog Pembayaran */}
      <Modal
        open={payOpen}
        title="Pembayaran"
        onClose={() => setPayOpen(false)}
        dismissible={!paying}
        footer={
          <>
            <Button variant="secondary" onClick={() => setPayOpen(false)} disabled={paying}>
              Batal
            </Button>
            <Button onClick={onPay} loading={paying} disabled={!canPay}>
              {paying ? "Memproses…" : `Bayar ${formatRupiah(grandTotal)}`}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          <div className="flex items-center justify-between rounded-lg bg-surface-muted px-4 py-3">
            <span className="text-sm text-muted-foreground">Total Tagihan</span>
            <span className="text-2xl font-bold">{formatRupiah(grandTotal)}</span>
          </div>

          <div className="grid grid-cols-3 gap-2">
            {(
              [
                ["cash", "Tunai"],
                ["debit", "Debit"],
                ["credit", "Kredit"],
                ["qris", "QRIS"],
                ["transfer", "Transfer"],
              ] as Array<[PaymentMethod, string]>
            ).map(([value, label]) => (
              <button
                key={value}
                type="button"
                onClick={() => setPaymentMethod(value)}
                className={`touch-target h-12 rounded-lg border-2 text-sm font-medium ${
                  paymentMethod === value
                    ? "border-primary bg-blue-50 text-primary"
                    : "border-border hover:bg-slate-50"
                }`}
              >
                {label}
              </button>
            ))}
          </div>

          {paymentMethod === "cash" ? (
            <>
              <Input
                label="Uang Diterima (Rp)"
                name="tendered"
                inputMode="numeric"
                placeholder="0"
                value={tendered}
                onChange={(e) => setTendered(e.target.value.replace(/\D/g, ""))}
                autoFocus
              />
              <div className="flex items-center justify-between rounded-lg px-2 py-1">
                <span className="text-sm text-muted-foreground">Kembalian</span>
                <span
                  className={`text-xl font-bold ${
                    change !== null && change < 0 ? "text-danger" : "text-success"
                  }`}
                >
                  {change === null ? "—" : formatRupiah(Math.max(0, change))}
                </span>
              </div>
            </>
          ) : (
            <Input
              label="Nomor Referensi"
              name="reference"
              placeholder="No. kartu / QRIS / transfer"
              value={reference}
              onChange={(e) => setReference(e.target.value)}
            />
          )}

          {payError && (
            <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-danger">
              {payError}
            </p>
          )}
        </div>
      </Modal>

      {/* Dialog Struk setelah sukses */}
      <Modal
        open={completedSale !== null}
        title="Transaksi Berhasil"
        onClose={() => setCompletedSale(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setCompletedSale(null)}>
              Tutup
            </Button>
            <Button
              variant="outline"
              onClick={() => completedSale && window.open(`/receipt/${completedSale.id}`, "_blank")}
            >
              Cetak Struk
            </Button>
            <Button onClick={() => setCompletedSale(null)}>Transaksi Baru</Button>
          </>
        }
      >
        {completedSale && (
          <div className="flex flex-col gap-2 text-sm">
            <p>
              Struk <span className="font-mono font-semibold">{completedSale.receipt_number}</span>
            </p>
            <p>Total: <span className="font-bold">{formatRupiah(completedSale.grand_total)}</span></p>
            {completedSale.payment.change_amount !== null && (
              <p>Kembalian: <span className="font-bold text-success">{formatRupiah(completedSale.payment.change_amount)}</span></p>
            )}
          </div>
        )}
      </Modal>
    </div>
  );
}
