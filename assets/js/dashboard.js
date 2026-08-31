/* Polling AJAX per la dashboard partecipante durante l'asta LIVE. */
(function () {
  const AUCTION_ID = window.FA_AUCTION_ID;
  const TEAM_ID = window.FA_TEAM_ID;
  const BASE = window.FA_BASE_PATH || '';
  const ROLE_LABELS = { P: 'POR', D: 'DIF', C: 'CEN', A: 'ATT' };
  const POLL_MS = 1500;

  function fmt(n) {
    return (n === null || n === undefined) ? '-' : n;
  }

  function avatarUrl(externalId) {
    return externalId ? `https://content.fantacalcio.it/web/campioncini/21/card/${externalId}.png` : null;
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
          <div class="d-flex justify-content-center mb-2">${avatarHtml(p, 'player-avatar-lg')}</div>
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

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  let latestState = null;

  function fetchState() {
    return fetch(`${BASE}/api/team_state.php?auction=${AUCTION_ID}&team=${TEAM_ID}`, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          latestState = data;
          render(data);
          if (data.auction_status !== 'LIVE') {
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

  // ---------------- Compra un giocatore (autodichiarazione) ----------------

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
