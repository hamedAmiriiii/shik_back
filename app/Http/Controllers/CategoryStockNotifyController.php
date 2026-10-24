<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CategoryStockNotifyRequest;
use App\Services\CategoryStockNotifyService;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CategoryStockNotifyController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            \App\Services\ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );
        if (! Schema::hasTable('category_stock_notify_requests')) {
            return response(['message' => 'جدول اعلان دسته هنوز ساخته نشده است.', 'data' => []], 503);
        }

        $query = CategoryStockNotifyRequest::query()
            ->with('category:id,name')
            ->where('atelier_id', $atelierId)
            ->orderByDesc('id');

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }
        if ($request->filled('phone')) {
            $phone = PhoneTools::normalizeIranPhone($request->input('phone'));
            if ($phone) {
                $query->where('phone', 'like', '%'.$phone.'%');
            }
        }

        $perPage = (int) $request->input('per_page', 50);
        $perPage = $perPage > 0 ? min($perPage, 200) : 50;

        $page = $query->paginate($perPage);
        $items = collect($page->items())->map(function (CategoryStockNotifyRequest $row) {
            return [
                'id' => $row->id,
                'atelier_id' => $row->atelier_id,
                'category_id' => $row->category_id,
                'category_name' => $row->category->name ?? null,
                'phone' => $row->phone,
                'note' => $row->note,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        })->values()->all();

        return response([
            'data' => $items,
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    public function store(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            \App\Services\ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );
        if (! Schema::hasTable('category_stock_notify_requests')) {
            return response(['message' => 'جدول اعلان دسته هنوز ساخته نشده است.'], 503);
        }

        if ($request->has('phone')) {
            $normalized = PhoneTools::normalizeIranPhone($request->input('phone'));
            $request->merge([
                'phone' => $normalized ?: (string) $request->input('phone'),
            ]);
        }

        $validated = $request->validate([
            'category_id' => 'required|integer|min:1',
            'phone' => 'required|string|regex:/^09\d{9}$/',
            'note' => 'nullable|string|max:255',
        ]);

        $category = Category::query()
            ->where('atelier_id', $atelierId)
            ->where('id', $validated['category_id'])
            ->first();
        if (! $category) {
            return response(['message' => 'دسته‌بندی یافت نشد'], 404);
        }

        $row = CategoryStockNotifyService::subscribe(
            $atelierId,
            (int) $category->id,
            $validated['phone'],
            $validated['note'] ?? null
        );

        return response([
            'message' => $row->wasRecentlyCreated
                ? 'شماره ثبت شد؛ با کالای جدید یا موجود شدن کالا در این دسته پیامک می‌رود'
                : 'این شماره قبلاً برای این دسته ثبت شده بود',
            'data' => [
                'id' => $row->id,
                'category_id' => $row->category_id,
                'category_name' => $category->name,
                'phone' => $row->phone,
                'note' => $row->note,
            ],
            'already_exists' => ! $row->wasRecentlyCreated,
        ], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, $id)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            \App\Services\ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );
        if (! Schema::hasTable('category_stock_notify_requests')) {
            return response(['message' => 'جدول اعلان دسته هنوز ساخته نشده است.'], 503);
        }

        $row = CategoryStockNotifyRequest::query()
            ->where('atelier_id', $atelierId)
            ->where('id', (int) $id)
            ->first();
        if (! $row) {
            return response(['message' => 'مورد یافت نشد'], 404);
        }

        if ($request->has('phone')) {
            $normalized = PhoneTools::normalizeIranPhone($request->input('phone'));
            $request->merge([
                'phone' => $normalized ?: (string) $request->input('phone'),
            ]);
        }

        $validated = $request->validate([
            'category_id' => 'sometimes|required|integer|min:1',
            'phone' => 'sometimes|required|string|regex:/^09\d{9}$/',
            'note' => 'nullable|string|max:255',
        ]);

        if (array_key_exists('category_id', $validated)) {
            $category = Category::query()
                ->where('atelier_id', $atelierId)
                ->where('id', $validated['category_id'])
                ->first();
            if (! $category) {
                return response(['message' => 'دسته‌بندی یافت نشد'], 404);
            }
            $row->category_id = (int) $category->id;
        }

        if (array_key_exists('phone', $validated)) {
            $exists = CategoryStockNotifyRequest::query()
                ->where('atelier_id', $atelierId)
                ->where('category_id', $row->category_id)
                ->where('phone', $validated['phone'])
                ->where('id', '!=', $row->id)
                ->exists();
            if ($exists) {
                return response(['message' => 'این شماره برای این دسته قبلاً ثبت شده است'], 422);
            }
            $row->phone = $validated['phone'];
        }

        if (array_key_exists('note', $validated)) {
            $note = trim((string) ($validated['note'] ?? ''));
            $row->note = $note !== '' ? $note : null;
        }

        $row->save();
        $row->load('category:id,name');

        return response([
            'message' => 'به‌روزرسانی شد',
            'data' => [
                'id' => $row->id,
                'category_id' => $row->category_id,
                'category_name' => $row->category->name ?? null,
                'phone' => $row->phone,
                'note' => $row->note,
            ],
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            \App\Services\ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
        );
        if (! Schema::hasTable('category_stock_notify_requests')) {
            return response(['message' => 'جدول اعلان دسته هنوز ساخته نشده است.'], 503);
        }

        $row = CategoryStockNotifyRequest::query()
            ->where('atelier_id', $atelierId)
            ->where('id', (int) $id)
            ->first();
        if (! $row) {
            return response(['message' => 'مورد یافت نشد'], 404);
        }

        $row->delete();

        return response(['message' => 'حذف شد']);
    }
}
