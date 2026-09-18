# Fantacalcio Asta Manager

Web app completa per gestire un'asta del Fantacalcio tra amici: registrazione
utenti, configurazione fantateam con settimane di anticipo, gestione di una o
più aste, asta live in tempo reale, dashboard partecipante da smartphone,
display generale per TV/proiettore, import del listone ed export finale delle
rose.

Tutta la persistenza è su **database MySQL** (letture/scritture con
transazioni atomiche). Il progetto è nato con storage su file CSV: quel
nome (`CsvStorage`, `Schema::*_HEADERS`, i vecchi file `data/*.csv`) è
rimasto come riferimento storico e come formato dei backup, ma dietro le
quinte ogni riga vive ora in una tabella MySQL — vedi la sezione
**Database** più sotto.

## Stack

- PHP 8+ (nessun framework), HTML5, CSS3, Bootstrap 5 (via CDN)
- JavaScript vanilla + `fetch` (polling AJAX, nessun WebSocket)
- Storage: MySQL 8 via PDO, con transazioni per le scritture concorrenti
- Sessioni PHP per l'autenticazione (`password_hash` / `password_verify`)
- PhpSpreadsheet (via Composer) per import/export XLSX — vedi punto 4

## 1. Requisiti

- PHP 8.0 o superiore con estensioni standard (`mbstring`, `json`, `pdo_mysql`);
  l'estensione `zip` è necessaria solo per l'export "ZIP con tutte le rose".
- Server Apache (o qualsiasi hosting PHP classico). Funziona anche con il server
  di sviluppo integrato: `php -S localhost:8000`.
- Un database MySQL 5.7+/MariaDB 10.3+ (accesso host/porta/nome
  database/utente/password).

## 2. Installazione

1. Carica il contenuto del repository sull'hosting (o clonalo).
2. Crea un database MySQL (o usane uno già fornito dal tuo hosting) e copia
   `config.local.example.php` in `config.local.php`, inserendo le credenziali
   reali:
   ```php
   define('DB_HOST', 'il-tuo-host');
   define('DB_PORT', 3306);
   define('DB_NAME', 'il-tuo-database');
   define('DB_USER', 'il-tuo-utente');
   define('DB_PASS', 'la-tua-password');
   ```
   `config.local.php` **non va mai versionato su git** (contiene una password
   in chiaro): è già escluso da `.gitignore`.
3. Crea le tabelle eseguendo `sql/schema.sql` sul database (via phpMyAdmin,
   riga di comando, o qualunque client MySQL). In alternativa, il primo avvio
   di `migrate_to_db.php` (punto 5) le crea da solo se mancanti.
4. Assicurati che la cartella `/data` (usata solo per gli snapshot di backup e
   la cache degli avatar, non più per i dati veri e propri) sia **scrivibile**
   dal processo PHP:
   ```bash
   chmod -R 775 data
   ```
5. **Solo se stai migrando un'installazione precedente su CSV**: carica anche
   i vecchi file `data/*.csv`, poi visita una volta `migrate_to_db.php` dal
   browser (es. `https://tuosito.it/fanta/migrate_to_db.php`). Importa i dati
   esistenti nel database appena creato, mostra quante righe ha importato per
   ogni tabella, ed è sicuro da rivisitare per errore (salta le tabelle già
   popolate, a meno di aggiungere `?force=1` all'URL per reimportarle da
   capo). **Al termine elimina `migrate_to_db.php` dal server.** Per
   un'installazione nuova senza dati pregressi questo passo si salta.
6. Nessuna configurazione di percorso è necessaria: l'app **rileva da sola**
   se è installata in radice (`https://tuosito.it/`) o in una sottocartella
   (`https://tuosito.it/fanta/`), confrontando la cartella di `config.php` con
   la document root del sito, e adatta di conseguenza tutti i link, i redirect
   e le chiamate AJAX. Basta caricare i file dove preferisci (anche dentro una
   sottocartella come `/fanta/`) e funziona senza modifiche.
7. Installa le dipendenze PHP con Composer (necessario per import/export XLSX,
   incluso il template "Quotazioni Fantacalcio" usato in questo progetto):
   ```bash
   composer install
   ```
   La cartella `vendor/` **non è versionata** nel repository (troppo pesante,
   >100 MB): va generata a ogni deploy con `composer install`. Se il tuo
   hosting non ha accesso SSH/composer, esegui `composer install` in locale e
   carica l'intera cartella `vendor/` via FTP insieme al resto del progetto.
   Senza questa cartella l'app funziona comunque al 100% (che non ha nulla a
   che vedere col database, richiesto sempre): solo l'upload/export XLSX
   viene disabilitato con un messaggio esplicito, senza errori fatali.

Le cartelle `/data`, `/lib`, `/partials` e `/scripts` includono un `.htaccess`
che blocca l'accesso diretto via browser (i CSV contengono, tra l'altro, gli
hash delle password: **non devono mai essere scaricabili via URL**). Perché
funzioni, la configurazione Apache del sito deve avere `AllowOverride All` (o
almeno `AllowOverride Limit AuthConfig`) sulla document root — è il default
sulla quasi totalità degli hosting condivisi. Nota: il server di sviluppo
integrato `php -S` **non legge i file `.htaccess`** (limitazione nota di PHP,
non un problema dell'app): usalo solo per test locali, mai in produzione.

## 3. Dati demo (per testare subito)

Il repository include già dati pronti all'uso: 1 admin, 10 utenti con
fantateam, 1 asta demo (`DEMO26`, stato `DRAFT`) e — visto che è già stato
importato un listone reale (vedi punto 6.1) — **il listone reale delle
quotazioni Fantacalcio 2026/27** (524 giocatori) al posto dei soliti
giocatori fittizi. Per rigenerare utenti/team/asta da zero (senza toccare il
listone):

```bash
php scripts/seed_demo.php --force
```

Se invece vuoi tornare ai 50 giocatori fittizi originali (es. per test che
non devono usare dati reali), aggiungi `--with-fake-players`:

```bash
php scripts/seed_demo.php --force --with-fake-players
```

Credenziali demo:

| Ruolo  | Nickname       | Password  |
|--------|----------------|-----------|
| Admin  | `admin`        | `admin123`|
| Utente | `user1`…`user10` | `demo123` |

Codice invito dell'asta demo: **`DEMO26`**.

## 4. Creazione di un amministratore (senza usare i dati demo)

Non esiste un form dedicato per creare admin dall'interfaccia (per sicurezza).
Per creare il primo admin:

1. Registrati normalmente da `/register.php` (l'utente viene creato con
   `role = user`).
2. Apri `data/users.csv` e cambia manualmente il valore `role` di quella riga
   da `user` ad `admin` (mantenendo intatte le altre colonne, incluso
   l'hash password).
3. Effettua nuovamente il login: la sessione verrà creata con il ruolo admin.

In alternativa usa direttamente l'admin demo (`admin` / `admin123`).

## 5. Flusso utente

```
Registrazione (nickname + password)
  -> Primo login
  -> Configurazione nome fantateam (/profile.php)
  -> Dashboard (/dashboard.php)
  -> Inserimento codice invito (/join-auction.php)
  -> Attesa (l'asta non è ancora LIVE)
  -> Giorno dell'asta: stesso login, stessa squadra già configurata
  -> Asta LIVE: ogni partecipante dichiara dal proprio telefono i giocatori
     presi e il prezzo pagato; dashboard e display si aggiornano in tempo reale
  -> Fine asta: export rose
```

## 6. Flusso amministratore

L'asta si svolge **verbalmente/di persona come sempre** (o in videochiamata):
l'admin non inserisce gli acquisti al posto dei partecipanti. Il suo ruolo è
predisporre l'asta e fare da arbitro in caso di errori:

1. Login come admin, poi `/admin/auctions.php` → crea una nuova asta
   (nome, data anche futura, budget, limiti rosa POR/DIF/CEN/ATT, codice
   invito). L'asta nasce in stato `DRAFT`.
2. `/import.php` → carica il listone giocatori (CSV o XLSX) e mappa le colonne
   del file sui campi richiesti (`name`, `real_team`, `role`, `quotation`,
   `fvm`) — la mappatura non dipende dall'ordine delle colonne del file.
3. Gli utenti si registrano autonomamente e inseriscono il codice invito da
   `/join-auction.php` per associare il proprio fantateam all'asta.
   L'admin può anche associare/rimuovere una squadra da un'asta manualmente
   da `/admin/users.php` (senza mai eliminare l'utente).
4. Quando tutti i partecipanti sono pronti, porta l'asta a `OPEN` e poi a
   `LIVE` da `/admin/auctions.php`. **Da questo momento ogni partecipante
   dichiara da solo, dal proprio telefono, i giocatori che si aggiudica** (vedi
   punto 8).
5. Apri `/display.php?auction=ID` su un PC collegato a TV/proiettore per la
   vista generale (leggibile da lontano, aggiornamento ogni secondo).
6. Da `/admin/auction.php?id=ID` l'admin supervisiona l'asta: può comunque
   cercare un giocatore e assegnarlo/correggerlo per conto di una squadra (per
   chi non ha il telefono a portata di mano, o in caso di errori), annullare
   l'ultima operazione, modificare prezzo/squadra di un acquisto o svincolare
   un giocatore — vedi punto 7.
7. A fine asta, porta lo stato a `COMPLETED` e poi `ARCHIVED`, quindi esporta
   le rose da `/export.php?auction=ID`.

## 6.1 Import del listone: template "Quotazioni Fantacalcio"

L'app riconosce nativamente il file XLSX "Quotazioni Fantacalcio" (quello
scaricabile ad es. da fantacalcio.it), che verrà ricaricato più volte da qui
al giorno dell'asta man mano che le quotazioni cambiano. In dettaglio:

- Se il file ha più fogli, viene usato automaticamente il foglio **"Tutti"**
  (elenco completo dei giocatori attualmente in rosa in Serie A); il foglio
  **"Ceduti"** (giocatori ormai fuori rosa) e gli eventuali fogli per-ruolo
  vengono ignorati. Se non esiste un foglio "Tutti", viene usato il primo
  foglio del file.
- La riga di intestazione viene individuata automaticamente anche se sopra
  di essa c'è una riga titolo (come nel template ufficiale).
- Colonne riconosciute e mappate automaticamente: `Id` → id ufficiale
  fantacalcio.it (usato per l'avatar del giocatore, vedi sotto), `Nome` →
  nome, `Squadra` → squadra reale, `R` → ruolo classico, `Qt.A` → quotazione,
  `FVM` → FVM. Le colonne "Mantra" (`RM`, `Qt.A M`, `Qt.I M`, `FVM M`) e la
  quotazione iniziale (`Qt.I`) **non** vengono mappate di default (l'app
  gestisce il regime classico P/D/C/A, non il Mantra): puoi comunque
  selezionarle a mano nello step di mappatura se ti servono per altri scopi.
- **Avatar giocatore**: quando il listone include la colonna `Id`, ogni
  giocatore mostra automaticamente la sua card ufficiale
  (`https://content.fantacalcio.it/web/campioncini/21/card/{id}.png`) nella
  ricerca admin, nel popup di assegnazione, nella dashboard partecipante e sul
  display. fantacalcio.it protegge queste immagini dall'hotlinking diretto
  (un `<img>` che le richiama da un altro dominio spesso non le carica), quindi
  l'app le scarica lato server tramite `/avatar.php?id=...`, le mette in cache
  in `data/avatar_cache/` (30 giorni) e le serve dal proprio dominio. Se l'id
  manca o il download fallisce, viene mostrato un pallino colorato con la
  lettera del ruolo al suo posto — nessun errore visibile. Richiede
  l'estensione PHP `curl` (quasi sempre disponibile) o, in mancanza,
  `allow_url_fopen` abilitato.
- **Reimportare il listone è sicuro solo prima che siano stati fatti acquisti**:
  ogni import riassegna da zero gli id interni dei giocatori. Se in
  un'asta esistono già acquisti attivi, l'app blocca l'import con un errore
  esplicito (per non spezzare i riferimenti delle rose già acquistate) —
  in quel caso completa/archivia quell'asta o creane una nuova prima di
  ricaricare un listone aggiornato.

## 7. Pannello admin (supervisione e correzioni)

Nella schermata `/admin/auction.php`:

- **Sinistra**: ricerca testuale (case-insensitive, per sottostringa), filtro
  per ruolo, filtro per squadra reale, ordinamento (nome, ruolo, quotazione,
  fantamedia/FVM). Un pulsante 📣 su ogni riga imposta quel giocatore come
  "attualmente all'asta" (visibile su Display e sulle dashboard dei
  partecipanti) — utile per annunciarlo prima che parta l'offerta verbale.
- Cliccando su un giocatore si apre un popup: scegli la squadra assegnataria e
  il prezzo, poi **ASSEGNA** (o **Invio**). Questa è la via "di emergenza" per
  l'admin (partecipante senza telefono a portata di mano, errore da
  correggere); il flusso normale è l'auto-dichiarazione dei partecipanti
  (punto 8).
- **Destra**: elenco fantateam con crediti residui, posti disponibili,
  massimo spendibile, aggiornato in tempo reale.

Prima di ogni acquisto (sia da admin che da partecipante) il sistema verifica:
crediti disponibili, disponibilità del giocatore, limite massimo per ruolo,
prezzo ≥ 1 e possibilità matematica di completare la rosa (offerta massima =
crediti residui − posti ancora da riempire dopo l'acquisto corrente). Se due
persone dichiarano lo stesso giocatore quasi in contemporanea, vince chi
arriva per primo al server (lock per asta, vedi punto 15): l'altro riceve un
errore "giocatore non disponibile".

Lo storico acquisti in fondo alla pagina permette all'admin di **modificare**
(prezzo, squadra) o **svincolare** (soft-delete, `active=0`) qualsiasi
acquisto — anche quelli auto-dichiarati dai partecipanti. Il pulsante "Annulla
ultima operazione" annulla l'ultimo acquisto registrato sull'intera asta.

## 8. Dashboard partecipante e auto-dichiarazione acquisti

`/dashboard.php` (mobile-first) mostra nome squadra, asta associata, stato,
data, crediti iniziali/residui. Quando l'asta è `LIVE`, la pagina mostra
automaticamente (con polling AJAX ogni 1.5s, nessun refresh manuale): rosa
attuale divisa per ruolo, posti disponibili, ultimo acquisto, prezzo medio per
ruolo, massimo spendibile e giocatore attualmente all'asta.

**Ogni partecipante gestisce da solo i propri acquisti.** Sotto la card
"🛒 Compra un giocatore" può cercare/filtrare/ordinare i giocatori ancora
disponibili (stessi filtri del pannello admin: ruolo, squadra reale,
ordinamento per nome/ruolo/quotazione/fantamedia). Quando si aggiudica un
giocatore durante l'asta dal vivo, lo cerca, ci clicca sopra, inserisce il
prezzo pagato nel popup e conferma con **"HO PRESO QUESTO GIOCATORE"** (o
Invio). Il sistema applica automaticamente le stesse validazioni di budget e
limiti di ruolo; l'acquisto può sempre essere corretto dall'admin in caso di
errore (punto 7). Un partecipante può dichiarare acquisti solo per la propria
squadra (verificato lato server), mai per conto di altri.

## 9. Export finale

Da `/export.php?auction=ID`:

- **CSV completo asta** / **CSV per singola squadra**
- **XLSX completo** / **XLSX per singola squadra** (richiede PhpSpreadsheet)
- **ZIP con tutte le rose** (un CSV per squadra, richiede l'estensione `zip`)

Il formato di export (nomi colonna, ordine, separatore, encoding) è isolato in
`lib/exporters/FantacalcioExporter.php`. **Il formato ufficiale richiesto da
Leghe Fantacalcio non era disponibile in questo repository**: l'exporter usa
quindi una struttura generica e ragionevole. Se in futuro viene fornito un
file CSV/XLSX di esempio ufficiale, basta adattare le costanti/i metodi di
quella singola classe (nessun'altra parte dell'app dipende da quel formato).

## 10. Backup

Uno snapshot delle tabelle principali viene esportato automaticamente in file
CSV leggibili sotto `data/backups/<timestamp>/` ogni volta che un'operazione
modifica i dati (creazione asta, acquisto, annullamento, modifica, import
listone, cambio stato) — **mai** ad ogni polling AJAX. Vengono conservati al
massimo gli ultimi 20 snapshot (i più vecchi vengono rimossi automaticamente).

## 11. Recovery

Per ripristinare uno stato precedente in caso di problemi:

1. Ferma temporaneamente l'accesso all'app (o mettila in manutenzione).
2. Individua lo snapshot desiderato in `data/backups/` (ordinati per
   timestamp).
3. Per ogni file `.csv` dello snapshot, svuota la tabella corrispondente sul
   database e reimportane il contenuto (stesse colonne dell'header CSV) — con
   phpMyAdmin/qualunque client MySQL, oppure con lo stesso script
   `migrate_to_db.php?force=1` puntato temporaneamente su quella cartella.
4. Riavvia l'accesso.

Lo storico completo di tutte le azioni (chi ha fatto cosa e quando) resta
comunque disponibile nella tabella `audit`, che non viene mai troncata
automaticamente.

## 12. Struttura del database

Le tabelle sono definite in `sql/schema.sql`. Ogni tabella ha una colonna
`id` auto-incrementale come chiave primaria (per alcune, indicato tra
parentesi, è un dettaglio interno mai esposto all'applicazione, che non
aveva un "id" nel vecchio CSV originale).

| Tabella | Colonne (oltre a `id`) |
|---|---|
| `users` | `nickname, password_hash, created_at, last_login, active, role` |
| `teams` | `user_id, name, coach_name, logo, created_at, updated_at, active` |
| `auctions` | `name, invite_code, status, auction_date, initial_budget, goalkeepers, defenders, midfielders, attackers, created_at, updated_at` |
| `auction_teams` | `auction_id, team_id, enabled, joined_at` |
| `players` | `name, real_team, role, quotation, fvm, external_id` (listone globale; `external_id` è l'id ufficiale fantacalcio.it, usato per l'avatar) |
| `auction_players` | `auction_id, player_id, available` (`id` interno; disponibilità **per asta**, non globale) |
| `purchases` | `auction_id, player_id, team_id, price, timestamp, active` (storico ufficiale; i crediti residui si calcolano sempre dinamicamente: `initial_budget - SUM(price WHERE active=1)`) |
| `current_auction` | `auction_id, player_id, updated_at` (`id` interno; giocatore attualmente chiamato, per asta) |
| `settings` | `key, value` (`id` interno) |
| `audit` | `timestamp, user_id, action, auction_id, player_id, team_id, price, previous_value, new_value` (`id` interno) |

Stati asta possibili: `DRAFT → OPEN → LIVE → COMPLETED → ARCHIVED`.
Ruoli giocatore: `P` (Portiere), `D` (Difensore), `C` (Centrocampista), `A` (Attaccante).

## 13. Architettura del codice

```
/lib/Database.php           connessione PDO condivisa al database MySQL
/lib/CsvStorage.php          storage centralizzato (nome storico, parla con MySQL: transazioni, lock di riga)
/lib/Schema.php               nomi tabella e intestazioni colonna centralizzati
/lib/Auth.php                  sessioni, requireLogin()/requireAdmin()
/lib/UserService.php           registrazione/login/attivazione utenti
/lib/TeamService.php           gestione fantateam
/lib/AuctionService.php        logica asta: acquisti, undo, svincoli, modifiche, budget
/lib/PlayerService.php         listone e disponibilità per asta
/lib/ImportService.php         parsing e mappatura import listone (CSV/XLSX)
/lib/AuditService.php          scrittura storico azioni
/lib/BackupService.php         snapshot periodici delle tabelle in CSV
/lib/exporters/FantacalcioExporter.php   formato export (unico punto da adattare)

/sql/schema.sql       definizione delle tabelle MySQL
/migrate_to_db.php    migrazione una tantum da vecchi CSV a database (da eliminare dopo l'uso)

/api/*.php    endpoint JSON usati dal frontend via fetch/AJAX
/admin/*.php  area amministrazione
/*.php        pagine pubbliche/autenticate (login, dashboard, display, ecc.)
```

Nessuna pagina PHP contiene logica di business diretta: tutte le pagine e gli
endpoint API richiamano i servizi in `/lib`.

## 14. Sicurezza

- Password mai salvate in chiaro: `password_hash()` / `password_verify()`.
- `session_regenerate_id(true)` dopo ogni login.
- Tutti gli endpoint `/api/*` verificano la sessione (`Auth::apiRequireLogin`/
  `apiRequireAdmin`); le operazioni di scrittura sull'asta sono riservate agli
  admin.
- Verifica di ownership sulle squadre (`team.user_id === session.user_id`),
  eccetto per gli admin.
- Validazione lato server su tutti gli input (prezzo, ruoli, limiti rosa,
  budget); il frontend non è mai considerato affidabile.
- Token di upload generati server-side per l'import (nessun path traversal).
- `.htaccess` che nega l'accesso diretto a `/data`, `/lib`, `/partials`,
  `/scripts`.

## 15. Affidabilità con più dispositivi

Le operazioni che modificano l'asta (acquisto, annullamento, svincolo,
modifica) sono racchiuse in un lock esclusivo per asta
(`data/locks/auction_<id>.lock`) che garantisce l'atomicità dell'intera
sequenza "leggi stato → valida → scrivi", anche quando coinvolge più tabelle
contemporaneamente (`purchases`, `auction_players`, `audit`) — a cui si
aggiungono le transazioni MySQL con lock di riga (`SELECT ... FOR UPDATE`) di
`CsvStorage` stesso per ogni singola scrittura. Non
è possibile acquistare due volte lo stesso giocatore, anche con più
dispositivi collegati in contemporanea (8-12+ testati).
