<?php

namespace App\Services\Billiard;

use App\Models\BilliardMatch;
use App\Models\BilliardRegistration;
use App\Models\BilliardTournament;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BilliardBracketService
{
    /**
     * ساخت جدول خالی Single Elimination — بدون قرعه‌کشی؛ اسلات‌ها بعداً دستی پر می‌شوند.
     */
    public function buildEmptyElimination(BilliardTournament $tournament, ?int $bracketSize = null): void
    {
        $activeCount = $tournament->registrations()->activeInTournament()->count();
        $n = $bracketSize ?? max(2, $activeCount);
        if ($n < 2) {
            throw new RuntimeException('حداقل دو جایگاه لازم است.');
        }

        $size = 1;
        while ($size < $n) {
            $size *= 2;
        }

        DB::transaction(function () use ($tournament, $size) {
            BilliardMatch::query()
                ->where('tournament_id', $tournament->id)
                ->where('phase', BilliardMatch::PHASE_KNOCKOUT)
                ->delete();

            $this->createKnockoutTree($tournament, $size);

            $tournament->update([
                'status' => BilliardTournament::STATUS_DRAWN,
                'drawn_at' => now(),
            ]);
        });
    }

    /**
     * ساخت درخت حذفی خالی (برای بعد از مرحله گروهی هم قابل استفاده است).
     */
    public function buildKnockoutAfterGroups(BilliardTournament $tournament, int $bracketSize): void
    {
        if ($tournament->format !== BilliardTournament::FORMAT_GROUP_THEN_ELIMINATION) {
            throw new RuntimeException('این مسابقه گروهی→حذفی نیست.');
        }
        if ($bracketSize < 2) {
            throw new RuntimeException('حداقل دو جایگاه لازم است.');
        }

        $groupCount = BilliardMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('phase', BilliardMatch::PHASE_GROUP)
            ->count();
        if ($groupCount < 1) {
            throw new RuntimeException('اول همه بازی‌های گروهی را بسازید، بعد جدول حذفی را ایجاد کنید.');
        }

        $size = 1;
        while ($size < $bracketSize) {
            $size *= 2;
        }

        DB::transaction(function () use ($tournament, $size) {
            BilliardMatch::query()
                ->where('tournament_id', $tournament->id)
                ->where('phase', BilliardMatch::PHASE_KNOCKOUT)
                ->delete();

            $this->createKnockoutTree($tournament, $size);

            if ($tournament->status === BilliardTournament::STATUS_REGISTRATION_CLOSED
                || $tournament->status === BilliardTournament::STATUS_REGISTRATION_OPEN) {
                $tournament->update([
                    'status' => BilliardTournament::STATUS_DRAWN,
                    'drawn_at' => now(),
                ]);
            }
        });
    }

    /**
     * @deprecated قرعه‌کشی تصادفی فعلاً غیرفعال است؛ از buildEmptyElimination استفاده کنید.
     */
    public function draw(BilliardTournament $tournament, ?array $orderedPlayerIds = null, bool $shuffle = true): void
    {
        unset($orderedPlayerIds, $shuffle);
        $this->buildEmptyElimination($tournament, null);
    }

    /**
     * نشاندن / خالی کردن یک بازیکن در جایگاه جدول.
     */
    public function assignSlot(BilliardMatch $match, int $slot, ?int $playerId): BilliardMatch
    {
        if (! in_array($slot, [1, 2], true)) {
            throw new RuntimeException('جایگاه باید ۱ یا ۲ باشد.');
        }
        if (in_array($match->status, [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER], true)) {
            throw new RuntimeException('بازی تمام‌شده قابل ویرایش نیست.');
        }

        if ($playerId !== null) {
            $ok = BilliardRegistration::query()
                ->where('tournament_id', $match->tournament_id)
                ->where('player_id', $playerId)
                ->activeInTournament()
                ->exists();
            if (! $ok) {
                throw new RuntimeException('بازیکن در لیست ثبت‌نام قطعی این مسابقه نیست.');
            }
        }

        $field = $slot === 1 ? 'player1_id' : 'player2_id';
        $sourceMatch = $slot === 1 ? 'slot1_source_match_id' : 'slot2_source_match_id';
        $sourceOutcome = $slot === 1 ? 'slot1_source_outcome' : 'slot2_source_outcome';

        $match->update([
            $field => $playerId,
            $sourceMatch => null,
            $sourceOutcome => null,
        ]);

        return $match->fresh(['player1', 'player2', 'poolTable']);
    }

    /**
     * نشاندن برنده/بازنده یک بازی دیگر در جایگاه (برای حذفی بعد از گروه یا بازی‌های گروهی).
     */
    public function assignSlotSource(
        BilliardMatch $match,
        int $slot,
        ?int $sourceMatchId = null,
        ?int $sourceMatchNo = null,
        string $outcome = BilliardMatch::SOURCE_WINNER
    ): BilliardMatch {
        if (! in_array($slot, [1, 2], true)) {
            throw new RuntimeException('جایگاه باید ۱ یا ۲ باشد.');
        }
        if (in_array($match->status, [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER], true)) {
            throw new RuntimeException('بازی تمام‌شده قابل ویرایش نیست.');
        }
        if (! in_array($outcome, [BilliardMatch::SOURCE_WINNER, BilliardMatch::SOURCE_LOSER], true)) {
            throw new RuntimeException('نتیجه مرجع باید winner یا loser باشد.');
        }

        $src = null;
        if ($sourceMatchId) {
            $src = BilliardMatch::query()
                ->where('tournament_id', $match->tournament_id)
                ->where('id', $sourceMatchId)
                ->first();
        } elseif ($sourceMatchNo) {
            $src = BilliardMatch::query()
                ->where('tournament_id', $match->tournament_id)
                ->where('match_no', $sourceMatchNo)
                ->first();
        }

        if (! $src) {
            throw new RuntimeException('بازی مرجع یافت نشد.');
        }
        if ((int) $src->id === (int) $match->id) {
            throw new RuntimeException('بازی نمی‌تواند به خودش ارجاع دهد.');
        }

        $resolved = $this->resolveSlotInput($match->tournament, [
            'type' => 'source',
            'match_id' => (int) $src->id,
            'outcome' => $outcome,
        ]);

        $field = $slot === 1 ? 'player1_id' : 'player2_id';
        $sourceMatchField = $slot === 1 ? 'slot1_source_match_id' : 'slot2_source_match_id';
        $sourceOutcomeField = $slot === 1 ? 'slot1_source_outcome' : 'slot2_source_outcome';

        $match->update([
            $field => $resolved['player_id'],
            $sourceMatchField => $resolved['source_match_id'],
            $sourceOutcomeField => $resolved['source_outcome'],
        ]);

        return $match->fresh(['player1', 'player2', 'poolTable']);
    }

    /**
     * افزودن یا به‌روزرسانی بازی گروهی با اسلات بازیکن / برنده / بازنده.
     *
     * @param  array{type:string,player_id?:int|null,match_id?:int|null,outcome?:string|null}  $slot1
     * @param  array{type:string,player_id?:int|null,match_id?:int|null,outcome?:string|null}  $slot2
     */
    /**
     * @param  array{table_id?:int|null,scheduled_on?:string|null,scheduled_at?:string|null}|null  $schedule
     */
    public function saveGroupMatch(
        BilliardTournament $tournament,
        string $groupCode,
        int $position,
        array $slot1,
        array $slot2,
        ?int $matchId = null,
        ?string $roundLabel = null,
        ?int $matchNo = null,
        ?array $schedule = null
    ): BilliardMatch {
        $groupCode = strtoupper(trim($groupCode));
        if ($groupCode === '') {
            throw new RuntimeException('کد گروه لازم است.');
        }

        return DB::transaction(function () use ($tournament, $groupCode, $position, $slot1, $slot2, $matchId, $roundLabel, $matchNo, $schedule) {
            if ($matchId) {
                $match = BilliardMatch::query()
                    ->where('tournament_id', $tournament->id)
                    ->where('phase', BilliardMatch::PHASE_GROUP)
                    ->where('id', $matchId)
                    ->firstOrFail();
            } else {
                // ترتیب تکراری بازی قبلی را بی‌صدا عوض نکند؛ جایگاه خالی بعدی را بگیر
                while (BilliardMatch::query()
                    ->where('tournament_id', $tournament->id)
                    ->where('phase', BilliardMatch::PHASE_GROUP)
                    ->where('group_code', $groupCode)
                    ->where('round', 1)
                    ->where('position', $position)
                    ->exists()) {
                    $position++;
                }
                $match = new BilliardMatch([
                    'tournament_id' => $tournament->id,
                    'phase' => BilliardMatch::PHASE_GROUP,
                    'group_code' => $groupCode,
                    'round' => 1,
                    'position' => $position,
                    'status' => BilliardMatch::STATUS_SCHEDULED,
                ]);
            }

            $resolved1 = $this->resolveSlotInput($tournament, $slot1);
            $resolved2 = $this->resolveSlotInput($tournament, $slot2);

            if ($match->exists) {
                $posTaken = BilliardMatch::query()
                    ->where('tournament_id', $tournament->id)
                    ->where('phase', BilliardMatch::PHASE_GROUP)
                    ->where('group_code', $groupCode)
                    ->where('round', 1)
                    ->where('position', $position)
                    ->where('id', '!=', $match->id)
                    ->exists();
                if ($posTaken) {
                    throw new RuntimeException('این ترتیب در همین گروه قبلاً استفاده شده.');
                }
            }

            if ($matchNo) {
                $taken = BilliardMatch::query()
                    ->where('tournament_id', $tournament->id)
                    ->where('match_no', $matchNo)
                    ->when($match->exists, fn ($q) => $q->where('id', '!=', $match->id))
                    ->lockForUpdate()
                    ->exists();
                if ($taken) {
                    throw new RuntimeException('شماره بازی '.$matchNo.' قبلاً ثبت شده است.');
                }
                $match->match_no = $matchNo;
            } elseif (! $match->match_no) {
                $match->match_no = BilliardMatch::nextMatchNo((int) $tournament->id);
            }

            $matchNo = (int) $match->match_no;
            $fill = [
                'group_code' => $groupCode,
                'phase' => BilliardMatch::PHASE_GROUP,
                'round' => 1,
                'position' => $position,
                'round_label' => $roundLabel ?: ('گروه '.$groupCode.' · بازی '.$matchNo),
                'player1_id' => $resolved1['player_id'],
                'player2_id' => $resolved2['player_id'],
                'slot1_source_match_id' => $resolved1['source_match_id'],
                'slot1_source_outcome' => $resolved1['source_outcome'],
                'slot2_source_match_id' => $resolved2['source_match_id'],
                'slot2_source_outcome' => $resolved2['source_outcome'],
            ];

            if (is_array($schedule)) {
                if (array_key_exists('table_id', $schedule)) {
                    $fill['table_id'] = $schedule['table_id'] !== null ? (int) $schedule['table_id'] : null;
                }
                if (array_key_exists('scheduled_on', $schedule)) {
                    $fill['scheduled_on'] = $schedule['scheduled_on'] ?: null;
                }
                if (array_key_exists('scheduled_at', $schedule)) {
                    $fill['scheduled_at'] = $schedule['scheduled_at'] ?: null;
                }
            }

            $match->fill($fill);
            $match->save();

            if ($tournament->status === BilliardTournament::STATUS_REGISTRATION_CLOSED
                || $tournament->status === BilliardTournament::STATUS_REGISTRATION_OPEN
                || $tournament->status === BilliardTournament::STATUS_PRE_REGISTRATION) {
                $tournament->update([
                    'status' => BilliardTournament::STATUS_DRAWN,
                    'drawn_at' => $tournament->drawn_at ?: now(),
                ]);
            }

            return $match->fresh(['player1', 'player2', 'poolTable']);
        });
    }

    public function deleteMatch(BilliardMatch $match): void
    {
        if (in_array($match->status, [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER], true)) {
            throw new RuntimeException('بازی تمام‌شده قابل حذف نیست.');
        }

        DB::transaction(function () use ($match) {
            $tournamentId = (int) $match->tournament_id;

            // ارجاع‌های وابسته را پاک کن تا حذف ممکن باشد
            BilliardMatch::query()
                ->where('tournament_id', $tournamentId)
                ->where('next_match_id', $match->id)
                ->update(['next_match_id' => null, 'next_slot' => null]);

            BilliardMatch::query()
                ->where('tournament_id', $tournamentId)
                ->where(function ($q) use ($match) {
                    $q->where('slot1_source_match_id', $match->id)
                        ->orWhere('slot2_source_match_id', $match->id);
                })
                ->get()
                ->each(function (BilliardMatch $dep) use ($match) {
                    $data = [];
                    if ((int) $dep->slot1_source_match_id === (int) $match->id) {
                        $data['slot1_source_match_id'] = null;
                        $data['slot1_source_outcome'] = null;
                        $data['player1_id'] = null;
                    }
                    if ((int) $dep->slot2_source_match_id === (int) $match->id) {
                        $data['slot2_source_match_id'] = null;
                        $data['slot2_source_outcome'] = null;
                        $data['player2_id'] = null;
                    }
                    if ($data !== []) {
                        $dep->update($data);
                    }
                });

            $match->delete();
        });
    }

    public function recordResult(
        BilliardMatch $match,
        int $score1,
        int $score2,
        ?int $refereeId = null,
        ?string $notes = null,
        bool $walkover = false
    ): BilliardMatch {
        if (! $match->player1_id || ! $match->player2_id) {
            throw new RuntimeException('هر دو بازیکن باید مشخص باشند.');
        }
        if ($score1 === $score2 && ! $walkover) {
            throw new RuntimeException('نتیجه نمی‌تواند مساوی باشد.');
        }

        $winnerId = $score1 > $score2 ? (int) $match->player1_id : (int) $match->player2_id;
        if ($walkover && $score1 === $score2) {
            throw new RuntimeException('در عدم حضور باید برنده مشخص باشد (امتیاز متفاوت).');
        }

        return DB::transaction(function () use ($match, $score1, $score2, $winnerId, $refereeId, $notes, $walkover) {
            $match = BilliardMatch::query()->lockForUpdate()->findOrFail($match->id);
            if (in_array($match->status, [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER], true)) {
                $this->assertResultEditable($match, $winnerId);
            }

            $match->update([
                'score1' => $score1,
                'score2' => $score2,
                'winner_id' => $winnerId,
                'referee_id' => $refereeId,
                'notes' => $notes,
                'status' => $walkover ? BilliardMatch::STATUS_WALKOVER : BilliardMatch::STATUS_FINISHED,
                'finished_at' => now(),
            ]);

            $fresh = $match->fresh();
            $this->advanceWinner($fresh, $winnerId, false);
            $this->fillDependentSlots($fresh);

            $tournament = $fresh->tournament;
            if ($tournament && $tournament->status === BilliardTournament::STATUS_DRAWN) {
                $tournament->update(['status' => BilliardTournament::STATUS_LIVE]);
            }

            $this->maybeFinishTournament($tournament ? $tournament->fresh() : null);

            return $match->fresh(['player1', 'player2', 'winner', 'poolTable']);
        });
    }

    public function clearResult(BilliardMatch $match): BilliardMatch
    {
        return DB::transaction(function () use ($match) {
            $match = BilliardMatch::query()->lockForUpdate()->findOrFail($match->id);
            if (! in_array($match->status, [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER], true)) {
                throw new RuntimeException('نتیجه‌ای برای حذف نیست.');
            }

            $this->assertResultEditable($match, null);
            $this->pullAdvancedPlayers($match);

            $match->update([
                'score1' => null,
                'score2' => null,
                'winner_id' => null,
                'status' => BilliardMatch::STATUS_SCHEDULED,
                'finished_at' => null,
            ]);

            $this->reopenTournamentIfFinished($match->tournament);

            return $match->fresh(['player1', 'player2', 'winner', 'poolTable']);
        });
    }

    public function scheduleMatch(
        BilliardMatch $match,
        ?int $tableId,
        ?string $date,
        ?string $time
    ): BilliardMatch {
        $match->update([
            'table_id' => $tableId,
            'scheduled_on' => $date,
            'scheduled_at' => $time,
        ]);

        return $match->fresh(['player1', 'player2', 'poolTable']);
    }

    private function createKnockoutTree(BilliardTournament $tournament, int $bracketSize): void
    {
        $rounds = (int) log($bracketSize, 2);
        $matchesByRound = [];
        $matchNo = BilliardMatch::nextMatchNo((int) $tournament->id);

        for ($round = 1; $round <= $rounds; $round++) {
            $count = (int) ($bracketSize / (2 ** $round));
            $label = $this->roundLabel($round, $rounds);
            $matchesByRound[$round] = [];
            for ($pos = 1; $pos <= $count; $pos++) {
                $match = BilliardMatch::create([
                    'tournament_id' => $tournament->id,
                    'match_no' => $matchNo,
                    'phase' => BilliardMatch::PHASE_KNOCKOUT,
                    'group_code' => '',
                    'round' => $round,
                    'position' => $pos,
                    'round_label' => $label,
                    'status' => BilliardMatch::STATUS_SCHEDULED,
                ]);
                $matchesByRound[$round][$pos] = $match;
                $matchNo++;
            }
        }

        for ($round = 1; $round < $rounds; $round++) {
            foreach ($matchesByRound[$round] as $pos => $match) {
                $nextPos = (int) ceil($pos / 2);
                $slot = ($pos % 2 === 1) ? 1 : 2;
                $next = $matchesByRound[$round + 1][$nextPos];
                $match->update([
                    'next_match_id' => $next->id,
                    'next_slot' => $slot,
                ]);

                // جایگاه بعدی را به «برنده این بازی» وصل کن تا کل درخت از اول چیده باشد
                if ($slot === 1) {
                    $next->update([
                        'slot1_source_match_id' => $match->id,
                        'slot1_source_outcome' => BilliardMatch::SOURCE_WINNER,
                    ]);
                } else {
                    $next->update([
                        'slot2_source_match_id' => $match->id,
                        'slot2_source_outcome' => BilliardMatch::SOURCE_WINNER,
                    ]);
                }
            }
        }
    }

    /**
     * @param  array{type:string,player_id?:int|null,match_id?:int|null,outcome?:string|null}  $slot
     * @return array{player_id:?int,source_match_id:?int,source_outcome:?string}
     */
    private function resolveSlotInput(BilliardTournament $tournament, array $slot): array
    {
        $type = $slot['type'] ?? 'player';

        if ($type === 'empty') {
            return ['player_id' => null, 'source_match_id' => null, 'source_outcome' => null];
        }

        if ($type === 'player') {
            $playerId = isset($slot['player_id']) ? (int) $slot['player_id'] : null;
            if ($playerId) {
                $ok = BilliardRegistration::query()
                    ->where('tournament_id', $tournament->id)
                    ->where('player_id', $playerId)
                    ->activeInTournament()
                    ->exists();
                if (! $ok) {
                    throw new RuntimeException('بازیکن #'.$playerId.' در لیست ثبت‌نام قطعی نیست.');
                }
            }

            return ['player_id' => $playerId, 'source_match_id' => null, 'source_outcome' => null];
        }

        if ($type === 'source') {
            $matchId = (int) ($slot['match_id'] ?? 0);
            $matchNo = (int) ($slot['match_no'] ?? 0);
            $outcome = $slot['outcome'] ?? BilliardMatch::SOURCE_WINNER;
            if (! $matchId && ! $matchNo) {
                throw new RuntimeException('شماره بازی مرجع لازم است.');
            }
            if (! in_array($outcome, [BilliardMatch::SOURCE_WINNER, BilliardMatch::SOURCE_LOSER], true)) {
                throw new RuntimeException('نتیجه مرجع باید winner یا loser باشد.');
            }
            $src = BilliardMatch::query()
                ->where('tournament_id', $tournament->id)
                ->when($matchId, fn ($q) => $q->where('id', $matchId))
                ->when(! $matchId && $matchNo, fn ($q) => $q->where('match_no', $matchNo))
                ->first();
            if (! $src) {
                throw new RuntimeException('بازی مرجع یافت نشد.');
            }
            $matchId = (int) $src->id;

            $playerId = null;
            if (in_array($src->status, [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER], true) && $src->winner_id) {
                if ($outcome === BilliardMatch::SOURCE_WINNER) {
                    $playerId = (int) $src->winner_id;
                } else {
                    $playerId = (int) ($src->player1_id == $src->winner_id ? $src->player2_id : $src->player1_id) ?: null;
                }
            }

            return [
                'player_id' => $playerId,
                'source_match_id' => $matchId,
                'source_outcome' => $outcome,
            ];
        }

        throw new RuntimeException('نوع اسلات نامعتبر است.');
    }

    /**
     * @param  int|null  $newWinnerId  null یعنی حذف نتیجه
     */
    private function assertResultEditable(BilliardMatch $match, ?int $newWinnerId): void
    {
        foreach ($this->dependentMatches($match) as $dep) {
            if (! in_array($dep->status, [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER], true)) {
                continue;
            }
            for ($slot = 1; $slot <= 2; $slot++) {
                if (! $this->slotFedBy($match, $dep, $slot)) {
                    continue;
                }
                if ($newWinnerId === null) {
                    throw new RuntimeException('اول نتیجه بازی‌های بعدی را حذف کنید.');
                }
                $expected = $this->expectedSlotPlayer($match, $dep, $slot, $newWinnerId);
                $current = $slot === 1 ? $dep->player1_id : $dep->player2_id;
                if ((int) $current !== (int) $expected) {
                    throw new RuntimeException('نتیجه بازی بعدی ثبت شده؛ اول آن را حذف کنید.');
                }
            }
        }
    }

    private function pullAdvancedPlayers(BilliardMatch $match): void
    {
        $winnerId = (int) $match->winner_id;
        if (! $winnerId) {
            return;
        }

        foreach ($this->dependentMatches($match) as $dep) {
            $changed = false;
            foreach ([1, 2] as $slot) {
                $expected = $this->expectedSlotPlayer($match, $dep, $slot, $winnerId);
                if (! $expected) {
                    continue;
                }
                if ($slot === 1 && (int) $dep->player1_id === $expected) {
                    $dep->player1_id = null;
                    $changed = true;
                }
                if ($slot === 2 && (int) $dep->player2_id === $expected) {
                    $dep->player2_id = null;
                    $changed = true;
                }
            }
            if ($changed) {
                $dep->save();
            }
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, BilliardMatch>
     */
    private function dependentMatches(BilliardMatch $match)
    {
        return BilliardMatch::query()
            ->where('tournament_id', $match->tournament_id)
            ->where(function ($q) use ($match) {
                $q->where('slot1_source_match_id', $match->id)
                    ->orWhere('slot2_source_match_id', $match->id);
                if ($match->next_match_id) {
                    $q->orWhere('id', $match->next_match_id);
                }
            })
            ->lockForUpdate()
            ->get();
    }

    private function slotFedBy(BilliardMatch $source, BilliardMatch $dep, int $slot): bool
    {
        $sourceId = $slot === 1 ? $dep->slot1_source_match_id : $dep->slot2_source_match_id;
        if ((int) $sourceId === (int) $source->id) {
            return true;
        }

        return (int) $source->next_match_id === (int) $dep->id && (int) $source->next_slot === $slot;
    }

    private function expectedSlotPlayer(BilliardMatch $source, BilliardMatch $dep, int $slot, int $winnerId): ?int
    {
        if (! $this->slotFedBy($source, $dep, $slot)) {
            return null;
        }

        $outcome = $slot === 1 ? $dep->slot1_source_outcome : $dep->slot2_source_outcome;
        $sourceId = $slot === 1 ? $dep->slot1_source_match_id : $dep->slot2_source_match_id;
        if ((int) $sourceId === (int) $source->id && $outcome === BilliardMatch::SOURCE_LOSER) {
            return $this->loserId($source, $winnerId);
        }

        return $winnerId;
    }

    private function loserId(BilliardMatch $match, int $winnerId): ?int
    {
        if (! $match->player1_id || ! $match->player2_id) {
            return null;
        }
        $loser = (int) ($match->player1_id == $winnerId ? $match->player2_id : $match->player1_id);

        return $loser ?: null;
    }

    private function reopenTournamentIfFinished(?BilliardTournament $tournament): void
    {
        if (! $tournament || $tournament->status !== BilliardTournament::STATUS_FINISHED) {
            return;
        }

        BilliardRegistration::query()
            ->where('tournament_id', $tournament->id)
            ->update([
                'final_place' => null,
                'points_awarded' => 0,
            ]);
        $tournament->update(['status' => BilliardTournament::STATUS_LIVE]);

        if ($tournament->discipline_id) {
            app(BilliardRankingService::class)->recomputeForDiscipline((int) $tournament->discipline_id, (int) $tournament->id);
        }
    }

    private function advanceWinner(BilliardMatch $match, int $winnerId, bool $bye): void
    {
        $match->update([
            'winner_id' => $winnerId,
            'status' => $bye ? BilliardMatch::STATUS_FINISHED : $match->status,
            'finished_at' => $match->finished_at ?: now(),
            'score1' => $bye ? ($match->player1_id == $winnerId ? 1 : 0) : $match->score1,
            'score2' => $bye ? ($match->player2_id == $winnerId ? 1 : 0) : $match->score2,
        ]);

        if (! $match->next_match_id || ! $match->next_slot) {
            return;
        }

        $next = BilliardMatch::query()->lockForUpdate()->find($match->next_match_id);
        if (! $next) {
            return;
        }

        if ((int) $match->next_slot === 1) {
            $next->player1_id = $winnerId;
        } else {
            $next->player2_id = $winnerId;
        }
        $next->save();
    }

    private function fillDependentSlots(BilliardMatch $match): void
    {
        if (! $match->winner_id) {
            return;
        }
        $winnerId = (int) $match->winner_id;
        $loserId = null;
        if ($match->player1_id && $match->player2_id) {
            $loserId = (int) ($match->player1_id == $winnerId ? $match->player2_id : $match->player1_id);
        }

        $deps = BilliardMatch::query()
            ->where('tournament_id', $match->tournament_id)
            ->where(function ($q) use ($match) {
                $q->where('slot1_source_match_id', $match->id)
                    ->orWhere('slot2_source_match_id', $match->id);
            })
            ->lockForUpdate()
            ->get();

        foreach ($deps as $dep) {
            $changed = false;
            if ((int) $dep->slot1_source_match_id === (int) $match->id) {
                $pid = $dep->slot1_source_outcome === BilliardMatch::SOURCE_LOSER ? $loserId : $winnerId;
                if ($pid) {
                    $dep->player1_id = $pid;
                    $changed = true;
                }
            }
            if ((int) $dep->slot2_source_match_id === (int) $match->id) {
                $pid = $dep->slot2_source_outcome === BilliardMatch::SOURCE_LOSER ? $loserId : $winnerId;
                if ($pid) {
                    $dep->player2_id = $pid;
                    $changed = true;
                }
            }
            if ($changed) {
                $dep->save();
            }
        }
    }

    private function maybeFinishTournament(?BilliardTournament $tournament): void
    {
        if (! $tournament) {
            return;
        }

        $knockoutExists = BilliardMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('phase', BilliardMatch::PHASE_KNOCKOUT)
            ->exists();

        if (! $knockoutExists) {
            return;
        }

        $open = BilliardMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('phase', BilliardMatch::PHASE_KNOCKOUT)
            ->whereNotIn('status', [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER, BilliardMatch::STATUS_CANCELLED])
            ->where(function ($q) {
                $q->whereNotNull('player1_id')->whereNotNull('player2_id');
            })
            ->exists();

        $final = BilliardMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('phase', BilliardMatch::PHASE_KNOCKOUT)
            ->whereNull('next_match_id')
            ->whereIn('status', [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER])
            ->first();

        if (! $open && $final && $final->winner_id) {
            $this->assignPlaces($tournament, $final);
            $tournament->update(['status' => BilliardTournament::STATUS_FINISHED]);
            app(BilliardRankingService::class)->recomputeForDiscipline((int) $tournament->discipline_id, (int) $tournament->id);
        }
    }

    private function assignPlaces(BilliardTournament $tournament, BilliardMatch $final): void
    {
        $pointsMap = $tournament->placePoints()->pluck('points', 'place');
        $winnerId = (int) $final->winner_id;
        $runnerId = (int) ($final->player1_id == $winnerId ? $final->player2_id : $final->player1_id);

        $this->setPlace($tournament->id, $winnerId, 1, (int) ($pointsMap[1] ?? 0));
        if ($runnerId) {
            $this->setPlace($tournament->id, $runnerId, 2, (int) ($pointsMap[2] ?? 0));
        }

        $semiRound = (int) BilliardMatch::query()
            ->where('tournament_id', $tournament->id)
            ->where('phase', BilliardMatch::PHASE_KNOCKOUT)
            ->max('round') - 1;
        if ($semiRound >= 1) {
            $semis = BilliardMatch::query()
                ->where('tournament_id', $tournament->id)
                ->where('phase', BilliardMatch::PHASE_KNOCKOUT)
                ->where('round', $semiRound)
                ->whereIn('status', [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER])
                ->get();
            foreach ($semis as $semi) {
                if (! $semi->winner_id) {
                    continue;
                }
                $loser = (int) ($semi->player1_id == $semi->winner_id ? $semi->player2_id : $semi->player1_id);
                if ($loser && $loser !== $runnerId && $loser !== $winnerId) {
                    $this->setPlace($tournament->id, $loser, 3, (int) ($pointsMap[3] ?? 0));
                }
            }
        }
    }

    private function setPlace(int $tournamentId, int $playerId, int $place, int $points): void
    {
        BilliardRegistration::query()
            ->where('tournament_id', $tournamentId)
            ->where('player_id', $playerId)
            ->update([
                'final_place' => $place,
                'points_awarded' => $points,
            ]);
    }

    private function roundLabel(int $round, int $totalRounds): string
    {
        $fromEnd = $totalRounds - $round;
        if ($fromEnd === 0) {
            return 'فینال';
        }
        if ($fromEnd === 1) {
            return 'نیمه‌نهایی';
        }
        if ($fromEnd === 2) {
            return 'یک‌چهارم';
        }
        if ($fromEnd === 3) {
            return 'یک‌هشتم';
        }

        return 'دور '.$round;
    }
}
