/* Interfaccia admin per la gestione dell'asta in tempo reale. */
(function () {
  const AUCTION_ID = window.FA_AUCTION_ID;
  const ROLE_LABELS = { P: 'Portiere', D: 'Difensore', C: 'Centrocampista', A: 'Attaccante' };
  const POLL_MS = 1500;

  let selectedPlayer = null;
  let selectedTeamId = null;
  let latestTeams = [];
  let searchDebounce = null;

  const el = (id) => document.getElementById(id);

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function jsonPost(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(r => r.json());
  }

  // ---------------- Ricerca giocatori ----------------

  function doSearch() {
    const q = el('searchQuery').value;
    const role = el('filterRole').value;
    const team = el('filterTeam').value;
    const available = el('filterAvailable').checked ? '1' : '0';

    const params = new URLSearchParams({ auction: AUCTION_ID, q, role, team, available });
    fetch('/api/players.php?' + params.toString(), { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (!data.success) return;
        const box = el('playerResults');
        if (data.players.length === 0) {
          box.innerHTML = '<p class="text-dim p-2">Nessun giocatore trovato.</p>';
          return;
        }
        box.innerHTML = data.players.map(p => `
          <div class="player-search-item ${p.available ? '' : 'unavailable'} ${selectedPlayer && selectedPlayer.id === p.id ? 'selected' : ''}" data-id="${p.id}">
            <div class="d-flex justify-content-between">
              <span><span class="badge badge-role-${p.role} me-1">${p.role}</span>${escapeHtml(p.name)}</span>
              <span class="text-dim small">${escapeHtml(p.real_team)}</span>
            </div>
            <div class="text-dim small">Quot. ${escapeHtml(p.quotation)} · FVM ${escapeHtml(p.fvm)} ${p.available ? '' : ' · GIA\' ACQUISTATO'}</div>
          </div>`).join('');

        box.querySelectorAll('.player-search-item').forEach(item => {
          item.addEventListener('click', () => {
            const player = data.players.find(p => String(p.id) === item.dataset.id);
            selectPlayer(player);
          });
        });
      });
  }

  function selectPlayer(player) {
    selectedPlayer = player;
    el('selectedPlayerBox').innerHTML = `
      <div class="fs-3 fw-bold">${escapeHtml(player.name)}</div>
      <div class="text-dim mb-2">${escapeHtml(player.real_team)} &middot; ${ROLE_LABELS[player.role] || player.role}</div>
      <div class="d-flex justify-content-center gap-4">
        <div><div class="text-dim small">Quotazione</div><div class="fw-bold">${escapeHtml(player.quotation)}</div></div>
        <div><div class="text-dim small">FVM</div><div class="fw-bold">${escapeHtml(player.fvm)}</div></div>
      </div>
      ${!player.available ? '<div class="alert alert-warning py-1 mt-2 mb-0">Giocatore già assegnato</div>' : ''}
    `;
    updateMaxBidLabel();
    doSearch();
  }

  // ---------------- Squadre / stato asta ----------------

  function renderTeams(teams) {
    latestTeams = teams;
    const box = el('teamsList');
    box.innerHTML = teams.map(t => `
      <div class="card team-pick-card mb-2 ${selectedTeamId === t.team_id ? 'selected' : ''}" data-team="${t.team_id}">
        <div class="card-body py-2">
          <div class="d-flex justify-content-between">
            <strong>${escapeHtml(t.name)}</strong>
            <span class="fw-bold credit-positive">${t.remaining_budget}</span>
          </div>
          <div class="text-dim small">
            P ${t.role_counts.P}/${t.role_limits.P} · D ${t.role_counts.D}/${t.role_limits.D} ·
            C ${t.role_counts.C}/${t.role_limits.C} · A ${t.role_counts.A}/${t.role_limits.A}
            &middot; max ${t.max_bid}
          </div>
        </div>
      </div>`).join('');

    box.querySelectorAll('.team-pick-card').forEach(card => {
      card.addEventListener('click', () => {
        selectedTeamId = parseInt(card.dataset.team, 10);
        const team = teams.find(t => t.team_id === selectedTeamId);
        el('selectedTeamLabel').textContent = team ? team.name : '-- nessuna --';
        renderTeams(teams);
        updateMaxBidLabel();
      });
    });
  }

  function updateMaxBidLabel() {
    if (selectedTeamId === null) {
      el('maxBidLabel').textContent = '-';
      return;
    }
    const team = latestTeams.find(t => t.team_id === selectedTeamId);
    el('maxBidLabel').textContent = team ? team.max_bid + ' crediti' : '-';
  }

  function renderCurrentPlayerDisplay(currentPlayer) {
    // Sincronizza il pannello centrale se un altro dispositivo ha impostato un giocatore.
    if (currentPlayer && (!selectedPlayer || selectedPlayer.id !== currentPlayer.id)) {
      // non forza la selezione locale per non interrompere una ricerca in corso
    }
  }

  function renderHistory(teams) {
    let allPurchases = [];
    teams.forEach(t => {
      t.roster.forEach(r => allPurchases.push({ ...r, team_name: t.name, team_id: t.team_id }));
    });
    allPurchases.sort((a, b) => b.purchase_id - a.purchase_id);
    allPurchases = allPurchases.slice(0, 30);

    const tbody = el('purchasesHistory');
    if (allPurchases.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-dim">Nessun acquisto ancora.</td></tr>';
      return;
    }
    tbody.innerHTML = allPurchases.map(p => `
      <tr>
        <td class="small text-dim">${escapeHtml(p.timestamp.split(' ')[1] || '')}</td>
        <td>${escapeHtml(p.name)}</td>
        <td><span class="badge badge-role-${p.role}">${p.role}</span></td>
        <td>${escapeHtml(p.team_name)}</td>
        <td class="fw-bold">${p.price}</td>
        <td>
          <button class="btn btn-sm btn-outline-primary btn-edit" data-id="${p.purchase_id}" data-price="${p.price}" data-team="${p.team_id}">✎</button>
          <button class="btn btn-sm btn-outline-danger btn-release" data-id="${p.purchase_id}">✕</button>
        </td>
      </tr>`).join('');

    tbody.querySelectorAll('.btn-release').forEach(btn => {
      btn.addEventListener('click', () => {
        if (!confirm('Svincolare questo giocatore?')) return;
        jsonPost('/api/release.php', { auction_id: AUCTION_ID, purchase_id: parseInt(btn.dataset.id, 10) })
          .then(() => refreshState());
      });
    });
    tbody.querySelectorAll('.btn-edit').forEach(btn => {
      btn.addEventListener('click', () => openEditModal(btn.dataset.id, btn.dataset.price, btn.dataset.team));
    });
  }

  function openEditModal(purchaseId, price, teamId) {
    el('editPurchaseId').value = purchaseId;
    el('editPrice').value = price;
    el('editError').classList.add('d-none');
    const select = el('editTeamId');
    select.innerHTML = latestTeams.map(t => `<option value="${t.team_id}" ${String(t.team_id) === String(teamId) ? 'selected' : ''}>${escapeHtml(t.name)}</option>`).join('');
    new bootstrap.Modal(el('editPurchaseModal')).show();
  }

  el('btnSaveEdit').addEventListener('click', () => {
    const purchaseId = parseInt(el('editPurchaseId').value, 10);
    const price = parseInt(el('editPrice').value, 10);
    const teamId = parseInt(el('editTeamId').value, 10);
    jsonPost('/api/update_purchase.php', { auction_id: AUCTION_ID, purchase_id: purchaseId, price, team_id: teamId })
      .then(res => {
        if (res.success) {
          bootstrap.Modal.getInstance(el('editPurchaseModal')).hide();
          refreshState();
        } else {
          el('editError').textContent = res.error || 'Errore';
          el('editError').classList.remove('d-none');
        }
      });
  });

  // ---------------- Assegnazione ----------------

  function doAssign() {
    el('assignError').classList.add('d-none');
    if (!window.FA_AUCTION_LIVE) {
      showAssignError("L'asta non è in stato LIVE.");
      return;
    }
    if (!selectedPlayer) {
      showAssignError('Seleziona prima un giocatore.');
      return;
    }
    if (selectedTeamId === null) {
      showAssignError('Seleziona una squadra.');
      return;
    }
    const price = parseInt(el('priceInput').value, 10);
    if (!price || price < 1) {
      showAssignError('Inserisci un prezzo valido (>= 1).');
      return;
    }

    jsonPost('/api/buy.php', { auction_id: AUCTION_ID, player_id: selectedPlayer.id, team_id: selectedTeamId, price })
      .then(res => {
        if (res.success) {
          selectedPlayer = null;
          el('selectedPlayerBox').innerHTML = '<p class="text-dim">Seleziona un giocatore dalla lista a sinistra.</p>';
          el('priceInput').value = '';
          doSearch();
          refreshState();
        } else {
          showAssignError(res.error || 'Errore sconosciuto.');
        }
      });
  }

  function showAssignError(msg) {
    const box = el('assignError');
    box.textContent = msg;
    box.classList.remove('d-none');
  }

  el('btnAssign').addEventListener('click', doAssign);
  el('priceInput').addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter') {
      ev.preventDefault();
      doAssign();
    }
  });

  el('btnUndoLast').addEventListener('click', () => {
    if (!confirm('Annullare l\'ultima operazione registrata?')) return;
    jsonPost('/api/undo.php', { auction_id: AUCTION_ID }).then(() => refreshState());
  });

  el('btnCallPlayer').addEventListener('click', () => {
    if (!selectedPlayer) {
      showAssignError('Seleziona prima un giocatore.');
      return;
    }
    jsonPost('/api/current_player.php', { auction_id: AUCTION_ID, player_id: selectedPlayer.id })
      .then(res => { if (!res.success) showAssignError(res.error || 'Errore'); });
  });

  el('btnClearCurrent').addEventListener('click', () => {
    jsonPost('/api/current_player.php', { auction_id: AUCTION_ID, player_id: '' });
  });

  ['searchQuery'].forEach(id => {
    el(id).addEventListener('input', () => {
      clearTimeout(searchDebounce);
      searchDebounce = setTimeout(doSearch, 250);
    });
  });
  ['filterRole', 'filterTeam', 'filterAvailable'].forEach(id => {
    el(id).addEventListener('change', doSearch);
  });

  // ---------------- Polling stato asta ----------------

  function refreshState() {
    fetch(`/api/state.php?auction=${AUCTION_ID}`, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (!data.success) return;
        el('auctionStatusBadge').textContent = data.auction.status;
        el('auctionStatusBadge').className = 'badge status-badge-' + data.auction.status;
        renderTeams(data.teams);
        renderHistory(data.teams);
        renderCurrentPlayerDisplay(data.current_player);
      });
  }

  function poll() {
    refreshState();
    setTimeout(poll, POLL_MS);
  }

  doSearch();
  poll();
})();
