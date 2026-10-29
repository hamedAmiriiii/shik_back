<?php

namespace App\Services\Billiard;

use App\Models\BilliardMatch;
use App\Models\BilliardRankingEntry;
use App\Models\BilliardRankingSetting;
use App\Models\BilliardRegistration;
use App\Models\BilliardTournament;
use Illuminate\Support\Facades\DB;

class BilliardRankingService
{
    public function recomputeForDiscipline(int $disciplineId, ?int $sourceTournamentId = null): void
    {
        $limit = BilliardRankingSetting::lastTournamentsCount();

        $tournamentIds = BilliardTournament::query()
            ->where('discipline_id', $disciplineId)
            ->where('status', BilliardTournament::STATUS_FINISHED)
            ->orderByDesc('ends_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($disciplineId, $tournamentIds, $sourceTournamentId) {
            BilliardRankingEntry::query()->where('discipline_id', $disciplineId)->delete();

            if ($tournamentIds === []) {
                return;
            }

            $regs = BilliardRegistration::query()
                ->whereIn('tournament_id', $tournamentIds)
                ->where('status', BilliardRegistration::STATUS_CONFIRMED)
                ->get();

            $byPlayer = [];
            foreach ($regs as $reg) {
                $pid = (int) $reg->player_id;
                if (! isset($byPlayer[$pid])) {
                    $byPlayer[$pid] = [
                        'points' => 0,
                        'tournaments_count' => 0,
                        'titles' => 0,
                        'runner_ups' => 0,
                        'thirds' => 0,
                        'wins' => 0,
                        'losses' => 0,
                    ];
                }
                $byPlayer[$pid]['points'] += (int) $reg->points_awarded;
                $byPlayer[$pid]['tournaments_count']++;
                if ((int) $reg->final_place === 1) {
                    $byPlayer[$pid]['titles']++;
                } elseif ((int) $reg->final_place === 2) {
                    $byPlayer[$pid]['runner_ups']++;
                } elseif ((int) $reg->final_place === 3) {
                    $byPlayer[$pid]['thirds']++;
                }
            }

            $matches = BilliardMatch::query()
                ->whereIn('tournament_id', $tournamentIds)
                ->whereIn('status', [BilliardMatch::STATUS_FINISHED, BilliardMatch::STATUS_WALKOVER])
                ->whereNotNull('winner_id')
                ->get();

            foreach ($matches as $match) {
                $w = (int) $match->winner_id;
                $l = (int) ($match->player1_id == $w ? $match->player2_id : $match->player1_id);
                if (isset($byPlayer[$w])) {
                    $byPlayer[$w]['wins']++;
                }
                if ($l && isset($byPlayer[$l])) {
                    $byPlayer[$l]['losses']++;
                }
            }

            uasort($byPlayer, function ($a, $b) {
                if ($a['points'] !== $b['points']) {
                    return $b['points'] <=> $a['points'];
                }

                return $b['titles'] <=> $a['titles'];
            });

            $rank = 1;
            foreach ($byPlayer as $playerId => $stats) {
                BilliardRankingEntry::create([
                    'discipline_id' => $disciplineId,
                    'player_id' => $playerId,
                    'points' => $stats['points'],
                    'rank' => $rank,
                    'tournaments_count' => $stats['tournaments_count'],
                    'wins' => $stats['wins'],
                    'losses' => $stats['losses'],
                    'titles' => $stats['titles'],
                    'runner_ups' => $stats['runner_ups'],
                    'thirds' => $stats['thirds'],
                    'computed_at' => now(),
                    'source_tournament_id' => $sourceTournamentId,
                ]);
                $rank++;
            }
        });
    }
}
