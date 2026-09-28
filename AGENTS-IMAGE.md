# AGENTS-IMAGE — FA Image, Environment, Role & Play Architecture

Companion to the master `AGENTS.md` and `AGENTS_ARCH.md`. This file records the
**deploy-time architecture** of the KSF FA web stack: how the runtime image is
built, how a pod's environment (mounts/overlays) is assembled, what the ansible
role `ksf.frontaccounting` does, and what the play passes in. Every `ksf_FA_*`
module depends on this layout — read it before touching deployment, container
image files, or mount paths.

---

## 1. Core rule — the FA version is NEVER baked into the image

The image `localhost/ksf-fa:php7.4` is a **generic PHP 7.4 Apache runtime** and
nothing else. The FA source tree is mounted **read-only** at `/var/www/html` at
container start. Which FA version runs is decided at **deploy time** by the
`fa_version` play var: the recipe unpacks the matching tarball from its *recipe
sources* into the mount dir, and (if the mod applies) applies the matching
`items.php` patch. Selecting a different FA version = picking a different source
tarball — **the image is never rebuilt for a version change.**

Rationale: environments live on different hosts (Integration here, UAT on its
own box) and FA versions will vary (2.4.3 deployed today; 2.4.19 is the target
floor per `AGENTS_ARCH.md` §1). Baking one version into an image would fork the
image per version and defeat version selection from tarballs.

## 2. The image (`containerfiles/FA`)

- `FROM docker.io/php:7.4-apache`
- apt: `libpng-dev libjpeg-dev libfreetype-dev libzip-dev`
- `docker-php-ext-configure gd --with-freetype --with-jpeg`
- `docker-php-ext-install mysqli gd zip`
- `a2enmod rewrite`
- `COPY php.ini → /usr/local/etc/php/conf.d/zz-ksf.ini` (memory/uploads/session)
- `ENTRYPOINT entrypoint.sh` (permission fixer, §6), `CMD ["apache2-foreground"]`
- Build: `podman build -t localhost/ksf-fa:php7.4 -f containerfiles/FA/Podfile .`
- Not baked: FA source, modules, per-pod overlays. All mounted at runtime.

## 3. The environment — per-pod overlay dirs mirror `/var/www/html`

For each pod (`ksf_fa`, `ksfii_app`, …) there is a pod dir
`<infra>/FA/<pod_name>/` holding only the pieces that differ from stock FA. The
pod dir is derived from the **pod name passed in the play** — the recipe sets up
`FA/<pod_name>/` and mounts it:

| Pod dir (`FA/<pod>/`) | Mounted at (`/var/www/html/`) | Mode |
|---|---|---|
| `FA/<version>/` (whole packed tree) | `/var/www/html` | ro |
| `fa_modules/` | `/var/www/html/modules` | rw |
| `config_db.php` | `/var/www/html/config_db.php` | rw |
| `installed_extensions.php` | `/var/www/html/installed_extensions.php` | rw |
| `company/` | `/var/www/html/company` | rw |
| `themes/default/default.css` | `/var/www/html/themes/default/default.css` | rw |

`config_db.php` is **templated from the play** (mysql host/user/pass, company
list). `installed_extensions.php` is the writable registry. `company/` holds the
numbered company dirs FA creates/writes at runtime. The theme canonical file is
`default.css` with unmounted `.default`/`.red`/`.yellow` variants (§5).

## 4. Theme selection by environment

- `fa_env` (`integration`|`uat`|…) maps to `fa_theme` color:
  `integration=red`, `uat=yellow`, `default=blue`.
- The role stages `default.css.<fa_theme>` onto the canonical `default.css`
  before container start (task `tasks/theme.yml`). Variants are never edited.
- Only `default.css` is overlaid; `renderer.php`/`index.php`/`images/` stay
  read-only from the FA version mount.

## 5. Permissions model

FA writes at runtime: `config_db.php` (new companies), the global
`installed_extensions.php` (extension installs), and `company/` (new numbered
company subdirs + `company/<id>/installed_extensions.php` + `js_cache`/
`pdf_files`/`reporting`/`backup`/`images`/`attachments`).

- The web worker is `www-data` (uid 33). Under **rootless podman** the host user's
  files map to `root` inside the container, so `www-data` is "other" for them —
  it needs world (other) write bits, i.e. `chmod a+rwx`-style modes.
- **Self-healing entrypoint**: `containerfiles/FA/entrypoint.sh` chmods the RW
  mount paths at every container start. Idempotent and userns-agnostic — works on
  any box regardless of that host's subuid layout.
- The role also enforces host-side modes on those paths at provision time.
- Note: git tracks only the executable bit, not group/other write bits, so these
  modes must come from the entrypoint/role, never from the repo working tree.

## 6. Version tree + version-anchored `items.php` mod

- Recipe **sources**: `fa/<version>.tar.gz` (pristine FA, one per version).
- The ProductAttributes tab system needs four hook points in FA core
  `inventory/manage/items.php` (`item_display_tab_headers`,
  `item_display_tab_content`, `post_item_write`, `pre_item_delete`). FA 2.4.3
  core has none.
- The mod is **per-version**: `patches/items.php.<version>.patch`, anchored to
  per-version pristine snippets, idempotent via the `// KSF host hook` sentinel.
- Because `/var/www/html` is mounted **read-only**, the runtime patcher
  (`ItemsPhpTabHookPatcher`) cannot persist — so the **recipe applies the matching
  patch at unpack time**, before the RO mount. ProductAttributes' own patcher
  stays as an idempotent no-op safety net.
- Adding an FA version = add `fa/<v>.tar.gz` (+ `patches/items.php.<v>.patch`
  only if that version's packaging changed).

## 7. DB bootstrap (SQL)

The mariadb container mounts `init-sql/` → `/docker-entrypoint-initdb.d`
(initial seed only, first boot). **Open decision (pending):** clean
schema + base-users seed vs keeping the current data dump. Direction:
ship a **clean** seed (schema + base `ksf_` users, no business data) in the
role sources; keep data dumps out of versioned sources.

## 8. ansible role `ksf.frontaccounting`

Defaults: `fa_version`, `pod_name`, `fa_env`, `fa_theme`, `fa_port`, `mysql_*`
(host/user/pass/db). Tasks:
1. **sources** — ensure `fa/<fa_version>.tar.gz` + matching patch are present.
2. **unpack + patch** — build `FA/<fa_version>/` and apply
   `items.php.<fa_version>.patch` if present.
3. **pod scaffold** — build `FA/<pod_name>/` (config_db templated from creds,
   registry skeleton, theme variant staged onto canonical `default.css`).
4. **perms** — host-side modes on the writable paths (§5).
5. **container start** — podman run with the §3 mount set.
6. **db** — seed mariadb with the bootstrap SQL on first boot.

**Reconcile pending**: `podman/ksf-compose.yaml` and the role's
`frontaccounting-container.yml` historically diverge; both must converge on the
§3 mount set. podman-compose 1.0.6 is broken on `${VAR:-default}` top-level
volume names ("volume [...] not defined in top level"), so **direct `podman run`
is the only practical path** — `podman/start-fa.sh` IS the verified rootless
reproduction of the `ksf-compose.yaml` `frontaccounting` service (2026-09). The
legacy `podman/compose.yaml` (declared a `fa_data` named volume and would have
been auto-picked by bare `podman-compose up`) has been **deleted**; the unused
`_fa_data`/`_iiapp_data`/`_stockmarket_python_data` named volumes were removed
from `ksf-compose.yaml`.

## 9. The play

Extra vars the play passes in:

| Var | Purpose |
|---|---|
| `fa_version` | selects `fa/<fa_version>.tar.gz` + matching patch |
| `pod_name` | the resulting `ksf_fa`-equivalent pod dir `FA/<pod_name>/` is set up and mounted |
| `fa_env` | environment (→ theme color, creds/tarball profile) |
| `fa_port` | host port → container 80 |
| `mysql_*` | mysql host/user/pass/db (+ root pass) |
| `fa_theme` | optional override of the env-derived color |

## 10. Decisions log

- **[D]** FA version NOT baked into the image; selected from recipe tarballs at
  deploy time (image stays version-agnostic).
- **[D]** Per-pod overlay dirs mirror `/var/www/html`; only the §3 files are
  pod-owned mounts; `FA/<pod_name>/` derives from the play's `pod_name`.
- **[D]** `items.php` mod applied by the **recipe at unpack time** (RO mount),
  keyed by `fa_version`; ProductAttributes patcher = idempotent safety net.
- **[D]** Perms via `chmod a+rwx` in the image entrypoint (self-healing,
  userns-agnostic) plus host-side modes in the role.
- **[P]** SQL seed: clean (recommended) vs data — pending decision.
- **[P]** Full reconcile of compose vs role mount specs — pending.
- **[P]** Which extra FA versions to bundle now — pending (2.4.3 today; floor
  target 2.4.19).