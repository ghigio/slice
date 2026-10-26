# Slice

Web app mobile-first per gestire l'ordine di pizze di un gruppo di famiglie e dividere il conto. Requisiti completi in [`docs/REQUISITI.md`](docs/REQUISITI.md).

Stack: Symfony 8.1 (PHP 8.4), Doctrine ORM e Migrations, Twig con Symfony UX Live Components e Turbo, Bootstrap 5.3 tramite AssetMapper (nessun Node), SQLite. Hosting su fortrabbit.

## Sviluppo locale

Prerequisiti: [Lando](https://lando.dev) con Docker. Il servizio unico `appserver` usa PHP 8.4 con Apache e nessun database server: SQLite è un file in `var/data/`.

```bash
lando start
lando composer install
mkdir -p var/data
lando console doctrine:migrations:migrate --no-interaction
```

L'app risponde all'URL mostrato da `lando info`. `composer install` scarica anche gli asset di Bootstrap e Stimulus (`importmap:install`) in `assets/vendor/`, che non è versionato.

I test (`lando phpunit`) ricreano a ogni esecuzione il database `var/data/test.db` applicando le migrazioni.

Comandi utili:

| Comando | Uso |
| --- | --- |
| `lando console …` | Console Symfony (`bin/console`) |
| `lando composer …` | Composer |
| `lando phpunit` | Test |
| `lando php …` | PHP nel container |

In sviluppo gli asset sono serviti dinamicamente da AssetMapper: non eseguire `asset-map:compile` in locale (se capita, svuotare `public/assets/`).

## Variabili d'ambiente

I valori predefiniti sono in `.env`; le modifiche locali vanno in `.env.local` (non versionato). In produzione si impostano dalla dashboard di fortrabbit.

| Variabile | Descrizione |
| --- | --- |
| `APP_ENV` | `dev` in locale, `prod` in produzione |
| `APP_SECRET` | Stringa casuale segreta, obbligatoria in produzione |
| `DATABASE_URL` | Connessione al database, vedi sotto |
| `DEFAULT_URI` | URL pubblico dell'app, usato dai comandi console |
| `JOBS_SECRET` | Segreto (almeno 32 caratteri) dell'URL `/_jobs/run/{segreto}` che avvia pulizia e backup |
| `TRUSTED_PROXIES` | Proxy fidati davanti all'app, per vedere l'IP reale dei client nel rate limiting (vedi deploy) |

`DATABASE_URL`, variante SQLite (predefinita):

```
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data/app.db"
```

Variante MySQL gestito da fortrabbit (da usare solo se le verifiche SQLite falliscono; credenziali dalla dashboard):

```
DATABASE_URL="mysql://UTENTE:PASSWORD@HOST:3306/DATABASE?serverVersion=8.0&charset=utf8mb4"
```

Su SQLite ogni connessione attiva `journal_mode=WAL` e `foreign_keys=ON` (`src/Doctrine/SqlitePragmaMiddleware.php`). I test usano `var/data/test.db`.

## Deploy su fortrabbit

Riferimenti verificati il 26/10/2026: documentazione fortrabbit (deploy con GitHub App, deploy file, domini esterni, HTTPS, SSH/SFTP/scp, variabili d'ambiente) e documentazione Symfony sui proxy (`deployment/proxies`). Dove la documentazione fortrabbit non dà un valore (target del CNAME, tempi del certificato), il valore si legge nella dashboard.

### 1. Creazione dell'app

1. Installa la GitHub App di fortrabbit sul tuo account GitHub (in fase di registrazione o dalle impostazioni dell'account nella dashboard) e concedi l'accesso al repository `slice`.
2. Crea l'app scegliendo il repository esistente. Ogni branch corrisponde a un environment: collega `main` all'environment di produzione.
3. Nelle impostazioni PHP dell'environment scegli **PHP 8.4** e verifica che l'estensione **pdo_sqlite** sia attiva.
4. Se non hai scelto il template Symfony, imposta il root path su `public`.

### 2. Variabili d'ambiente

Nell'editor delle variabili dell'environment (sintassi dotenv, una `CHIAVE=valore` per riga; salvando si sostituisce l'intero elenco):

```
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<stringa casuale>
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data/app.db"
DEFAULT_URI=https://pizza.tuodominio.it
JOBS_SECRET=<almeno 32 caratteri casuali>
TRUSTED_PROXIES=<vedi il punto 5>
```

Per generare i segreti: `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. La documentazione non dice se la modifica delle variabili richiede un nuovo deploy: se l'app non vede un valore aggiornato, rifai il deploy.

### 3. Deploy

Ogni push sul branch collegato avvia il deploy. Le impostazioni sono in `.fortrabbit/deploy.yml` e, quando il file esiste, sostituiscono i campi corrispondenti della dashboard (restano in dashboard branch, trigger, permessi di deploy, versioni di PHP e Node):

- build: `composer install --no-dev`, `importmap:install`, `asset-map:compile`; se la build fallisce resta online il codice precedente;
- dopo il deploy: creazione di `var/data` e `var/backups`, migrazioni Doctrine, `cache:clear`; se un comando fallisce il deploy risulta fallito ma il nuovo codice è già online;
- strategia `replace` con esclusione di `/var/data` e `/var/backups`, così database e backup non vengono cancellati.

### 4. Job periodici

Pulizia delle serate scadute (90 giorni dopo la data) e backup giornaliero partono dall'URL segreto:

```
curl -fsS https://pizza.tuodominio.it/_jobs/run/<JOBS_SECRET>
```

Va chiamato almeno una volta al giorno, per esempio da un servizio di cron esterno o a mano; chiamarlo più volte è innocuo (un solo backup al giorno). Con segreto assente, troppo corto o errato risponde 404. Il segreto compare nei log di accesso: trattalo come una password e rigeneralo se esposto. La risposta riporta serate cancellate, esito del backup, IP del client visto dall'app e header `X-Forwarded-For`.

### 5. Proxy fidati e rate limiting

I limiti per IP funzionano solo se l'app vede l'IP reale dei client. La documentazione fortrabbit non descrive proxy o header inoltrati, quindi va verificato:

1. Senza `TRUSTED_PROXIES`, apri l'URL dei job dal telefono con la rete mobile e confronta «IP del client» con il tuo IP pubblico.
2. Se coincide, non serve altro.
3. Se «IP del client» è un indirizzo interno e il tuo IP compare in `X-Forwarded-For`, l'app è dietro un proxy. Se conosci gli indirizzi del proxy, indicali (`TRUSTED_PROXIES=10.0.0.0/8`, per esempio); altrimenti la documentazione Symfony consente `TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR` solo se l'app è raggiungibile esclusivamente attraverso il proxy, perché in caso contrario un client potrebbe falsificare il proprio IP. Nel dubbio chiedi conferma al supporto fortrabbit.
4. Ripeti il punto 1: «IP del client» deve mostrare il tuo IP pubblico.

Esito della verifica (26/10/2026): da rete mobile «IP del client» coincide con l'IP pubblico del telefono, quindi l'app riceve direttamente l'IP reale e `TRUSTED_PROXIES` **non va impostata**. Se in futuro si aggiunge un proxy (per esempio Cloudflare), la verifica va ripetuta.

### 6. Dominio personalizzato e HTTPS

1. In dashboard: *New* → *Add a new domain*, scegli l'environment, il tipo *external domain* e inserisci il sottodominio (es. `pizza.tuodominio.it`).
2. Dal tuo provider DNS crea un record **CNAME** per il sottodominio con il valore mostrato nella panoramica del dominio in dashboard. Rimuovi eventuali record AAAA del sottodominio e non toccare gli altri record (soprattutto MX). Se il dominio ha record CAA, ne serve uno per `letsencrypt.org`.
3. Attendi la propagazione DNS (dipende dal TTL dei vecchi record). Il certificato Let's Encrypt viene emesso e rinnovato automaticamente; le richieste HTTP ricevono un redirect 301 verso HTTPS.
4. Aggiorna `DEFAULT_URI` con il nuovo indirizzo e verifica che la home risponda in HTTPS.

### 7. Backup

I backup dell'app sono in `var/backups/app-AAAA-MM-GG.sqlite` (ultime 7 copie). fortrabbit fa inoltre un proprio backup giornaliero dello storage, ma ne conserva una sola copia.

Download di una copia (serve una chiave SSH registrata; utente e host sono nella dashboard):

```
scp {app-env-id}@ssh.{region}.frbit.app:var/backups/app-2026-10-31.sqlite ./
```

In alternativa con un client SFTP: host `ssh.{region}.frbit.app`, utente `{app-env-id}`, porta 22, autenticazione con chiave SSH.

Ripristino: carica la copia come `var/data/app.db` (per esempio con `scp ./app-2026-10-31.sqlite {app-env-id}@ssh.{region}.frbit.app:var/data/app.db`), elimina eventuali `var/data/app.db-wal` e `var/data/app.db-shm` e verifica il sito. Conviene farlo quando nessuno sta usando l'app.

## Verifiche SQLite su fortrabbit

| Verifica | Esito |
| --- | --- |
| PHP 8.4 selezionabile e `pdo_sqlite` attiva | Superata (20/10/2026) |
| Il file del database sopravvive a un nuovo deploy | Superata (20/10/2026), con `/var/data` escluso in `.fortrabbit/deploy.yml` |
| Backup dello storage inclusi nel piano | Giornalieri, ma viene conservata una sola copia |

Esito: si adotta SQLite. Poiché fortrabbit conserva un solo backup, resta necessario il backup dell'app (URL dei job o comando `app:db:backup`), che mantiene le ultime 7 copie in `var/backups/`.
