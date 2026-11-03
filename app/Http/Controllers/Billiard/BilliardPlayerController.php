<?php

namespace App\Http\Controllers\Billiard;

use App\Http\Controllers\Controller;
use App\Models\BilliardDiscipline;
use App\Models\BilliardMatch;
use App\Models\BilliardNationalCompetition;
use App\Models\BilliardNationalEntryRequest;
use App\Models\BilliardRegistration;
use App\Models\BilliardTournament;
use App\Models\BilliardUser;
use App\Services\Billiard\BilliardRegistrationService;
use App\Services\GatewayPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BilliardPlayerController extends Controller
{
    public function register(Request $request, string $slug, BilliardRegistrationService $service)
    {
        /** @var BilliardUser $user */
        $user = $request->user();
        $tournament = BilliardTournament::query()->where('slug', $slug)->firstOrFail();

        try {
            $registration = $service->register($tournament, $user);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['registration' => $registration->toApiArray()], 201);
    }

    public function pay(Request $request, int $registrationId, BilliardRegistrationService $service, GatewayPaymentService $payments)
    {
        /** @var BilliardUser $user */
        $user = $request->user();
        $registration = BilliardRegistration::query()->findOrFail($registrationId);
        $data = $request->validate([
            'return_url' => 'nullable|string|max:1024',
            'gateway' => 'nullable|string|in:zarinpal,sep',
        ]);

        try {
            $payload = $service->startPayment(
                $registration,
                $user,
                $payments,
                $data['return_url'] ?? null,
                $data['gateway'] ?? null
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'payment_url' => $payload['payment_url'],
            'authority' => $payload['authority'] ?? null,
        ], 201);
    }

    public function myRegistrations(Request $request)
    {
        /** @var BilliardUser $user */
        $user = $request->user();
        $items = BilliardRegistration::query()
            ->with('tournament.discipline')
            ->where('player_id', $user->id)
            ->orderByDesc('id')
            ->get()
            ->map(function (BilliardRegistration $r) {
                $row = $r->toApiArray();
                if ($r->tournament && $r->tournament->published_at === null) {
                    $row['group_code'] = null;
                }

                return $row;
            });

        return response(['data' => $items]);
    }

    public function myMatches(Request $request)
    {
        /** @var BilliardUser $user */
        $user = $request->user();
        $items = BilliardMatch::query()
            ->with(['player1', 'player2', 'poolTable', 'tournament'])
            ->whereHas('tournament', fn ($q) => $q->lineupPublic())
            ->where(function ($q) use ($user) {
                $q->where('player1_id', $user->id)->orWhere('player2_id', $user->id);
            })
            ->orderByDesc('scheduled_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (BilliardMatch $m) => $m->toApiArray() + [
                'tournament' => $m->tournament ? [
                    'id' => (int) $m->tournament->id,
                    'name' => $m->tournament->name,
                    'slug' => $m->tournament->slug,
                ] : null,
            ]);

            return response(['data' => $items]);
    }

    public function nationalCompetitions()
    {
        if (! Schema::hasTable('billiard_national_competitions')) {
            return response(['data' => []]);
        }

        $items = BilliardNationalCompetition::query()
            ->with('discipline')
            ->active()
            ->ordered()
            ->get()
            ->map->toApiArray();

        return response(['data' => $items]);
    }

    public function myNationalEntryRequests(Request $request)
    {
        if (! Schema::hasTable('billiard_national_entry_requests')) {
            return response(['data' => []]);
        }

        /** @var BilliardUser $user */
        $user = $request->user();
        $items = BilliardNationalEntryRequest::query()
            ->with('discipline')
            ->where('player_id', $user->id)
            ->orderByDesc('id')
            ->get()
            ->map->toApiArray();

        return response(['data' => $items]);
    }

    public function storeNationalEntryRequest(Request $request)
    {
        if (! Schema::hasTable('billiard_national_entry_requests')) {
            return response()->json([
                'message' => 'جدول درخواست‌های کشوری هنوز ساخته نشده است. SQL مربوط را اجرا کنید.',
            ], 503);
        }

        /** @var BilliardUser $user */
        $user = $request->user();
        $data = $request->validate([
            'national_competition_id' => 'nullable|integer',
            'title' => 'nullable|string|max:255',
            'discipline_id' => 'nullable|integer',
            'note' => 'nullable|string|max:1000',
        ]);

        $competition = null;
        if (! empty($data['national_competition_id'])) {
            $competition = BilliardNationalCompetition::query()
                ->active()
                ->with('discipline')
                ->find((int) $data['national_competition_id']);
            if (! $competition) {
                return response()->json(['message' => 'مسابقهٔ انتخاب‌شده معتبر نیست.'], 422);
            }
        }

        $title = $competition
            ? $competition->title
            : trim((string) ($data['title'] ?? ''));
        $disciplineId = $competition
            ? (int) $competition->discipline_id
            : (int) ($data['discipline_id'] ?? 0);

        if ($title === '') {
            return response()->json(['message' => 'عنوان مسابقه را انتخاب یا وارد کنید.'], 422);
        }
        if ($disciplineId < 1) {
            return response()->json(['message' => 'نوع (رشته) مسابقه را انتخاب کنید.'], 422);
        }

        $discipline = BilliardDiscipline::query()->where('is_active', true)->find($disciplineId);
        if (! $discipline) {
            return response()->json(['message' => 'رشتهٔ انتخاب‌شده معتبر نیست.'], 422);
        }

        $dup = BilliardNationalEntryRequest::query()
            ->where('player_id', $user->id)
            ->where('status', BilliardNationalEntryRequest::STATUS_PENDING)
            ->where(function ($q) use ($competition, $title, $disciplineId) {
                if ($competition) {
                    $q->where('national_competition_id', $competition->id);
                } else {
                    $q->whereNull('national_competition_id')
                        ->where('title', $title)
                        ->where('discipline_id', $disciplineId);
                }
            })
            ->exists();
        if ($dup) {
            return response()->json(['message' => 'برای این مسابقه درخواست باز دارید.'], 422);
        }

        $row = BilliardNationalEntryRequest::create([
            'player_id' => $user->id,
            'national_competition_id' => $competition?->id,
            'title' => $title,
            'discipline_id' => $disciplineId,
            'note' => isset($data['note']) ? trim((string) $data['note']) ?: null : null,
            'status' => BilliardNationalEntryRequest::STATUS_PENDING,
        ]);

        return response([
            'message' => 'درخواست شما ثبت شد.',
            'request' => $row->load('discipline')->toApiArray(),
        ], 201);
    }
}
