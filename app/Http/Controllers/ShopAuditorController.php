<?php

namespace App\Http\Controllers;

use App\Models\ShopAuditor;
use App\Models\User;
use App\Services\ShopAuditorAccess;
use App\Services\ShopStaffAccess;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * مدیریت حسابرس‌های فروشگاه — فقط صاحب اصلی فروشگاه (نه خود حسابرس).
 */
class ShopAuditorController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->ownerAtelierIdOrAbort($request);

        $items = ShopAuditor::query()
            ->with('user:id,phone,name')
            ->where('atelier_id', $atelierId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ShopAuditor $link) => $this->present($link))
            ->values();

        return response(['data' => $items], 200);
    }

    public function store(Request $request)
    {
        $atelierId = $this->ownerAtelierIdOrAbort($request);
        $this->normalizePhone($request);

        $fields = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|regex:/^09\d{9}$/',
            'password' => 'nullable|string|min:6|max:255',
            'note' => 'nullable|string|max:2000',
        ], $this->ruleMessages());

        try {
            $link = ShopAuditorAccess::link(
                $atelierId,
                $fields['phone'],
                $fields['name'],
                $fields['password'] ?? null,
                $fields['note'] ?? null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($this->present($link->load('user:id,phone,name')), 201);
    }

    public function update(Request $request, ShopAuditor $shopAuditor)
    {
        $atelierId = $this->ownerAtelierIdOrAbort($request);
        if ((int) $shopAuditor->atelier_id !== $atelierId) {
            return response()->json(['message' => 'یافت نشد'], 404);
        }

        $fields = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'is_active' => 'sometimes|boolean',
            'note' => 'sometimes|nullable|string|max:2000',
        ], $this->ruleMessages());

        $shopAuditor->update($fields);

        if (! $shopAuditor->is_active) {
            ShopAuditorAccess::clearSelectionIfCurrent($shopAuditor);
        }

        return response($this->present($shopAuditor->fresh()->load('user:id,phone,name')), 200);
    }

    public function destroy(Request $request, ShopAuditor $shopAuditor)
    {
        $atelierId = $this->ownerAtelierIdOrAbort($request);
        if ((int) $shopAuditor->atelier_id !== $atelierId) {
            return response()->json(['message' => 'یافت نشد'], 404);
        }

        ShopAuditorAccess::clearSelectionIfCurrent($shopAuditor);
        $shopAuditor->delete();

        return response(['message' => 'حسابرس از فروشگاه حذف شد.'], 200);
    }

    private function ownerAtelierIdOrAbort(Request $request): int
    {
        $actor = $this->requireStaffShopUser($request);
        if (ShopStaffAccess::isAuditor($actor) || ! ShopStaffAccess::isOwner($actor)) {
            abort(response()->json([
                'message' => 'فقط صاحب فروشگاه می‌تواند حسابرس اضافه یا حذف کند.',
            ], 403));
        }
        if (! ShopAuditorAccess::tableReady()) {
            abort(response()->json([
                'message' => 'جدول shop_auditors وجود ندارد. migration یا SQL حسابرس را اجرا کنید.',
            ], 503));
        }

        $atelierId = $this->staffShopAtelierId($request);
        if ($atelierId === null) {
            abort(response()->json([
                'message' => 'حساب شما به فروشگاه متصل نیست.',
            ], 422));
        }

        return $atelierId;
    }

    private function normalizePhone(Request $request): void
    {
        $this->mergeRequestPayload($request, ['name', 'phone', 'username', 'password', 'note']);
        if (! $request->filled('phone') && $request->filled('username')) {
            $request->merge(['phone' => $request->input('username')]);
        }
        if ($request->has('phone')) {
            $request->merge(['phone' => PhoneTools::normalizeIranPhone($request->input('phone'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ShopAuditor $link): array
    {
        $user = $link->user;

        return [
            'id' => (int) $link->id,
            'name' => $link->name,
            'phone' => $user instanceof User ? $user->phone : null,
            'is_active' => (bool) $link->is_active,
            'note' => $link->note,
            'created_at' => $link->created_at,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function ruleMessages(): array
    {
        return [
            'phone.regex' => 'شماره موبایل حسابرس باید ۱۱ رقمی و با ۰۹ شروع شود.',
            'password.min' => 'رمز ورود حسابرس باید حداقل ۶ کاراکتر باشد.',
        ];
    }
}
