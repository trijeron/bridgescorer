<?php
declare(strict_types=1);

/**
 * Bridge Scorer – REST-like API
 *
 * All responses are JSON.  HTTP status codes follow REST conventions.
 *
 * Routes:
 *   POST   /api/tournaments
 *   GET    /api/tournaments/{id}                  ?admin_token=
 *   GET    /api/tournaments/{id}/movement         ?admin_token=
 *   GET    /api/tournaments/{id}/standings        ?admin_token= | ?pair_token=
 *   GET    /api/tournaments/{id}/results          ?admin_token=
 *   GET    /api/tournaments/{id}/boards/{n}       ?admin_token= | ?pair_token=
 *   POST   /api/tournaments/{id}/join             body: {public_token, player1_name, player2_name}
 *   GET    /api/tournaments/{id}/assignment       ?pair_token=
 *   POST   /api/tournaments/{id}/results          body: {pair_token|admin_token, round_number, board_number,
 *                                                        contract, declarer, tricks_result, raw_score}
 *   PUT    /api/results/{id}                      body: {admin_token, ...fields}
 *   DELETE /api/results/{id}                      body: {admin_token}
 */

// ─── Autoload helpers ────────────────────────────────────────────────────────
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Movement.php';
require_once __DIR__ . '/../src/Scoring.php';

// ─── CORS / headers ──────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token, X-Pair-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error_response(string $message, int $status = 400): never
{
    json_response(['error' => $message], $status);
}

function request_body(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function generate_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function require_admin_token(PDO $db, int $tournamentId): array
{
    $token = $_GET['admin_token']
          ?? (request_body()['admin_token'] ?? null);
    if (empty($token)) {
        error_response('admin_token is required', 401);
    }
    $st = $db->prepare('SELECT * FROM tournaments WHERE id = ? AND admin_token = ?');
    $st->execute([$tournamentId, $token]);
    $t = $st->fetch();
    if (!$t) {
        error_response('Invalid admin token', 403);
    }
    return $t;
}

function require_pair_token(PDO $db, int $tournamentId): array
{
    $token = $_GET['pair_token']
          ?? (request_body()['pair_token'] ?? null);
    if (empty($token)) {
        error_response('pair_token is required', 401);
    }
    $st = $db->prepare('SELECT * FROM pairs WHERE tournament_id = ? AND join_token = ?');
    $st->execute([$tournamentId, $token]);
    $p = $st->fetch();
    if (!$p) {
        error_response('Invalid pair token', 403);
    }
    return $p;
}

// ─── Router ──────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Strip any path prefix (e.g. /bridgescorer/backend/api or /api)
$uri = preg_replace('#^.*/api#', '', $uri) ?: '/';

$segments = array_values(array_filter(explode('/', $uri)));

try {
    $db = Database::connect();
    route($db, $method, $segments);
} catch (PDOException $e) {
    error_response('Database error: ' . $e->getMessage(), 500);
} catch (InvalidArgumentException $e) {
    error_response($e->getMessage(), 400);
}

error_response('Not found', 404);

// ─── Routing logic ────────────────────────────────────────────────────────────
function route(PDO $db, string $method, array $seg): never
{
    // POST /tournaments
    if ($method === 'POST' && count($seg) === 1 && $seg[0] === 'tournaments') {
        createTournament($db);
    }

    // GET /tournaments/{id}
    if ($method === 'GET' && count($seg) === 2 && $seg[0] === 'tournaments') {
        getTournament($db, (int) $seg[1]);
    }

    // GET /tournaments/{id}/movement
    if ($method === 'GET' && count($seg) === 3 && $seg[0] === 'tournaments' && $seg[2] === 'movement') {
        getMovement($db, (int) $seg[1]);
    }

    // GET /tournaments/{id}/standings
    if ($method === 'GET' && count($seg) === 3 && $seg[0] === 'tournaments' && $seg[2] === 'standings') {
        getStandings($db, (int) $seg[1]);
    }

    // GET /tournaments/{id}/results
    if ($method === 'GET' && count($seg) === 3 && $seg[0] === 'tournaments' && $seg[2] === 'results') {
        getAllResults($db, (int) $seg[1]);
    }

    // POST /tournaments/{id}/results
    if ($method === 'POST' && count($seg) === 3 && $seg[0] === 'tournaments' && $seg[2] === 'results') {
        submitResult($db, (int) $seg[1]);
    }

    // GET /tournaments/{id}/boards/{n}
    if ($method === 'GET' && count($seg) === 4 && $seg[0] === 'tournaments' && $seg[2] === 'boards') {
        getBoardResults($db, (int) $seg[1], (int) $seg[3]);
    }

    // POST /tournaments/{id}/join
    if ($method === 'POST' && count($seg) === 3 && $seg[0] === 'tournaments' && $seg[2] === 'join') {
        joinTournament($db, (int) $seg[1]);
    }

    // GET /tournaments/{id}/assignment
    if ($method === 'GET' && count($seg) === 3 && $seg[0] === 'tournaments' && $seg[2] === 'assignment') {
        getAssignment($db, (int) $seg[1]);
    }

    // PUT /results/{id}
    if ($method === 'PUT' && count($seg) === 2 && $seg[0] === 'results') {
        editResult($db, (int) $seg[1]);
    }

    // DELETE /results/{id}
    if ($method === 'DELETE' && count($seg) === 2 && $seg[0] === 'results') {
        deleteResult($db, (int) $seg[1]);
    }

    error_response('Not found', 404);
}

// ─── Handlers ────────────────────────────────────────────────────────────────

/**
 * POST /api/tournaments
 * Body: { name, date?, num_pairs, num_boards }
 */
function createTournament(PDO $db): never
{
    $body = request_body();

    $name      = trim($body['name'] ?? '');
    $date      = $body['date'] ?? null;
    $numPairs  = isset($body['num_pairs'])  ? (int) $body['num_pairs']  : null;
    $numBoards = isset($body['num_boards']) ? (int) $body['num_boards'] : null;

    if ($name === '') {
        error_response('name is required');
    }
    if ($numPairs === null || $numPairs < 2 || $numPairs > 10) {
        error_response('num_pairs must be between 2 and 10');
    }
    if ($numBoards === null || $numBoards < 1 || $numBoards > 32) {
        error_response('num_boards must be between 1 and 32');
    }
    if ($date !== null && $date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        error_response('date must be in YYYY-MM-DD format');
    }

    $adminToken  = generate_token();
    $publicToken = generate_token();

    $st = $db->prepare(
        'INSERT INTO tournaments (name, date, num_pairs, num_boards, admin_token, public_token)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$name, $date ?: null, $numPairs, $numBoards, $adminToken, $publicToken]);
    $tournamentId = (int) $db->lastInsertId();

    // Pre-create pair slots
    $stPair = $db->prepare(
        'INSERT INTO pairs (tournament_id, pair_number, join_token) VALUES (?, ?, ?)'
    );
    for ($i = 1; $i <= $numPairs; $i++) {
        $stPair->execute([$tournamentId, $i, generate_token()]);
    }

    // Generate and store movement
    $movement = Movement::generate($numPairs, $numBoards);
    storeMovement($db, $tournamentId, $movement);

    // Activate tournament
    $db->prepare('UPDATE tournaments SET status = ? WHERE id = ?')
       ->execute(['active', $tournamentId]);

    json_response([
        'tournament_id' => $tournamentId,
        'admin_token'   => $adminToken,
        'public_token'  => $publicToken,
    ], 201);
}

/**
 * Store pre-generated movement in rounds + assignments tables.
 */
function storeMovement(PDO $db, int $tournamentId, array $movement): void
{
    // Fetch pair_id by pair_number
    $stPairs = $db->prepare('SELECT id, pair_number FROM pairs WHERE tournament_id = ?');
    $stPairs->execute([$tournamentId]);
    $pairMap = [];
    foreach ($stPairs->fetchAll() as $p) {
        $pairMap[(int) $p['pair_number']] = (int) $p['id'];
    }

    $stRound  = $db->prepare(
        'INSERT INTO rounds (tournament_id, round_number) VALUES (?, ?)'
    );
    $stAssign = $db->prepare(
        'INSERT INTO assignments
            (tournament_id, round_id, table_number, ns_pair_id, ew_pair_id, board_set_id, first_board, last_board)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($movement as $roundData) {
        $stRound->execute([$tournamentId, $roundData['round']]);
        $roundId = (int) $db->lastInsertId();

        foreach ($roundData['tables'] as $tableData) {
            $nsPairId = $pairMap[$tableData['ns_pair_number']] ?? null;
            $ewPairId = $pairMap[$tableData['ew_pair_number']] ?? null;

            if ($nsPairId === null) {
                continue;
            }

            $stAssign->execute([
                $tournamentId,
                $roundId,
                $tableData['table'],
                $nsPairId,
                $ewPairId,
                $tableData['board_set_id'],
                $tableData['first_board'],
                $tableData['last_board'],
            ]);
        }
    }
}

/**
 * GET /api/tournaments/{id}?admin_token=
 */
function getTournament(PDO $db, int $id): never
{
    $tournament = require_admin_token($db, $id);

    // Pairs
    $stPairs = $db->prepare(
        'SELECT id, pair_number, player1_name, player2_name, join_token
         FROM pairs WHERE tournament_id = ? ORDER BY pair_number'
    );
    $stPairs->execute([$id]);
    $pairs = $stPairs->fetchAll();

    json_response([
        'id'           => (int) $tournament['id'],
        'name'         => $tournament['name'],
        'date'         => $tournament['date'],
        'num_pairs'    => (int) $tournament['num_pairs'],
        'num_boards'   => (int) $tournament['num_boards'],
        'status'       => $tournament['status'],
        'admin_token'  => $tournament['admin_token'],
        'public_token' => $tournament['public_token'],
        'pairs'        => array_map(static fn($p) => [
            'id'           => (int) $p['id'],
            'pair_number'  => (int) $p['pair_number'],
            'player1_name' => $p['player1_name'],
            'player2_name' => $p['player2_name'],
            'join_token'   => $p['join_token'],
        ], $pairs),
    ]);
}

/**
 * GET /api/tournaments/{id}/movement?admin_token=
 */
function getMovement(PDO $db, int $id): never
{
    $tournament = require_admin_token($db, $id);

    $sql = '
        SELECT r.round_number,
               a.table_number,
               a.board_set_id,
               a.first_board,
               a.last_board,
               ns.pair_number AS ns_pair_number, ns.player1_name AS ns_p1, ns.player2_name AS ns_p2,
               ew.pair_number AS ew_pair_number, ew.player1_name AS ew_p1, ew.player2_name AS ew_p2
        FROM assignments a
        JOIN rounds r      ON r.id = a.round_id
        JOIN pairs ns      ON ns.id = a.ns_pair_id
        LEFT JOIN pairs ew ON ew.id = a.ew_pair_id
        WHERE a.tournament_id = ?
        ORDER BY r.round_number, a.table_number
    ';
    $st = $db->prepare($sql);
    $st->execute([$id]);
    $rows = $st->fetchAll();

    $rounds = [];
    foreach ($rows as $row) {
        $rn = (int) $row['round_number'];
        if (!isset($rounds[$rn])) {
            $rounds[$rn] = ['round_number' => $rn, 'tables' => []];
        }
        $rounds[$rn]['tables'][] = [
            'table'          => (int) $row['table_number'],
            'ns_pair_number' => (int) $row['ns_pair_number'],
            'ns_names'       => trim($row['ns_p1'] . ' / ' . $row['ns_p2'], ' /'),
            'ew_pair_number' => $row['ew_pair_number'] !== null ? (int) $row['ew_pair_number'] : null,
            'ew_names'       => $row['ew_pair_number'] !== null ? trim($row['ew_p1'] . ' / ' . $row['ew_p2'], ' /') : 'Bye',
            'board_set_id'   => (int) $row['board_set_id'],
            'first_board'    => (int) $row['first_board'],
            'last_board'     => (int) $row['last_board'],
        ];
    }

    json_response([
        'tournament_id' => $id,
        'name'          => $tournament['name'],
        'rounds'        => array_values($rounds),
    ]);
}

/**
 * POST /api/tournaments/{id}/join
 * Body: { public_token, player1_name, player2_name }
 */
function joinTournament(PDO $db, int $id): never
{
    $body        = request_body();
    $publicToken = trim($body['public_token'] ?? '');
    $p1          = trim($body['player1_name'] ?? '');
    $p2          = trim($body['player2_name'] ?? '');

    if ($publicToken === '') {
        error_response('public_token is required');
    }

    // Verify tournament
    $st = $db->prepare('SELECT * FROM tournaments WHERE id = ? AND public_token = ?');
    $st->execute([$id, $publicToken]);
    $tournament = $st->fetch();
    if (!$tournament) {
        error_response('Invalid public token or tournament not found', 403);
    }

    // Find the first unclaimed pair slot (no player names set and no join_token used yet)
    // Actually pair slots are pre-created; we pick the first without player names
    $stSlot = $db->prepare(
        "SELECT * FROM pairs
         WHERE tournament_id = ? AND player1_name = '' AND player2_name = ''
         ORDER BY pair_number
         LIMIT 1"
    );
    $stSlot->execute([$id]);
    $slot = $stSlot->fetch();

    if (!$slot) {
        error_response('No available pair slots in this tournament', 409);
    }

    // Claim the slot
    $db->prepare(
        "UPDATE pairs SET player1_name = ?, player2_name = ? WHERE id = ?"
    )->execute([$p1, $p2, $slot['id']]);

    json_response([
        'pair_id'      => (int) $slot['id'],
        'pair_number'  => (int) $slot['pair_number'],
        'pair_token'   => $slot['join_token'],
        'player1_name' => $p1,
        'player2_name' => $p2,
    ], 201);
}

/**
 * GET /api/tournaments/{id}/assignment?pair_token=
 * Returns all round assignments for this pair.
 */
function getAssignment(PDO $db, int $id): never
{
    $pair = require_pair_token($db, $id);

    $sql = '
        SELECT r.id AS round_id, r.round_number,
               a.table_number,
               a.board_set_id, a.first_board, a.last_board,
               ns.pair_number AS ns_pair_number, ns.player1_name AS ns_p1, ns.player2_name AS ns_p2,
               ew.pair_number AS ew_pair_number, ew.player1_name AS ew_p1, ew.player2_name AS ew_p2
        FROM assignments a
        JOIN rounds r      ON r.id = a.round_id
        JOIN pairs ns      ON ns.id = a.ns_pair_id
        LEFT JOIN pairs ew ON ew.id = a.ew_pair_id
        WHERE a.tournament_id = ?
          AND (a.ns_pair_id = ? OR a.ew_pair_id = ?)
        ORDER BY r.round_number
    ';
    $st = $db->prepare($sql);
    $pairId = (int) $pair['id'];
    $st->execute([$id, $pairId, $pairId]);
    $rows = $st->fetchAll();

    $assignments = array_map(static fn($row) => [
        'round_id'       => (int) $row['round_id'],
        'round_number'   => (int) $row['round_number'],
        'table_number'   => (int) $row['table_number'],
        'board_set_id'   => (int) $row['board_set_id'],
        'first_board'    => (int) $row['first_board'],
        'last_board'     => (int) $row['last_board'],
        'ns_pair_number' => (int) $row['ns_pair_number'],
        'ns_names'       => trim($row['ns_p1'] . ' / ' . $row['ns_p2'], ' /'),
        'ew_pair_number' => $row['ew_pair_number'] !== null ? (int) $row['ew_pair_number'] : null,
        'ew_names'       => $row['ew_pair_number'] !== null ? trim($row['ew_p1'] . ' / ' . $row['ew_p2'], ' /') : 'Bye',
    ], $rows);

    json_response([
        'pair_id'      => $pairId,
        'pair_number'  => (int) $pair['pair_number'],
        'player1_name' => $pair['player1_name'],
        'player2_name' => $pair['player2_name'],
        'assignments'  => $assignments,
    ]);
}

/**
 * POST /api/tournaments/{id}/results
 * Body: { pair_token | admin_token, round_number, board_number,
 *         contract, declarer, tricks_result, raw_score }
 */
function submitResult(PDO $db, int $id): never
{
    $body = request_body();

    // Determine actor (pair or admin)
    $adminTokenIn = trim($body['admin_token'] ?? '');
    $pairTokenIn  = trim($body['pair_token']  ?? '');

    $actorPairId = null;
    $tournament  = null;

    if ($adminTokenIn !== '') {
        $st = $db->prepare('SELECT * FROM tournaments WHERE id = ? AND admin_token = ?');
        $st->execute([$id, $adminTokenIn]);
        $tournament = $st->fetch();
        if (!$tournament) {
            error_response('Invalid admin token', 403);
        }
    } elseif ($pairTokenIn !== '') {
        $st = $db->prepare('SELECT * FROM pairs WHERE tournament_id = ? AND join_token = ?');
        $st->execute([$id, $pairTokenIn]);
        $pairRow = $st->fetch();
        if (!$pairRow) {
            error_response('Invalid pair token', 403);
        }
        $actorPairId = (int) $pairRow['id'];
        $st2 = $db->prepare('SELECT * FROM tournaments WHERE id = ?');
        $st2->execute([$id]);
        $tournament = $st2->fetch();
    } else {
        error_response('pair_token or admin_token is required', 401);
    }

    if (!$tournament) {
        error_response('Tournament not found', 404);
    }

    $roundNumber = isset($body['round_number']) ? (int) $body['round_number'] : null;
    $boardNumber = isset($body['board_number']) ? (int) $body['board_number'] : null;
    $contract    = trim($body['contract']     ?? '');
    $declarer    = strtoupper(trim($body['declarer'] ?? ''));
    $tricksResult = isset($body['tricks_result']) ? (int) $body['tricks_result'] : 0;
    $rawScore    = isset($body['raw_score'])  ? (int) $body['raw_score']    : 0;

    if ($roundNumber === null) {
        error_response('round_number is required');
    }
    if ($boardNumber === null || $boardNumber < 1 || $boardNumber > (int) $tournament['num_boards']) {
        error_response('board_number is required and must be within tournament range');
    }
    if ($declarer !== '' && !in_array($declarer, ['N', 'S', 'E', 'W'], true)) {
        error_response('declarer must be N, S, E, or W');
    }

    // Fetch round
    $stRound = $db->prepare('SELECT id FROM rounds WHERE tournament_id = ? AND round_number = ?');
    $stRound->execute([$id, $roundNumber]);
    $round = $stRound->fetch();
    if (!$round) {
        error_response('Round not found');
    }
    $roundId = (int) $round['id'];

    // Fetch assignment to get ns_pair_id and ew_pair_id.
    // When boards are shared (same board_set at multiple tables in a round), multiple
    // assignments may cover the same board_number.  For pair submissions we filter
    // by the submitting pair; for admin submissions an optional ns_pair_number
    // may be supplied to disambiguate.
    if ($actorPairId !== null) {
        // Pair submitting: find their specific assignment
        $stAssign = $db->prepare(
            'SELECT * FROM assignments
             WHERE round_id = ? AND first_board <= ? AND last_board >= ?
               AND (ns_pair_id = ? OR ew_pair_id = ?)
             LIMIT 1'
        );
        $stAssign->execute([$roundId, $boardNumber, $boardNumber, $actorPairId, $actorPairId]);
    } else {
        // Admin submitting: optional ns_pair_number to select the right table
        $nsPairNumber = isset($body['ns_pair_number']) ? (int) $body['ns_pair_number'] : null;
        if ($nsPairNumber !== null) {
            $stAssign = $db->prepare(
                'SELECT a.* FROM assignments a
                 JOIN pairs p ON p.id = a.ns_pair_id
                 WHERE a.round_id = ? AND a.first_board <= ? AND a.last_board >= ?
                   AND p.pair_number = ? AND p.tournament_id = ?
                 LIMIT 1'
            );
            $stAssign->execute([$roundId, $boardNumber, $boardNumber, $nsPairNumber, $id]);
        } else {
            $stAssign = $db->prepare(
                'SELECT * FROM assignments
                 WHERE round_id = ? AND first_board <= ? AND last_board >= ?
                 ORDER BY table_number
                 LIMIT 1'
            );
            $stAssign->execute([$roundId, $boardNumber, $boardNumber]);
        }
    }

    $assign = $stAssign->fetch();
    if (!$assign) {
        error_response('No assignment found for this round/board combination');
    }

    // Upsert result
    $st = $db->prepare(
        'INSERT INTO results
            (tournament_id, round_id, board_number, ns_pair_id, ew_pair_id,
             contract, declarer, tricks_result, raw_score, entered_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             contract = VALUES(contract),
             declarer = VALUES(declarer),
             tricks_result = VALUES(tricks_result),
             raw_score = VALUES(raw_score),
             entered_by = VALUES(entered_by),
             updated_at = CURRENT_TIMESTAMP'
    );
    $st->execute([
        $id, $roundId, $boardNumber,
        (int) $assign['ns_pair_id'], (int) $assign['ew_pair_id'],
        $contract, $declarer, $tricksResult, $rawScore,
        $actorPairId,
    ]);

    // Fetch the inserted/updated result id
    $stId = $db->prepare(
        'SELECT id FROM results WHERE tournament_id = ? AND round_id = ? AND board_number = ? AND ns_pair_id = ?'
    );
    $stId->execute([$id, $roundId, $boardNumber, (int) $assign['ns_pair_id']]);
    $resultRow = $stId->fetch();

    json_response(['result_id' => $resultRow ? (int) $resultRow['id'] : null], 201);
}

/**
 * PUT /api/results/{id}
 * Body: { admin_token, contract?, declarer?, tricks_result?, raw_score? }
 */
function editResult(PDO $db, int $resultId): never
{
    $body = request_body();
    $adminToken = trim($body['admin_token'] ?? '');
    if ($adminToken === '') {
        error_response('admin_token is required', 401);
    }

    // Fetch result
    $st = $db->prepare('SELECT * FROM results WHERE id = ?');
    $st->execute([$resultId]);
    $result = $st->fetch();
    if (!$result) {
        error_response('Result not found', 404);
    }

    // Verify admin token against tournament
    $stT = $db->prepare('SELECT id FROM tournaments WHERE id = ? AND admin_token = ?');
    $stT->execute([(int) $result['tournament_id'], $adminToken]);
    if (!$stT->fetch()) {
        error_response('Invalid admin token', 403);
    }

    $fields = [];
    $params = [];

    if (array_key_exists('contract', $body)) {
        $fields[] = 'contract = ?';
        $params[] = trim($body['contract']);
    }
    if (array_key_exists('declarer', $body)) {
        $d = strtoupper(trim($body['declarer']));
        if ($d !== '' && !in_array($d, ['N', 'S', 'E', 'W'], true)) {
            error_response('declarer must be N, S, E, or W');
        }
        $fields[] = 'declarer = ?';
        $params[] = $d;
    }
    if (array_key_exists('tricks_result', $body)) {
        $fields[] = 'tricks_result = ?';
        $params[] = (int) $body['tricks_result'];
    }
    if (array_key_exists('raw_score', $body)) {
        $fields[] = 'raw_score = ?';
        $params[] = (int) $body['raw_score'];
    }

    if (empty($fields)) {
        error_response('No fields to update');
    }

    $params[] = $resultId;
    $db->prepare('UPDATE results SET ' . implode(', ', $fields) . ' WHERE id = ?')
       ->execute($params);

    json_response(['success' => true]);
}

/**
 * DELETE /api/results/{id}
 * Body: { admin_token }
 */
function deleteResult(PDO $db, int $resultId): never
{
    $body = request_body();
    $adminToken = trim($body['admin_token'] ?? '');
    if ($adminToken === '') {
        error_response('admin_token is required', 401);
    }

    $st = $db->prepare('SELECT * FROM results WHERE id = ?');
    $st->execute([$resultId]);
    $result = $st->fetch();
    if (!$result) {
        error_response('Result not found', 404);
    }

    $stT = $db->prepare('SELECT id FROM tournaments WHERE id = ? AND admin_token = ?');
    $stT->execute([(int) $result['tournament_id'], $adminToken]);
    if (!$stT->fetch()) {
        error_response('Invalid admin token', 403);
    }

    $db->prepare('DELETE FROM results WHERE id = ?')->execute([$resultId]);
    json_response(['success' => true]);
}

/**
 * GET /api/tournaments/{id}/standings?admin_token= | ?pair_token=
 */
function getStandings(PDO $db, int $id): never
{
    // Allow both admin and pair tokens (standings are semi-public)
    $adminToken = trim($_GET['admin_token'] ?? '');
    $pairToken  = trim($_GET['pair_token']  ?? '');

    if ($adminToken !== '') {
        $st = $db->prepare('SELECT id FROM tournaments WHERE id = ? AND admin_token = ?');
        $st->execute([$id, $adminToken]);
        if (!$st->fetch()) {
            error_response('Invalid admin token', 403);
        }
    } elseif ($pairToken !== '') {
        $st = $db->prepare('SELECT id FROM pairs WHERE tournament_id = ? AND join_token = ?');
        $st->execute([$id, $pairToken]);
        if (!$st->fetch()) {
            error_response('Invalid pair token', 403);
        }
    } else {
        error_response('admin_token or pair_token is required', 401);
    }

    $stResults = $db->prepare(
        'SELECT board_number, ns_pair_id, ew_pair_id, raw_score FROM results WHERE tournament_id = ?'
    );
    $stResults->execute([$id]);
    $results = $stResults->fetchAll();

    $stPairs = $db->prepare(
        'SELECT id, pair_number, player1_name, player2_name FROM pairs WHERE tournament_id = ?'
    );
    $stPairs->execute([$id]);
    $pairs = $stPairs->fetchAll();

    $standings = Scoring::computeStandings($results, $pairs);

    json_response(['standings' => $standings]);
}

/**
 * GET /api/tournaments/{id}/results?admin_token=
 */
function getAllResults(PDO $db, int $id): never
{
    require_admin_token($db, $id);

    $sql = '
        SELECT res.id, r.round_number, res.board_number,
               ns.pair_number AS ns_pair_number, ns.player1_name AS ns_p1, ns.player2_name AS ns_p2,
               ew.pair_number AS ew_pair_number, ew.player1_name AS ew_p1, ew.player2_name AS ew_p2,
               res.contract, res.declarer, res.tricks_result, res.raw_score, res.updated_at
        FROM results res
        JOIN rounds r   ON r.id  = res.round_id
        JOIN pairs ns   ON ns.id = res.ns_pair_id
        JOIN pairs ew   ON ew.id = res.ew_pair_id
        WHERE res.tournament_id = ?
        ORDER BY r.round_number, res.board_number
    ';
    $st = $db->prepare($sql);
    $st->execute([$id]);
    $rows = $st->fetchAll();

    $results = array_map(static fn($row) => [
        'id'             => (int) $row['id'],
        'round_number'   => (int) $row['round_number'],
        'board_number'   => (int) $row['board_number'],
        'ns_pair_number' => (int) $row['ns_pair_number'],
        'ns_names'       => trim($row['ns_p1'] . ' / ' . $row['ns_p2'], ' /'),
        'ew_pair_number' => (int) $row['ew_pair_number'],
        'ew_names'       => trim($row['ew_p1'] . ' / ' . $row['ew_p2'], ' /'),
        'contract'       => $row['contract'],
        'declarer'       => $row['declarer'],
        'tricks_result'  => (int) $row['tricks_result'],
        'raw_score'      => (int) $row['raw_score'],
        'updated_at'     => $row['updated_at'],
    ], $rows);

    json_response(['results' => $results]);
}

/**
 * GET /api/tournaments/{id}/boards/{n}
 */
function getBoardResults(PDO $db, int $id, int $boardNumber): never
{
    // Verify token (admin or pair)
    $adminToken = trim($_GET['admin_token'] ?? '');
    $pairToken  = trim($_GET['pair_token']  ?? '');

    if ($adminToken !== '') {
        $st = $db->prepare('SELECT id FROM tournaments WHERE id = ? AND admin_token = ?');
        $st->execute([$id, $adminToken]);
        if (!$st->fetch()) {
            error_response('Invalid admin token', 403);
        }
    } elseif ($pairToken !== '') {
        $st = $db->prepare('SELECT id FROM pairs WHERE tournament_id = ? AND join_token = ?');
        $st->execute([$id, $pairToken]);
        if (!$st->fetch()) {
            error_response('Invalid pair token', 403);
        }
    } else {
        error_response('admin_token or pair_token is required', 401);
    }

    $sql = '
        SELECT res.id, r.round_number,
               ns.pair_number AS ns_pair_number, ns.player1_name AS ns_p1, ns.player2_name AS ns_p2,
               ew.pair_number AS ew_pair_number, ew.player1_name AS ew_p1, ew.player2_name AS ew_p2,
               res.contract, res.declarer, res.tricks_result, res.raw_score
        FROM results res
        JOIN rounds r   ON r.id  = res.round_id
        JOIN pairs ns   ON ns.id = res.ns_pair_id
        JOIN pairs ew   ON ew.id = res.ew_pair_id
        WHERE res.tournament_id = ? AND res.board_number = ?
        ORDER BY r.round_number
    ';
    $st = $db->prepare($sql);
    $st->execute([$id, $boardNumber]);
    $rows = $st->fetchAll();

    $results = array_map(static fn($row) => [
        'id'             => (int) $row['id'],
        'round_number'   => (int) $row['round_number'],
        'ns_pair_number' => (int) $row['ns_pair_number'],
        'ns_names'       => trim($row['ns_p1'] . ' / ' . $row['ns_p2'], ' /'),
        'ew_pair_number' => (int) $row['ew_pair_number'],
        'ew_names'       => trim($row['ew_p1'] . ' / ' . $row['ew_p2'], ' /'),
        'contract'       => $row['contract'],
        'declarer'       => $row['declarer'],
        'tricks_result'  => (int) $row['tricks_result'],
        'raw_score'      => (int) $row['raw_score'],
    ], $rows);

    json_response([
        'board_number' => $boardNumber,
        'results'      => $results,
    ]);
}
