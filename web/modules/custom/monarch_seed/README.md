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
- **Import:** `drush default-content:import-module monarch_seed`

## Fresh install flow

```
drush site:install --existing-config -y
drush config:import -y
drush pm:enable monarch_seed -y
drush default-content:import-module monarch_seed
```

See `SETUP.md` at the repository root for the full ordered runbook.
