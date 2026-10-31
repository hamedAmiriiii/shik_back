<?php

namespace App\Http\Controllers\Billiard;

use App\Http\Controllers\Controller;
use App\Models\BilliardDiscipline;
use App\Models\BilliardMatch;
use App\Models\BilliardNews;
use App\Models\BilliardRankingEntry;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BilliardAdminController extends Controller
{
    public function dashboard()
    {
        return response([
            'players' => BilliardUser::query()->where('role', BilliardUser::ROLE_PLAYER)->count(),
            'tournaments_live' => BilliardTournament::query()->where('status', BilliardTournament::STATUS_LIVE)->count(),
            'tournaments_open' => BilliardTournament::query()->where('status', BilliardTournament::STATUS_REGISTRATION_OPEN)->count(),
            'pending_registrations' => BilliardRegistration::query()
                ->where('status', BilliardRegistration::STATUS_PENDING)
                ->whereIn('payment_status', [
                    BilliardRegistration::PAYMENT_PAID,
                    BilliardRegistration::PAYMENT_WAIVED,
                ])
                ->count(),
            'awaiting_payment' => BilliardRegistration::query()
                ->whereNotIn('status', [
                    BilliardRegistration::STATUS_CANCELLED,
                    BilliardRegistration::STATUS_REJECTED,
                ])
                ->whereNotIn('payment_status', [
                    BilliardRegistration::PAYMENT_PAID,
                    BilliardRegistration::PAYMENT_WAIVED,
                ])
                ->count(),
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
        $perPage = min(200, max(10, (int) $request->query('per_page', 30)));
        $items = $q->paginate($perPage);

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

    /**
     * ورود گروهی بازیکن از اکسل — مثل bulk کالا:
     * اگر موبایل وجود داشته باشد به‌روزرسانی، وگرنه ثبت جدید.
     */
    public function bulkStorePlayers(Request $request)
    {
        $data = $request->validate([
            'players' => 'required|array|min:1|max:2000',
            'players.*.first_name' => 'required|string|max:100',
            'players.*.last_name' => 'required|string|max:100',
            'players.*.phone' => 'required|string|max:20',
            'players.*.city' => 'nullable|string|max:100',
            'players.*.club' => 'nullable|string|max:150',
            'players.*.national_id' => 'nullable|string|max:20',
        ]);

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($data['players'] as $index => $row) {
            $rowNum = $index + 1;
            $phone = PhoneTools::normalizeIranPhone($row['phone'] ?? '');
            if (! PhoneTools::isValidIranMobile($phone)) {
                $errors[] = "ردیف {$rowNum}: شماره موبایل معتبر نیست.";
                continue;
            }

            $nid = isset($row['national_id'])
                ? (preg_replace('/\D/', '', (string) $row['national_id']) ?: null)
                : null;

            $payload = [
                'first_name' => trim((string) $row['first_name']),
                'last_name' => trim((string) $row['last_name']),
                'city' => isset($row['city']) ? (trim((string) $row['city']) ?: null) : null,
                'club' => isset($row['club']) ? (trim((string) $row['club']) ?: null) : null,
                'national_id' => $nid,
            ];

            $existing = BilliardUser::query()->where('phone', $phone)->first();
            if ($existing) {
                // ادمین/اپراتور را با ایمپورت بازیکن بازنویسی نکن
                if ($existing->isStaff()) {
                    $errors[] = "ردیف {$rowNum}: شماره {$phone} متعلق به کاربر سیستمی است.";
                    continue;
                }
                $existing->update($payload + [
                    'role' => BilliardUser::ROLE_PLAYER,
                    'is_active' => true,
                ]);
                $updated++;
                continue;
            }

            BilliardUser::create($payload + [
                'phone' => $phone,
                'role' => BilliardUser::ROLE_PLAYER,
                'membership_at' => now(),
                'is_active' => true,
            ]);
            $created++;
        }

        return response([
            'message' => "پردازش شد: {$created} ثبت جدید، {$updated} به‌روزرسانی"
                .($errors !== [] ? ' — '.count($errors).' خطا' : ''),
            'created_count' => $created,
            'updated_count' => $updated,
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 50),
        ]);
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

    public function destroyPlayer(BilliardUser $player)
    {
        if ($player->isStaff()) {
            return response()->json(['message' => 'کاربر ادمین/اپراتور قابل حذف نیست.'], 422);
        }

        $hasRegs = $player->registrations()->exists();
        $hasMatches = BilliardMatch::query()
            ->where(function ($q) use ($player) {
                $q->where('player1_id', $player->id)
                    ->orWhere('player2_id', $player->id)
                    ->orWhere('winner_id', $player->id);
            })
            ->exists();
        $hasRanking = BilliardRankingEntry::query()->where('player_id', $player->id)->exists();

        if ($hasRegs || $hasMatches || $hasRanking) {
            return response()->json([
                'message' => 'این بازیکن در مسابقه یا رنکینگ سابقه دارد؛ به‌جای حذف، غیرفعال کنید.',
            ], 422);
        }

        if ($player->photo_path && Storage::exists('public/'.$player->photo_path)) {
            Storage::delete('public/'.$player->photo_path);
        }
        $player->tokens()->delete();
        $player->delete();

        return response(['message' => 'بازیکن حذف شد.']);
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
            'data' => collect($items->items())->map(fn (BilliardTournament $t) => $this->tournamentAdminArray($t))->values(),
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
        try {
            $this->maybeStorePoster($request, $tournament);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'tournament' => $this->tournamentAdminArray($tournament->fresh(['discipline', 'placePoints'])),
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
        unset($data['place_points'], $data['poster_base64'], $data['remove_poster']);
        $tournament->update($data);
        if (is_array($placePoints)) {
            $this->syncPlacePoints($tournament, $placePoints);
        }
        try {
            if ($request->boolean('remove_poster')) {
                $this->clearPoster($tournament);
            } else {
                $this->maybeStorePoster($request, $tournament);
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'tournament' => $this->tournamentAdminArray($tournament->fresh(['discipline', 'placePoints'])),
        ]);
    }

    public function publishTournament(Request $request, BilliardTournament $tournament)
    {
        $data = $request->validate([
            'published' => 'required|boolean',
        ]);
        $published = (bool) $data['published'];
        $tournament->update([
            'published_at' => $published ? ($tournament->published_at ?: now()) : null,
        ]);

        return response([
            'message' => $published
                ? 'بازیکنان، گروه‌بندی و ساعت بازی‌ها برای عموم منتشر شد.'
                : 'بازیکنان، گروه‌بندی و ساعت بازی‌ها از دید عموم برداشته شد.',
            'tournament' => $tournament->fresh(['discipline'])->toApiArray(true),
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
        BilliardMatch::query()
            ->where('table_id', $table->id)
            ->update(['table_id' => null]);

        $table->delete();

        return response(['message' => 'حذف شد.']);
    }

    // ——— Registrations ———
    public function registrations(Request $request)
    {
        $q = BilliardRegistration::query()->with(['player', 'tournament'])->orderByDesc('id');
        if ($tid = $request->query('tournament_id')) {
            BilliardBracketService::useNumericGroupCodes((int) $tid);
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

    public function addTournamentPlayer(
        Request $request,
        BilliardTournament $tournament,
        BilliardRegistrationService $service
    ) {
        $data = $request->validate([
            'player_id' => 'required|integer|exists:billiard_users,id',
            'waive_fee' => 'boolean',
        ]);

        $player = BilliardUser::query()->findOrFail((int) $data['player_id']);

        try {
            $registration = $service->adminAddPlayer(
                $tournament,
                $player,
                (bool) ($data['waive_fee'] ?? true)
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['registration' => $registration->toApiArray()], 201);
    }

    /**
     * افزودن دسته‌جمعی بازیکن به مسابقه.
     */
    public function bulkAddTournamentPlayers(
        Request $request,
        BilliardTournament $tournament,
        BilliardRegistrationService $service
    ) {
        $data = $request->validate([
            'player_ids' => 'required|array|min:1|max:500',
            'player_ids.*' => 'integer|exists:billiard_users,id',
            'waive_fee' => 'boolean',
        ]);

        $waive = (bool) ($data['waive_fee'] ?? true);
        $added = 0;
        $skipped = 0;
        $errors = [];

        $ids = array_values(array_unique(array_map('intval', $data['player_ids'])));
        $players = BilliardUser::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $pid) {
            $player = $players->get($pid);
            if (! $player) {
                $errors[] = "بازیکن #{$pid} یافت نشد.";
                $skipped++;
                continue;
            }
            try {
                $service->adminAddPlayer($tournament, $player, $waive);
                $added++;
            } catch (RuntimeException $e) {
                $skipped++;
                $errors[] = ($player->fullName() ?: "بازیکن #{$pid}").': '.$e->getMessage();
            }
        }

        $message = "{$added} بازیکن اضافه شد"
            .($skipped > 0 ? " — {$skipped} رد شد" : '')
            .'.';

        if ($added === 0 && $skipped > 0) {
            return response()->json([
                'message' => $errors[0] ?? 'هیچ بازیکنی اضافه نشد.',
                'added_count' => 0,
                'skipped_count' => $skipped,
                'errors' => array_slice($errors, 0, 30),
            ], 422);
        }

        return response([
            'message' => $message,
            'added_count' => $added,
            'skipped_count' => $skipped,
            'errors' => array_slice($errors, 0, 30),
        ], 201);
    }

    public function confirmRegistration(BilliardRegistration $registration, BilliardRegistrationService $service)
    {
        try {
            return response(['registration' => $service->confirm($registration)->toApiArray()]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function rejectRegistration(BilliardRegistration $registration, BilliardRegistrationService $service)
    {
        return response(['registration' => $service->reject($registration)->toApiArray()]);
    }

    public function destroyRegistration(BilliardRegistration $registration)
    {
        $registration->delete();

        return response(['message' => 'ثبت‌نام حذف شد.']);
    }

    public function bulkRemoveRegistrations(Request $request, BilliardTournament $tournament)
    {
        $data = $request->validate([
            'registration_ids' => 'required|array|min:1|max:500',
            'registration_ids.*' => 'integer',
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['registration_ids'])));
        $deleted = BilliardRegistration::query()
            ->where('tournament_id', $tournament->id)
            ->whereIn('id', $ids)
            ->delete();

        return response([
            'message' => $deleted.' ثبت‌نام حذف شد.',
            'deleted' => $deleted,
        ]);
    }

    public function updateRegistrationSeed(Request $request, BilliardRegistration $registration)
    {
        $data = $request->validate(['seed' => 'nullable|integer|min:1|max:256']);
        $registration->update(['seed' => $data['seed'] ?? null]);

        return response(['registration' => $registration->fresh(['player', 'tournament'])->toApiArray()]);
    }

    // ——— Bracket / Matches ———
    public function tournamentMatches(BilliardTournament $tournament)
    {
        BilliardBracketService::useNumericGroupCodes((int) $tournament->id);

        return response([
            'data' => $this->bracketPayload($tournament->id),
        ]);
    }

    /** ساخت جدول خالی حذفی (بدون قرعه‌کشی). */
    public function buildBracket(Request $request, BilliardTournament $tournament, BilliardBracketService $bracket)
    {
        $data = $request->validate([
            'bracket_size' => 'nullable|integer|min:2|max:256',
            'phase' => 'nullable|in:knockout,after_groups',
        ]);
        try {
            if (($data['phase'] ?? 'knockout') === 'after_groups') {
                $size = (int) ($data['bracket_size'] ?? 0);
                if ($size < 2) {
                    return response()->json(['message' => 'تعداد جایگاه حذفی لازم است.'], 422);
                }
                $bracket->buildKnockoutAfterGroups($tournament, $size);
            } else {
                if ($tournament->format === BilliardTournament::FORMAT_GROUP_THEN_ELIMINATION
                    && empty($data['bracket_size'])) {
                    return response()->json([
                        'message' => 'برای فرمت گروهی→حذفی ابتدا بازی‌های گروهی را بسازید، سپس مرحله حذفی را با phase=after_groups ایجاد کنید.',
                    ], 422);
                }
                $bracket->buildEmptyElimination($tournament, $data['bracket_size'] ?? null);
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'tournament' => $tournament->fresh()->toApiArray(),
            'bracket' => $this->bracketPayload($tournament->id),
        ]);
    }

    /** سازگاری قدیمی — همان ساخت جدول خالی */
    public function draw(Request $request, BilliardTournament $tournament, BilliardBracketService $bracket)
    {
        return $this->buildBracket($request, $tournament, $bracket);
    }

    public function assignMatchSlot(Request $request, BilliardMatch $match, BilliardBracketService $bracket)
    {
        $data = $request->validate([
            'slot' => 'required|integer|in:1,2',
            'type' => 'nullable|in:player,source,empty',
            'player_id' => 'nullable|integer',
            'match_id' => 'nullable|integer',
            'match_no' => 'nullable|integer|min:1',
            'outcome' => 'nullable|in:winner,loser',
        ]);
        try {
            $type = $data['type'] ?? 'player';
            if ($type === 'source') {
                $updated = $bracket->assignSlotSource(
                    $match,
                    (int) $data['slot'],
                    isset($data['match_id']) ? (int) $data['match_id'] : null,
                    isset($data['match_no']) ? (int) $data['match_no'] : null,
                    $data['outcome'] ?? 'winner'
                );
            } elseif ($type === 'empty') {
                $updated = $bracket->assignSlot($match, (int) $data['slot'], null);
            } else {
                $playerId = array_key_exists('player_id', $data)
                    ? ($data['player_id'] !== null ? (int) $data['player_id'] : null)
                    : null;
                $updated = $bracket->assignSlot($match, (int) $data['slot'], $playerId);
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['match' => $updated->toApiArray()]);
    }

    /** تعداد گروه‌ها را ست می‌کند. */
    public function setTournamentGroups(Request $request, BilliardTournament $tournament)
    {
        $data = $request->validate([
            'group_count' => 'required|integer|min:1|max:16',
        ]);
        $tournament->update([
            'group_count' => (int) $data['group_count'],
            'format' => BilliardTournament::FORMAT_GROUP_THEN_ELIMINATION,
        ]);

        // گروه‌ها با شماره ۱، ۲، ۳ ذخیره می‌شوند. حروف قدیمی A… به همان شماره برمی‌گردند.
        BilliardBracketService::useNumericGroupCodes((int) $tournament->id);
        $codes = [];
        for ($i = 0; $i < (int) $data['group_count']; $i++) {
            $codes[] = (string) ($i + 1);
        }
        BilliardRegistration::query()
            ->where('tournament_id', $tournament->id)
            ->whereNotNull('group_code')
            ->whereNotIn('group_code', $codes)
            ->update(['group_code' => null]);

        return response([
            'tournament' => $tournament->fresh(['discipline', 'placePoints'])->toApiArray(true),
            'group_codes' => $codes,
        ]);
    }

    /** تخصیص بازیکنان به یک گروه (جایگزینی لیست همان گروه). */
    public function assignGroupPlayers(Request $request, BilliardTournament $tournament)
    {
        $data = $request->validate([
            'group_code' => 'required|string|max:8',
            'player_ids' => 'present|array',
            'player_ids.*' => 'integer',
        ]);
        $code = BilliardBracketService::numericGroupCode((string) $data['group_code']);
        $ids = array_values(array_unique(array_map('intval', $data['player_ids'])));

        // اول همه اعضای این گروه را خالی کن، بعد لیست جدید را بنشان
        BilliardRegistration::query()
            ->where('tournament_id', $tournament->id)
            ->where('group_code', $code)
            ->update(['group_code' => null]);

        if ($ids !== []) {
            $updated = BilliardRegistration::query()
                ->where('tournament_id', $tournament->id)
                ->whereIn('player_id', $ids)
                ->activeInTournament()
                ->update(['group_code' => $code]);

            if ($updated < count($ids)) {
                // بعضی idها ثبت‌نام قطعی ندارند — فقط موجودها ست شدند
            }
        }

        $regs = BilliardRegistration::query()
            ->with('player')
            ->where('tournament_id', $tournament->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (BilliardRegistration $r) => $r->toApiArray())
            ->values();

        return response([
            'message' => 'اعضای گروه '.$code.' به‌روز شد.',
            'data' => $regs,
        ]);
    }

    public function saveGroupMatch(Request $request, BilliardTournament $tournament, BilliardBracketService $bracket)
    {
        $data = $request->validate([
            'match_id' => 'nullable|integer',
            'match_no' => 'nullable|integer|min:1|max:9999',
            'group_code' => 'required|string|max:8',
            'position' => 'required|integer|min:1|max:128',
            'round_label' => 'nullable|string|max:120',
            'slot1' => 'required|array',
            'slot1.type' => 'required|in:player,source,empty',
            'slot1.player_id' => 'nullable|integer',
            'slot1.match_id' => 'nullable|integer',
            'slot1.match_no' => 'nullable|integer|min:1',
            'slot1.outcome' => 'nullable|in:winner,loser',
            'slot2' => 'required|array',
            'slot2.type' => 'required|in:player,source,empty',
            'slot2.player_id' => 'nullable|integer',
            'slot2.match_id' => 'nullable|integer',
            'slot2.match_no' => 'nullable|integer|min:1',
            'slot2.outcome' => 'nullable|in:winner,loser',
            'table_id' => 'nullable|integer',
            'scheduled_on' => 'nullable|date',
            'scheduled_at' => 'nullable|date_format:H:i',
        ]);

        try {
            $updated = $bracket->saveGroupMatch(
                $tournament,
                $data['group_code'],
                (int) $data['position'],
                $data['slot1'],
                $data['slot2'],
                $data['match_id'] ?? null,
                $data['round_label'] ?? null,
                isset($data['match_no']) ? (int) $data['match_no'] : null,
                [
                    'table_id' => array_key_exists('table_id', $data) ? ($data['table_id'] !== null ? (int) $data['table_id'] : null) : null,
                    'scheduled_on' => $data['scheduled_on'] ?? null,
                    'scheduled_at' => $data['scheduled_at'] ?? null,
                ]
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'match' => $updated->toApiArray(),
            'bracket' => $this->bracketPayload($tournament->id),
        ]);
    }

    public function saveGroupDoubleElimination(Request $request, BilliardTournament $tournament, BilliardBracketService $bracket)
    {
        $data = $request->validate([
            'group_code' => 'required|string|max:8',
            'matches' => 'required|array|min:1|max:80',
            'matches.*.place' => 'nullable|in:first,second',
            'matches.*.scheduled_on' => 'required|date',
            'matches.*.scheduled_at' => 'required|date_format:H:i',
            'matches.*.slot1' => 'required|array',
            'matches.*.slot1.type' => 'required|in:player,source',
            'matches.*.slot1.player_id' => 'nullable|integer',
            'matches.*.slot1.match_index' => 'nullable|integer|min:0',
            'matches.*.slot1.outcome' => 'nullable|in:winner,loser',
            'matches.*.slot2' => 'required|array',
            'matches.*.slot2.type' => 'required|in:player,source',
            'matches.*.slot2.player_id' => 'nullable|integer',
            'matches.*.slot2.match_index' => 'nullable|integer|min:0',
            'matches.*.slot2.outcome' => 'nullable|in:winner,loser',
        ]);

        try {
            $bracket->saveGroupDoubleElimination($tournament, $data['group_code'], $data['matches']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'بازی‌های دوحذفی گروه ثبت شد.',
            'bracket' => $this->bracketPayload((int) $tournament->id),
        ]);
    }

    public function destroyMatch(BilliardMatch $match, BilliardBracketService $bracket)
    {
        $tid = (int) $match->tournament_id;
        try {
            $bracket->deleteMatch($match);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response([
            'message' => 'حذف شد.',
            'bracket' => $this->bracketPayload($tid),
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
                array_key_exists('notes', $data) ? $data['notes'] : $match->notes,
                (bool) ($data['walkover'] ?? false)
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['match' => $updated->toApiArray()]);
    }

    public function clearResult(BilliardMatch $match, BilliardBracketService $bracket)
    {
        try {
            $updated = $bracket->clearResult($match);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response(['match' => $updated->toApiArray()]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function bracketPayload(int $tournamentId)
    {
        return BilliardMatch::query()
            ->with(['player1', 'player2', 'poolTable'])
            ->where('tournament_id', $tournamentId)
            ->orderByRaw("FIELD(phase, 'group', 'knockout')")
            ->orderBy('match_no')
            ->orderBy('group_code')
            ->orderBy('round')
            ->orderBy('position')
            ->get()
            ->map->toApiArray()
            ->values();
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
            'format' => 'nullable|in:'.implode(',', BilliardTournament::formats()),
            'group_count' => 'nullable|integer|min:1|max:16',
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
            'remove_poster' => 'boolean',
            'results_clerk_phone' => 'nullable|string|max:20',
        ];

        $data = $request->validate($rules);
        if (array_key_exists('results_clerk_phone', $data)) {
            $clerkPhone = $this->normalizeClerkPhone($data['results_clerk_phone'] ?? null);
            if ($clerkPhone === null && ! Schema::hasColumn('billiard_tournaments', 'results_clerk_phone')) {
                unset($data['results_clerk_phone']);
            } else {
                $data['results_clerk_phone'] = $clerkPhone;
            }
        }
        if ($creating) {
            $data['format'] = $data['format'] ?? BilliardTournament::FORMAT_SINGLE_ELIMINATION;
            $data['fee_toman'] = $data['fee_toman'] ?? 0;
            $data['status'] = $data['status'] ?? BilliardTournament::STATUS_PRE_REGISTRATION;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function tournamentAdminArray(BilliardTournament $tournament): array
    {
        return $tournament->toApiArray(true) + [
            'results_clerk_phone' => $tournament->results_clerk_phone,
        ];
    }

    private function normalizeClerkPhone(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (! Schema::hasColumn('billiard_tournaments', 'results_clerk_phone')) {
            throw ValidationException::withMessages([
                'results_clerk_phone' => 'اول ستون مسئول نتایج را به جدول مسابقات اضافه کنید.',
            ]);
        }
        $phone = PhoneTools::normalizeIranPhone($raw);
        if (! PhoneTools::isValidIranMobile($phone)) {
            throw ValidationException::withMessages([
                'results_clerk_phone' => 'شماره موبایل مسئول نتایج معتبر نیست.',
            ]);
        }

        return $phone;
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
        if (strlen($content) > 5 * 1024 * 1024) {
            throw new RuntimeException('حجم پوستر نباید بیشتر از ۵ مگابایت باشد.');
        }
        $info = @getimagesizefromstring($content);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if ($ext === null) {
            throw new RuntimeException('فایل پوستر تصویر معتبر نیست.');
        }
        $old = $tournament->poster_path;
        $path = ImageTools::saveFile("/billiard-tournaments/{$tournament->id}/poster_".time().'.'.$ext, $content);
        $tournament->update(['poster_path' => $path]);
        if ($old && $old !== $path && Storage::exists('public/'.$old)) {
            Storage::delete('public/'.$old);
        }
    }

    private function clearPoster(BilliardTournament $tournament): void
    {
        $old = $tournament->poster_path;
        if ($old && Storage::exists('public/'.$old)) {
            Storage::delete('public/'.$old);
        }
        $tournament->update(['poster_path' => null]);
    }
}
