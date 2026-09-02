/* Display generale TV/proiettore: aggiornamento AJAX ogni secondo. */
(function () {
  const AUCTION_ID = window.FA_AUCTION_ID;
  const BASE = window.FA_BASE_PATH || '';
  const ROLE_LABELS = { P: 'POR', D: 'DIF', C: 'CEN', A: 'ATT' };
  const POLL_MS = 1000;

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function avatarUrl(externalId) {
    return externalId ? `${BASE}/avatar.php?id=${externalId}` : null;
  }

  /* Card fantacalcio.it quando abbiamo l'id ufficiale, altrimenti pallino colorato per ruolo. */
  function avatarHtml(externalId, role, sizeClass) {
    const url = avatarUrl(externalId);
    role = role || '';
    let html = `<div class="player-avatar-wrap ${sizeClass}"><div class="player-avatar-placeholder badge-role-${role}">${role}</div>`;
    if (url) {
      html += `<img src="${url}" alt="" onerror="this.style.display='none'">`;
    }
    html += `</div>`;
    return html;
  }

  const ROLE_ORDER = { P: 0, D: 1, C: 2, A: 3 };

  function render(data) {
    document.getElementById('displayStatus').textContent = data.auction.status;
    document.getElementById('displayStatus').className = 'badge status-badge-' + data.auction.status + ' fs-5';

    if (data.last_purchase) {
      const lp = data.last_purchase;
      document.getElementById('dLastPlayer').textContent = lp.player_name;
      document.getElementById('dLastTeam').textContent = lp.team_name;
      document.getElementById('dLastPrice').textContent = lp.price + ' crediti';
      document.getElementById('dLastAvatar').innerHTML = avatarHtml(lp.player_external_id, lp.player_role, 'player-avatar-md');
    }

    const grid = document.getElementById('teamsGrid');
    grid.innerHTML = data.teams.map(t => {
      const roster = (t.roster || []).slice().sort((a, b) => (ROLE_ORDER[a.role] ?? 9) - (ROLE_ORDER[b.role] ?? 9) || a.name.localeCompare(b.name));
      const rosterHtml = roster.length === 0
        ? '<div class="text-dim small mt-2">Nessun giocatore ancora.</div>'
        : `<div class="team-roster mt-2">${roster.map(r => `
            <div class="team-roster-item" title="${escapeHtml(r.name)} (${r.price})">
              ${avatarHtml(r.external_id, r.role, 'player-avatar-sm')}
              <span class="team-roster-name">${escapeHtml(r.name)}</span>
            </div>`).join('')}</div>`;

      return `
      <div class="col-md-6 col-lg-4">
        <div class="display-team-card h-100">
          <div class="team-name">${escapeHtml(t.name)}</div>
          <div class="team-credits credit-positive">${t.remaining_budget}</div>
          <div class="mt-2">
            <span class="role-pill badge-role-P">POR ${t.role_counts.P}/${t.role_limits.P}</span>
            <span class="role-pill badge-role-D">DIF ${t.role_counts.D}/${t.role_limits.D}</span>
            <span class="role-pill badge-role-C">CEN ${t.role_counts.C}/${t.role_limits.C}</span>
            <span class="role-pill badge-role-A">ATT ${t.role_counts.A}/${t.role_limits.A}</span>
          </div>
          ${rosterHtml}
        </div>
      </div>`;
    }).join('');
  }

  function poll() {
    fetch(`${BASE}/api/state.php?auction=${AUCTION_ID}`, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => { if (data.success) render(data); })
      .catch(() => {})
      .finally(() => setTimeout(poll, POLL_MS));
  }

  poll();
})();
