/**
 * Admin JavaScript for Bridge Scorer
 *
 * Reads API_BASE from window.API_BASE (injected by server/config) or falls back
 * to a relative path that works when frontend is served from the same origin.
 */

'use strict';

const API_BASE = (window.API_BASE || '../backend/api').replace(/\/$/, '');

// ─── State ────────────────────────────────────────────────────────────────────
let state = {
  tournamentId:  null,
  adminToken:    null,
  publicToken:   null,
  tournamentName:'',
  numBoards:     0,
};

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

// ─── URL helpers ──────────────────────────────────────────────────────────────
function frontendBase() {
  return location.origin + location.pathname.replace(/index\.html$/, '').replace(/\/$/, '');
}

function pairUrl(tournamentId, token) {
  return `${frontendBase()}/pair.html?id=${tournamentId}&token=${token}`;
}


document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const pane = btn.dataset.tab;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(pane).classList.add('active');

    // Lazy load data
    if (pane === 'tab-standings') loadStandings();
    if (pane === 'tab-results')   loadAllResults();
  });
});

// ─── Create Tournament ────────────────────────────────────────────────────────
document.getElementById('form-create').addEventListener('submit', async e => {
  e.preventDefault();
  hideAlert('alert-create');

  const name      = document.getElementById('t-name').value.trim();
  const date      = document.getElementById('t-date').value;
  const numPairs  = parseInt(document.getElementById('t-pairs').value, 10);
  const numBoards = parseInt(document.getElementById('t-boards').value, 10);

  if (!name)                         return showAlert('alert-create', 'Name is required.');
  if (numPairs < 2 || numPairs > 10) return showAlert('alert-create', 'Pairs must be 2–10.');
  if (numBoards < 1 || numBoards > 32) return showAlert('alert-create', 'Boards must be 1–32.');

  try {
    const data = await api('POST', '/tournaments', { name, date: date || null, num_pairs: numPairs, num_boards: numBoards });

    state.tournamentId   = data.tournament_id;
    state.adminToken     = data.admin_token;
    state.publicToken    = data.public_token;
    state.tournamentName = name;
    state.numBoards      = numBoards;

    const pairLink = pairUrl(data.tournament_id, data.public_token);

    document.getElementById('display-admin-token').textContent = data.admin_token;
    document.getElementById('display-pair-link').textContent   = pairLink;
    document.getElementById('created-info').style.display = 'block';

    // Save to sessionStorage for page refresh
    sessionStorage.setItem('bs_admin', JSON.stringify({
      id: state.tournamentId, adminToken: state.adminToken,
      publicToken: state.publicToken, name, numBoards,
    }));

  } catch (err) {
    showAlert('alert-create', err.message);
  }
});

// ─── Copy helpers ─────────────────────────────────────────────────────────────
window.copyToken = function(type) {
  const el = document.getElementById(type === 'admin' ? 'display-admin-token' : 'display-pair-link');
  navigator.clipboard.writeText(el.textContent).catch(() => {});
};

// ─── Go to dashboard ──────────────────────────────────────────────────────────
document.getElementById('btn-go-dashboard').addEventListener('click', openDashboard);
document.getElementById('btn-back-create').addEventListener('click', () => {
  document.getElementById('section-dashboard').style.display = 'none';
  document.getElementById('section-create').style.display    = 'block';
});

async function openDashboard() {
  document.getElementById('section-create').style.display    = 'none';
  document.getElementById('section-dashboard').style.display = 'block';

  document.getElementById('dash-title').textContent = state.tournamentName;

  try {
    const data = await api('GET', `/tournaments/${state.tournamentId}?admin_token=${state.adminToken}`);
    document.getElementById('dash-meta').textContent =
      `${data.num_pairs} pairs · ${data.num_boards} boards · ${data.status}`;

    renderPairsTable(data.pairs, data.num_boards);
    loadMovement();
    populateBoardSelect(data.num_boards);
  } catch (err) {
    showAlert('alert-movement', err.message);
  }
}

// ─── Pairs table ─────────────────────────────────────────────────────────────
function renderPairsTable(pairs, numBoards) {
  const tbody = document.getElementById('pairs-tbody');
  tbody.innerHTML = pairs.map(p => `
    <tr>
      <td>${p.pair_number}</td>
      <td>${escHtml(p.player1_name) || '<em style="color:var(--muted)">—</em>'}</td>
      <td>${escHtml(p.player2_name) || '<em style="color:var(--muted)">—</em>'}</td>
      <td><a href="${pairUrl(state.tournamentId, p.join_token)}" target="_blank" style="font-size:.8rem">Pair link</a></td>
    </tr>
  `).join('');
}

// ─── Movement ─────────────────────────────────────────────────────────────────
async function loadMovement() {
  try {
    const data = await api('GET', `/tournaments/${state.tournamentId}/movement?admin_token=${state.adminToken}`);
    const el = document.getElementById('movement-content');
    el.innerHTML = data.rounds.map(r => `
      <div>
        <div class="round-header">Round ${r.round_number}</div>
        <div class="round-body">
          ${r.tables.map(t => `
            <div class="table-row">
              <span class="table-num">${t.table}</span>
              <span><strong>NS ${t.ns_pair_number}</strong> ${escHtml(t.ns_names)}</span>
              <span class="vs">vs</span>
              <span><strong>EW ${t.ew_pair_number ?? '—'}</strong> ${escHtml(t.ew_names)}</span>
              <span class="board-badge">Boards ${t.first_board}–${t.last_board}</span>
            </div>
          `).join('')}
        </div>
      </div>
    `).join('');
  } catch (err) {
    showAlert('alert-movement', err.message);
  }
}

// ─── Standings ────────────────────────────────────────────────────────────────
document.getElementById('btn-refresh-standings').addEventListener('click', loadStandings);

async function loadStandings() {
  hideAlert('alert-standings');
  try {
    const data = await api('GET', `/tournaments/${state.tournamentId}/standings?admin_token=${state.adminToken}`);
    const tbody = document.getElementById('standings-tbody');
    if (!data.standings.length) {
      tbody.innerHTML = '<tr><td colspan="5" style="color:var(--muted)">No results yet.</td></tr>';
      return;
    }
    tbody.innerHTML = data.standings.map(s => `
      <tr class="${s.rank === 1 ? 'rank-1' : ''}">
        <td>${s.rank}</td>
        <td>${s.pair_number}</td>
        <td>${escHtml(s.player1_name)}${s.player2_name ? ' / ' + escHtml(s.player2_name) : ''}</td>
        <td>${s.matchpoints}</td>
        <td>${s.boards_played}</td>
      </tr>
    `).join('');
  } catch (err) {
    showAlert('alert-standings', err.message);
  }
}

// ─── All Results ──────────────────────────────────────────────────────────────
document.getElementById('btn-refresh-results').addEventListener('click', loadAllResults);

async function loadAllResults() {
  hideAlert('alert-results');
  try {
    const data = await api('GET', `/tournaments/${state.tournamentId}/results?admin_token=${state.adminToken}`);
    renderResultsTable(data.results);
  } catch (err) {
    showAlert('alert-results', err.message);
  }
}

function renderResultsTable(results) {
  const tbody = document.getElementById('results-tbody');
  if (!results.length) {
    tbody.innerHTML = '<tr><td colspan="9" style="color:var(--muted)">No results yet.</td></tr>';
    return;
  }
  tbody.innerHTML = results.map(r => `
    <tr>
      <td>${r.round_number}</td>
      <td>${r.board_number}</td>
      <td>${r.ns_pair_number} ${escHtml(r.ns_names)}</td>
      <td>${r.ew_pair_number} ${escHtml(r.ew_names)}</td>
      <td>${escHtml(r.contract)}</td>
      <td>${escHtml(r.declarer)}</td>
      <td>${r.tricks_result >= 0 ? '+' + r.tricks_result : r.tricks_result}</td>
      <td>${r.raw_score}</td>
      <td>
        <button class="btn btn-sm btn-ghost" onclick="openEdit(${r.id})">Edit</button>
        <button class="btn btn-sm btn-danger" onclick="deleteResult(${r.id})">Del</button>
      </td>
    </tr>
  `).join('');
}

// ─── Board results ────────────────────────────────────────────────────────────
function populateBoardSelect(numBoards) {
  const sel = document.getElementById('board-select');
  sel.innerHTML = '<option value="">— choose —</option>';
  for (let i = 1; i <= numBoards; i++) {
    sel.insertAdjacentHTML('beforeend', `<option value="${i}">Board ${i}</option>`);
  }
}

document.getElementById('board-select').addEventListener('change', async function() {
  const bn = parseInt(this.value, 10);
  const el = document.getElementById('board-results-content');
  if (!bn) { el.innerHTML = ''; return; }

  try {
    const data = await api('GET', `/tournaments/${state.tournamentId}/boards/${bn}?admin_token=${state.adminToken}`);
    if (!data.results.length) {
      el.innerHTML = '<p style="color:var(--muted)">No results for this board yet.</p>';
      return;
    }
    el.innerHTML = `
      <div class="table-wrap">
        <table>
          <thead><tr><th>Rd</th><th>NS</th><th>EW</th><th>Contract</th><th>Dec</th><th>Result</th><th>Score</th></tr></thead>
          <tbody>
            ${data.results.map(r => `
              <tr>
                <td>${r.round_number}</td>
                <td>${r.ns_pair_number} ${escHtml(r.ns_names)}</td>
                <td>${r.ew_pair_number} ${escHtml(r.ew_names)}</td>
                <td>${escHtml(r.contract)}</td>
                <td>${escHtml(r.declarer)}</td>
                <td>${r.tricks_result >= 0 ? '+' + r.tricks_result : r.tricks_result}</td>
                <td>${r.raw_score}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    `;
  } catch (err) {
    el.innerHTML = `<p style="color:var(--error)">${escHtml(err.message)}</p>`;
  }
});

// ─── Edit modal ───────────────────────────────────────────────────────────────
window.openEdit = function(resultId) {
  document.getElementById('edit-result-id').value = resultId;
  hideAlert('alert-edit');
  const modal = document.getElementById('modal-edit');
  modal.style.display = 'flex';
};

document.getElementById('btn-cancel-edit').addEventListener('click', () => {
  document.getElementById('modal-edit').style.display = 'none';
});

document.getElementById('modal-edit').addEventListener('click', e => {
  if (e.target === document.getElementById('modal-edit')) {
    document.getElementById('modal-edit').style.display = 'none';
  }
});

document.getElementById('form-edit').addEventListener('submit', async e => {
  e.preventDefault();
  hideAlert('alert-edit');

  const id = parseInt(document.getElementById('edit-result-id').value, 10);
  const body = {
    admin_token:   state.adminToken,
    contract:      document.getElementById('edit-contract').value.trim(),
    declarer:      document.getElementById('edit-declarer').value,
    tricks_result: parseInt(document.getElementById('edit-tricks').value, 10) || 0,
    raw_score:     parseInt(document.getElementById('edit-score').value, 10) || 0,
  };

  try {
    await api('PUT', `/results/${id}`, body);
    document.getElementById('modal-edit').style.display = 'none';
    showAlert('alert-results', 'Result updated.', 'success');
    loadAllResults();
    loadStandings();
  } catch (err) {
    showAlert('alert-edit', err.message);
  }
});

// ─── Delete result ────────────────────────────────────────────────────────────
window.deleteResult = async function(resultId) {
  if (!confirm('Delete this result?')) return;
  try {
    await api('DELETE', `/results/${resultId}`, { admin_token: state.adminToken });
    loadAllResults();
    loadStandings();
  } catch (err) {
    showAlert('alert-results', err.message);
  }
};

// ─── Restore state from sessionStorage ────────────────────────────────────────
(function restoreState() {
  const saved = sessionStorage.getItem('bs_admin');
  if (!saved) return;
  try {
    const s = JSON.parse(saved);
    state.tournamentId   = s.id;
    state.adminToken     = s.adminToken;
    state.publicToken    = s.publicToken;
    state.tournamentName = s.name;
    state.numBoards      = s.numBoards;
    openDashboard();
  } catch (_) {}
})();
