<?php
namespace App\Services;

use PDO;
use Exception;

/**
 * Carcassonne scoring — simplified for an asset-only deck (no per-tile metadata).
 *
 *   Monasteries  (meeple at center 'c')
 *     - Mid-game: 9 points when surrounded by 8 placed neighbors. Meeple returns.
 *     - End-game: 1 + (placed neighbors) for any still-claimed monastery.
 *
 *   Edge meeples (meeple at n/s/e/w) — stand in for roads & cities
 *     - Mid-game: no completion detection (would require knowing tile edges).
 *     - End-game: 1 point per tile in the 4-connected cluster touching the meeple.
 *
 * All score writes happen against the `players` row keyed by (gameId, userId).
 * If the owning player has already left the game their players row is gone and
 * the UPDATE is a harmless no-op.
 */
class ScoreService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Called after a tile is placed. Looks at every monastery within range of
     * the new placement and awards points for any that just became complete.
     *
     * Returns the list of scoring events so the caller can log / broadcast them.
     */
    public function scoreAfterMove(string $gameId, int $newX, int $newY): array {
        $placed = $this->loadPlacedCoords($gameId);

        // Candidate monasteries: any active monastery whose 3x3 footprint includes
        // the cell we just placed. That's exactly the monasteries within [-1,+1].
        $stmt = $this->db->prepare("
            SELECT moveNumber, x, y, userId
            FROM moves
            WHERE gameId = :gameId
              AND placeMeeple = 1
              AND meepleLocation = 'c'
              AND meeple_returned = 0
              AND x BETWEEN :minX AND :maxX
              AND y BETWEEN :minY AND :maxY
        ");
        $stmt->execute([
            'gameId' => $gameId,
            'minX' => $newX - 1, 'maxX' => $newX + 1,
            'minY' => $newY - 1, 'maxY' => $newY + 1,
        ]);
        $candidates = $stmt->fetchAll();

        $events = [];
        foreach ($candidates as $m) {
            $neighbors = $this->countNeighbors((int)$m['x'], (int)$m['y'], $placed);
            if ($neighbors === 8) {
                $this->awardPoints($gameId, $m['userId'], 9, true);
                $this->markMeepleReturned($gameId, (int)$m['moveNumber']);
                $events[] = [
                    'userId'  => $m['userId'],
                    'feature' => 'monastery',
                    'x'       => (int)$m['x'],
                    'y'       => (int)$m['y'],
                    'points'  => 9,
                ];
            }
        }
        return $events;
    }

    /**
     * Called when the game ends, BEFORE final scores are read. Awards reduced
     * points for any meeple still on the board.
     */
    public function scoreEndOfGame(string $gameId): array {
        $placed = $this->loadPlacedCoords($gameId);
        if (empty($placed)) return [];

        $events = [];

        // 1) Incomplete monasteries.
        $stmt = $this->db->prepare("
            SELECT moveNumber, x, y, userId
            FROM moves
            WHERE gameId = :gameId
              AND placeMeeple = 1
              AND meepleLocation = 'c'
              AND meeple_returned = 0
        ");
        $stmt->execute(['gameId' => $gameId]);
        foreach ($stmt->fetchAll() as $m) {
            $neighbors = $this->countNeighbors((int)$m['x'], (int)$m['y'], $placed);
            $points = 1 + $neighbors; // 1..9
            $this->awardPoints($gameId, $m['userId'], $points, false);
            $this->markMeepleReturned($gameId, (int)$m['moveNumber']);
            $events[] = [
                'userId'  => $m['userId'],
                'feature' => 'monastery_incomplete',
                'x'       => (int)$m['x'],
                'y'       => (int)$m['y'],
                'points'  => $points,
            ];
        }

        // 2) Edge meeples — score the 4-connected cluster size touching the meeple.
        //    Memoise per-cluster so meeples sharing a cluster reuse the BFS.
        $stmt = $this->db->prepare("
            SELECT moveNumber, x, y, userId
            FROM moves
            WHERE gameId = :gameId
              AND placeMeeple = 1
              AND meepleLocation IN ('n','s','e','w')
              AND meeple_returned = 0
        ");
        $stmt->execute(['gameId' => $gameId]);

        $clusterIndex = $this->buildClusterIndex($placed); // "x,y" => rootKey
        $clusterSizes = array_count_values($clusterIndex); // rootKey => tile count

        foreach ($stmt->fetchAll() as $em) {
            $key = ($em['x']) . ',' . ($em['y']);
            $root = $clusterIndex[$key] ?? null;
            if ($root === null) continue; // defensive — meeple tile must be placed
            $points = $clusterSizes[$root] ?? 0;
            if ($points === 0) continue;
            $this->awardPoints($gameId, $em['userId'], $points, false);
            $this->markMeepleReturned($gameId, (int)$em['moveNumber']);
            $events[] = [
                'userId'  => $em['userId'],
                'feature' => 'edge_cluster',
                'x'       => (int)$em['x'],
                'y'       => (int)$em['y'],
                'points'  => $points,
            ];
        }

        return $events;
    }

    // ---------------- internals ----------------

    /** @return array<string,bool>  keyed "x,y" => true for every placed tile */
    private function loadPlacedCoords(string $gameId): array {
        $stmt = $this->db->prepare("SELECT x, y FROM moves WHERE gameId = :gameId");
        $stmt->execute(['gameId' => $gameId]);
        $placed = [];
        foreach ($stmt->fetchAll() as $row) {
            $placed[$row['x'] . ',' . $row['y']] = true;
        }
        return $placed;
    }

    private function countNeighbors(int $x, int $y, array $placed): int {
        $n = 0;
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                if ($dx === 0 && $dy === 0) continue;
                if (isset($placed[($x + $dx) . ',' . ($y + $dy)])) $n++;
            }
        }
        return $n;
    }

    private function awardPoints(string $gameId, string $userId, int $points, bool $returnMeeple): void {
        $sql = $returnMeeple
            ? "UPDATE players SET score = score + :p, meeplesLeft = meeplesLeft + 1 WHERE gameId = :g AND userId = :u"
            : "UPDATE players SET score = score + :p WHERE gameId = :g AND userId = :u";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['p' => $points, 'g' => $gameId, 'u' => $userId]);
    }

    private function markMeepleReturned(string $gameId, int $moveNumber): void {
        $stmt = $this->db->prepare("UPDATE moves SET meeple_returned = 1 WHERE gameId = :g AND moveNumber = :m");
        $stmt->execute(['g' => $gameId, 'm' => $moveNumber]);
    }

    /**
     * Flood-fill every placed tile into a connected component. Returns a map of
     * "x,y" => "canonical key (the seed of its component)" so each meeple can
     * look up which cluster it belongs to in O(1).
     */
    private function buildClusterIndex(array $placed): array {
        $index = [];
        foreach (array_keys($placed) as $key) {
            if (isset($index[$key])) continue;
            $rootKey = $key;
            $queue = [$key];
            while ($queue) {
                $cur = array_shift($queue);
                if (isset($index[$cur])) continue;
                $index[$cur] = $rootKey;
                [$cx, $cy] = array_map('intval', explode(',', $cur));
                foreach ([[1,0],[-1,0],[0,1],[0,-1]] as $d) {
                    $nk = ($cx + $d[0]) . ',' . ($cy + $d[1]);
                    if (isset($placed[$nk]) && !isset($index[$nk])) {
                        $queue[] = $nk;
                    }
                }
            }
        }
        return $index;
    }
}
