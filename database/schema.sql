-- Bridge Tournament Scorer – Database Schema
-- Compatible with MySQL 8+ / MariaDB 10.4+

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- --------------------------------------------------------
-- tournaments
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS tournaments (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name          VARCHAR(255)    NOT NULL,
    date          DATE            NULL,
    num_pairs     TINYINT UNSIGNED NOT NULL,
    num_boards    TINYINT UNSIGNED NOT NULL,
    admin_token   VARCHAR(64)     NOT NULL,
    public_token  VARCHAR(64)     NOT NULL,
    status        ENUM('setup','active','finished') NOT NULL DEFAULT 'setup',
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_token  (admin_token),
    UNIQUE KEY uq_public_token (public_token),
    CONSTRAINT chk_num_pairs  CHECK (num_pairs  BETWEEN 2 AND 10),
    CONSTRAINT chk_num_boards CHECK (num_boards BETWEEN 1 AND 32)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- pairs  (registered players / teams)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS pairs (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tournament_id INT UNSIGNED    NOT NULL,
    pair_number   TINYINT UNSIGNED NOT NULL,
    player1_name  VARCHAR(100)    NOT NULL DEFAULT '',
    player2_name  VARCHAR(100)    NOT NULL DEFAULT '',
    join_token    VARCHAR(64)     NOT NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_join_token              (join_token),
    UNIQUE KEY uq_tournament_pair_number  (tournament_id, pair_number),
    CONSTRAINT fk_pairs_tournament FOREIGN KEY (tournament_id)
        REFERENCES tournaments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- rounds
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS rounds (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tournament_id INT UNSIGNED    NOT NULL,
    round_number  TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tournament_round (tournament_id, round_number),
    CONSTRAINT fk_rounds_tournament FOREIGN KEY (tournament_id)
        REFERENCES tournaments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- assignments  (who plays whom at which table, with which boards)
-- Supports shared boards: same board_set_id may appear at
-- multiple table rows within the same round.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS assignments (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tournament_id INT UNSIGNED    NOT NULL,
    round_id      INT UNSIGNED    NOT NULL,
    table_number  TINYINT UNSIGNED NOT NULL,
    ns_pair_id    INT UNSIGNED    NOT NULL,
    ew_pair_id    INT UNSIGNED    NULL,          -- NULL = bye
    board_set_id  TINYINT UNSIGNED NOT NULL,     -- logical board group (supports sharing)
    first_board   TINYINT UNSIGNED NOT NULL,
    last_board    TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_round_table (round_id, table_number),
    CONSTRAINT fk_assign_tournament FOREIGN KEY (tournament_id)
        REFERENCES tournaments (id) ON DELETE CASCADE,
    CONSTRAINT fk_assign_round      FOREIGN KEY (round_id)
        REFERENCES rounds (id) ON DELETE CASCADE,
    CONSTRAINT fk_assign_ns_pair    FOREIGN KEY (ns_pair_id)
        REFERENCES pairs (id),
    CONSTRAINT fk_assign_ew_pair    FOREIGN KEY (ew_pair_id)
        REFERENCES pairs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- results  (one row per board played at a table in a round)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS results (
    id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tournament_id  INT UNSIGNED    NOT NULL,
    round_id       INT UNSIGNED    NOT NULL,
    board_number   TINYINT UNSIGNED NOT NULL,
    ns_pair_id     INT UNSIGNED    NOT NULL,
    ew_pair_id     INT UNSIGNED    NOT NULL,
    contract       VARCHAR(10)     NOT NULL DEFAULT '',
    declarer       CHAR(1)         NOT NULL DEFAULT '' COMMENT 'N, S, E, W, or empty',
    tricks_result  TINYINT         NOT NULL DEFAULT 0  COMMENT 'tricks relative to contract (positive=made, negative=down)',
    raw_score      SMALLINT        NOT NULL DEFAULT 0  COMMENT 'NS perspective (+NS wins, -EW wins)',
    entered_by     INT UNSIGNED    NULL                COMMENT 'pair id or NULL if admin',
    created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_board_in_round (tournament_id, round_id, board_number, ns_pair_id),
    CONSTRAINT fk_results_tournament FOREIGN KEY (tournament_id)
        REFERENCES tournaments (id) ON DELETE CASCADE,
    CONSTRAINT fk_results_round      FOREIGN KEY (round_id)
        REFERENCES rounds (id) ON DELETE CASCADE,
    CONSTRAINT fk_results_ns_pair    FOREIGN KEY (ns_pair_id)
        REFERENCES pairs (id),
    CONSTRAINT fk_results_ew_pair    FOREIGN KEY (ew_pair_id)
        REFERENCES pairs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
