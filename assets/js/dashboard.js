/* Polling AJAX per la dashboard partecipante durante l'asta LIVE. */
(function () {
  const AUCTION_ID = window.FA_AUCTION_ID;
  const TEAM_ID = window.FA_TEAM_ID;
  const ROLE_LABELS = { P: 'POR', D: 'DIF', C: 'CEN', A: 'ATT' };
  const POLL_MS = 1500;

  function fmt(n) {
    return (n === null || n === undefined) ? '-' : n;
  }

  function render(data) {
    const remainingEl = document.getElementById('remainingBudget');
    if (remainingEl) {
      remainingEl.textContent = data.remaining_budget;
      remainingEl.className = 'credit-medium ' + (data.remaining_budget < 0 ? 'credit-danger' : (data.remaining_budget < data.initial_budget * 0.15 ? 'credit-warn' : 'credit-positive'));
    }

    const cpBody = document.getElementById('currentPlayerBody');
    if (cpBody) {
      if (data.current_player) {
        const p = data.current_player;
        cpBody.innerHTML = `
          <div class="fs-3 fw-bold">${escapeHtml(p.name)}</div>
          <div class="text-dim mb-2">${escapeHtml(p.real_team)} · <span class="badge badge-role-${p.role}">${ROLE_LABELS[p.role] || p.role}</span></div>
          <div class="d-flex justify-content-center gap-4">
            <div><div class="text-dim small">Quotazione</div><div class="fw-bold">${escapeHtml(p.quotation)}</div></div>
            <div><div class="text-dim small">FVM</div><div class="fw-bold">${escapeHtml(p.fvm)}</div></div>
          </div>`;
      } else {
        cpBody.innerHTML = '<p class="text-dim mb-0">Nessun giocatore selezionato al momento.</p>';
      }
    }

    document.getElementById('statSpent').textContent = data.spent;
    document.getElementById('statSlots').textContent = data.slots_free + ' / ' + data.slots_total;
    document.getElementById('statMax').textContent = data.max_bid;

    const totalPlayers = data.players_count || 0;
    const totalSpent = data.spent || 0;
    document.getElementById('statAvg').textContent = totalPlayers > 0 ? Math.round(totalSpent / totalPlayers * 10) / 10 : '-';

    const roleCounters = document.getElementById('roleCounters');
    if (roleCounters) {
      roleCounters.innerHTML = Object.keys(ROLE_LABELS).map(role => {
        const owned = (data.role_counts && data.role_counts[role]) || 0;
        const limit = (data.role_limits && data.role_limits[role]) || 0;
        return `<span class="badge badge-role-${role}">${ROLE_LABELS[role]} ${owned}/${limit}</span>`;
      }).join('');
    }

    const rosterList = document.getElementById('rosterList');
    if (rosterList) {
      if (!data.roster || data.roster.length === 0) {
        rosterList.innerHTML = '<p class="text-dim mb-0 mt-2">Nessun giocatore acquistato ancora.</p>';
      } else {
        rosterList.innerHTML = data.roster.slice().reverse().map(r => `
          <div class="roster-row">
            <span><span class="badge badge-role-${r.role} me-2">${ROLE_LABELS[r.role] || r.role}</span>${escapeHtml(r.name)} <span class="text-dim">(${escapeHtml(r.real_team)})</span></span>
            <span class="fw-bold">${r.price}</span>
          </div>`).join('');
      }
    }

    const lastUpdate = document.getElementById('lastUpdate');
    if (lastUpdate) {
      lastUpdate.textContent = 'Agg. ' + new Date().toLocaleTimeString('it-IT');
    }
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function poll() {
    fetch(`/api/team_state.php?auction=${AUCTION_ID}&team=${TEAM_ID}`, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          render(data);
          if (data.auction_status !== 'LIVE') {
            location.reload();
          }
        }
      })
      .catch(() => {})
      .finally(() => setTimeout(poll, POLL_MS));
  }

  poll();
})();
