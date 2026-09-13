<?php

namespace App\Http\Controllers;

use App\Models\Atelier;
use App\Models\FormalInvoiceBuyerProfile;
use App\Models\FormalInvoiceSellerProfile;
use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class FormalInvoiceController extends Controller
{
    public function sellerShow(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if (! Schema::hasTable('formal_invoice_seller_profiles')) {
            return response(['seller' => $this->defaultSellerFromAtelier($atelierId)], 200);
        }

        $row = FormalInvoiceSellerProfile::query()->where('atelier_id', $atelierId)->first();
        if (! $row) {
            return response(['seller' => $this->defaultSellerFromAtelier($atelierId)], 200);
        }

        return response(['seller' => $this->formatSeller($row)], 200);
    }

    public function sellerUpdate(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if (! Schema::hasTable('formal_invoice_seller_profiles')) {
            return response(['message' => 'جدول مشخصات فروشنده ساخته نشده است.'], 503);
        }

        $request->validate([
            'legal_name' => 'nullable|string|max:191',
            'brand_name' => 'nullable|string|max:191',
            'province' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:500',
            'postal_code' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:40',
            'economic_code' => 'nullable|string|max:64',
            'national_id' => 'nullable|string|max:64',
            'registration_number' => 'nullable|string|max:64',
            'fixed_notes' => 'nullable|string|max:2000',
        ]);

        $payload = [];
        foreach ([
            'legal_name', 'brand_name', 'province', 'city', 'address', 'postal_code',
            'phone', 'economic_code', 'national_id', 'registration_number', 'fixed_notes',
        ] as $key) {
            if (! $request->exists($key)) {
                continue;
            }
            $value = $request->input($key);
            if (is_string($value)) {
                $value = trim($value);
            }
            $payload[$key] = $value === '' ? null : $value;
        }

        $row = FormalInvoiceSellerProfile::query()->updateOrCreate(
            ['atelier_id' => $atelierId],
            $payload
        );

        return response([
            'message' => 'مشخصات فروشنده ذخیره شد.',
            'seller' => $this->formatSeller($row->fresh()),
        ], 200);
    }

    public function buyerShow(Request $request, string $phone)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $normalized = $this->normalizePhone($phone);
        if ($normalized === '') {
            return response(['buyer' => null], 200);
        }

        if (! Schema::hasTable('formal_invoice_buyer_profiles')) {
            return response(['buyer' => ['phone' => $normalized]], 200);
        }

        $row = FormalInvoiceBuyerProfile::query()
            ->where('atelier_id', $atelierId)
            ->where('phone', $normalized)
            ->first();

        if (! $row) {
            return response(['buyer' => ['phone' => $normalized]], 200);
        }

        return response(['buyer' => $this->formatBuyer($row)], 200);
    }

    public function buyerUpdate(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if (! Schema::hasTable('formal_invoice_buyer_profiles')) {
            return response(['message' => 'جدول مشخصات خریدار ساخته نشده است.'], 503);
        }

        $fields = $request->validate([
            'phone' => 'required|string|max:20',
            'full_name' => 'nullable|string|max:191',
            'province' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:500',
            'postal_code' => 'nullable|string|max:20',
            'economic_code' => 'nullable|string|max:64',
            'national_id' => 'nullable|string|max:64',
            'registration_number' => 'nullable|string|max:64',
        ]);

        $phone = $this->normalizePhone($fields['phone']);
        if ($phone === '') {
            return response(['message' => 'شماره تلفن معتبر نیست.'], 422);
        }

        $payload = [];
        foreach ([
            'full_name', 'province', 'city', 'address', 'postal_code',
            'economic_code', 'national_id', 'registration_number',
        ] as $key) {
            if (! array_key_exists($key, $fields)) {
                continue;
            }
            $value = is_string($fields[$key]) ? trim($fields[$key]) : $fields[$key];
            $payload[$key] = $value === '' ? null : $value;
        }

        $row = FormalInvoiceBuyerProfile::query()->updateOrCreate(
            ['atelier_id' => $atelierId, 'phone' => $phone],
            $payload
        );

        return response([
            'message' => 'مشخصات خریدار ذخیره شد.',
            'buyer' => $this->formatBuyer($row),
        ], 200);
    }

    public function purchaseBundle(Request $request, Purchase $purchase)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        if ((int) ($purchase->atelier_id ?? 0) !== (int) $atelierId) {
            $belongs = Purchase::query()
                ->where('id', $purchase->id)
                ->forAtelier($atelierId)
                ->exists();
            if (! $belongs) {
                return response(['message' => 'این فروش متعلق به فروشگاه شما نیست.'], 403);
            }
        }

        $purchase->load([
            'purchasedProducts.product',
            'purchasedProducts.producedGood',
            'purchasedProducts.rawMaterial',
        ]);

        $phone = $this->normalizePhone((string) ($purchase->phone ?? ''));
        $seller = null;
        if (Schema::hasTable('formal_invoice_seller_profiles')) {
            $sellerRow = FormalInvoiceSellerProfile::query()->where('atelier_id', $atelierId)->first();
            $seller = $sellerRow ? $this->formatSeller($sellerRow) : $this->defaultSellerFromAtelier($atelierId);
        } else {
            $seller = $this->defaultSellerFromAtelier($atelierId);
        }

        $buyer = ['phone' => $phone ?: null];
        if ($phone !== '' && Schema::hasTable('formal_invoice_buyer_profiles')) {
            $buyerRow = FormalInvoiceBuyerProfile::query()
                ->where('atelier_id', $atelierId)
                ->where('phone', $phone)
                ->first();
            if ($buyerRow) {
                $buyer = $this->formatBuyer($buyerRow);
            }
        }

        return response([
            'purchase' => $purchase,
            'seller' => $seller,
            'buyer' => $buyer,
        ], 200);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatSeller(FormalInvoiceSellerProfile $row): array
    {
        return [
            'legal_name' => $row->legal_name,
            'brand_name' => $row->brand_name,
            'province' => $row->province,
            'city' => $row->city,
            'address' => $row->address,
            'postal_code' => $row->postal_code,
            'phone' => $row->phone,
            'economic_code' => $row->economic_code,
            'national_id' => $row->national_id,
            'registration_number' => $row->registration_number,
            'fixed_notes' => $row->fixed_notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatBuyer(FormalInvoiceBuyerProfile $row): array
    {
        return [
            'phone' => $row->phone,
            'full_name' => $row->full_name,
            'province' => $row->province,
            'city' => $row->city,
            'address' => $row->address,
            'postal_code' => $row->postal_code,
            'economic_code' => $row->economic_code,
            'national_id' => $row->national_id,
            'registration_number' => $row->registration_number,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultSellerFromAtelier(int $atelierId): array
    {
        $atelier = Atelier::query()->find($atelierId);

        return [
            'legal_name' => $atelier?->name,
            'brand_name' => $atelier?->name,
            'province' => null,
            'city' => null,
            'address' => $atelier?->address,
            'postal_code' => null,
            'phone' => null,
            'economic_code' => null,
            'national_id' => null,
            'registration_number' => null,
            'fixed_notes' => null,
        ];
    }

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '0'.$digits;
        }

        return $digits;
    }
}
