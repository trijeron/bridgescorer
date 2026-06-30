<?php
declare(strict_types=1);

/**
 * Matchpoint scoring engine.
 *
 * Each board's results (NS raw scores) are compared against every other
 * result on the same board within the same tournament:
 *   - 2 matchpoints for each result beaten
 *   - 1 matchpoint for each tie
 *   - 0 matchpoints for each result lost to
 *
 * Matchpoints are awarded to both NS and EW perspectives.
 * EW matchpoints = (max_possible_on_board) − NS matchpoints.
 */
class Scoring
{
    /**
     * Compute per-pair matchpoint totals for the given tournament results.
     *
     * @param  array  $results  Rows from the `results` table:
     *                          each must have: board_number, ns_pair_id, ew_pair_id, raw_score
     * @param  array  $pairs    Rows from the `pairs` table:
     *                          each must have: id, pair_number, player1_name, player2_name
     * @return array  Standings sorted descending by matchpoints:
     *                [ { pair_id, pair_number, player1_name, player2_name,
     *                    matchpoints, boards_played, rank } ]
     */
    public static function computeStandings(array $results, array $pairs): array
    {
        // Group results by board_number
        $byBoard = [];
        foreach ($results as $r) {
            $byBoard[$r['board_number']][] = $r;
        }

        // Accumulate matchpoints per pair_id
        $mp = [];   // pair_id => float matchpoints
        $bp = [];   // pair_id => boards played

        foreach ($byBoard as $boardNo => $boardResults) {
            $n = count($boardResults);
            if ($n === 0) {
                continue;
            }

            // Maximum matchpoints a pair can earn on this board = 2*(n-1)
            $maxMp = 2 * ($n - 1);

            foreach ($boardResults as $i => $ri) {
                $nsMp = 0.0;
                foreach ($boardResults as $j => $rj) {
                    if ($i === $j) {
                        continue;
                    }
                    if ($ri['raw_score'] > $rj['raw_score']) {
                        $nsMp += 2;
                    } elseif ($ri['raw_score'] === $rj['raw_score']) {
                        $nsMp += 1;
                    }
                }

                $ewMp = $maxMp - $nsMp;

                $nsPairId = (int) $ri['ns_pair_id'];
                $ewPairId = (int) $ri['ew_pair_id'];

                $mp[$nsPairId] = ($mp[$nsPairId] ?? 0.0) + $nsMp;
                $mp[$ewPairId] = ($mp[$ewPairId] ?? 0.0) + $ewMp;

                $bp[$nsPairId] = ($bp[$nsPairId] ?? 0) + 1;
                $bp[$ewPairId] = ($bp[$ewPairId] ?? 0) + 1;
            }
        }

        // Build indexed pair lookup
        $pairIndex = [];
        foreach ($pairs as $p) {
            $pairIndex[(int) $p['id']] = $p;
        }

        // Combine into standings rows
        $standings = [];
        foreach ($pairIndex as $pairId => $pair) {
            $standings[] = [
                'pair_id'      => $pairId,
                'pair_number'  => (int) $pair['pair_number'],
                'player1_name' => $pair['player1_name'],
                'player2_name' => $pair['player2_name'],
                'matchpoints'  => $mp[$pairId] ?? 0.0,
                'boards_played'=> $bp[$pairId] ?? 0,
                'rank'         => 0,
            ];
        }

        // Sort descending by matchpoints, then ascending by pair_number
        usort($standings, static function (array $a, array $b): int {
            if ($b['matchpoints'] !== $a['matchpoints']) {
                return $b['matchpoints'] <=> $a['matchpoints'];
            }
            return $a['pair_number'] <=> $b['pair_number'];
        });

        // Assign ranks (ties get the same rank)
        $rank       = 1;
        $sameRankAt = 1;
        $prevMp     = null;
        foreach ($standings as $idx => &$row) {
            if ($prevMp === null || $row['matchpoints'] !== $prevMp) {
                $rank        = $sameRankAt;
                $prevMp      = $row['matchpoints'];
            }
            $row['rank'] = $rank;
            $sameRankAt++;
        }
        unset($row);

        return $standings;
    }
}
