<?php

namespace App\Http\Controllers\Billiard;

use App\Http\Controllers\Controller;
use App\Models\BilliardDiscipline;
use App\Models\BilliardMatch;
use App\Models\BilliardNews;
use App\Models\BilliardRankingEntry;
use App\Models\BilliardRegistration;
use App\Models\BilliardTournament;
use App\Models\BilliardUser;
use Illuminate\Http\Request;

class BilliardPublicController extends Controller
{
    public function home()
    {
        $upcoming = $this->tournamentsByStatuses([
            BilliardTournament::STATUS_PRE_REGISTRATION,
            BilliardTournament::STATUS_REGISTRATION_OPEN,
            BilliardTournament::STATUS_REGISTRATION_CLOSED,
            BilliardTournament::STATUS_DRAWN,
        ], 8);

        $live = $this->tournamentsByStatuses([BilliardTournament::STATUS_LIVE], 8);

        $recent = BilliardTournament::query()
            ->with('discipline')
            ->where('status', BilliardTournament::STATUS_FINISHED)
            ->orderByDesc('ends_on')
            ->limit(8)
            ->get()
            ->map(fn (BilliardTournament $t) => $t->toApiArray());

        $latestResults = BilliardMatch::query()
            ->with(['player1', 'player2', 'winner', 'tournament'])
            ->whereIn('status', [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER])
            ->orderByDesc('finished_at')
            ->limit(12)
            ->get()
            ->map(fn (BilliardMatch $m) => $m->toApiArray() + [
                'tournament' => $m->tournament ? [
                    'id' => (int) $m->tournament->id,
                    'name' => $m->tournament->name,
                    'slug' => $m->tournament->slug,
                ] : null,
            ]);

        $disciplines = BilliardDiscipline::query()->where('is_active', true)->orderBy('sort_order')->get();
        $rankings = [];
        foreach ($disciplines as $d) {
            $rankings[] = [
                'discipline' => $d->toApiArray(),
                'entries' => BilliardRankingEntry::query()
                    ->with('player')
                    ->where('discipline_id', $d->id)
                    ->orderBy('rank')
                    ->limit(10)
                    ->get()
                    ->map(fn (BilliardRankingEntry $e) => $e->toApiArray())
                    ->values(),
            ];
        }

        $news = BilliardNews::query()
            ->where('is_published', true)
            ->orderByDesc('published_at')
            ->limit(6)
            ->get()
            ->map(fn (BilliardNews $n) => $n->toApiArray());

        return response([
            'upcoming' => $upcoming,
            'live' => $live,
            'recent' => $recent,
            'latest_results' => $latestResults,
            'rankings' => $rankings,
            'news' => $news,
            'disciplines' => $disciplines->map->toApiArray()->values(),
        ]);
    }

    public function tournaments(Request $request)
    {
        $q = BilliardTournament::query()->with('discipline')->withCount([
            'registrations as registrations_count' => function ($query) {
                $query->activeInTournament();
            },
        ]);

        if ($request->query('for_register') === '1') {
            $q->whereIn('status', [
                BilliardTournament::STATUS_PRE_REGISTRATION,
                BilliardTournament::STATUS_REGISTRATION_OPEN,
                BilliardTournament::STATUS_REGISTRATION_CLOSED,
            ]);
        } elseif ($status = $request->query('status')) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $status))));
            if (count($statuses) > 1) {
                $q->whereIn('status', $statuses);
            } else {
                $q->where('status', $statuses[0] ?? $status);
            }
        }
        if ($discipline = $request->query('discipline_id')) {
            $q->where('discipline_id', (int) $discipline);
        }
        if ($request->query('archive') === '1') {
            $q->where('status', BilliardTournament::STATUS_FINISHED);
        }

        $perPage = min(50, max(10, (int) $request->query('per_page', 20)));
        $items = $q->orderByDesc('starts_on')->orderByDesc('id')->paginate($perPage);

        return response([
            'data' => collect($items->items())->map(fn (BilliardTournament $t) => $t->toApiArray())->values(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function tournament(string $slug)
    {
        $tournament = BilliardTournament::query()
            ->with(['discipline', 'placePoints'])
            ->withCount([
                'registrations as registrations_count' => function ($query) {
                    $query->activeInTournament();
                },
            ])
            ->where('slug', $slug)
            ->firstOrFail();

        $players = BilliardRegistration::query()
            ->with('player')
            ->where('tournament_id', $tournament->id)
            ->activeInTournament()
            ->orderBy('seed')
            ->get()
            ->map(fn (BilliardRegistration $r) => $r->toApiArray());

        $matches = BilliardMatch::query()
            ->with(['player1', 'player2', 'winner', 'poolTable'])
            ->where('tournament_id', $tournament->id)
            ->orderBy('match_no')
            ->orderBy('round')
            ->orderBy('position')
            ->get()
            ->map(fn (BilliardMatch $m) => $m->toApiArray());

        $stats = [
            'participants' => $players->count(),
            'matches_total' => $matches->count(),
            'matches_done' => $matches->whereIn('status', [
                BilliardMatch::STATUS_FINISHED,
                BilliardMatch::STATUS_WALKOVER,
            ])->count(),
            'matches_remaining' => $matches->whereNotIn('status', [
                BilliardMatch::STATUS_FINISHED,
                BilliardMatch::STATUS_WALKOVER,
                BilliardMatch::STATUS_CANCELLED,
            ])->count(),
        ];

        return response([
            'tournament' => $tournament->toApiArray(true),
            'players' => $players,
            'bracket' => $matches,
            'schedule' => $matches->sortBy([
                ['scheduled_on', 'asc'],
                ['scheduled_at', 'asc'],
            ])->values(),
            'results' => $matches->whereIn('status', [
                BilliardMatch::STATUS_FINISHED,
                BilliardMatch::STATUS_WALKOVER,
            ])->values(),
            'stats' => $stats,
        ]);
    }

    public function schedule(Request $request)
    {
        $q = BilliardMatch::query()
            ->with(['player1', 'player2', 'poolTable', 'tournament'])
            ->whereNotNull('scheduled_on');

        if ($tid = $request->query('tournament_id')) {
            $q->where('tournament_id', (int) $tid);
        }
        if ($date = $request->query('date')) {
            $q->whereDate('scheduled_on', $date);
        }

        $items = $q->orderBy('scheduled_on')->orderBy('scheduled_at')->limit(200)->get()
            ->map(fn (BilliardMatch $m) => $m->toApiArray() + [
                'tournament' => $m->tournament ? [
                    'id' => (int) $m->tournament->id,
                    'name' => $m->tournament->name,
                    'slug' => $m->tournament->slug,
                ] : null,
            ]);

        return response(['data' => $items]);
    }

    public function results(Request $request)
    {
        $q = BilliardMatch::query()
            ->with(['player1', 'player2', 'winner', 'tournament'])
            ->whereIn('status', [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER]);

        if ($tid = $request->query('tournament_id')) {
            $q->where('tournament_id', (int) $tid);
        }

        $items = $q->orderByDesc('finished_at')->paginate(30);

        return response([
            'data' => collect($items->items())->map(fn (BilliardMatch $m) => $m->toApiArray() + [
                'tournament' => $m->tournament ? [
                    'id' => (int) $m->tournament->id,
                    'name' => $m->tournament->name,
                    'slug' => $m->tournament->slug,
                ] : null,
            ])->values(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function ranking(Request $request)
    {
        $disciplineId = (int) $request->query('discipline_id');
        $disciplines = BilliardDiscipline::query()->where('is_active', true)->orderBy('sort_order')->get();
        if (! $disciplineId && $disciplines->isNotEmpty()) {
            $disciplineId = (int) $disciplines->first()->id;
        }

        $entries = [];
        if ($disciplineId) {
            $entries = BilliardRankingEntry::query()
                ->with('player')
                ->where('discipline_id', $disciplineId)
                ->orderBy('rank')
                ->limit(100)
                ->get()
                ->map(fn (BilliardRankingEntry $e) => $e->toApiArray())
                ->values();
        }

        return response([
            'disciplines' => $disciplines->map->toApiArray()->values(),
            'discipline_id' => $disciplineId ?: null,
            'entries' => $entries,
        ]);
    }

    public function players(Request $request)
    {
        $q = BilliardUser::query()
            ->where('role', BilliardUser::ROLE_PLAYER)
            ->where('is_active', true);

        if ($search = trim((string) $request->query('q', ''))) {
            $q->where(function ($query) use ($search) {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('club', 'like', "%{$search}%");
            });
        }

        $items = $q->orderBy('last_name')->orderBy('first_name')->paginate(24);
        $disciplineId = (int) $request->query('discipline_id');

        $data = collect($items->items())->map(function (BilliardUser $u) use ($disciplineId) {
            $ranking = null;
            if ($disciplineId) {
                $ranking = BilliardRankingEntry::query()
                    ->where('player_id', $u->id)
                    ->where('discipline_id', $disciplineId)
                    ->first();
            }

            return $u->toPublicArray($ranking);
        });

        return response([
            'data' => $data->values(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function player(int $id)
    {
        $player = BilliardUser::query()
            ->where('role', BilliardUser::ROLE_PLAYER)
            ->where('is_active', true)
            ->findOrFail($id);

        $rankings = BilliardRankingEntry::query()
            ->with('discipline')
            ->where('player_id', $player->id)
            ->get();

        $history = BilliardRegistration::query()
            ->with('tournament.discipline')
            ->where('player_id', $player->id)
            ->activeInTournament()
            ->orderByDesc('id')
            ->get()
            ->map(function (BilliardRegistration $r) use ($player) {
                $matches = BilliardMatch::query()
                    ->with(['player1', 'player2'])
                    ->where('tournament_id', $r->tournament_id)
                    ->where(function ($q) use ($player) {
                        $q->where('player1_id', $player->id)->orWhere('player2_id', $player->id);
                    })
                    ->whereIn('status', [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER])
                    ->orderBy('round')
                    ->get()
                    ->map(function (BilliardMatch $m) use ($player) {
                        $opponent = (int) $m->player1_id === (int) $player->id ? $m->player2 : $m->player1;
                        $won = (int) $m->winner_id === (int) $player->id;

                        return [
                            'match_id' => (int) $m->id,
                            'round_label' => $m->round_label,
                            'opponent' => $opponent ? $opponent->fullName() : null,
                            'result' => $won ? 'برد' : 'باخت',
                            'score' => ($m->score1 ?? '-').' - '.($m->score2 ?? '-'),
                        ];
                    });

                return [
                    'tournament' => $r->tournament ? $r->tournament->toApiArray() : null,
                    'final_place' => $r->final_place,
                    'points_awarded' => (int) $r->points_awarded,
                    'matches' => $matches,
                ];
            });

        $primary = $rankings->sortBy('rank')->first();

        return response([
            'player' => $player->toPublicArray($primary),
            'rankings' => $rankings->map(fn (BilliardRankingEntry $e) => $e->toApiArray() + [
                'discipline' => $e->discipline ? $e->discipline->toApiArray() : null,
            ])->values(),
            'history' => $history->values(),
        ]);
    }

    public function news(Request $request)
    {
        $items = BilliardNews::query()
            ->where('is_published', true)
            ->orderByDesc('published_at')
            ->paginate(20);

        return response([
            'data' => collect($items->items())->map(fn (BilliardNews $n) => $n->toApiArray())->values(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function newsItem(string $slug)
    {
        $item = BilliardNews::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        return response(['news' => $item->toApiArray(true)]);
    }

    public function disciplines()
    {
        $items = BilliardDiscipline::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map->toApiArray();

        return response(['data' => $items]);
    }

    private function tournamentsByStatuses(array $statuses, int $limit)
    {
        return BilliardTournament::query()
            ->with('discipline')
            ->whereIn('status', $statuses)
            ->orderBy('starts_on')
            ->limit($limit)
            ->get()
            ->map(fn (BilliardTournament $t) => $t->toApiArray());
    }
}
