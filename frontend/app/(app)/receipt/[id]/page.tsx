"use client";

import { useEffect, useState, useCallback } from "react";
import { saleApi } from "@/lib/api/pos";
import type { ReceiptPayload } from "@/lib/types";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api";
import { formatRupiah } from "@/lib/format";

export default function ReceiptPrintPage({ params }: { params: Promise<{ id: string }> }) {
  const [receipt, setReceipt] = useState<ReceiptPayload | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (id: number) => {
    try {
      setReceipt(await saleApi.receipt(id));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal memuat struk.");
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    params.then(({ id }) => {
      if (cancelled) return;
      const numericId = parseInt(id, 10);
      if (!Number.isNaN(numericId)) load(numericId);
    });
    return () => {
      cancelled = true;
    };
  }, [params, load]);

  const onPrint = () => window.print();
  const onReprint = async () => {
    if (!receipt) return;
    const fresh = await saleApi.reprint(receipt.sale_id, "Cetak ulang dari kasir");
    setReceipt(fresh);
  };

  if (error) {
    return (
      <div className="flex h-screen items-center justify-center p-6 text-sm text-danger">
        {error}
      </div>
    );
  }
  if (!receipt) {
    return (
      <div className="flex h-screen items-center justify-center">
        <span className="h-6 w-6 animate-spin rounded-full border-4 border-primary border-t-transparent" />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-slate-100 p-4 print:p-0 print:bg-white">
      <div className="no-print mx-auto mb-3 flex max-w-sm gap-2">
        <Button variant="secondary" onClick={() => window.close()}>
          Tutup
        </Button>
        <Button variant="outline" onClick={onReprint}>
          Tandai SALINAN
        </Button>
        <Button onClick={onPrint}>Cetak</Button>
      </div>

      <article className="mx-auto max-w-sm bg-white p-4 font-mono text-sm shadow print:shadow-none">
        <header className="mb-2 text-center">
          <h1 className="text-base font-bold">{receipt.store_name ?? "DragonMart"}</h1>
          {receipt.store_address && <p className="text-xs">{receipt.store_address}</p>}
          {receipt.store_phone && <p className="text-xs">{receipt.store_phone}</p>}
        </header>

        {receipt.is_copy && receipt.copy_label && (
          <p className="mb-2 text-center text-xs font-bold">{receipt.copy_label}</p>
        )}

        <dl className="mb-2 grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 text-xs">
          <dt>No</dt>
          <dd className="text-right">{receipt.receipt_number}</dd>
          <dt>Tanggal</dt>
          <dd className="text-right">{new Date(receipt.printed_at).toLocaleString("id-ID")}</dd>
          <dt>Kasir</dt>
          <dd className="text-right">{receipt.cashier_name}</dd>
          {receipt.customer_name && (
            <>
              <dt>Pelanggan</dt>
              <dd className="text-right">{receipt.customer_name}</dd>
            </>
          )}
        </dl>

        <hr className="my-2 border-dashed border-slate-300" />

        <table className="w-full text-xs">
          <tbody>
            {receipt.items.map((line, idx) => (
              <tr key={idx} className="align-top">
                <td className="py-0.5">
                  <div>{line.name}</div>
                  <div className="text-slate-500">
                    {line.quantity} {line.unit} × {formatRupiah(line.unit_price)}
                  </div>
                  {line.discount_amount > 0 && (
                    <div className="text-slate-500">- {formatRupiah(line.discount_amount)}</div>
                  )}
                </td>
                <td className="py-0.5 text-right">{formatRupiah(line.line_subtotal)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <hr className="my-2 border-dashed border-slate-300" />

        <dl className="grid grid-cols-[1fr_auto] gap-y-0.5 text-xs">
          <dt>Subtotal</dt>
          <dd className="text-right">{formatRupiah(receipt.subtotal)}</dd>
          {receipt.sale_discount_amount > 0 && (
            <>
              <dt>Diskon</dt>
              <dd className="text-right">- {formatRupiah(receipt.sale_discount_amount)}</dd>
            </>
          )}
          {receipt.tax_amount > 0 && (
            <>
              <dt>Pajak</dt>
              <dd className="text-right">{formatRupiah(receipt.tax_amount)}</dd>
            </>
          )}
          <dt className="pt-1 text-sm font-bold">TOTAL</dt>
          <dd className="pt-1 text-right text-sm font-bold">
            {formatRupiah(receipt.grand_total)}
          </dd>
        </dl>

        <hr className="my-2 border-dashed border-slate-300" />

        <dl className="grid grid-cols-[1fr_auto] gap-y-0.5 text-xs">
          <dt>Bayar ({receipt.payment.method.toUpperCase()})</dt>
          <dd className="text-right">{formatRupiah(receipt.payment.amount)}</dd>
          {receipt.payment.tendered_amount !== null && (
            <>
              <dt>Tunai</dt>
              <dd className="text-right">{formatRupiah(receipt.payment.tendered_amount)}</dd>
              {receipt.payment.change_amount !== null && (
                <>
                  <dt>Kembali</dt>
                  <dd className="text-right">{formatRupiah(receipt.payment.change_amount)}</dd>
                </>
              )}
            </>
          )}
          {receipt.payment.reference && (
            <>
              <dt>Ref</dt>
              <dd className="text-right">{receipt.payment.reference}</dd>
            </>
          )}
        </dl>

        {receipt.reprint && (
          <p className="mt-2 text-center text-xs italic">
            Cetak ulang: {receipt.reprint.reprinted_by_name}
            {receipt.reprint.reason && ` — ${receipt.reprint.reason}`}
          </p>
        )}

        <footer className="mt-3 text-center text-xs">Terima kasih atas kunjungannya</footer>
      </article>
    </div>
  );
}