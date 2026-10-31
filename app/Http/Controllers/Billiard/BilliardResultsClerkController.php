<?php

namespace App\Http\Controllers\Billiard;

use App\Http\Controllers\Controller;
use App\Models\BilliardMatch;
use App\Models\BilliardTournament;
use App\Models\BilliardUser;
use App\Services\Billiard\BilliardBracketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BilliardResultsClerkController extends Controller
{
    public function tournaments(Request $request)
    {
        /** @var BilliardUser $user */
        $user = $request->user();

        return response(['data' => $user->resultsClerkTournaments()]);
    }

    public function matches(Request $request, BilliardTournament $tournament)
    {
        $this->assertClerk($request, $tournament);

        $matches = BilliardMatch::query()
            ->with(['player1', 'player2', 'poolTable'])
            ->where('tournament_id', $tournament->id)
            ->orderByRaw('scheduled_on is null')
            ->orderBy('scheduled_on')
            ->orderBy('scheduled_at')
            ->orderBy('match_no')
            ->get()
            ->map->toApiArray()
            ->values();

        return response([
            'tournament' => [
                'id' => (int) $tournament->id,
                'name' => $tournament->name,
            ],
            'data' => $matches,
        ]);
    }

    public function recordResult(Request $request, BilliardMatch $match, BilliardBracketService $bracket)
    {
        $tournament = $this->tournamentOf($match);
        $this->assertClerk($request, $tournament);
        $data = $request->validate([
            'score1' => 'required|integer|min:0|max:99',
            'score2' => 'required|integer|min:0|max:99',
        ]);
        /** @var BilliardUser $user */
        $user = $request->user();
        try {
            $updated = $bracket->recordResult(
                $match,
                (int) $data['score1'],
                (int) $data['score2'],
                $user->id
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['match' => $updated->toApiArray()]);
    }

    public function clearResult(Request $request, BilliardMatch $match, BilliardBracketService $bracket)
    {
        $this->assertClerk($request, $this->tournamentOf($match));
        try {
            $updated = $bracket->clearResult($match);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['match' => $updated->toApiArray()]);
    }

    private function tournamentOf(BilliardMatch $match): BilliardTournament
    {
        $tournament = $match->relationLoaded('tournament') ? $match->tournament : $match->tournament()->first();
        if (! $tournament) {
            abort(404);
        }

        return $tournament;
    }

    private function assertClerk(Request $request, BilliardTournament $tournament): void
    {
        if (! Schema::hasColumn('billiard_tournaments', 'results_clerk_phone')) {
            abort(response()->json(['message' => 'ثبت مسئول نتایج هنوز روی دیتابیس فعال نشده.'], 503));
        }
        /** @var BilliardUser $user */
        $user = $request->user();
        if (! $tournament->results_clerk_phone || $tournament->results_clerk_phone !== $user->phone) {
            abort(response()->json(['message' => 'به ثبت نتیجهٔ این مسابقه دسترسی ندارید.'], 403));
        }
    }
}
