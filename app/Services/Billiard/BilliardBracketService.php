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
     * قرعه‌کشی و ساخت براکت Single Elimination.
     *
     * @param  array<int, int>|null  $orderedPlayerIds  ترتیب دستی؛ null = تصادفی با حفظ seedها
     */
    public function draw(BilliardTournament $tournament, ?array $orderedPlayerIds = null, bool $shuffle = true): void
    {
        if ($tournament->format !== BilliardTournament::FORMAT_SINGLE_ELIMINATION) {
            throw new RuntimeException('در این نسخه فقط Single Elimination پشتیبانی می‌شود.');
        }

        $regs = $tournament->registrations()
            ->activeInTournament()
            ->with('player')
            ->get();

        if ($regs->count() < 2) {
            throw new RuntimeException('حداقل دو بازیکن پرداخت‌کرده لازم است.');
        }

        if ($orderedPlayerIds !== null) {
            $map = $regs->keyBy('player_id');
            $ordered = collect($orderedPlayerIds)->map(function ($id) use ($map) {
                $reg = $map->get((int) $id);
                if (! $reg) {
                    throw new RuntimeException('بازیکن #'.$id.' در لیست ثبت‌نام تأییدشده نیست.');
                }

                return $reg;
            });
            foreach ($regs as $reg) {
                if (! $ordered->contains(fn ($r) => (int) $r->id === (int) $reg->id)) {
                    $ordered->push($reg);
                }
            }
            $regs = $ordered->values();
        } else {
            $seeded = $regs->filter(fn ($r) => $r->seed !== null)->sortBy('seed')->values();
            $unseeded = $regs->filter(fn ($r) => $r->seed === null)->values();
            if ($shuffle) {
                $unseeded = $unseeded->shuffle()->values();
            }
            $regs = $seeded->concat($unseeded)->values();
        }

        DB::transaction(function () use ($tournament, $regs) {
            BilliardMatch::query()->where('tournament_id', $tournament->id)->delete();

            $players = $regs->pluck('player_id')->map(fn ($id) => (int) $id)->all();
            $n = count($players);
            $bracketSize = 1;
            while ($bracketSize < $n) {
                $bracketSize *= 2;
            }
            // بازیکنان در ابتدای اسلات‌ها؛ بقیه bye
            $slots = array_fill(0, $bracketSize, null);
            foreach ($players as $i => $pid) {
                $slots[$i] = $pid;
            }

            $rounds = (int) log($bracketSize, 2);
            $matchesByRound = [];

            for ($round = 1; $round <= $rounds; $round++) {
                $count = (int) ($bracketSize / (2 ** $round));
                $label = $this->roundLabel($round, $rounds);
                $matchesByRound[$round] = [];
                for ($pos = 1; $pos <= $count; $pos++) {
                    $match = BilliardMatch::create([
                        'tournament_id' => $tournament->id,
                        'round' => $round,
                        'position' => $pos,
                        'round_label' => $label,
                        'status' => BilliardMatch::STATUS_SCHEDULED,
                    ]);
                    $matchesByRound[$round][$pos] = $match;
                }
            }

            // لینک next_match
            for ($round = 1; $round < $rounds; $round++) {
                foreach ($matchesByRound[$round] as $pos => $match) {
                    $nextPos = (int) ceil($pos / 2);
                    $slot = ($pos % 2 === 1) ? 1 : 2;
                    $match->update([
                        'next_match_id' => $matchesByRound[$round + 1][$nextPos]->id,
                        'next_slot' => $slot,
                    ]);
                }
            }

            // پر کردن راند ۱
            $round1 = $matchesByRound[1];
            foreach ($round1 as $pos => $match) {
                $i1 = ($pos - 1) * 2;
                $i2 = $i1 + 1;
                $p1 = $slots[$i1] ?? null;
                $p2 = $slots[$i2] ?? null;
                $match->update([
                    'player1_id' => $p1,
                    'player2_id' => $p2,
                ]);
            }

            // bye خودکار
            foreach ($round1 as $match) {
                $match->refresh();
                if ($match->player1_id && ! $match->player2_id) {
                    $this->advanceWinner($match, (int) $match->player1_id, true);
                } elseif ($match->player2_id && ! $match->player1_id) {
                    $this->advanceWinner($match, (int) $match->player2_id, true);
                }
            }

            foreach ($regs as $index => $reg) {
                $reg->update(['bracket_position' => $index + 1]);
            }

            $tournament->update([
                'status' => BilliardTournament::STATUS_DRAWN,
                'drawn_at' => now(),
            ]);
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
            $match->update([
                'score1' => $score1,
                'score2' => $score2,
                'winner_id' => $winnerId,
                'referee_id' => $refereeId,
                'notes' => $notes,
                'status' => $walkover ? BilliardMatch::STATUS_WALKOVER : BilliardMatch::STATUS_FINISHED,
                'finished_at' => now(),
            ]);

            $this->advanceWinner($match->fresh(), $winnerId, false);

            $tournament = $match->tournament;
            if ($tournament && $tournament->status === BilliardTournament::STATUS_DRAWN) {
                $tournament->update(['status' => BilliardTournament::STATUS_LIVE]);
            }

            $this->maybeFinishTournament($tournament->fresh());

            return $match->fresh(['player1', 'player2', 'winner', 'table']);
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

        return $match->fresh(['player1', 'player2', 'table']);
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

    private function maybeFinishTournament(?BilliardTournament $tournament): void
    {
        if (! $tournament) {
            return;
        }
        $open = BilliardMatch::query()
            ->where('tournament_id', $tournament->id)
            ->whereNotIn('status', [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER, BilliardMatch::STATUS_CANCELLED])
            ->where(function ($q) {
                $q->whereNotNull('player1_id')->whereNotNull('player2_id');
            })
            ->exists();

        $final = BilliardMatch::query()
            ->where('tournament_id', $tournament->id)
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

        // نیمه‌نهایی بازنده‌ها → مقام ۳ (اگر دو نفر، هر دو سوم با امتیاز place 3)
        $semiRound = (int) BilliardMatch::query()->where('tournament_id', $tournament->id)->max('round') - 1;
        if ($semiRound >= 1) {
            $semis = BilliardMatch::query()
                ->where('tournament_id', $tournament->id)
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
