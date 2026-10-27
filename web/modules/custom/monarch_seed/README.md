# monarch_seed

Custom Drupal module that holds the canonical baseline seed content for the MonarchWorks headless content hub.

## Purpose

This module owns the `content/` directory, which stores Default Content exports (HAL+JSON YAML files) for all baseline content entities:

- Taxonomy terms (Property vocabulary: `monarchworks-website`, `monarch-app`)
- `marketing_section` nodes: `hero`, `what-we-building`, `contact-cta`
- `site_meta` nodes: `home`, `terms`, `privacy`

Legal policy content (Terms of Use, Privacy Policy) is not seeded here — AMS
owns it as published PolicyDocument versions, and the website fetches the
current version live from the AMS public policy-documents endpoint (task 443).
- Media entities (referenced by content above; bytes stored in MinIO `drupal-content` bucket)

## Seed location convention

`web/modules/custom/monarch_seed/content/` is the single source of truth for seed entities. All Jenkins jobs target this directory:

- **Export:** `drush default-content:export-module monarch_seed`
- **Import / update:** `drush monarch-seed:sync` (this module's command, `src/Drush/Commands/MonarchSeedCommands.php`)

There is no `default-content:import-module` command in the installed
`default_content` (2.0.0-beta1). Its importer only runs when a module is first
enabled, only creates entities, and fails with a unique-key violation on a UUID
that already exists, so it cannot apply an edit to an existing node.

## Editing seed content (edit, import, snapshot)

1. Edit the YAML under `content/` (or author in the local Drupal admin and
   export with `default-content:export-module`).
2. Run the local bootstrapper Jenkins job `local-drupal-import-seed`
   (http://localhost:4470). It imports Drupal config, makes sure this module
   is enabled, then runs `drush monarch-seed:sync --revision-log=local-drupal-import-seed`.
   It imports the checkout on disk and does not run `git pull` (`PULL_LATEST`
   is `false` in the job's shell).
3. `monarch-seed:sync` reads every `content/<entity_type>/*.yml`, orders the
   files by `_meta.depends`, then for each UUID:
   - **missing** in Drupal: creates it, the same way `default_content` does;
   - **present** and some YAML field differs: copies those fields onto the
     entity and saves a new revision with the job's revision log;
   - **present** and identical: does not save it.
   It ends with `updated N, created M, unchanged K`; running it again reports
   `updated 0`. `changed` and revision metadata are never compared, and
   translations are not compared (no seed file has any). Deleting a YAML file
   does not delete the entity; unpublish with `status: false` instead.
4. Regenerate the website snapshot in `monarchworks-website`
   (`npm run snapshot:drupal`, see
   `project/docs/developer/monarchworks-website-content-architecture.md`).

Do not edit seed nodes by hand with `drush php:eval`; edit the YAML and run
the job.

## Fresh install flow

```
drush site:install --existing-config -y
drush config:import -y
drush pm:enable monarch_seed -y   # first enable imports content/ via default_content
drush monarch-seed:sync            # idempotent; reports created/updated/unchanged
```

See `SETUP.md` at the repository root for the full ordered runbook.
