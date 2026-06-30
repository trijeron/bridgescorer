# bridgescorer

A lightweight bridge tournament scoring web application — vanilla JavaScript frontend, PHP 8 backend API, MySQL/MariaDB database.

---

## Architecture overview

```
bridgescorer/
├── frontend/          # Static HTML/CSS/JS (served by any web server)
│   ├── index.html     # Admin page
│   ├── pair.html      # Pair (player) page
│   ├── css/style.css
│   └── js/
│       ├── admin.js
│       └── pair.js
├── backend/
│   ├── config.php     # DB config (uses env vars or config.local.php)
│   ├── api/
│   │   ├── index.php  # Single-entry REST API router
│   │   └── .htaccess  # Apache rewrite rules
│   └── src/
│       ├── Database.php   # PDO singleton
│       ├── Movement.php   # Mitchell movement generator
│       └── Scoring.php    # Matchpoint scoring engine
└── database/
    └── schema.sql     # CREATE TABLE statements
```

### Key design decisions

| Topic | Choice | Reason |
|-------|--------|--------|
| Movement | Mitchell (with phantom-pair support for odd counts) | Most common in club bridge; naturally produces relay/shared boards |
| Scoring | Matchpoints | Suitable for pair tournaments; simple to compute |
| Auth | Token-based (admin token, public token, per-pair join token) | Stateless, no login required |
| Shared boards | Modelled via `board_set_id` in `assignments` | Multiple tables can have the same `board_set_id` in the same round |

---

## Setup (local development)

### Prerequisites

- PHP 8.1+ with PDO and pdo_mysql extensions
- MySQL 8+ or MariaDB 10.4+
- A web server (Apache with mod_rewrite, or PHP built-in server)

### 1 – Create the database

```sql
CREATE DATABASE bridgescorer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bsuser'@'localhost' IDENTIFIED BY 'changeme';
GRANT ALL PRIVILEGES ON bridgescorer.* TO 'bsuser'@'localhost';
FLUSH PRIVILEGES;
```

Then run the schema:

```bash
mysql -u bsuser -p bridgescorer < database/schema.sql
```

### 2 – Configure the backend

Copy and edit the local config (never commit this file):

```bash
cp backend/config.php backend/config.local.php
```

Edit `backend/config.local.php`:

```php
return [
    'host'     => 'localhost',
    'port'     => 3306,
    'dbname'   => 'bridgescorer',
    'username' => 'bsuser',
    'password' => 'changeme',
    'charset'  => 'utf8mb4',
];
```

Alternatively, set environment variables: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`.

### 3 – Run (PHP built-in server, development only)

Open two terminals:

**Terminal 1 – API**

```bash
cd backend/api
php -S localhost:8080
```

**Terminal 2 – Frontend**

```bash
cd frontend
# Edit js/admin.js and js/pair.js:
# change  const API_BASE = ...  to  'http://localhost:8080'
php -S localhost:3000
```

Then open `http://localhost:3000/index.html` in your browser.

### 4 – Apache / nginx (production)

Point your document root at `frontend/` and configure a proxy:

**Apache** – add in your vhost:

```apache
ProxyPass        /api/ http://127.0.0.1:8080/
ProxyPassReverse /api/ http://127.0.0.1:8080/
```

Or place `backend/api/` under the same vhost and enable mod_rewrite for `backend/api/.htaccess`.

Set `window.API_BASE = '/api'` in your HTML (or edit the constant in admin.js / pair.js).

---

## API routes

All requests and responses use `Content-Type: application/json`.

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/api/tournaments` | — | Create tournament |
| `GET` | `/api/tournaments/{id}` | `?admin_token=` | Tournament info + pairs |
| `GET` | `/api/tournaments/{id}/movement` | `?admin_token=` | Full movement schedule |
| `POST` | `/api/tournaments/{id}/join` | body: `public_token` | Pair joins tournament |
| `GET` | `/api/tournaments/{id}/assignment` | `?pair_token=` | Pair's round assignments |
| `POST` | `/api/tournaments/{id}/results` | body: `pair_token` or `admin_token` | Submit / upsert result |
| `PUT` | `/api/results/{id}` | body: `admin_token` | Edit result |
| `DELETE` | `/api/results/{id}` | body: `admin_token` | Delete result |
| `GET` | `/api/tournaments/{id}/standings` | `?admin_token=` or `?pair_token=` | Current standings |
| `GET` | `/api/tournaments/{id}/results` | `?admin_token=` | All results |
| `GET` | `/api/tournaments/{id}/boards/{n}` | `?admin_token=` or `?pair_token=` | Results for one board |

### Example: create tournament

```bash
curl -s -X POST http://localhost:8080/tournaments \
  -H 'Content-Type: application/json' \
  -d '{"name":"Club Night","num_pairs":6,"num_boards":18}'
```

Response:
```json
{
  "tournament_id": 1,
  "admin_token": "abc123...",
  "public_token": "xyz456..."
}
```

---

## Assumptions & limitations

1. **Mitchell movement only** – Howell and other movements are not implemented.  For an odd number of pairs the movement adds a phantom pair; the resulting bye tables are omitted.
2. **No authentication** – security relies entirely on unguessable 64-character random tokens.  Do not expose admin tokens publicly.
3. **Score entry is raw** – the pair enters the raw NS score directly.  Contract/declarer/tricks fields are stored for reference but are not used to auto-calculate the score.
4. **No real-time updates** – standings and results must be refreshed manually.
5. **Single tournament per browser session** – the admin page stores state in `sessionStorage`; opening multiple tournaments requires separate browser tabs or windows.
6. **No HTTPS enforcement** – use HTTPS in production.
7. **Pair link format** – pair links include both the tournament ID and the public token: `pair.html?id=<id>&token=<public_token>`.

## Follow-up tasks

- [ ] Add contract-to-score calculator so raw score is auto-filled
- [ ] Add Howell movement for small field sizes
- [ ] Real-time standings using polling or WebSockets
- [ ] Admin login instead of token-only auth
- [ ] Export results to CSV/PDF
- [ ] Vulnerability / dealer display per board
