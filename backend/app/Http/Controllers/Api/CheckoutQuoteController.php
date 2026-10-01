<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QuoteService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutQuoteController extends Controller
{
    public function __construct(
        protected QuoteService $quoteService
    ) {}

    /**
     * Create an opaque server quote for checkout.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:10000',
            'items.*.discount_type' => 'nullable|in:percent,fixed',
            'items.*.discount_value' => 'nullable|integer|min:0',
            'sale_discount_type' => 'nullable|in:percent,fixed',
            'sale_discount_value' => 'nullable|integer|min:0',
            'customer_id' => 'nullable|integer|exists:customers,id',
        ]);

        $quote = $this->quoteService->createQuote(
            $request->user(),
            $validated['items'],
            $validated['sale_discount_type'] ?? null,
            isset($validated['sale_discount_value']) ? (int) $validated['sale_discount_value'] : null,
            isset($validated['customer_id']) ? (int) $validated['customer_id'] : null
        );

        return ApiResponse::success($quote, 'Kutipan checkout berhasil dihitung.', 201);
    }
}
