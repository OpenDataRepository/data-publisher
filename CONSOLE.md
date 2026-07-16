# CONSOLE.md — ODR Console Command Reference

The console entry point is **`php app/console`** (this is a pre-Flex Symfony Standard Edition
layout — there is no `bin/console`). Custom ODR commands use the `odr_*` prefix and are grouped by
subsystem (`odr_cache`, `odr_record`, `odr_csv_import`, …).

```bash
php app/console list                 # all available commands
php app/console list odr_record      # commands in one namespace
php app/console help odr_record:mass_edit
```

---

## Running the console on linked / WordPress-integrated instances

Linked instances keep their **own** `app/config`, `var/cache`, and `var/log`, selected at runtime by
the `ODR_APP_DIR` constant. The web front controllers set it from the request; **the console resolves
it in this order** (it prints `[console] targeting instance: …` when it lands on one):

1. **`cd` into the site** — the console auto-detects the instance from your working directory:
   ```bash
   cd /home/rruff/data-publisher
   php /path/to/source/app/console cache:clear --env=prod   # targets /home/rruff/data-publisher
   ```
2. **Env override** (cron / scripts / ambiguous CWD):
   ```bash
   ODR_INSTANCE=/home/rruff/data-publisher php app/console cache:clear --env=prod   # instance root
   ODR_APP_DIR=/home/rruff/data-publisher/app php app/console ...                   # raw app dir
   ```

Running from the source tree (or any CWD with no instance above it) targets the source tree, unchanged.

**Every** per-instance command below must be run this way — otherwise it silently hits the source tree.
See [UPGRADE_TO_SF7.md](UPGRADE_TO_SF7.md) §8 for the full explanation.

### Fan out to all instances at once

```bash
cp app/config/instances.list.dist app/config/instances.list   # once; one instance root per line
php app/console-all cache:clear --env=prod
php app/console-all doctrine:migrations:migrate --no-interaction
```

`app/console-all` runs a single console command against every instance in `app/config/instances.list`
(gitignored; `#` comments and blank lines ignored), continues past a failing site, and exits non-zero
if any failed.

---

## Operational commands (deploy / maintenance)

Standard Symfony/vendor commands you need to run the site. On a linked instance, target it per the
section above.

| Command | Purpose |
|---|---|
| `php app/console cache:clear --env=prod` | Rebuild the container + clear cache (run after any config or code change). |
| `php app/console assets:install web/` | Publish bundle web assets into `web/`. |
| `php app/console doctrine:migrations:status` | Show applied/pending migrations. |
| `php app/console doctrine:migrations:migrate --no-interaction` | Apply pending schema migrations (replaces `doctrine:schema:update`). |
| `php app/console doctrine:migrations:version 'DoctrineMigrations\VersionYYYYMMDDHHMMSS' --add --no-interaction` | Mark a migration applied without running it (needs the FQCN identifier). |
| `php app/console lexik:jwt:generate-keypair --overwrite` | Generate the API JWT keypair into `app/config/jwt/` (passphrase in `config.yml`). |
| `php app/console debug:router` | List routes (e.g. verify the `odr_api_*` / `/odr`-prefixed routes resolve). |
| `php app/console debug:container <id>` | Inspect a service definition / wiring. |
| `php app/console lint:twig src/ app/Resources/` | Lint Twig templates. |

Cache/asset layout moved to `var/cache` + `var/log` in SF7; `set_cache_permissions.sh` ACLs them.

---

## Custom ODR commands

Many `odr_*` commands come in a **worker / monitor / clear** trio:
- **worker** — a long-running daemon that waits on a Beanstalkd tube and processes jobs;
- **monitor** — restarts its worker after it exits;
- **clear_*** — drains (deletes all jobs from) that tube.

In normal operation the **workers and monitors are launched and kept alive by the Node daemons in
[`background_services/`](background_services/)** — you rarely invoke them by hand. The `clear_*`
commands are manual maintenance for draining a stuck queue. See
[background_services/](background_services/) for the daemon ↔ command mapping (e.g.
`clear_tube_export_worker.js`).

### Cache
| Command | Purpose |
|---|---|
| `odr_cache:flush` | Delete **all** Memcached entries on the server. |

### Records & data
| Command | Purpose |
|---|---|
| `odr_record:mass_edit` | *(worker)* Update many datarecords/datafields at once. |
| `odr_record:clear_mass_edit` | Drain the `mass_edit` tube. |
| `odr_record:migrate` | *(worker)* Migrate stored data from one fieldtype to another and rebuild caches. |
| `odr_record:clear_migrate` | Drain the `migrate_datafields` tube. |
| `odr_record:precache` | Pre-cache records so search runs faster and graphs exist. |
| `odr_record:rebuild_thumbnails` | Rebuild thumbnails for **all** uploaded images. |
| `odr_record:storage_entity_cleanup` | *(worker)* Delete useless blank storage entities. |
| `odr_record:clear_storage_entity_cleanup` | Drain the storage-entity-cleanup tube. |
| `odr_record:tag_rebuild` | *(worker)* Ensure parents of selected tags are also selected. |
| `odr_record:clear_tag_rebuild` | Drain the `tag_rebuild` tube. |

### CSV import / export
| Command | Purpose |
|---|---|
| `odr_csv_import:validate` | *(worker)* Validate a row of CSV import data. |
| `odr_csv_import:worker` | *(worker)* Import CSV rows for a datatype. |
| `odr_csv_import:clear_validate` / `odr_csv_import:clear_worker` | Drain the CSV-import validate / worker tubes. |
| `odr_csv_export:worker_express` | *(worker)* Write lines of CSV export data to file. |
| `odr_csv_export:finalize_express` | *(worker)* Finish a CSV export file. |
| `odr_csv_export:clear_worker` / `odr_csv_export:clear_finalize` | Drain the CSV-export worker / finalize tubes. |

### XML import
| Command | Purpose |
|---|---|
| `odr_xml_import:start` | *(worker)* Wait for an import request for a datatype. |
| `odr_xml_import:validate` | *(worker)* Validate an XML file scheduled for import. |
| `odr_xml_import:worker` | *(worker)* Import an XML file into the database. |
| `odr_xml_import:file_download` | *(worker)* Download a remote file for import. |
| `odr_xml_import:clear_start` / `clear_validate` / `clear_worker` / `clear_file_download` | Drain the corresponding XML-import tubes. |

### Datatypes & templates
| Command | Purpose |
|---|---|
| `odr_datatype:clone_master` | Clone a datatype from a master template. |
| `odr_datatype:clone_monitor` | Restart the `odr_datatype:clone` process after it exits. |
| `odr_datatype:clone_and_link_datatype` | Clone a datatype from a master template and link it. |
| `odr_datatype:clone_and_link_monitor` | Restart the clone-and-link process after it exits. |
| `odr_datatype:clone_datatype_preloader` | Build a queue of cloned databases from templates. |
| `odr_datatype:clone_datatype_preloader_monitor` | Restart the preloader after it exits. |
| `odr_datatype:sync_template` | Synchronize a datatype with its master template. |
| `odr_datatype:clear_sync_template` | Drain the `synch_template` tube. |
| `odr_theme:clone` | Clone a theme/view for users to customize. |

### Metadata
| Command | Purpose |
|---|---|
| `odr_metadata:start` | Run the DQL that finds which entities need metadata entries. |
| `odr_metadata:build` | Transfer entity properties into their `*Meta` tables. |
| `odr_metadata:clear_metaqueue` | Drain the build-metadata queue. |

### Crypto (encrypted file/image storage)
| Command | Purpose |
|---|---|
| `odr_crypto:worker` | *(worker)* Encrypt/decrypt a File or Image object. |
| `odr_crypto:clear_worker` | Drain the `crypto_requests` tube. |

### Static rendering
| Command | Purpose |
|---|---|
| `odr_static_render:enqueue` | Enqueue static-render jobs for every public top-level record of a datatype. |

### AMCSD dataset update (GraphBundle) — run in order
| Command | Purpose |
|---|---|
| `odr_amcsd_update:1_parse` | Parse the AMCSD source files. |
| `odr_amcsd_update:2_decrypt` | Decrypt the parsed files. |
| `odr_amcsd_update:3_diff` | Compute the diff against current data. |
| `odr_amcsd_update:4_references` | Create reference records. |
| `odr_amcsd_update:5_update` | Apply the update. |

---

## Background service daemons

The Node daemons in [`background_services/`](background_services/) launch and supervise the worker
commands above (graph rendering, record pre-caching, RRUFF/AMCSD/IMA file builders, statistics, etc.).
They talk to Beanstalkd (`:11300`) and Redis. Start/stop them per your process manager; the
`clear_tube_*.js` scripts (and the `odr_*:clear_*` console commands) drain stuck tubes. See
[background_services/DEV_SETUP_ARM.md](background_services/DEV_SETUP_ARM.md) for the local setup.
