/* Display generale TV/proiettore: aggiornamento AJAX ogni secondo. */
(function () {
  const AUCTION_ID = window.FA_AUCTION_ID;
  const ROLE_LABELS = { P: 'POR', D: 'DIF', C: 'CEN', A: 'ATT' };
  const POLL_MS = 1000;

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function render(data) {
    document.getElementById('displayStatus').textContent = data.auction.status;
    document.getElementById('displayStatus').className = 'badge status-badge-' + data.auction.status + ' fs-5';

    const nameEl = document.getElementById('dCurrentName');
    const metaEl = document.getElementById('dCurrentMeta');
    if (data.current_player) {
      const p = data.current_player;
      nameEl.textContent = p.name;
      metaEl.innerHTML = `${escapeHtml(p.real_team)} &middot; <span class="badge badge-role-${p.role}">${ROLE_LABELS[p.role] || p.role}</span> &middot; Quot. ${escapeHtml(p.quotation)} &middot; FVM ${escapeHtml(p.fvm)}`;
    } else {
      nameEl.textContent = 'In attesa...';
      metaEl.innerHTML = '&nbsp;';
    }

    if (data.last_purchase) {
      const lp = data.last_purchase;
      document.getElementById('dLastPlayer').textContent = lp.player_name;
      document.getElementById('dLastTeam').textContent = lp.team_name;
      document.getElementById('dLastPrice').textContent = lp.price + ' crediti';
    }

    const grid = document.getElementById('teamsGrid');
    grid.innerHTML = data.teams.map(t => `
      <div class="col-md-4 col-lg-3">
        <div class="display-team-card h-100">
          <div class="team-name">${escapeHtml(t.name)}</div>
          <div class="team-credits credit-positive">${t.remaining_budget}</div>
          <div class="mt-2">
            <span class="role-pill badge-role-P">POR ${t.role_counts.P}/${t.role_limits.P}</span>
            <span class="role-pill badge-role-D">DIF ${t.role_counts.D}/${t.role_limits.D}</span>
            <span class="role-pill badge-role-C">CEN ${t.role_counts.C}/${t.role_limits.C}</span>
            <span class="role-pill badge-role-A">ATT ${t.role_counts.A}/${t.role_limits.A}</span>
          </div>
          <div class="text-dim small mt-2">${t.players_count} giocatori acquistati</div>
        </div>
      </div>`).join('');
  }

  function poll() {
    fetch(`/api/state.php?auction=${AUCTION_ID}`, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => { if (data.success) render(data); })
      .catch(() => {})
      .finally(() => setTimeout(poll, POLL_MS));
  }

  poll();
})();
