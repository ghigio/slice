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

L'app risponde all'URL mostrato da `lando info`.

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

Il codice sta sul repository GitHub `slice`, collegato a fortrabbit tramite la GitHub App: ogni push sul branch collegato avvia un deploy. Le impostazioni di deploy sono versionate in `.fortrabbit/deploy.yml` e, se il file è presente, prevalgono sulla dashboard:

- build: `composer install --no-dev`, `importmap:install`, `asset-map:compile`;
- dopo il deploy: creazione di `var/data` e `var/backups`, migrazioni Doctrine, `cache:clear`;
- strategia `replace` con esclusione di `/var/data` e `/var/backups`, così database e backup non vengono cancellati dal deploy.

Impostazioni da fare in dashboard: root path `public`, PHP 8.4 con estensione `pdo_sqlite`, variabili `APP_ENV=prod`, `APP_DEBUG=0`, `APP_SECRET`, `DATABASE_URL`.

Istruzioni dettagliate, dominio personalizzato e download dei backup: da completare nella fase 10.

## Verifiche SQLite su fortrabbit

| Verifica | Esito |
| --- | --- |
| PHP 8.4 selezionabile e `pdo_sqlite` attiva | Superata (20/10/2026) |
| Il file del database sopravvive a un nuovo deploy | Superata (20/10/2026), con `/var/data` escluso in `.fortrabbit/deploy.yml` |
| Backup dello storage inclusi nel piano | Giornalieri, ma viene conservata una sola copia |

Esito: si adotta SQLite. Poiché fortrabbit conserva un solo backup, resta necessario il comando `app:db:backup` (fase 9), che mantiene le ultime 7 copie in `var/backups/`.
