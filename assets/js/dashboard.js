/* Dashboard partecipante: tab bar app-style + polling AJAX durante l'asta LIVE. */
(function () {
  const AUCTION_ID = window.FA_AUCTION_ID;
  const TEAM_ID = window.FA_TEAM_ID;
  const BASE = window.FA_BASE_PATH || '';
  const INITIAL_LIVE = !!window.FA_AUCTION_LIVE;
  const ROLE_LABELS = { P: 'POR', D: 'DIF', C: 'CEN', A: 'ATT' };
  const ROLE_ORDER = { P: 0, D: 1, C: 2, A: 3 };
  const POLL_MS = 1500;

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function avatarUrl(externalId) {
    return externalId ? `${BASE}/avatar.php?id=${externalId}` : null;
  }

  /* Card fantacalcio.it quando abbiamo l'id ufficiale, altrimenti pallino colorato per ruolo. */
  function avatarHtml(player, sizeClass) {
    const url = avatarUrl(player.external_id);
    const role = player.role || '';
    let html = `<div class="player-avatar-wrap ${sizeClass}"><div class="player-avatar-placeholder badge-role-${role}">${role}</div>`;
    if (url) {
      html += `<img src="${url}" alt="" onerror="this.style.display='none'">`;
    }
    html += `</div>`;
    return html;
  }

  // ---------------- Tab bar (La mia situazione / Asta in corso / Situazione lega) ----------------

  const tabbar = document.querySelector('.app-tabbar');
  if (tabbar) {
    tabbar.querySelectorAll('.app-tabbar-item').forEach(btn => {
      btn.addEventListener('click', () => {
        tabbar.querySelectorAll('.app-tabbar-item').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-pane-mobile').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
      });
    });
  }

  // ---------------- Tab: La mia situazione ----------------

  function render(data) {
    const remainingEl = document.getElementById('remainingBudget');
    if (remainingEl) {
      remainingEl.textContent = data.remaining_budget;
      remainingEl.className = 'credit-medium ' + (data.remaining_budget < 0 ? 'credit-danger' : (data.remaining_budget < data.initial_budget * 0.15 ? 'credit-warn' : 'credit-positive'));
    }

    document.getElementById('statSpent').textContent = data.spent;
    document.getElementById('statMax').textContent = data.max_bid;

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
            <span class="d-flex align-items-center gap-2">${avatarHtml(r, 'player-avatar-sm')}<span class="badge badge-role-${r.role}">${ROLE_LABELS[r.role] || r.role}</span>${escapeHtml(r.name)} <span class="text-dim">(${escapeHtml(r.real_team)})</span></span>
            <span class="fw-bold">${r.price}</span>
          </div>`).join('');
      }
    }

    const lastUpdate = document.getElementById('lastUpdate');
    if (lastUpdate) {
      lastUpdate.textContent = 'Agg. ' + new Date().toLocaleTimeString('it-IT');
    }
  }

  let latestState = null;

  function fetchState() {
    return fetch(`${BASE}/api/team_state.php?auction=${AUCTION_ID}&team=${TEAM_ID}`, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          latestState = data;
          render(data);
          // Ricarica solo quando lo stato dell'asta cambia rispetto a quello con cui
          // la pagina è stata renderizzata (es. l'admin porta l'asta in LIVE, o la
          // chiude): un confronto contro una stringa fissa ricaricherebbe la pagina
          // ad ogni poll quando l'asta non è LIVE, in loop.
          if ((data.auction_status === 'LIVE') !== INITIAL_LIVE) {
            location.reload();
          }
        }
        return data;
      });
  }

  function pollLoop() {
    fetchState().catch(() => {}).finally(() => setTimeout(pollLoop, POLL_MS));
  }

  pollLoop();

  // ---------------- Tab: Situazione lega ----------------

  function renderLeague(data) {
    const box = document.getElementById('leagueTeams');
    if (!box) return;

    // Il rendering ricrea da zero tutto l'HTML ad ogni poll: senza questo,
    // riaprire una rosa e aspettare un secondo la richiuderebbe da sola.
    const expandedIds = Array.from(box.querySelectorAll('.collapse.show')).map(el => el.id);

    box.innerHTML = data.teams.map(t => {
      const roster = (t.roster || []).slice().sort((a, b) => (ROLE_ORDER[a.role] ?? 9) - (ROLE_ORDER[b.role] ?? 9) || a.name.localeCompare(b.name));
      const rosterHtml = roster.length === 0
        ? '<p class="text-dim small p-3 mb-0">Nessun giocatore ancora.</p>'
        : roster.map(r => `
            <div class="roster-row">
              <span class="d-flex align-items-center gap-2">${avatarHtml(r, 'player-avatar-sm')}<span class="badge badge-role-${r.role}">${r.role}</span>${escapeHtml(r.name)}</span>
              <span class="fw-bold">${r.price}</span>
            </div>`).join('');
      const collapseId = 'leagueRoster' + t.team_id;
      const isMe = t.team_id === TEAM_ID;

      return `
      <div class="league-team-card ${isMe ? 'border-primary' : ''}">
        <button class="league-team-header" type="button" data-bs-toggle="collapse" data-bs-target="#${collapseId}" aria-expanded="false">
          <div>
            <div class="fw-bold">${escapeHtml(t.name)} ${isMe ? '<span class="badge bg-primary ms-1">Tu</span>' : ''}</div>
            <div class="mt-1">
              <span class="badge badge-role-P">P ${t.role_counts.P}/${t.role_limits.P}</span>
              <span class="badge badge-role-D">D ${t.role_counts.D}/${t.role_limits.D}</span>
              <span class="badge badge-role-C">C ${t.role_counts.C}/${t.role_limits.C}</span>
              <span class="badge badge-role-A">A ${t.role_counts.A}/${t.role_limits.A}</span>
            </div>
          </div>
          <div class="text-end flex-shrink-0">
            <div class="credit-medium credit-positive">${t.remaining_budget}</div>
            <i class="bi bi-chevron-down text-dim"></i>
          </div>
        </button>
        <div class="collapse" id="${collapseId}">
          <div class="league-team-roster">${rosterHtml}</div>
        </div>
      </div>`;
    }).join('');

    expandedIds.forEach(id => {
      const collapseEl = document.getElementById(id);
      if (!collapseEl) return;
      collapseEl.classList.add('show');
      const trigger = box.querySelector(`[data-bs-target="#${id}"]`);
      if (trigger) trigger.setAttribute('aria-expanded', 'true');
    });
  }

  function leaguePoll() {
    fetch(`${BASE}/api/state.php?auction=${AUCTION_ID}`, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => { if (data.success) renderLeague(data); })
      .catch(() => {})
      .finally(() => setTimeout(leaguePoll, POLL_MS));
  }

  if (document.getElementById('leagueTeams')) {
    leaguePoll();
  }

  // ---------------- Tab: Asta in corso — compra un giocatore (autodichiarazione) ----------------

  const el = (id) => document.getElementById(id);
  let buyModal = null;
  let buySelectedPlayer = null;
  let buySearchDebounce = null;

  function jsonPost(path, body) {
    return fetch(BASE + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(r => r.json());
  }

  function doBuySearch() {
    const q = el('buySearchQuery').value;
    const role = el('buyFilterRole').value;
    const team = el('buyFilterTeam').value;
    const sort = el('buyFilterSort').value;

    const params = new URLSearchParams({ auction: AUCTION_ID, q, role, team, sort, available: '1' });
    fetch(BASE + '/api/players.php?' + params.toString(), { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (!data.success) return;
        const box = el('buyPlayerResults');
        if (data.players.length === 0) {
          box.innerHTML = '<p class="text-dim p-2">Nessun giocatore disponibile trovato.</p>';
          return;
        }
        box.innerHTML = data.players.map(p => `
          <div class="player-search-item" data-id="${p.id}">
            <div class="d-flex align-items-center gap-2">
              ${avatarHtml(p, 'player-avatar-sm')}
              <div>
                <div><span class="badge badge-role-${p.role} me-1">${p.role}</span>${escapeHtml(p.name)}</div>
                <div class="text-dim small">${escapeHtml(p.real_team)} &middot; Quot. ${escapeHtml(p.quotation)} &middot; FVM ${escapeHtml(p.fvm)}</div>
              </div>
            </div>
          </div>`).join('');

        box.querySelectorAll('.player-search-item').forEach(item => {
          const player = data.players.find(p => String(p.id) === item.dataset.id);
          item.addEventListener('click', () => openBuyModal(player));
        });
      });
  }

  function openBuyModal(player) {
    buySelectedPlayer = player;
    el('buyError').classList.add('d-none');
    const maxBid = latestState ? latestState.max_bid : null;
    const remaining = latestState ? latestState.remaining_budget : null;
    el('buyPlayerInfo').innerHTML = `
      <div class="d-flex justify-content-center mb-2">${avatarHtml(player, 'player-avatar-lg')}</div>
      <div class="fs-3 fw-bold">${escapeHtml(player.name)}</div>
      <div class="text-dim mb-2">${escapeHtml(player.real_team)} &middot; <span class="badge badge-role-${player.role}">${ROLE_LABELS[player.role] || player.role}</span></div>
      <div class="d-flex justify-content-center gap-4 mb-2">
        <div><div class="text-dim small">Quotazione</div><div class="fw-bold">${escapeHtml(player.quotation)}</div></div>
        <div><div class="text-dim small">FVM</div><div class="fw-bold">${escapeHtml(player.fvm)}</div></div>
      </div>
      <div class="text-dim small">Crediti residui: <strong>${remaining ?? '-'}</strong> &middot; Massimo spendibile: <strong>${maxBid ?? '-'}</strong></div>
    `;
    el('buyPriceInput').value = '';
    if (!buyModal) {
      buyModal = new bootstrap.Modal(el('buyModal'));
    }
    buyModal.show();
    setTimeout(() => el('buyPriceInput').focus(), 300);
  }

  function doConfirmBuy() {
    el('buyError').classList.add('d-none');
    if (!buySelectedPlayer) return;
    const price = parseInt(el('buyPriceInput').value, 10);
    if (!price || price < 1) {
      showBuyError('Inserisci un prezzo valido (>= 1).');
      return;
    }
    jsonPost('/api/buy.php', { auction_id: AUCTION_ID, player_id: buySelectedPlayer.id, team_id: TEAM_ID, price })
      .then(res => {
        if (res.success) {
          buyModal.hide();
          buySelectedPlayer = null;
          doBuySearch();
          fetchState();
        } else {
          showBuyError(res.error || 'Errore sconosciuto.');
        }
      });
  }

  function showBuyError(msg) {
    const box = el('buyError');
    box.textContent = msg;
    box.classList.remove('d-none');
  }

  if (el('buyPlayerResults')) {
    el('btnConfirmBuy').addEventListener('click', doConfirmBuy);
    el('buyPriceInput').addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        doConfirmBuy();
      }
    });
    el('buySearchQuery').addEventListener('input', () => {
      clearTimeout(buySearchDebounce);
      buySearchDebounce = setTimeout(doBuySearch, 250);
    });
    ['buyFilterRole', 'buyFilterTeam', 'buyFilterSort'].forEach(id => {
      el(id).addEventListener('change', doBuySearch);
    });
    doBuySearch();
  }
})();
