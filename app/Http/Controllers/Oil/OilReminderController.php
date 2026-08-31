<?php

namespace App\Http\Controllers\Oil;

use App\Http\Controllers\Controller;
use App\Models\OilReminderSms;
use App\Models\User;
use App\Services\OilReminderDispatcher;
use App\Support\ProjectType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class OilReminderController extends Controller
{
    /**
     * بدون کران: با توکن همهٔ تعویض روغنی‌ها، با لاگین فقط همان مغازه.
     * GET یا POST /api/oil/reminders/run
     */
    public function run(Request $request)
    {
        $atelierId = $this->resolveRunAtelierId($request);

        return response()->json(OilReminderDispatcher::run($atelierId));
    }

    public function index(Request $request)
    {
        $atelierId = $this->shopOilAtelierId($request);
        $q = trim((string) $request->input('q', ''));
        $perPage = max(1, min(50, (int) $request->input('per_page', 30)));

        $rows = OilReminderSms::query()
            ->where('atelier_id', $atelierId)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('plate', 'like', '%'.$q.'%')
                        ->orWhere('plate_display', 'like', '%'.$q.'%')
                        ->orWhere('phone', 'like', '%'.$q.'%')
                        ->orWhere('message', 'like', '%'.$q.'%');
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage);

        $rows->getCollection()->transform(fn (OilReminderSms $row) => $row->toApiArray());

        return response($rows);
    }

    private function resolveRunAtelierId(Request $request): ?int
    {
        $user = $request->user('sanctum');
        if ($user instanceof User && ProjectType::normalize($user->project_type) === ProjectType::OIL) {
            if (! $user->atelier_id) {
                abort(response()->json(['message' => 'حساب به تعویض روغنی متصل نیست.'], 422));
            }

            return (int) $user->atelier_id;
        }

        $this->assertDispatchToken($request);

        return null;
    }

    private function assertDispatchToken(Request $request): void
    {
        $expected = (string) config('oil.reminder_token', '');
        if ($expected === '') {
            abort(response()->json([
                'message' => 'برای اجرای بدون لاگین، OIL_REMINDER_TOKEN را در .env بگذارید. یا با حساب تعویض روغن لاگین کنید.',
            ], 503));
        }

        $given = (string) (
            $request->header('X-Oil-Reminder-Token')
            ?: $request->input('token')
            ?: $request->query('token')
            ?: ''
        );
        if (! hash_equals($expected, $given)) {
            abort(response()->json(['message' => 'توکن یادآوری نامعتبر است.'], 403));
        }
    }

    private function shopOilAtelierId(Request $request): int
    {
        if (! Schema::hasTable('oil_reminder_sms')) {
            abort(response()->json([
                'message' => 'جدول یادآوری تعویض روغن هنوز ساخته نشده.',
            ], 503));
        }

        $user = $request->user();
        if (! $user || ProjectType::normalize($user->project_type) !== ProjectType::OIL || ! $user->atelier_id) {
            abort(response()->json(['message' => 'دسترسی ندارید.'], 403));
        }

        return $this->shopAtelierIdOrAbort($request);
    }
}
