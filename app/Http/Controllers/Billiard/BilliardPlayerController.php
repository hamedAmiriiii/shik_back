<?php

namespace App\Http\Controllers\Billiard;

use App\Http\Controllers\Controller;
use App\Models\BilliardMatch;
use App\Models\BilliardRegistration;
use App\Models\BilliardTournament;
use App\Models\BilliardUser;
use App\Services\Billiard\BilliardRegistrationService;
use App\Services\GatewayPaymentService;
use Illuminate\Http\Request;
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
            ->map(fn (BilliardRegistration $r) => $r->toApiArray());

        return response(['data' => $items]);
    }

    public function myMatches(Request $request)
    {
        /** @var BilliardUser $user */
        $user = $request->user();
        $items = BilliardMatch::query()
            ->with(['player1', 'player2', 'table', 'tournament'])
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
}
