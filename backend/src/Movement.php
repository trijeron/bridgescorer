<?php
declare(strict_types=1);

/**
 * Movement generator – Mitchell movement with phantom-pair support for odd counts.
 *
 * Mitchell movement rules:
 *   - NS pairs (numbered 1 … T) sit fixed at their tables.
 *   - EW pairs (numbered T+1 … 2T) advance one table per round.
 *   - Boards retreat one table per round (relay style).
 *   - "Shared boards" are modelled naturally: the same board_set_id may appear at
 *     multiple tables within the same round when boards are relayed.
 *
 * For odd pair counts a phantom pair (id = 0) is added.  Any assignment that
 * references the phantom results in a bye and is omitted from the output.
 */
class Movement
{
    /**
     * Generate the full movement for a tournament.
     *
     * @param  int   $numPairs   2 – 10 real pairs
     * @param  int   $numBoards  1 – 32 boards
     * @return array  [ ['round' => r, 'tables' => [ [...], ... ]], ... ]
     */
    public static function generate(int $numPairs, int $numBoards): array
    {
        if ($numPairs < 2 || $numPairs > 10) {
            throw new InvalidArgumentException('numPairs must be between 2 and 10.');
        }
        if ($numBoards < 1 || $numBoards > 32) {
            throw new InvalidArgumentException('numBoards must be between 1 and 32.');
        }

        // Phantom pair makes the count even
        $usePhantom = ($numPairs % 2 !== 0);
        $n          = $usePhantom ? $numPairs + 1 : $numPairs; // effective even count
        $nTables    = $n / 2;
        $nRounds    = $nTables; // standard Mitchell: T rounds

        // Divide boards into T sets (last set may be smaller)
        $boardsPerSet = (int) ceil($numBoards / $nTables);

        $movement = [];

        for ($r = 1; $r <= $nRounds; $r++) {
            $tables = [];

            for ($t = 1; $t <= $nTables; $t++) {
                $nsPairNumber = $t;                                         // NS fixed
                $ewPairNumber = $nTables + (($t - 1 + $r - 1) % $nTables) + 1; // EW advances

                // Board set retreats (opposite to EW direction)
                $boardSetId  = (($t - $r + $nTables * 100) % $nTables) + 1; // 1-indexed
                $firstBoard  = ($boardSetId - 1) * $boardsPerSet + 1;
                $lastBoard   = min($boardSetId * $boardsPerSet, $numBoards);

                // Skip phantom-pair table (bye)
                if ($usePhantom && ($nsPairNumber > $numPairs || $ewPairNumber > $numPairs)) {
                    continue;
                }

                $tables[] = [
                    'table'          => $t,
                    'ns_pair_number' => $nsPairNumber,
                    'ew_pair_number' => $ewPairNumber,
                    'board_set_id'   => $boardSetId,
                    'first_board'    => $firstBoard,
                    'last_board'     => $lastBoard,
                ];
            }

            $movement[] = [
                'round'  => $r,
                'tables' => $tables,
            ];
        }

        return $movement;
    }

    /**
     * List every board number that falls within a board-set range.
     *
     * @param  int  $firstBoard
     * @param  int  $lastBoard
     * @return int[]
     */
    public static function boardsInRange(int $firstBoard, int $lastBoard): array
    {
        return range($firstBoard, $lastBoard);
    }
}
