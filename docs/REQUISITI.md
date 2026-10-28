# Slice – Requisiti per Claude Code

Oct 19, 2026 · @Federico

## Obiettivo

Realizzare una web app mobile-first, solo in italiano, per gestire l'ordine di pizze di un gruppo di famiglie in una serata occasionale: ogni famiglia ordina tramite un proprio link segreto, una sola persona (l'organizzatore) paga l'intero conto in pizzeria e le altre famiglie lo rimborsano.

L'app deve:

- raccogliere gli ordini di ogni famiglia (persona e pizza; i prezzi li inserisce l'organizzatore);
- produrre la lista aggregata delle pizze da dettare al pizzaiolo;
- ridistribuire su tutte le pizze lo sconto ottenuto quando il pizzaiolo arrotonda il totale dello scontrino;
- mostrare a ogni famiglia quanto deve rimborsare, con il dettaglio per persona;
- permettere all'organizzatore di segnare chi ha già rimborsato.

Non ci sono account utente: l'accesso avviene solo tramite link segreti. Il nome dell'app è «Slice»: va usato nel titolo delle pagine, nell'intestazione, nel nome del repository e del progetto Symfony e nel messaggio precompilato di WhatsApp.

## Stack e hosting

Scelta: Symfony su fortrabbit, con database SQLite salvato in un file sullo storage persistente, pubblicato su un sottodominio del dominio personale (es. `pizza.miodominio.it`) con HTTPS. Alternativa: MySQL gestito da fortrabbit, se una delle verifiche qui sotto fallisce.

Perché: fortrabbit offre PHP con storage persistente e deploy automatico al push sul repository GitHub collegato, in linea con le competenze PHP dello sviluppatore. Con al massimo 300 pizze per serata e poche decine di utenti contemporanei SQLite basta e non richiede un servizio database separato. Doctrine astrae il database, quindi il passaggio a MySQL richiede solo di cambiare `DATABASE_URL` e rigenerare le migrazioni.

Verifiche da fare su fortrabbit prima di adottare SQLite (se una fallisce si usa MySQL):

- [x] PHP 8.4 è selezionabile e l'estensione `pdo_sqlite` risulta attiva (pagina di prova temporanea, da cancellare subito dopo).
- [x] Il file del database sopravvive a un nuovo deploy: con `/var/data` escluso nel deploy file, creare un record, rifare il deploy, verificare che il record ci sia ancora. La strategia di deploy predefinita di fortrabbit (`replace`) cancella i file non presenti nel pacchetto di deploy, quindi l'esclusione è indispensabile.
- [x] È chiaro se il piano include backup dello storage; in caso contrario si usa il comando di backup descritto sotto. Esito: backup giornalieri con una sola copia conservata, quindi il comando `app:db:backup` è necessario.

| Componente | Scelta |
| --- | --- |
| Linguaggio | PHP 8.4 o superiore (richiesto da Symfony 8) |
| Framework | Symfony, ultima versione stabile (8.x) |
| Persistenza | Doctrine ORM e Doctrine Migrations |
| Interfaccia | Twig + Symfony UX Live Components e Turbo; Bootstrap 5.3 tramite AssetMapper (importmap), senza Node né build frontend |
| Aggiornamento dati | Polling dei Live Components ogni 15 secondi, niente websocket |
| Database | SQLite in `var/data/app.db`, fuori dalla cartella pubblica e non versionato, con `journal_mode=WAL` e `foreign_keys=ON`; alternativa MySQL di fortrabbit |
| Altri componenti | Validator, protezione CSRF, RateLimiter; job periodici avviati da un URL segreto (vedi «Sicurezza e privacy») |
| Test | PHPUnit, con test unitari sull'algoritmo di sconto e test funzionali sui flussi |
| Ambiente locale | Lando, servizio unico PHP 8.4 + Apache, nessun database server |
| Deploy | Push sul repository GitHub `slice`, collegato a fortrabbit tramite GitHub App; impostazioni versionate in `.fortrabbit/deploy.yml` (comandi di build, migrazioni Doctrine dopo il deploy, esclusione di `/var/data` e `/var/backups`) |

Le migrazioni su SQLite vanno scritte senza ricreare tabelle con `DROP TABLE` (come fa a volte `doctrine:migrations:diff`): con `foreign_keys=ON` cancellerebbero a cascata gruppi e pizze. Il test `MigrationsPreserveDataTest` lo verifica.

Con SQLite serve un backup giornaliero (comando `app:db:backup`, avviato anche dall'URL segreto dei job): crea una copia coerente del database con `VACUUM INTO` in `var/backups/` e conserva le ultime 7 copie. Con MySQL il comando non serve.

Claude Code deve includere un file `README.md` con le istruzioni di installazione locale, la configurazione delle variabili d'ambiente (incluse le due varianti di `DATABASE_URL`), i passi di deploy su fortrabbit (GitHub App e deploy file), il collegamento del dominio personalizzato e come scaricare i backup. Prima di scrivere le istruzioni di deploy deve verificare la documentazione aggiornata di fortrabbit e quella di Symfony.

## Ruoli, link e ciclo di vita

Ci sono due ruoli, ciascuno identificato solo da un link segreto: l'organizzatore, che crea la serata e paga il conto, e il gruppo (famiglia), che ordina e rimborsa.

| Link | Chi lo riceve | Cosa permette |
| --- | --- | --- |
| `/s/{token_admin}` | Organizzatore | Gestire la serata: gruppi, stato, conto, sconto, rimborsi, riepiloghi |
| `/g/{token_gruppo}` | Tutti i membri di una famiglia | Inserire e modificare le pizze della propria famiglia, vedere il proprio riepilogo e lo stato del rimborso |
| `/i/{token_invito}` | Tutte le famiglie (chat comune) | Vedere i nomi dei gruppi già aggiunti e aggiungere il proprio gruppo, solo con ordini aperti |

Un gruppo può essere composto anche da una sola persona. L'organizzatore appartiene a uno dei gruppi, che viene marcato come «gruppo dell'organizzatore» e non deve rimborsare nulla.

Stati della serata, in ordine:

1. **Ordini aperti**: i gruppi aggiungono, modificano ed eliminano pizze.
2. **Ordini chiusi**: l'organizzatore ha bloccato gli ordini per dettarli al pizzaiolo; le pagine dei gruppi diventano di sola lettura. L'organizzatore può riaprire gli ordini.
3. **Conto inserito**: l'organizzatore ha inserito il totale pagato e la modalità di distribuzione; i gruppi vedono la propria quota. L'organizzatore può modificare totale e modalità finché la serata non è archiviata; ogni modifica ricalcola tutte le quote.
4. **Archiviata**: tutto in sola lettura, anche per l'organizzatore, salvo un'azione esplicita «Riapri».

I rimborsi si possono segnare solo negli stati «Conto inserito» e «Archiviata».

Transizioni ammesse (tutte verificate lato server):

| Da | A | Azione |
| --- | --- | --- |
| Ordini aperti | Ordini chiusi | «Chiudi ordini», con conferma |
| Ordini chiusi | Ordini aperti | «Riapri ordini» |
| Ordini chiusi | Conto inserito | Inserimento del conto (il conto non si può inserire con ordini aperti) |
| Conto inserito | Archiviata | «Archivia», con conferma |
| Archiviata | Conto inserito | «Riapri» |

Da «Conto inserito» non si torna a «Ordini aperti» o «Ordini chiusi»: per una pizza dimenticata l'organizzatore modifica direttamente le pizze del gruppo e le quote si ricalcolano.

## Funzionalità

### Home

- Pagina iniziale con una breve spiegazione e il pulsante «Crea una serata».
- Creazione: nome della serata (es. «Pizza da Gino»), data, nome della pizzeria (facoltativo), nome dell'organizzatore, note per il rimborso (facoltative, es. IBAN o numero Satispay, mostrate ai gruppi).
- Dopo la creazione l'organizzatore viene portato alla pagina admin, con un avviso ben visibile: «Salva questo link, è l'unico modo per gestire la serata» e un pulsante per copiarlo.
- Rotte: home `/`, creazione `/nuova-serata`. La data è precompilata con il giorno corrente; le note per il rimborso hanno al massimo 1000 caratteri. L'avviso compare subito dopo la creazione, mentre il link admin con il pulsante di copia resta sempre visibile in testa alla pagina admin.

### Organizzatore (`/s/{token_admin}`)

**Gruppi**

- Aggiungere, rinominare ed eliminare gruppi (nome, es. «Famiglia Rossi»). Un gruppo con pizze si può eliminare solo dopo conferma.
- Indicare quale gruppo è il proprio.
- Link d'invito, generato alla creazione della serata e mostrato nella pagina admin con «Copia link», «Condividi su WhatsApp» (messaggio «Slice – {nome serata}: aggiungi il tuo gruppo e ordina le pizze qui: {link}») e «Rigenera link» (il vecchio smette di funzionare; gruppi e loro link restano validi; non disponibile da serata archiviata). Così l'organizzatore non deve creare i gruppi in anticipo, ma può continuare a farlo.
- Per ogni gruppo: copiare il link, condividerlo su WhatsApp (link `https://wa.me/?text=...` con messaggio precompilato) e rigenerarlo (il vecchio link smette di funzionare).
- Vedere e modificare le pizze di qualsiasi gruppo, in qualunque stato non archiviato.
- Gruppi e link si gestiscono in ogni stato tranne «Archiviata». Il nome del gruppo è unico nella serata senza distinzione di maiuscole, accenti e spazi; al massimo 50 gruppi.
- Messaggio WhatsApp precompilato: «Slice – {nome serata}: ecco il link per ordinare le pizze di {nome gruppo}: {link}».
- Conferma esplicita prima di eliminare un gruppo (con il numero di pizze che verranno eliminate) e prima di rigenerare un link. La lista dei gruppi si aggiorna con polling ogni 15 secondi.

**Lista per il pizzaiolo**

- Pizze raggruppate per nome normalizzato, con quantità, ordinate alfabeticamente, e numero totale di pizze.
- Pulsante «Copia lista» in testo semplice (es. `3 x Margherita`).
- Vista alternativa per gruppo, utile per la consegna ai tavoli.
- Avviso se la stessa pizza ha prezzi diversi tra gruppi (possibile errore di inserimento), con l'elenco delle righe coinvolte.
- Dettagli di implementazione: il testo copiato contiene solo le righe `quantità x nome` in ordine alfabetico (senza distinzione di maiuscole e accenti); il totale delle pizze è mostrato a schermo. Il nome mostrato è la forma digitata più frequente, a parità la più recente. La vista per gruppo riporta le quantità per pizza di ogni gruppo. L'avviso compare quando due pizze con lo stesso nome normalizzato hanno prezzi diversi in gruppi diversi (prezzi diversi nello stesso gruppo non generano avviso) ed elenca tutte le righe di quella pizza con gruppo, persona, nome digitato e prezzo. La lista si aggiorna con polling ogni 15 secondi.

**Unisci pizze**

- Riquadro «Unisci pizze» prima della lista per il pizzaiolo, visibile con almeno due pizze diverse e in ogni stato tranne «Archiviata».
- «Possibili doppioni»: le coppie di nomi simili (stessa regola del suggerimento nella pagina del gruppo), ciascuna con il numero di pizze e i pulsanti «Unisci in «A»» e «Unisci in «B»».
- Unione libera tra due pizze qualsiasi con due elenchi («Pizza» e «Unisci in»), per i casi che la somiglianza non trova.
- Conferma esplicita prima di unire. Le pizze unite prendono il nome mostrato della destinazione e il suo ultimo prezzo noto; se la destinazione non ha prezzo mantengono il loro. Persone e gruppi non cambiano. Con il conto inserito l'unione è rifiutata se rende impossibile il calcolo in modalità uguale, come le altre modifiche alle pizze. Dopo l'unione si aggiornano subito lista per il pizzaiolo, «Pizze senza prezzo» e anteprima del conto.

**Conto e sconto**

- Somma dei prezzi inseriti («totale da listino»).
- Pizze senza prezzo: riquadro «Pizze senza prezzo» con una riga per nome di pizza (nome mostrato come nella lista per il pizzaiolo e numero di pizze); il prezzo inserito vale per tutte le pizze senza prezzo con quel nome. Finché ne resta anche una, il conto non si può inserire («Ci sono N pizze senza prezzo: completale prima di inserire il conto.»). Con il conto inserito ogni pizza deve avere un prezzo. Le pizze senza prezzo non generano avvisi di prezzi incoerenti; il suggerimento di prezzo usa l'ultimo prezzo noto.
- Campo «Totale pagato» (importo dello scontrino arrotondato dal pizzaiolo). Se coincide con il totale da listino, lo sconto è zero.
- Scelta della modalità: «Proporzionale al prezzo» (predefinita) o «Uguale per ogni pizza».
- Anteprima prima di confermare: sconto totale in euro e in percentuale, quota di ogni gruppo.
- Se il totale pagato è maggiore del totale da listino, mostrare un avviso e chiedere conferma esplicita (è quasi sempre un errore di inserimento).

**Riepilogo e rimborsi**

- Tabella dei gruppi: nome, numero pizze, totale da listino, quota dopo lo sconto, stato del rimborso.
- Per ogni gruppo diverso dal proprio: interruttore «Ha rimborsato», con data e ora registrate automaticamente.
- Totale da ricevere, totale già ricevuto, totale mancante.
- Riga «Differenza da arrotondamento»: somma delle quote meno totale pagato, mostrata solo all'organizzatore (vedi la sezione sull'algoritmo).
- Dettagli di implementazione del conto: il totale pagato va da 0,01 € a 50.000,00 € (virgola o punto, al massimo due decimali). L'anteprima si aggiorna mentre si digita e il pulsante «Conferma conto» (poi «Aggiorna conto») compare solo se il calcolo è possibile; con totale pagato maggiore del totale da listino serve la casella «Confermo l'importo». Lo sconto in percentuale ha due decimali (es. «10,45 %»); con maggiorazione si mostra «Maggiorazione applicata». Con conto inserito sono rifiutate le modifiche alle pizze che renderebbero impossibile il calcolo in modalità uguale (con la pizza coinvolta e il suggerimento della modalità proporzionale) e l'eliminazione dell'ultima pizza.
- Rimborsi: l'interruttore registra data e ora (fuso Europe/Rome, impostato per tutta l'app); non compare per il gruppo dell'organizzatore né per i gruppi senza pizze, che mostrano «Nessun rimborso dovuto». Se il gruppo dell'organizzatore non è indicato, il riepilogo lo segnala e tutti i gruppi risultano da rimborsare. Riepilogo e pannello di stato si aggiornano subito dopo la conferma del conto e con polling ogni 15 secondi.

### Gestione delle pizzerie (`/gestione`)

- Area protetta da username e password, con un solo account definito da `ADMIN_USERNAME` e `ADMIN_PASSWORD_HASH`; con una delle due vuota nessuno può entrare. Login a form con CSRF, blocco dopo 5 tentativi falliti al minuto, pulsante «Esci». È l'unica parte dell'app con un login: serate, gruppi e inviti restano accessibili solo con i link segreti.
- Elenco delle pizzerie con numero di pizze e ingredienti: aggiungere, rinominare ed eliminare (con conferma; se la pizzeria è usata da serate, il messaggio indica quante perderanno il menu, ma non le pizze ordinate).
- Pagina di una pizzeria (`/gestione/pizzerie/{id}`): ingredienti (aggiungere, rinominare, eliminare con conferma che indica da quante pizze verrà tolto) e menu (pizze con nome, prezzo facoltativo e ingredienti scelti con caselle; modificare ed eliminare con conferma). Pizze e ingredienti in ordine alfabetico.
- Limiti: nome della pizzeria al massimo 100 caratteri, dell'ingrediente 60, della pizza 80; prezzo come per le pizze ordinate (0,01 €–100 €). Nomi unici senza distinzione di maiuscole, accenti e spazi: le pizzerie nell'archivio, ingredienti e pizze nella propria pizzeria.
- Le azioni dei Live Components di quest'area passano da `/gestione/_components/…` (coperte dal firewall) e ogni componente verifica comunque il ruolo, così le richieste costruite a mano sulla rotta pubblica `/_components/…` vengono rifiutate.

### Invito (`/i/{token_invito}`)

- Intestazione con nome della serata, data, pizzeria e organizzatore; elenco dei **soli nomi** dei gruppi già aggiunti, senza link alle loro pagine né altri dati.
- Con ordini aperti: campo «Nome del gruppo» e pulsante «Aggiungi il mio gruppo». Valgono le regole dei gruppi creati dall'organizzatore (nome obbligatorio e unico senza distinzione di maiuscole, accenti e spazi; massimo 50 gruppi). Dopo l'aggiunta si viene reindirizzati alla pagina del nuovo gruppo, che mostra l'avviso «Salva questo link: è l'unico modo per tornare alle pizze del tuo gruppo» con il pulsante «Copia link».
- Negli altri stati: elenco dei nomi e messaggio «Non è più possibile aggiungere gruppi: gli ordini sono chiusi.»; anche le richieste costruite a mano vengono rifiutate.
- Token non valido o rigenerato: 404 generico; la rotta rientra nel rate limiting delle rotte con token.

### Gruppo (`/g/{token_gruppo}`)

- Intestazione con nome della serata, pizzeria, data e nome del gruppo.
- Con ordini aperti: aggiungere una pizza indicando nome della persona e nome della pizza, senza prezzo (chi ordina di solito non lo conosce: lo inserisce l'organizzatore); modificare ed eliminare le righe del proprio gruppo. Prima del conto il gruppo non vede prezzi né totali, solo l'elenco per persona con il numero di pizze. Una pizza aggiunta o rinominata dal gruppo prende l'ultimo prezzo noto per quel nome nella serata, altrimenti resta senza prezzo; cambiando solo la persona il prezzo resta. Il server ignora qualsiasi prezzo inviato da un gruppo.
- Il campo pizza propone in autocompletamento i nomi già inseriti nella serata da qualsiasi gruppo, per evitare varianti come «margherita» e «Margherita ».
- Mentre si digita (anche nella modifica), se esiste già una pizza simile compare «Esiste già «Salamino»: vuoi usare questo nome?» con il pulsante «Usa «Salamino»»; è un invito, non un blocco. Due nomi normalizzati diversi sono simili se uno contiene tutte le parole dell'altro («Salamino» e «Salamino piccante») o se differiscono per al massimo 1 carattere (nomi fino a 6 caratteri) o 2 (nomi più lunghi), es. «Magherita» e «Margherita». Se ci sono più nomi simili si propone il più vicino; le parole in più contano meno di un errore di battitura.
- Quando l'organizzatore modifica le pizze di un gruppo, il form ha anche il prezzo (facoltativo: se non è noto si lascia vuoto e la pizza mostra «Prezzo da definire»; i totali sommano i prezzi noti e indicano quante pizze sono senza prezzo); selezionando un nome viene proposto l'ultimo prezzo usato per quella pizza.
- Il campo persona propone i nomi già usati nel gruppo.
- Elenco delle pizze del gruppo raggruppate per persona, con il totale.
- Con ordini chiusi: stesso elenco in sola lettura, con messaggio «Ordini chiusi dall'organizzatore».
- Con conto inserito: per ogni pizza prezzo di listino e prezzo scontato; totale per persona; totale da rimborsare all'organizzatore; sconto applicato in percentuale; note per il rimborso; stato «Rimborsato il …» oppure «Da rimborsare».
- Il gruppo dell'organizzatore vede la propria quota con la dicitura «Nessun rimborso dovuto».
- Un gruppo non vede le pizze, le quote né lo stato dei rimborsi degli altri gruppi.
- Dettagli di implementazione: il prezzo (solo organizzatore) accetta interi o al massimo due decimali, con virgola o punto ed eventuale simbolo «€» (es. «7», «7,5», «7.50 €»). Dopo l'inserimento il campo persona resta compilato per aggiungere più pizze alla stessa persona. I suggerimenti mostrano solo nomi delle pizze della serata (e ultimo prezzo, solo all'organizzatore), mai nomi di persone o gruppi altrui; a parità di frequenza vince la forma digitata più recente. Al massimo 300 pizze per serata. Pagine e liste si aggiornano con polling ogni 15 secondi.
- L'organizzatore modifica le pizze di un gruppo dalla pagina `/s/{token_admin}/g/{token_gruppo}`, raggiungibile dal pulsante «Pizze» nella lista gruppi.

## Modello dati

Tutti gli importi sono salvati come interi in centesimi di euro; non si usano mai float per i soldi. Le quote scontate non vengono salvate ma ricalcolate a ogni lettura da un servizio dedicato, così una modifica di totale o modalità è sempre coerente.

Le colonne di tipo enum sono stringhe mappate su enum PHP (`enumType` di Doctrine), così lo schema funziona sia su SQLite sia su MySQL. Le chiavi esterne con cancellazione a cascata richiedono `foreign_keys=ON` su SQLite.

Nel codice e nello schema i nomi sono in inglese; i testi dell'interfaccia restano in italiano. Il gruppo (famiglia) è l'entità `Eater`, tabella `eaters`: il nome `groups` è evitato perché è una parola riservata in MySQL 8. Le colonne `created_at` e `updated_at` (timestamp) sono presenti in tutte le tabelle.

**Serate** (`evenings`, entità `Evening`)

| Campo | Tipo | Note |
| --- | --- | --- |
| id | bigint | Chiave primaria |
| admin\_token | string(64), unico | Generato in modo casuale e sicuro |
| invite\_token | string(64), unico | Link d'invito, generato in modo casuale e sicuro; rigenerabile. Nullable nello schema solo per migrare le serate esistenti |
| name | string | Obbligatorio |
| date | date | Obbligatoria |
| pizzeria | string, nullable |  |
| organizer\_name | string | Obbligatorio |
| refund\_notes | text, nullable | Note per il rimborso, mostrate ai gruppi |
| status | enum | `orders_open` (ordini aperti), `orders_closed` (ordini chiusi), `bill_entered` (conto inserito), `archived` (archiviata) |
| paid\_total\_cents | int, nullable | Totale pagato, valorizzato con il conto |
| discount\_mode | enum | `proportional` (predefinita), `equal` (uguale per ogni pizza) |
| organizer\_eater\_id | FK nullable | Gruppo dell'organizzatore; impostata a NULL se il gruppo viene eliminato |
| created\_at, updated\_at | datetime |  |

**Gruppi** (`eaters`, entità `Eater`)

| Campo | Tipo | Note |
| --- | --- | --- |
| id | bigint |  |
| evening\_id | FK | Cancellazione a cascata |
| token | string(64), unico | Rigenerabile |
| name | string | Unico all'interno della serata, senza distinzione di maiuscole e spazi (vincolo DB sul nome ripulito, controllo case-insensitive in validazione) |
| reimbursed\_at | datetime, nullable | Data del rimborso; null = da rimborsare |
| created\_at, updated\_at | datetime |  |

**Pizze** (`pizzas`, entità `Pizza`)

| Campo | Tipo | Note |
| --- | --- | --- |
| id | bigint |  |
| eater\_id | FK | Cancellazione a cascata |
| person | string(60) | Obbligatoria |
| pizza\_name | string(80) | Obbligatorio, salvato come digitato ma senza spazi iniziali/finali e spazi multipli |
| normalized\_name | string(80) | Minuscolo, senza accenti, spazi compattati; usato per aggregare |
| price\_cents | int, nullable | Tra 1 e 10.000 (0,01 € – 100 €); null = prezzo da definire, obbligatorio prima del conto; lo inserisce solo l'organizzatore |
| created\_at, updated\_at | datetime |  |

Nella lista per il pizzaiolo il nome mostrato per ogni gruppo di pizze uguali è la forma digitata più frequente.

Limiti: al massimo 50 gruppi per serata e 300 pizze per serata.

**Pizzerie** (`pizzerias`, entità `Pizzeria`): name string(100), normalized\_name string(100) unico (senza distinzione di maiuscole, accenti e spazi). La serata ha `pizzeria_id` (FK nullable, impostata a NULL se la pizzeria viene eliminata) e conserva comunque il nome in `pizzeria`.

**Ingredienti** (`ingredients`, entità `Ingredient`): pizzeria\_id FK con cancellazione a cascata, name string(60), normalized\_name string(60), unico per pizzeria.

**Pizze del menu** (`menu_pizzas`, entità `MenuPizza`): pizzeria\_id FK con cancellazione a cascata, name string(80), normalized\_name string(80) unico per pizzeria, price\_cents int nullable (1–10.000). Gli ingredienti sono nella tabella `menu_pizza_ingredients` (cancellazione a cascata su entrambi i lati). Le pizze ordinate copiano solo il nome: modificare o eliminare il menu non tocca le serate.

## Algoritmo dello sconto

Ogni pizza riceve un prezzo scontato arrotondato al centesimo; la quota di un gruppo è la somma dei prezzi scontati delle sue pizze, e l'eventuale scarto dovuto agli arrotondamenti non viene ridistribuito ma mostrato all'organizzatore.

Notazione, tutto in centesimi: n = numero di pizze della serata, p\_i = prezzo di listino della pizza i, S = somma dei p\_i, T = totale pagato, D = S − T = sconto totale.

**Modalità proporzionale** (predefinita): ogni pizza è scontata della stessa percentuale.

```latex
p'_i = \operatorname{round}\left( p_i \cdot \frac{T}{S} \right)
```

**Modalità uguale per pizza**: ogni pizza è scontata dello stesso importo.

```latex
p'_i = \operatorname{round}\left( p_i - \frac{D}{n} \right)
```

Se in modalità uguale anche una sola pizza risulterebbe con prezzo negativo dopo l'arrotondamento al centesimo, il sistema non applica il calcolo e mostra all'organizzatore un errore che indica la pizza coinvolta e suggerisce la modalità proporzionale.

**Differenza da arrotondamento**: Δ = somma dei p'\_i − T. Va mostrata nel riepilogo dell'organizzatore così:

- Δ = 0: «Nessuna differenza».
- Δ > 0: «Riceverai Δ € in più di quanto hai pagato».
- Δ < 0: «Riceverai |Δ| € in meno di quanto hai pagato».

Regole di implementazione:

- Calcolo solo con interi: la proporzionale si calcola come `intdiv(2 * p_i * T + S, 2 * S)`, la uguale come arrotondamento di `(n * p_i - D) / n` con la stessa tecnica. Arrotondamento «half up» (0,5 centesimi va verso l'alto, cioè verso +∞, anche per valori negativi): in modalità uguale un prezzo esatto di −0,33 o −0,5 centesimi diventa 0 ed è ammesso, −1,5 diventa −1 ed è un errore.
- La logica sta in una classe di servizio pura (`App\Discount\DiscountCalculator`) che riceve l'elenco dei prezzi, T e la modalità e restituisce i prezzi scontati e Δ, senza dipendere dal database: è la parte più importante da coprire con test.
- Totale per persona e totale per gruppo sono somme dei p'\_i, mai ricalcolati proporzionalmente sui totali.
- Se S = 0 (nessuna pizza) il conto non si può inserire.
- Se T > S («maggiorazione», confermata dall'organizzatore) le stesse formule valgono con D negativo.
- La quota da rimborsare di un gruppo è la sua quota; per il gruppo dell'organizzatore è zero. Totale da ricevere = somma delle quote degli altri gruppi.

## Interfaccia, sicurezza e requisiti non funzionali

**Interfaccia**

- Mobile-first, utilizzabile con una mano su schermi da 360 px; pulsanti di almeno 44 px.
- Tutti i testi in italiano; importi nel formato `12,50 €`; date nel formato `19/10/2026`.
- Il campo prezzo accetta sia la virgola sia il punto come separatore decimale.
- Tema chiaro e scuro secondo le impostazioni del dispositivo, con i color modes di Bootstrap.
- Messaggi di conferma brevi dopo ogni azione e conferma esplicita prima di eliminazioni, chiusura ordini e rigenerazione dei link.
- Accessibilità di base: etichette sui campi, contrasto sufficiente, navigazione da tastiera.

**Sicurezza e privacy**

- Token generati con `bin2hex(random_bytes(32))` o equivalente crittograficamente sicuro; mai id numerici negli URL.
- Un token di gruppo non dà accesso a nessun dato degli altri gruppi né alle funzioni admin; ogni azione dei Live Components verifica lato server che la riga appartenga al gruppo del token.
- Token non validi: pagina 404 generica.
- Header `X-Robots-Tag: noindex` e meta `noindex` su tutte le pagine tranne la home.
- Job periodici (cancellazione delle serate scadute e backup giornaliero) avviati da `GET /_jobs/run/{segreto}`, con il segreto nella variabile `JOBS_SECRET` (almeno 32 caratteri, confronto a tempo costante, 404 se assente o errato). L'URL è idempotente (al massimo un backup al giorno) e va richiamato almeno una volta al giorno da un servizio di cron esterno o a mano. Il segreto finisce nei log di accesso: va trattato come una password e rigenerato se esposto. La risposta mostra anche l'IP del client visto dall'app, per verificare `TRUSTED_PROXIES`. Gli stessi job sono disponibili come comandi `app:evenings:purge` e `app:db:backup`.
- Pagine di errore generiche in italiano (404 «Pagina non trovata», 429 «Troppe richieste»), senza dettagli tecnici.
- Rate limiting per IP, con quattro limiti separati: creazione di serate 10 all'ora; apertura delle pagine `/s/…` e `/g/…` 300 ogni 5 minuti; aggiornamenti automatici e azioni dei Live Components 1.500 ogni 5 minuti; link con token inesistente 30 ogni 5 minuti, superati i quali l'IP è bloccato temporaneamente su tutte le rotte con token. Risposta 429 con pagina in italiano. Il limite più utile è quello sulla creazione: indovinare un token da 64 caratteri esadecimali è comunque impraticabile. Su fortrabbit l'app riceve direttamente l'IP reale dei client (verificato il 26/10/2026 con l'URL dei job), quindi `TRUSTED_PROXIES` non va impostata; la verifica va ripetuta se si aggiunge un proxy. Il rate limiting è disattivato solo nell'ambiente `dev`, per le prove locali; resta attivo in `prod` e in `test`.
- Protezione CSRF e validazione lato server di tutti gli input.
- Dati personali minimi (solo nomi di battesimo o soprannomi). Le serate vengono cancellate automaticamente 90 giorni dopo la data della serata, tramite un job avviato dall'URL segreto (vedi sotto); il periodo è indicato nella home.

**Altri requisiti**

- Concorrenza: più membri dello stesso gruppo possono inserire pizze contemporaneamente; ogni pizza è una riga indipendente, quindi non servono lock. Le pagine si aggiornano con polling ogni 15 secondi.
- Ogni azione che cambia lo stato della serata è controllata lato server (es. niente aggiunta di pizze a ordini chiusi, anche con richieste costruite a mano).
- Tempo di risposta sotto 500 ms per le pagine principali con 300 pizze.

## Casi di test e criteri di accettazione

I casi seguenti vanno implementati come test unitari di `DiscountCalculator` con questi valori esatti (importi in euro, calcoli in centesimi).

| # | Modalità | Prezzi di listino | Totale pagato | Prezzi scontati attesi | Δ atteso |
| --- | --- | --- | --- | --- | --- |
| 1 | Proporzionale | 7,00 · 8,00 · 12,00 · 6,50 | 30,00 | 6,27 · 7,16 · 10,75 · 5,82 | 0,00 |
| 2 | Proporzionale | 10,00 · 10,00 · 10,00 | 20,00 | 6,67 · 6,67 · 6,67 | +0,01 |
| 3 | Proporzionale (half up) | 5,00 · 5,00 | 9,99 | 5,00 · 5,00 | +0,01 |
| 4 | Uguale | 7,00 · 8,00 · 12,00 | 25,00 | 6,33 · 7,33 · 11,33 | −0,01 |
| 5 | Uguale | 5,00 · 30,00 | 24,00 | Errore: la pizza da 5,00 € andrebbe a −0,50 € | — |
| 6 | Proporzionale | 5,00 · 30,00 | 24,00 | 3,43 · 20,57 | 0,00 |
| 7 | Entrambe | 7,00 · 8,00 | 15,00 | 7,00 · 8,00 | 0,00 |
| 8 | Proporzionale (maggiorazione confermata) | 10,00 | 11,00 | 11,00 | 0,00 |

Test di riepilogo (caso 1): Famiglia A con le pizze da 7,00 e 8,00 €, Famiglia B con quelle da 12,00 e 6,50 €; la Famiglia A è dell'organizzatore. Quote attese: A 13,43 € (nessun rimborso), B 16,57 €; totale da ricevere 16,57 €.

Criteri di accettazione (spuntati quelli verificati dai test automatici in `tests/Functional/AcceptanceTest.php` e negli altri test; restano da verificare a mano la prova con due telefoni reali e il funzionamento su fortrabbit con il dominio in HTTPS):

- [x] Si crea una serata dalla home e si ottiene il link admin con avviso di salvataggio.
- [x] Si creano gruppi, se ne indica uno come proprio e si condividono i link (copia e WhatsApp).
- [ ] Due membri dello stesso gruppo, da due telefoni, inseriscono pizze e vedono entro 15 secondi quelle dell'altro.
- [x] Un link di gruppo non mostra mai pizze, quote o rimborsi di altri gruppi; un link rigenerato rende inutilizzabile il precedente.
- [x] La lista per il pizzaiolo aggrega «Margherita», «margherita» e « margherita » in una sola riga e si copia come testo.
- [x] Con ordini chiusi, i gruppi non possono aggiungere o modificare pizze, neanche con richieste costruite a mano.
- [x] Inserito il totale pagato, ogni gruppo vede prezzi di listino, prezzi scontati, totale per persona e totale da rimborsare; i valori coincidono con quelli dei test.
- [x] Cambiando modalità o totale, tutte le quote si aggiornano.
- [x] La differenza da arrotondamento compare solo nella pagina admin, con il messaggio corretto.
- [x] L'organizzatore segna i rimborsi e vede totale ricevuto e mancante; il gruppo vede «Rimborsato il …».
- [ ] Tutti i test passano e l'app funziona su fortrabbit con il dominio personalizzato in HTTPS.

## Fuori ambito e piano di lavoro

**Fuori ambito per questa versione**: account e login (salvo l'unico account dell'area di gestione delle pizzerie), bevande e altri prodotti, coperto, varianti e aggiunte (es. «Margherita baby» si inserisce come pizza a sé), elementi condivisi tra persone, pagamenti online, rimborsi tra persone diverse dall'organizzatore, notifiche, lingue diverse dall'italiano, storico tra serate.

**Piano di lavoro per Claude Code**, da eseguire in ordine e con un commit per fase:

1. Verifiche SQLite su fortrabbit (sezione Stack), poi setup del progetto Symfony con Doctrine, Symfony UX, Bootstrap, PHPUnit e SQLite; `README.md` iniziale.
2. Entità Doctrine, relazioni e migrazioni secondo il modello dati.
3. `DiscountCalculator` con tutti i test della tabella dei casi; non proseguire finché non passano.
4. Home e creazione serata.
5. Pagina admin: gruppi, link, gestione stati.
6. Pagina gruppo: inserimento pizze, autocompletamento, sola lettura a ordini chiusi.
7. Lista per il pizzaiolo e avviso prezzi incoerenti.
8. Conto, scelta modalità, anteprima, riepiloghi e rimborsi.
9. Sicurezza: verifiche di appartenenza, rate limiting, noindex, cancellazione automatica dopo 90 giorni, backup giornaliero del database; test feature sui criteri di accettazione.
10. Rifinitura grafica mobile, tema scuro, istruzioni di deploy su fortrabbit e collegamento del dominio.

In caso di ambiguità non coperte da questo documento, Claude Code deve chiedere prima di decidere, salvo dettagli puramente grafici.

