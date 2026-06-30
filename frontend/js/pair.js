/**
 * Pair JavaScript for Bridge Scorer
 *
 * URL format expected: pair.html?token=<public_token>
 * After joining, pair_token and tournament_id are stored in sessionStorage
 * for restoration on page reload.
 */

'use strict';

const API_BASE = (window.API_BASE || '../backend/api').replace(/\/$/, '');

// ─── State ────────────────────────────────────────────────────────────────────
let state = {
  tournamentId:  null,
  publicToken:   null,
  pairToken:     null,
  pairId:        null,
  pairNumber:    null,
  player1:       '',
  player2:       '',
};

// ─── URL params ───────────────────────────────────────────────────────────────
const urlParams  = new URLSearchParams(window.location.search);
const tokenParam = urlParams.get('token') || '';

// ─── Helpers ─────────────────────────────────────────────────────────────────
function api(method, path, body) {
  return fetch(`${API_BASE}${path}`, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  }).then(async r => {
    const data = await r.json();
    if (!r.ok) throw new Error(data.error || `HTTP ${r.status}`);
    return data;
  });
}

function showAlert(id, msg, type = 'error') {
  const el = document.getElementById(id);
  el.textContent = msg;
  el.className = `alert alert-${type} show`;
}

function hideAlert(id) {
  const el = document.getElementById(id);
  el.className = 'alert';
}

function escHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// ─── Init: look up tournament from public token ───────────────────────────────
async function initJoinPage() {
  if (!tokenParam) {
    showAlert('alert-join', 'No tournament token in URL. Please use the link provided by the admin.', 'info');
    document.getElementById('join-tournament-name').textContent = 'Unknown tournament';
    return;
  }

  // We need the tournament ID from the public token.
  // We'll try to POST /tournaments/{id}/join with an empty body first — but we
  // don't know the ID yet.  Instead we use a dedicated lookup endpoint via
  // query parameter on the create route.
  //
  // Workaround: embed tournament ID in join URL  (pair.html?id=X&token=Y)
  // OR: store tournament info in public_token lookup.
  //
  // Since the current API doesn't have a "lookup tournament by public_token"
  // endpoint at root level, we'll require the URL to include the tournament ID.
  // Format: pair.html?id=<tournament_id>&token=<public_token>
  //
  // If only token is provided (old-style links), show an error.

  const idParam = parseInt(urlParams.get('id') || '0', 10);
  if (!idParam) {
    // Try to fetch tournament name from any tournament matching the public token
    // by using a fallback approach: include id in link from admin dashboard.
    document.getElementById('join-tournament-name').textContent =
      'Tournament (token: ' + tokenParam.substring(0, 8) + '…)';
    state.publicToken = tokenParam;
    showAlert('alert-join',
      'The share link is missing the tournament ID. Please ask the admin to share the updated link.',
      'info');
    return;
  }

  state.tournamentId = idParam;
  state.publicToken  = tokenParam;

  // Show tournament info (public-safe query via public_token embedded in URL)
  document.getElementById('join-tournament-name').textContent = `Tournament #${idParam}`;
}

// ─── Tab handling ─────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const pane = btn.dataset.tab;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(pane).classList.add('active');
    if (pane === 'ptab-standings') loadPairStandings();
  });
});

// ─── Join tournament ──────────────────────────────────────────────────────────
document.getElementById('form-join').addEventListener('submit', async e => {
  e.preventDefault();
  hideAlert('alert-join');

  if (!state.tournamentId) {
    return showAlert('alert-join', 'Tournament ID is missing from the URL.');
  }

  const p1 = document.getElementById('j-player1').value.trim();
  const p2 = document.getElementById('j-player2').value.trim();

  if (!p1) return showAlert('alert-join', 'Player 1 name is required.');

  try {
    const data = await api('POST', `/tournaments/${state.tournamentId}/join`, {
      public_token:  state.publicToken,
      player1_name:  p1,
      player2_name:  p2,
    });

    applyPairData(data.pair_id, data.pair_number, data.pair_token, p1, p2);
    openPairDashboard();
  } catch (err) {
    showAlert('alert-join', err.message);
  }
});

// ─── Rejoin ───────────────────────────────────────────────────────────────────
document.getElementById('btn-show-rejoin').addEventListener('click', () => {
  document.getElementById('rejoin-section').style.display = 'block';
  document.getElementById('btn-show-rejoin').style.display = 'none';
});

document.getElementById('btn-rejoin').addEventListener('click', async () => {
  hideAlert('alert-rejoin');
  const token = document.getElementById('rejoin-token').value.trim();
  if (!token) return showAlert('alert-rejoin', 'Please enter your pair token.');
  if (!state.tournamentId) return showAlert('alert-rejoin', 'Tournament ID is missing from the URL.');

  try {
    const data = await api('GET', `/tournaments/${state.tournamentId}/assignment?pair_token=${token}`);
    applyPairData(null, data.pair_number, token, data.player1_name, data.player2_name);
    openPairDashboard();
  } catch (err) {
    showAlert('alert-rejoin', err.message);
  }
});

function applyPairData(pairId, pairNumber, pairToken, p1, p2) {
  state.pairId     = pairId;
  state.pairNumber = pairNumber;
  state.pairToken  = pairToken;
  state.player1    = p1;
  state.player2    = p2;

  sessionStorage.setItem('bs_pair', JSON.stringify({
    tournamentId:  state.tournamentId,
    publicToken:   state.publicToken,
    pairToken,
    pairNumber,
    player1: p1,
    player2: p2,
  }));
}

// ─── Open pair dashboard ──────────────────────────────────────────────────────
async function openPairDashboard() {
  document.getElementById('section-join').style.display = 'none';
  document.getElementById('section-pair').style.display = 'block';

  const badge = document.getElementById('pair-badge');
  badge.textContent = `Pair ${state.pairNumber}`;

  document.getElementById('pair-title').textContent =
    `Pair ${state.pairNumber}: ${state.player1}${state.player2 ? ' / ' + state.player2 : ''}`;
  document.getElementById('pair-meta').textContent =
    `Tournament #${state.tournamentId}`;
  document.getElementById('pair-token-display').textContent =
    state.pairToken.substring(0, 12) + '…';

  loadAssignments();
}

// ─── Load assignments ─────────────────────────────────────────────────────────
async function loadAssignments() {
  hideAlert('alert-rounds');
  try {
    const data = await api('GET',
      `/tournaments/${state.tournamentId}/assignment?pair_token=${state.pairToken}`);
    renderAssignments(data.assignments);
  } catch (err) {
    showAlert('alert-rounds', err.message);
    document.getElementById('rounds-content').innerHTML = '';
  }
}

function renderAssignments(assignments) {
  const el = document.getElementById('rounds-content');
  if (!assignments.length) {
    el.innerHTML = '<p style="color:var(--muted)">No assignments found.</p>';
    return;
  }

  el.innerHTML = assignments.map(a => `
    <div class="assignment-card">
      <div class="assignment-header">
        <span>Round ${a.round_number}</span>
        <span class="board-badge">Boards ${a.first_board}–${a.last_board}</span>
      </div>
      <div class="assignment-body">
        <div class="assignment-meta">
          <div class="meta-item">
            <span class="meta-label">Table</span>
            <span class="meta-value">${a.table_number}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">NS Pair ${a.ns_pair_number}</span>
            <span class="meta-value">${escHtml(a.ns_names) || '—'}</span>
          </div>
          <div class="meta-item">
            <span class="meta-label">EW Pair ${a.ew_pair_number ?? '—'}</span>
            <span class="meta-value">${escHtml(a.ew_names)}</span>
          </div>
        </div>

        <h3>Enter Scores</h3>
        ${renderBoardForms(a)}
      </div>
    </div>
  `).join('');

  // Attach submit handlers
  assignments.forEach(a => {
    for (let b = a.first_board; b <= a.last_board; b++) {
      const formId = `score-form-${a.round_id}-${b}`;
      const formEl = document.getElementById(formId);
      if (formEl) {
        formEl.addEventListener('submit', ev => {
          ev.preventDefault();
          submitScore(a.round_id, a.round_number, b, formId);
        });
      }
    }
  });
}

function renderBoardForms(assignment) {
  let html = '';
  for (let b = assignment.first_board; b <= assignment.last_board; b++) {
    const formId = `score-form-${assignment.round_id}-${b}`;
    html += `
      <div style="margin-bottom:1rem">
        <div style="font-weight:700;margin-bottom:.5rem">
          <span class="board-badge">Board ${b}</span>
        </div>
        <div id="status-${assignment.round_id}-${b}" class="alert"></div>
        <form id="${formId}" class="score-form" data-round="${assignment.round_id}" data-board="${b}">
          <div class="form-group">
            <label>Contract</label>
            <input type="text" name="contract" placeholder="e.g. 3NT, 4S, Pass" autocomplete="off">
          </div>
          <div class="form-group">
            <label>Declarer</label>
            <select name="declarer">
              <option value="">—</option>
              <option value="N">N</option><option value="S">S</option>
              <option value="E">E</option><option value="W">W</option>
            </select>
          </div>
          <div class="form-group">
            <label>Result (+/−)</label>
            <input type="number" name="tricks_result" placeholder="e.g. 0, 1, -2" value="0">
          </div>
          <div class="form-group">
            <label>Raw Score (NS)</label>
            <input type="number" name="raw_score" placeholder="e.g. 120, -100" value="0">
          </div>
          <div class="form-group" style="align-self:flex-end">
            <button type="submit" class="btn btn-accent btn-sm">Submit</button>
          </div>
        </form>
      </div>
    `;
  }
  return html;
}

async function submitScore(roundId, roundNumber, boardNumber, formId) {
  const formEl   = document.getElementById(formId);
  const statusId = `status-${roundId}-${boardNumber}`;

  const contract     = formEl.querySelector('[name=contract]').value.trim();
  const declarer     = formEl.querySelector('[name=declarer]').value;
  const tricksResult = parseInt(formEl.querySelector('[name=tricks_result]').value, 10) || 0;
  const rawScore     = parseInt(formEl.querySelector('[name=raw_score]').value, 10) || 0;

  try {
    await api('POST', `/tournaments/${state.tournamentId}/results`, {
      pair_token:    state.pairToken,
      round_number:  roundNumber,
      board_number:  boardNumber,
      contract,
      declarer,
      tricks_result: tricksResult,
      raw_score:     rawScore,
    });
    showAlert(statusId, '✓ Score saved.', 'success');
  } catch (err) {
    showAlert(statusId, err.message, 'error');
  }
}

// ─── Pair standings ───────────────────────────────────────────────────────────
document.getElementById('btn-pair-refresh-standings').addEventListener('click', loadPairStandings);

async function loadPairStandings() {
  try {
    const data = await api('GET',
      `/tournaments/${state.tournamentId}/standings?pair_token=${state.pairToken}`);
    const tbody = document.getElementById('pair-standings-tbody');
    if (!data.standings.length) {
      tbody.innerHTML = '<tr><td colspan="5" style="color:var(--muted)">No results yet.</td></tr>';
      return;
    }
    tbody.innerHTML = data.standings.map(s => `
      <tr class="${s.rank === 1 ? 'rank-1' : ''}${s.pair_number === state.pairNumber ? '" style="background:#fffde7' : ''}">
        <td>${s.rank}</td>
        <td>${s.pair_number}</td>
        <td>${escHtml(s.player1_name)}${s.player2_name ? ' / ' + escHtml(s.player2_name) : ''}</td>
        <td>${s.matchpoints}</td>
        <td>${s.boards_played}</td>
      </tr>
    `).join('');
  } catch (_) {}
}

// ─── Restore session ──────────────────────────────────────────────────────────
(function restoreSession() {
  // Get tournament ID from URL first
  const idParam = parseInt(urlParams.get('id') || '0', 10);
  state.tournamentId = idParam || null;
  state.publicToken  = tokenParam || null;

  const saved = sessionStorage.getItem('bs_pair');
  if (!saved) {
    initJoinPage();
    return;
  }
  try {
    const s = JSON.parse(saved);
    // Only restore if same tournament
    if (idParam && s.tournamentId !== idParam) {
      initJoinPage();
      return;
    }
    state.tournamentId = s.tournamentId;
    state.publicToken  = s.publicToken;
    applyPairData(null, s.pairNumber, s.pairToken, s.player1, s.player2);
    openPairDashboard();
  } catch (_) {
    initJoinPage();
  }
})();
