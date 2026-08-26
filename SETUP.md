# MonarchWorks Drupal Hub — Integrated Bring-Up Runbook

This runbook is executed by the integrated bring-up phase AFTER the Drupal
container is live and the database is reachable.  Do not run these steps during
static scaffolding — a live site is required.

All drush commands run inside the container:

```
docker exec monarchai-deploy-drupal-1 vendor/bin/drush <command>
```

---

## Prerequisites (already done by scaffolding)

- `composer.json` requires: `consumers`, `simple_oauth`, `jsonapi_extras`,
  `s3fs`, `default_content` (all present — run `composer install` on first
  container build).
- `web/sites/default/settings.php` contains the s3fs configuration block
  (env-driven: `S3FS_*` vars; defaults match local Docker stack).
- MinIO bucket `drupal-content` is created by `init-minio.sh` on startup
  with `anonymous set download` policy (public read).
- Custom module `web/modules/custom/monarch_seed/` skeleton exists with
  `content/` directory (populated by export step below).

---

## Step 1 — Install Drupal

```bash
docker exec monarchai-deploy-drupal-1 vendor/bin/drush site:install standard \
  --db-url="pgsql://innovate:12345678@postgres/drupal" \
  --site-name="MonarchWorks Hub" \
  --account-name=admin \
  --account-pass=CHANGE_ME \
  --no-interaction \
  -y
```

If the database already has a prior install, add `--existing-config` and skip
the content-type / field steps that conflict.

---

## Step 2 — Enable the Decoupled Module Stack

```bash
docker exec monarchai-deploy-drupal-1 vendor/bin/drush pm:enable \
  jsonapi \
  consumers \
  simple_oauth \
  jsonapi_extras \
  s3fs \
  default_content \
  monarch_seed \
  -y
```

JSON:API is a Drupal core module; the rest are contrib.

---

## Step 3 — Configure s3fs

### 3a. Verify settings (already in settings.php)

The following config keys are pre-set in `settings.php` (env-driven):

| Key | Default |
|-----|---------|
| `endpoint` | `http://s3:9000` |
| `bucket` | `drupal-content` |
| `access_key` | `innovate` |
| `secret_key` | from `S3FS_SECRET_KEY` / `POSTGRES_PASSWORD` |
| `region` | `us-east-1` |
| `use_path_style_endpoint` | `TRUE` |
| `disable_ssl` | `TRUE` |

### 3b. Refresh the s3fs file table

```bash
docker exec monarchai-deploy-drupal-1 vendor/bin/drush s3fs:refresh-cache
```

### 3c. Set s3:// as the default public file scheme

Via Drush config-set or Admin UI → Configuration → File system:

```bash
docker exec monarchai-deploy-drupal-1 vendor/bin/drush config:set \
  system.file default_scheme s3 -y
```

Alternatively via Admin UI:
- Admin → Configuration → Media → File system
- Set "Default download method" to **S3 File System**
- Save

### 3d. Smoke test

Upload any file via Admin → Content → Media → Add media.
Verify the object appears in MinIO console (http://localhost:4401) under
`drupal-content/`.

---

## Step 4 — Create the Property Taxonomy Vocabulary

Admin UI path: **Admin → Structure → Taxonomy → Add vocabulary**

| Field | Value |
|-------|-------|
| Name | Property |
| Machine name | `property` |

Add two terms (Admin → Structure → Taxonomy → Property → Add term):

| Name | Machine name (auto) |
|------|---------------------|
| `monarchworks-website` | `monarchworks-website` |
| `monarch-app` | `monarch-app` |

Alternatively via Drush:

```bash
docker exec monarchai-deploy-drupal-1 vendor/bin/drush php:eval "
\$vocab = \Drupal\taxonomy\Entity\Vocabulary::create([
  'vid' => 'property',
  'name' => 'Property',
])->save();
\Drupal\taxonomy\Entity\Term::create([
  'vid' => 'property',
  'name' => 'monarchworks-website',
])->save();
\Drupal\taxonomy\Entity\Term::create([
  'vid' => 'property',
  'name' => 'monarch-app',
])->save();
print 'Done';
"
```

---

## Step 5 — Create Content Types

> **Note (content model has grown since this section was written).** This step
> documents the original three-type model (`marketing_section`, `legal_document`,
> `site_meta`) used to bootstrap the hub. The site has since moved to a
> **page-composition** model — `marketing_page`, a richer `marketing_section`
> (with `field_page`, `field_weight`, `field_section_type`, `field_section_data`),
> `product`, and scoped `nav_menu`/`nav_link`. The current model is captured in
> `config/sync` and reproduced by `drush config:import`; it is documented in
> `project/docs/developer/monarchworks-website-content-architecture.md` (developer)
> and `project/docs/user/monarchworks-website-composing-pages.md` (editor). Use
> those as the source of truth; the steps below remain useful only for
> understanding the original baseline.

All content types are created via Admin → Structure → Content types → Add content type (or via Drush php:eval).

### 5a. marketing_section

| Setting | Value |
|---------|-------|
| Name | Marketing Section |
| Machine name | `marketing_section` |
| Title label | Title (keep default; not displayed — used as admin label) |

Fields to add (Admin → Structure → Content types → Marketing Section → Manage fields):

| Label | Machine name | Field type | Notes |
|-------|-------------|------------|-------|
| Section Key | `field_section_key` | Text (plain) | Required; values: `hero`, `what-we-building`, `contact-cta` |
| Eyebrow | `field_eyebrow` | Text (plain) | Optional |
| Heading | `field_heading` | Text (plain) | Optional |
| Body | `field_body` | Text (formatted, long) | Optional |
| CTA Label | `field_cta_label` | Text (plain) | Optional |
| CTA Href | `field_cta_href` | Text (plain) | Optional |
| Media | `field_media` | Entity reference → Media | Optional; cardinality 1 |
| Property | `field_property` | Entity reference → Taxonomy term (vocabulary: property) | Required; cardinality 1 |

### 5b. legal_document — RETIRED (task 443)

The `legal_document` content type has been removed. Legal policy content
(Terms of Use, Privacy Policy) is owned by AMS as published `PolicyDocument`
versions; the website's `/terms` and `/privacy` pages fetch the current
published document client-side from the AMS public policy-documents endpoint.
Drupal no longer stores any legal text.

### 5c. site_meta

| Setting | Value |
|---------|-------|
| Name | Site Meta |
| Machine name | `site_meta` |

Fields:

| Label | Machine name | Field type | Notes |
|-------|-------------|------------|-------|
| Page Key | `field_page_key` | Text (plain) | Required; values: `home`, `terms`, `privacy` |
| SEO Title | `field_seo_title` | Text (plain) | Required |
| SEO Description | `field_seo_description` | Text (plain) | Required |
| Property | `field_property` | Entity reference → Taxonomy term (vocabulary: property) | Required; cardinality 1 |

---

## Step 6 — Generate Simple OAuth Keys

```bash
docker exec monarchai-deploy-drupal-1 vendor/bin/drush php:eval "
\Drupal::service('simple_oauth.key_generator')->generateKeys('../oauth-keys');
print 'Keys generated at ../oauth-keys';
"
```

Then configure the key paths in Admin → Configuration → Simple OAuth:
- Public key: `../oauth-keys/public.key`
- Private key: `../oauth-keys/private.key`

---

## Step 7 — Create the Marketing Site Consumer

Admin UI path: **Admin → Configuration → Services → Consumers → Add Consumer**

| Field | Value |
|-------|-------|
| Label | Marketing Site |
| Client ID | `marketing-site` |
| New Secret | (generate and record securely) |
| Scopes / Roles | Authenticated (or a custom role with read-only content access) |
| Description | Astro marketing site consumer — read access to monarchworks-website property slice |

The Astro site will authenticate with this client ID + secret to obtain a
bearer token, then pass it as `Authorization: Bearer <token>` on JSON:API
requests.

---

## Step 8 — Author Baseline Seed Content

Create all nodes via Admin → Content → Add content.
Tag every node with `field_property = monarchworks-website`.

### 8a. marketing_section — hero

Source file: `monarchworks-website/src/components/sections/Hero.astro`

| Field | Value |
|-------|-------|
| Title (admin) | Hero — monarchworks-website |
| field_section_key | `hero` |
| field_eyebrow | (none — Hero.astro has no eyebrow) |
| field_heading | `We're building a new way to <em>create software.</em>` |
| field_body | `Monarch Works is developing Create—a platform where human expertise and AI agents collaborate through structured specifications to deliver complete, production-ready software systems.` |
| field_cta_label | `Learn More` |
| field_cta_href | `#about` |
| field_media | (none) |
| field_property | `monarchworks-website` |

Note: the heading contains an `<em>` italic span. Use the formatted heading
field or store the raw HTML; the Astro consumer renders it.

### 8b. marketing_section — what-we-building

Source file: `monarchworks-website/src/components/sections/WhatWeBuilding.astro`

| Field | Value |
|-------|-------|
| Title (admin) | What We're Building — monarchworks-website |
| field_section_key | `what-we-building` |
| field_eyebrow | `What We're Building` |
| field_heading | `Specifications as the source of truth. AI as the execution layer.` |
| field_body | Three paragraphs (paste as HTML): |
| field_cta_label | (none) |
| field_cta_href | (none) |
| field_property | `monarchworks-website` |

field_body HTML:

```html
<p>Current AI coding tools generate fragments from vague prompts—fast but unreliable. The result: security vulnerabilities, unmaintainable code, and projects that collapse under real-world pressure.</p>

<p>Create takes a different approach. Business professionals define what they need through structured, reviewable specifications. AI agents—specialized in security, architecture, UX, and more—collaborate to expand these specs and generate complete systems: frontend, backend, APIs, tests, and infrastructure.</p>

<p>Every decision is tracked. Every change is reviewable. The specification becomes the permanent asset; the code becomes regenerable for any technology stack.</p>
```

### 8c. marketing_section — contact-cta

Source file: `monarchworks-website/src/components/sections/ContactCTA.astro`

| Field | Value |
|-------|-------|
| Title (admin) | Contact CTA — monarchworks-website |
| field_section_key | `contact-cta` |
| field_eyebrow | (none) |
| field_heading | `Get in Touch` |
| field_body | `Have a question or want to learn more about what we do? We'd love to hear from you.` |
| field_cta_label | `Send` |
| field_cta_href | (contact form action — not a nav link; Astro renders its own form) |
| field_property | `monarchworks-website` |

### 8d / 8e. legal_document seeds — RETIRED (task 443)

The Terms of Use and Privacy Policy seed nodes have been removed along with
the `legal_document` content type. AMS is the single source of legal text —
documents are seeded and published there (see the AMS PolicyDocument seed),
and the website fetches the current published version live.

### 8f. site_meta — home

Source file: `monarchworks-website/src/pages/index.astro` (seo object)

| Field | Value |
|-------|-------|
| Title (admin) | Site Meta: Home |
| field_page_key | `home` |
| field_seo_title | `Home` |
| field_seo_description | `Monarch Works is developing Create—a platform where human expertise and AI agents collaborate through structured specifications to deliver complete, production-ready software systems.` |
| field_property | `monarchworks-website` |

### 8g. site_meta — terms

Source file: `monarchworks-website/src/pages/terms.astro` (seo object)

| Field | Value |
|-------|-------|
| Title (admin) | Site Meta: Terms |
| field_page_key | `terms` |
| field_seo_title | `Terms of Use` |
| field_seo_description | `Terms of Use for Monarch Works services and the Create Platform.` |
| field_property | `monarchworks-website` |

### 8h. site_meta — privacy

Source file: `monarchworks-website/src/pages/privacy.astro` (seo object)

| Field | Value |
|-------|-------|
| Title (admin) | Site Meta: Privacy |
| field_page_key | `privacy` |
| field_seo_title | `Privacy Policy` |
| field_seo_description | `Privacy Policy for Monarch Works - how we collect, use, and protect your data.` |
| field_property | `monarchworks-website` |

### 8i. Navigation / footer links (site_meta or nav content type)

Source files: `monarchworks-website/src/components/Navigation.astro` and
`monarchworks-website/src/components/Footer.astro`

Navigation is currently minimal (logo + brand name only in `Navigation.astro`).
Footer links:

| Label | Href |
|-------|------|
| Contact | `/#contact` |
| Privacy | `/privacy` |
| Terms | `/terms` |

These links are static in the current site. Model them either as additional
`site_meta` entries (field_page_key: `nav-footer`) or as a simple JSON field
on a global settings entity — document the decision in task 053.

---

## Step 9 — Verify JSON:API Endpoints

Confirm content is reachable with property filter:

```bash
# All marketing sections for monarchworks-website property
curl "http://localhost:4478/jsonapi/node/marketing_section?filter[field_property.name]=monarchworks-website"

# Specific section key
curl "http://localhost:4478/jsonapi/node/marketing_section?filter[field_property.name]=monarchworks-website&filter[field_section_key]=hero"

# Site meta
curl "http://localhost:4478/jsonapi/node/site_meta?filter[field_property.name]=monarchworks-website"
```

All endpoints should return 200 with the seeded content.

---

## Step 10 — Export Config

```bash
docker exec monarchai-deploy-drupal-1 vendor/bin/drush config:export -y
```

This writes all Drupal configuration (content types, fields, vocabulary, s3fs
settings, consumer, simple_oauth config) to `config/sync/` (outside the web
root, tracked in git per `$settings['config_sync_directory'] = '../config/sync'`).

Commit the `config/sync/` directory to git.

---

## Step 11 — Export Seed Content

```bash
docker exec monarchai-deploy-drupal-1 vendor/bin/drush default-content:export-module monarch_seed
```

This writes HAL+JSON YAML files for all nodes, taxonomy terms, and media
entities to `web/modules/custom/monarch_seed/content/`.

Commit the `web/modules/custom/monarch_seed/content/` directory to git.

---

## Step 12 — Verify Fresh Install

On a clean database:

```bash
docker exec monarchai-deploy-drupal-1 vendor/bin/drush site:install standard \
  --db-url="pgsql://innovate:12345678@postgres/drupal" \
  --no-interaction -y

docker exec monarchai-deploy-drupal-1 vendor/bin/drush config:import -y

docker exec monarchai-deploy-drupal-1 vendor/bin/drush pm:enable monarch_seed -y

docker exec monarchai-deploy-drupal-1 vendor/bin/drush php:eval "\Drupal::service('default_content.importer')->importContent('monarch_seed');"
```

Then run the JSON:API curl checks from Step 9 to confirm all baseline content
is present and filterable.

---

## JSON:API Contract (pinned — Astro consumer builds against these)

| Content type | Endpoint | Key filter |
|-------------|----------|------------|
| marketing_section | `/jsonapi/node/marketing_section` | `filter[field_property.name]=monarchworks-website&filter[field_section_key]=<key>` |
| site_meta | `/jsonapi/node/site_meta` | `filter[field_property.name]=monarchworks-website&filter[field_page_key]=<key>` |

Section keys: `hero`, `what-we-building`, `contact-cta`
Meta page keys: `home`, `terms`, `privacy`

Legal policy content (Terms of Use, Privacy Policy) is not served from Drupal —
the website fetches it client-side from the AMS public policy-documents
endpoint (task 443).

For content with media: append `&include=field_media,field_media.field_media_image`

Consumer auth header: `Authorization: Bearer <token>` (obtained from Simple
OAuth token endpoint with `client_id=marketing-site`).

---

## Property taxonomy terms (pinned)

| Term name | Usage |
|-----------|-------|
| `monarchworks-website` | All content for the Astro marketing site |
| `monarch-app` | Future: App content sections |

---

## MinIO bucket

| Bucket | Policy | Purpose |
|--------|--------|---------|
| `drupal-content` | `anonymous download` (public read) | Drupal managed-file uploads (images, PDFs) |

Console: http://localhost:4401 (innovate / 12345678)
