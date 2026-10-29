<?php

namespace App\Http\Controllers\Billiard;

use App\Http\Controllers\Controller;
use App\Models\BilliardDiscipline;
use App\Models\BilliardMatch;
use App\Models\BilliardNews;
use App\Models\BilliardRankingPlacePoint;
use App\Models\BilliardRankingSetting;
use App\Models\BilliardRegistration;
use App\Models\BilliardTable;
use App\Models\BilliardTournament;
use App\Models\BilliardUser;
use App\Services\Billiard\BilliardBracketService;
use App\Services\Billiard\BilliardRankingService;
use App\Services\Billiard\BilliardRegistrationService;
use App\Tools\ImageTools;
use App\Tools\PhoneTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class BilliardAdminController extends Controller
{
    public function dashboard()
    {
        return response([
            'players' => BilliardUser::query()->where('role', BilliardUser::ROLE_PLAYER)->count(),
            'tournaments_live' => BilliardTournament::query()->where('status', BilliardTournament::STATUS_LIVE)->count(),
            'tournaments_open' => BilliardTournament::query()->where('status', BilliardTournament::STATUS_REGISTRATION_OPEN)->count(),
            'pending_registrations' => BilliardRegistration::query()->where('status', BilliardRegistration::STATUS_PENDING)->count(),
        ]);
    }

    // ——— Disciplines ———
    public function disciplines()
    {
        return response([
            'data' => BilliardDiscipline::query()->orderBy('sort_order')->get()->map->toApiArray(),
        ]);
    }

    public function storeDiscipline(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'slug' => 'nullable|string|max:120',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);
        $data['slug'] = $this->disciplineSlug($data['slug'] ?? null, $data['name']);
        if (BilliardDiscipline::query()->where('slug', $data['slug'])->exists()) {
            return response()->json(['message' => 'این رشته قبلاً ثبت شده است.'], 422);
        }
        $data['is_active'] = $data['is_active'] ?? true;
        $data['sort_order'] = $data['sort_order'] ?? ((int) BilliardDiscipline::query()->max('sort_order') + 1);
        $item = BilliardDiscipline::create($data);

        return response(['discipline' => $item->toApiArray()], 201);
    }

    public function updateDiscipline(Request $request, BilliardDiscipline $discipline)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'slug' => 'sometimes|string|max:120',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);
        if (array_key_exists('slug', $data) || array_key_exists('name', $data)) {
            $data['slug'] = $this->disciplineSlug(
                $data['slug'] ?? $discipline->slug,
                $data['name'] ?? $discipline->name
            );
            if (BilliardDiscipline::query()->where('slug', $data['slug'])->where('id', '!=', $discipline->id)->exists()) {
                return response()->json(['message' => 'این اسلاگ قبلاً استفاده شده است.'], 422);
            }
        }
        $discipline->update($data);

        return response(['discipline' => $discipline->fresh()->toApiArray()]);
    }

    private function disciplineSlug(?string $slug, string $name): string
    {
        $slug = trim((string) $slug);
        if ($slug !== '') {
            return Str::slug($slug) ?: $slug;
        }
        $fromName = Str::slug($name);
        if ($fromName !== '') {
            return $fromName;
        }
        $map = [
            'اسنوکر' => 'snooker',
            'پاکت' => 'pocket',
            '۸ توپ' => 'eight-ball',
            '8 توپ' => 'eight-ball',
            '۹ توپ' => 'nine-ball',
            '9 توپ' => 'nine-ball',
        ];

        return $map[$name] ?? ('discipline-'.Str::lower(Str::random(6)));
    }

    // ——— Players / Users ———
    public function players(Request $request)
    {
        $q = BilliardUser::query()->orderByDesc('id');
        if ($role = $request->query('role')) {
            $q->where('role', $role);
        } else {
            $q->where('role', BilliardUser::ROLE_PLAYER);
        }
        if ($search = trim((string) $request->query('q', ''))) {
            $q->where(function ($query) use ($search) {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('national_id', 'like', "%{$search}%");
            });
        }
        $items = $q->paginate(30);

        return response([
            'data' => collect($items->items())->map(fn (BilliardUser $u) => $u->toAdminArray())->values(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function storePlayer(Request $request)
    {
        $data = $request->validate([
            'role' => 'nullable|in:admin,operator,player',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'national_id' => 'nullable|string|max:20',
            'birth_date' => 'nullable|date',
            'city' => 'nullable|string|max:100',
            'club' => 'nullable|string|max:150',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'boolean',
            'membership_status' => 'nullable|string|max:30',
            'photo_base64' => 'nullable|string',
        ]);

        $phone = PhoneTools::normalizeIranPhone($data['phone']);
        if (! PhoneTools::isValidIranMobile($phone)) {
            return response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422);
        }
        if (BilliardUser::query()->where('phone', $phone)->exists()) {
            return response()->json(['message' => 'این شماره قبلاً ثبت شده است.'], 422);
        }

        $nid = isset($data['national_id']) ? (preg_replace('/\D/', '', $data['national_id']) ?: null) : null;
        unset($data['photo_base64'], $data['phone'], $data['national_id']);
        $user = BilliardUser::create($data + [
            'phone' => $phone,
            'national_id' => $nid,
            'role' => $data['role'] ?? BilliardUser::ROLE_PLAYER,
            'membership_at' => now(),
        ]);

        if ($request->filled('photo_base64')) {
            try {
                $user->storePhoto($request->input('photo_base64'));
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response(['player' => $user->fresh()->toAdminArray()], 201);
    }

    public function updatePlayer(Request $request, BilliardUser $player)
    {
        $data = $request->validate([
            'role' => 'sometimes|in:admin,operator,player',
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'phone' => 'sometimes|string|max:20',
            'national_id' => 'nullable|string|max:20',
            'birth_date' => 'nullable|date',
            'city' => 'nullable|string|max:100',
            'club' => 'nullable|string|max:150',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'boolean',
            'membership_status' => 'nullable|string|max:30',
            'photo_base64' => 'nullable|string',
        ]);

        if (isset($data['phone'])) {
            $phone = PhoneTools::normalizeIranPhone($data['phone']);
            if (! PhoneTools::isValidIranMobile($phone)) {
                return response()->json(['message' => 'شماره موبایل معتبر نیست.'], 422);
            }
            if (BilliardUser::query()->where('phone', $phone)->where('id', '!=', $player->id)->exists()) {
                return response()->json(['message' => 'این شماره قبلاً ثبت شده است.'], 422);
            }
            $data['phone'] = $phone;
        }
        if (array_key_exists('national_id', $data)) {
            $data['national_id'] = preg_replace('/\D/', '', (string) ($data['national_id'] ?? '')) ?: null;
        }
        unset($data['photo_base64']);
        $player->update($data);

        if ($request->filled('photo_base64')) {
            try {
                $player->storePhoto($request->input('photo_base64'));
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response(['player' => $player->fresh()->toAdminArray()]);
    }

    // ——— Tournaments ———
    public function tournaments(Request $request)
    {
        $q = BilliardTournament::query()->with('discipline')->orderByDesc('id');
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        $items = $q->paginate(30);

        return response([
            'data' => collect($items->items())->map(fn (BilliardTournament $t) => $t->toApiArray(true))->values(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function storeTournament(Request $request)
    {
        $data = $this->validatedTournament($request);
        $data['slug'] = BilliardTournament::makeUniqueSlug($data['name']);
        $placePoints = $data['place_points'] ?? $this->defaultPlacePoints();
        unset($data['place_points'], $data['poster_base64']);

        $tournament = BilliardTournament::create($data);
        $this->syncPlacePoints($tournament, $placePoints);
        $this->maybeStorePoster($request, $tournament);

        return response([
            'tournament' => $tournament->fresh(['discipline', 'placePoints'])->toApiArray(true),
        ], 201);
    }

    public function updateTournament(Request $request, BilliardTournament $tournament)
    {
        $data = $this->validatedTournament($request, false);
        if (isset($data['name']) && empty($data['slug'])) {
            // keep slug unless provided
        }
        if (! empty($data['slug'])) {
            $data['slug'] = BilliardTournament::makeUniqueSlug($data['slug'], $tournament->id);
        }
        $placePoints = $data['place_points'] ?? null;
        unset($data['place_points'], $data['poster_base64']);
        $tournament->update($data);
        if (is_array($placePoints)) {
            $this->syncPlacePoints($tournament, $placePoints);
        }
        $this->maybeStorePoster($request, $tournament);

        return response([
            'tournament' => $tournament->fresh(['discipline', 'placePoints'])->toApiArray(true),
        ]);
    }

    // ——— Tables ———
    public function tables(BilliardTournament $tournament)
    {
        return response([
            'data' => $tournament->tables()->get()->map->toApiArray(),
        ]);
    }

    public function storeTable(Request $request, BilliardTournament $tournament)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'sort_order' => 'integer|min:0',
            'is_active' => 'boolean',
        ]);
        $table = $tournament->tables()->create($data);

        return response(['table' => $table->toApiArray()], 201);
    }

    public function updateTable(Request $request, BilliardTable $table)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:80',
            'sort_order' => 'integer|min:0',
            'is_active' => 'boolean',
        ]);
        $table->update($data);

        return response(['table' => $table->fresh()->toApiArray()]);
    }

    public function destroyTable(BilliardTable $table)
    {
        $table->delete();

        return response(['message' => 'حذف شد.']);
    }

    // ——— Registrations ———
    public function registrations(Request $request)
    {
        $q = BilliardRegistration::query()->with(['player', 'tournament'])->orderByDesc('id');
        if ($tid = $request->query('tournament_id')) {
            $q->where('tournament_id', (int) $tid);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        $items = $q->paginate(40);

        return response([
            'data' => collect($items->items())->map(fn (BilliardRegistration $r) => $r->toApiArray())->values(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function confirmRegistration(BilliardRegistration $registration, BilliardRegistrationService $service)
    {
        return response(['registration' => $service->confirm($registration)->toApiArray()]);
    }

    public function rejectRegistration(BilliardRegistration $registration, BilliardRegistrationService $service)
    {
        return response(['registration' => $service->reject($registration)->toApiArray()]);
    }

    public function updateRegistrationSeed(Request $request, BilliardRegistration $registration)
    {
        $data = $request->validate(['seed' => 'nullable|integer|min:1|max:256']);
        $registration->update(['seed' => $data['seed'] ?? null]);

        return response(['registration' => $registration->fresh(['player', 'tournament'])->toApiArray()]);
    }

    // ——— Draw / Matches ———
    public function draw(Request $request, BilliardTournament $tournament, BilliardBracketService $bracket)
    {
        $data = $request->validate([
            'player_ids' => 'nullable|array',
            'player_ids.*' => 'integer',
            'shuffle' => 'boolean',
        ]);
        try {
            $bracket->draw(
                $tournament,
                $data['player_ids'] ?? null,
                $data['shuffle'] ?? true
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $matches = BilliardMatch::query()
            ->with(['player1', 'player2', 'table'])
            ->where('tournament_id', $tournament->id)
            ->orderBy('round')
            ->orderBy('position')
            ->get()
            ->map->toApiArray();

        return response([
            'tournament' => $tournament->fresh()->toApiArray(),
            'bracket' => $matches,
        ]);
    }

    public function scheduleMatch(Request $request, BilliardMatch $match, BilliardBracketService $bracket)
    {
        $data = $request->validate([
            'table_id' => 'nullable|integer',
            'scheduled_on' => 'nullable|date',
            'scheduled_at' => 'nullable|date_format:H:i',
        ]);
        $updated = $bracket->scheduleMatch(
            $match,
            $data['table_id'] ?? null,
            $data['scheduled_on'] ?? null,
            $data['scheduled_at'] ?? null
        );

        return response(['match' => $updated->toApiArray()]);
    }

    public function recordResult(Request $request, BilliardMatch $match, BilliardBracketService $bracket)
    {
        $data = $request->validate([
            'score1' => 'required|integer|min:0|max:99',
            'score2' => 'required|integer|min:0|max:99',
            'notes' => 'nullable|string|max:1000',
            'walkover' => 'boolean',
        ]);
        /** @var BilliardUser $user */
        $user = $request->user();
        try {
            $updated = $bracket->recordResult(
                $match,
                (int) $data['score1'],
                (int) $data['score2'],
                $user->id,
                $data['notes'] ?? null,
                (bool) ($data['walkover'] ?? false)
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['match' => $updated->toApiArray()]);
    }

    // ——— Ranking settings ———
    public function rankingSettings()
    {
        return response([
            'last_tournaments' => BilliardRankingSetting::lastTournamentsCount(),
        ]);
    }

    public function updateRankingSettings(Request $request, BilliardRankingService $ranking)
    {
        $data = $request->validate([
            'last_tournaments' => 'required|integer|min:1|max:50',
            'recompute_discipline_id' => 'nullable|integer',
        ]);
        BilliardRankingSetting::put('last_tournaments', $data['last_tournaments']);
        if (! empty($data['recompute_discipline_id'])) {
            $ranking->recomputeForDiscipline((int) $data['recompute_discipline_id']);
        }

        return response(['last_tournaments' => BilliardRankingSetting::lastTournamentsCount()]);
    }

    public function recomputeRanking(Request $request, BilliardRankingService $ranking)
    {
        $data = $request->validate(['discipline_id' => 'required|integer']);
        $ranking->recomputeForDiscipline((int) $data['discipline_id']);

        return response(['message' => 'رنکینگ به‌روز شد.']);
    }

    // ——— News ———
    public function newsAdmin()
    {
        $items = BilliardNews::query()->orderByDesc('id')->limit(100)->get()->map(fn (BilliardNews $n) => $n->toApiArray(true));

        return response(['data' => $items]);
    }

    public function storeNews(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
            'cover_base64' => 'nullable|string',
        ]);
        $item = BilliardNews::create([
            'title' => $data['title'],
            'slug' => BilliardNews::makeUniqueSlug($data['title']),
            'body' => $data['body'],
            'is_published' => array_key_exists('is_published', $data) ? (bool) $data['is_published'] : true,
            'published_at' => $data['published_at'] ?? now(),
        ]);

        if ($request->filled('cover_base64')) {
            try {
                $item->storeCover($request->input('cover_base64'));
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response(['news' => $item->fresh()->toApiArray(true)], 201);
    }

    public function updateNews(Request $request, BilliardNews $news)
    {
        $data = $request->validate([
            'title' => 'sometimes|string|max:200',
            'body' => 'sometimes|string',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
            'cover_base64' => 'nullable|string',
            'remove_cover' => 'boolean',
        ]);
        unset($data['cover_base64'], $data['remove_cover']);
        $news->update($data);

        if ($request->boolean('remove_cover')) {
            $news->clearCover();
        } elseif ($request->filled('cover_base64')) {
            try {
                $news->storeCover($request->input('cover_base64'));
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response(['news' => $news->fresh()->toApiArray(true)]);
    }

    public function destroyNews(BilliardNews $news)
    {
        $news->clearCover();
        $news->delete();

        return response(['message' => 'حذف شد.']);
    }

    private function validatedTournament(Request $request, bool $creating = true): array
    {
        $rules = [
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:200',
            'slug' => 'nullable|string|max:200',
            'discipline_id' => ($creating ? 'required' : 'sometimes').'|integer|exists:billiard_disciplines,id',
            'format' => 'nullable|in:single_elimination',
            'venue' => 'nullable|string|max:200',
            'host_club' => 'nullable|string|max:200',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date',
            'starts_at_time' => 'nullable|date_format:H:i',
            'capacity' => 'nullable|integer|min:2|max:512',
            'fee_toman' => 'nullable|integer|min:0',
            'registration_deadline' => 'nullable|date',
            'description' => 'nullable|string',
            'rules' => 'nullable|string',
            'status' => 'nullable|in:'.implode(',', BilliardTournament::statuses()),
            'place_points' => 'nullable|array',
            'place_points.*.place' => 'required_with:place_points|integer|min:1|max:64',
            'place_points.*.points' => 'required_with:place_points|integer|min:0',
            'poster_base64' => 'nullable|string',
        ];

        $data = $request->validate($rules);
        $data['format'] = $data['format'] ?? BilliardTournament::FORMAT_SINGLE_ELIMINATION;
        $data['fee_toman'] = $data['fee_toman'] ?? 0;
        $data['status'] = $data['status'] ?? BilliardTournament::STATUS_PRE_REGISTRATION;

        return $data;
    }

    private function defaultPlacePoints(): array
    {
        return [
            ['place' => 1, 'points' => 1000],
            ['place' => 2, 'points' => 700],
            ['place' => 3, 'points' => 500],
            ['place' => 4, 'points' => 350],
        ];
    }

    private function syncPlacePoints(BilliardTournament $tournament, array $points): void
    {
        BilliardRankingPlacePoint::query()->where('tournament_id', $tournament->id)->delete();
        foreach ($points as $row) {
            BilliardRankingPlacePoint::create([
                'tournament_id' => $tournament->id,
                'place' => (int) $row['place'],
                'points' => (int) $row['points'],
            ]);
        }
    }

    private function maybeStorePoster(Request $request, BilliardTournament $tournament): void
    {
        if (! $request->filled('poster_base64')) {
            return;
        }
        $raw = $request->input('poster_base64');
        if (strpos($raw, ',') !== false) {
            $raw = substr($raw, strpos($raw, ',') + 1);
        }
        $content = base64_decode($raw, true);
        if ($content === false) {
            return;
        }
        $info = @getimagesizefromstring($content);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? 'jpg';
        $old = $tournament->poster_path;
        $path = ImageTools::saveFile("/billiard-tournaments/{$tournament->id}/poster_".time().'.'.$ext, $content);
        $tournament->update(['poster_path' => $path]);
        if ($old && $old !== $path && Storage::exists('public/'.$old)) {
            Storage::delete('public/'.$old);
        }
    }
}
