# AGENTS_ARCH — KSF Cross-Module Architecture & Conventions

Companion to the master `AGENTS.md`. While `AGENTS.md` records specific FA
architecture **decisions** (data-dictionary/query-builder direction, PDO rule,
hook-system reference, SQL-prefix conventions, feature root-causes), this file
captures the **shared module conventions and cross-repo engineering standards**
that every ksf FA module / library follows. If something is a convention that
applies to many modules, it lives here. Repo-specific detail lives in each
repo's `_APPENDIX` / `AGENTS_APPENDIX.md`.

Read this before creating or refactoring any ksf module.

---

## 1. PHP platform floor (canonical)

- **PHP 7.3 is the compatibility floor.** Current production runs PHP 7.3
  (Fedora 30) until a web container is stood up there. Code MUST target
  PHP 7.3: no PHP 8+ features (no `match`, no named args, no nullsafe, no
  typed properties, no union/`mixed` return types in signatures — `mixed`
  only in docblocks). The FA container runtime is 7.4; standalone/core
  libraries may differ — each repo records its own floor in `_APPENDIX`.
- `declare(strict_types=1);` at the top of every PHP file.

## 2. Core design principles

- **SOLID, DRY, SRP, DI, TDD** — single responsibility, dependencies injected
  (never hardcoded), test-first.
- **Polymorphism over conditionals** — prefer strategy/handler dispatch to
  long `if/else` chains.
- **Traits over inheritance** — shared cross-cutting behavior is composed via
  traits, not deep class inheritance.
- **Business logic + platform adapter split** — framework-agnostic business
  logic lives in a `*_Core` package (`ksfraser\<Package>\`); the FA adapter
  module (`ksf_FA_*` → `ksfraser\FrontAccounting\<Module>\`) is a thin wrapper.

## 3. Standard module layout

Every ksf FA module follows this layout:

```
<module>/
├── sql/               <table>.sql  (one file per table; retag/contact-type SQL)
├── includes/          *_db.inc     ({table}_db.inc — write_{table}(), get_{table}(), delete_{table}())
├── pages/             UI pages
├── src/               business logic (PSR-4 under ksfraser\FrontAccounting\<Module>\
├── hooks.php          hooks_<module> extends hooks
├── composer.json      PSR-4 autoload
└── ProjectDocs/       Requirements.md, RTM.md, BABOK.md, UML.md
```

Each table gets a `{table}_db.inc` gateway exposing `write_{table}()` /
`get_{table}()` / `delete_{table}()` (Table Gateway pattern).

## 4. Namespace conventions

- FA platform modules: `ksfraser\FrontAccounting\<ModuleName>\` (PSR-4, maps to `src/`).
- Framework-agnostic core / business logic: `ksfraser\<Package>\`.
- Shared libraries: `ksfraser\Exceptions\`, `ksfraser\Traits\`, `ksfraser\CommonDb\`.
- SQL tables use hardcoded `0_` company prefix (FA `db_import` does NOT resolve
  `@TB_PREF@`); PHP code uses the `TB_PREF` constant.

## 5. Coding standards

- `declare(strict_types=1);` in every file.
- Naming: `InterfaceNameInterface`, `AbstractClassName`, `ServiceNameService`,
  `ValueObjectName` (immutable VOs), class `FooException`.
- DocBlocks require `@param`, `@return`, `@throws`, `@since`.
  `@UML` / `@BABOK` annotations cross-reference `doc/ProjectDocuments/{UML,BABOK}`
  requirement files (BR-*/FR-*/UC-*/UT-*/UAT-* naming).
- Version tagging: SemVer `MAJOR.MINOR.PATCH` (`git tag -a vX.Y.Z`).
- Git: feature branches `feature/*`, `fix/*`, `refactor/*`; commits
  `type(scope): description`; merge back to the default branch.
- Never track `vendor/` or `composer.lock` (each dev/consumer runs `composer install`).

## 6. Testing / TDD

- TDD red→green→refactor; **100% code coverage** target; **skipped tests = failed**.
- PHPUnit, `Tests\Unit` namespace convention; `php -l` lint before commit.
- Business logic tested standalone (in-memory SQLite / PDO stubs); FA adapter
  code tested against namespaced stubs of the FA `db_*` functions.

## 7. Development / deployment workflow

- Develop in the **devel tree** `~/Documents/<Module>`; the UAT bind point under
  `~/ksf_Infrastructure/fa_modules/<Module>` is a **deployment bind copy only** —
  never create/edit/commit code there.
- Flow: develop → test → `php -l` → commit/branch → push → merge → deploy.
- Deploy at the bind point: `git stash -u && git pull origin <branch> && git stash pop`,
  then re-run architecture-doc hardlinks (`ln -f`) if they were clobbered.
- Container deploy: run `composer install --no-dev` (a `require-dev` that pulls
  PHP 8+ transitive deps breaks a PHP 7.x container) — pin
  `config.platform.php` to the container's PHP where needed.

### Integration-environment gotchas (ksfii_app pod, verified 2026-09)

- The dev-shell runs as **root**, so `git`/file writes inside the bind-mounted
  deploy clones leave root-owned files and rewrite mode bits on
  checkout/reset. If `git pull` at a bind point refuses with "Your local
  changes to the following files would be overwritten" while `git status --short`
  is clean, suspect in order: an `assume-unchanged` flag (`git ls-files -v`
  shows lowercase `h`), a root-owned `.git/index` (makes `git update-index`
  silently a no-op), or a 186-hardlink shared doc whose content is ahead of
  HEAD. The reliable fix is aligning the clone to origin from the deploy clone:
  `git fetch && git reset --hard origin/main`. Never hand-`chmod`/`chown`
  tracked files mid-tree; re-align instead.
- The web front-end HTML-encodes `&` in query strings mid-transit:
  `$_SERVER['REQUEST_URI']` reads e.g. `...?view=contacts&amp;filter_debtor_no=1`
  while `$_GET` still parses every param correctly (so filtering works end to
  end). Round-trips are consistent — form `action=` URLs and `Location:` headers
  carry the same encoded form and are re-decoded the same way. Therefore when
  appending a query param to `formAction()`/`REQUEST_URI` for a redirect, detect
  an existing param with `strpos($url, 'name=')` instead of splitting on raw `&`.

### ComposerDependencies — self-installing vendor on activation

Each module bundles `ComposerDependencies.php` in its **root directory**. The copy
source is the per-module template
`ksf_FA_Common/src/Utils/ComposerDependencies.template.php`: **replace the
`MODULENAME` token in the namespace with your module's short name** (e.g.
`ksfraser\FrontAccounting\HRM\Utils` for ksf_FA_HRM). This solves the
chicken-and-egg problem: vendor/ doesn't exist until composer runs, but we need
to run composer to create vendor/.

```php
// hooks.php — top of file, BEFORE any other requires
require_once __DIR__ . '/ComposerDependencies.php';
\ksfraser\FrontAccounting\HRM\Utils\ComposerDependencies::ensure(__DIR__);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
```

`ComposerDependencies::ensure($moduleDir)` checks if `vendor/autoload.php` exists. If
not, it runs `composer install --no-interaction --prefer-dist` in `$moduleDir`. FA
calls `install_extension()` before activation completes, so vendor/ is ready when
other hook methods run.

The guard is **namespace-scoped** (sentinel constant derived from `__NAMESPACE__` +
`class_exists(__NAMESPACE__, false)`). This is deliberate:
- Each module that renames `MODULENAME` gets its own class in its own namespace —
  copies can never collide, and the legacy global constant
  `KSF_FA_COMMON_COMPOSER_DEPENDENCIES_DECLARED` is NOT set for renamed copies (so a
  properly-renamed module never suppresses a sibling).
- If a module forgets to replace `MODULENAME`, all unrenamed copies collapse onto
  the placeholder namespace; only the first to load declares the class, the rest
  short-circuit — no redeclaration fatal, no clobbering. Their hooks.php calls still
  resolve since the class takes `$moduleDir` per call.
- The package's own `ksf_FA_Common/src/Utils/ComposerDependencies.php` (in the
  `Common\Utils` namespace) uses the identical guard and may also be loaded; it
  additionally defines the legacy constant for backward compatibility with older
  copies. Do NOT copy that file into a module — copy the `.template.php`.

## 8. FA module naming / security constants

- Hooks class: `hooks_ksf_FA_<ModuleName>`.
- Security section: `define('SS_ksf_FA_<ModuleName>', N << 8);`
- Security areas: `SA_ksf_FA_<ModuleName>` / `SA_<MODULE>_<ACTION>`.
- **Security-area numbering registry (single source):** Core FA uses 1–53; KSF
  modules start at 114. Current highest: `SS_GPG = 145` (`SS_DataIntegrity = 144`).
  Next available: **146**. Always take the next unused number — never reuse.

## 9. FA page security

Every direct-access module page MUST call `add_access_extensions()` (registering
its security areas) **before** `page_header()`. Missing it produces a blank
(~855-byte) page. Guard so that a user without the area is refused before any
output.

### FA UI bootstrap — `ui.inc` is NOT auto-loaded

`includes/main.inc` only pulls `ui_controls.inc` (provides `start_form`,
`end_form`, `start_table`, button helpers, ...). The rest of the FA UI layer —
`ui_lists.inc` (`customer_list`, `customer_list_row`, `combo_input`,
`array_selector`, ...), `ui_input.inc`, `ui_msgs.inc`, `ui_globals.inc`,
`ui_view.inc`, `data_checks.inc` — is loaded by `includes/ui.inc`, which every
native FA page `include_once(...)`s after `session.inc`.

App-shell module entry pages (e.g. `modules/ksf_FA_CRM/index.php`) MUST do the
same:

```php
include_once($path_to_root . "/includes/session.inc");
add_access_extensions();
include_once($path_to_root . "/includes/ui.inc"); // required for ui_lists helpers
```

Without it, tab code that calls an FA-native list/DLL helper (e.g.
`customer_list_row`) silently skips rendering that control: `start_form` /
`end_table` still work because `main.inc` loaded `ui_controls.inc`, so the page
renders normally minus the helper control, with no error visible. Debugging
trap: `function_exists('start_form') === true` does NOT imply
`function_exists('customer_list_row')`.

### Tab-footer buttons — native `inputsubmit`, never `ajaxsubmit`

FA's `js/inserts.js` intercepts clicks on elements with class
`ajaxsubmit`/`editbutton`/`navibutton` and routes them through
`JsHttpRequest.request()` — an XHR that swallows navigation (POST persists, the
page never reloads; F5 shows the change). `ksf_FA_Common`'s `FormFooter`
historically emitted `class="ajaxsubmit"`, so Save/Cancel on every app-shell tab
had this symptom. Convention (decided 2026-09): tab-footer Save/Cancel buttons
use FA-native classes (`inputsubmit`) — `FormFooter` defaults `useAjax=false`;
`ajaxsubmit` is only opt-in for content that genuinely wants in-place XHR.
`MasterSummaryTable` row actions (Edit/Delete) already render native
`inputsubmit` rows (the tab controller passes `'ajax' => false`).

## 10. FA DB layer — correct API (gotchas)

- `$db` is **raw mysqli**; FA has **no prepared statements**.
- Use `mysqli_real_escape_string($db, ...)` + `db_query`; read with
  `db_fetch_assoc` (returns `false`, not `null`, at end).
- `db_escape()` is NOT a general escaper — it HTML-decodes; do not rely on it
  for SQL parameter safety.
- For merged/`O_`-style writes the affected-row/insert-id handling differs from
  PDO; test `db_insert_id`/`sql_trail` behavior carefully.
- **Hard rule:** FA modules MUST use native `db_*` calls at runtime. PDO only in
  the standalone/portable side (tests, CLI, non-FA embedding). `DbConnectionInterface`
  (ksf-common-db) is the PDO-shaped contract; `FaDbAdapter` translates to `db_*`.

## 11. Inter-module communication

- Use FA hooks via `hook_invoke` / `hook_invoke_first` / `hook_invoke_all`.
- **4-method discovery contract** a module may expose to others:
  `getModuleConstants()`, `getModuleCapabilities()`, `hasCapability(...)`,
  `respondToCapabilityRequest(...)`.
- Cross-module config read via `ksf_get_value('module.key')` / `ksf_set_value()`
  (HookQueryProviderTrait) — always pass a **variable** (hooks declare `&$data`
  by reference).
- Cross-module CRUD lifecycle via `ksf_crud_event` + `<module>_<action>_<recordType>`
  dual dispatch (CrudEventEmitterTrait); payload: `action`, `module`, `record_type`,
  `record_id`, `data`.
- Cross-module services: `ksf_log()` (ksf_FA_Common) routes to `ksf_log` hook →
  writes `company/<n>/logs/<module>_<date>.log`.

### 11.1 Inter-module dependencies (verified against FA 2.4.3, 2026-09)

**FA has no dependency mechanism.** There is no WP-style `Requires:` /
`depends:` key, no topological ordering, and no pre-activation resolver. The
control file parser `get_control_file()` (`includes/packages.inc:164`) is a
generic `Key: Value` gzip reader that will happily accept a `Requires:` line, but
**nothing in FA ever reads it** — it would be inert decoration. The *only*
pre-activation gate FA performs is `check_src_ext_version()` (FA-version
compatibility, §12).

Beware the misleading label: `admin/view/view_package.php:37` maps
`'Depends' => _('Minimal software versions')`, i.e. FA's "Depends" means
*version* constraints (`FA Version:`, `PHP Version:`), **not** other modules.
There is one in-repo attempt at the word "Depends" —
`FA_ProductAttributes_Variations/_init/config` line 13 reads
`Depends: FA_ProductAttributes_Core` — and it is doubly wrong: nothing reads it,
*and* that file is stored as plain ASCII rather than gzip, so
`get_control_file()`'s `gzopen()` cannot parse it at all. Don't treat it as a
precedent.

Activation order is therefore whatever order the sysadmin ticks the checkboxes.
Nothing enforces "activate Calendar before ProjectManagement".

**A module CAN enforce its own dependency and surface a real error to the
activation screen.** The extension page (`admin/inst_module.php:206-236`) honours
the return value of `activate_extension()`:

```php
$activated = activate_hooks($ext['package'], $comp, !$ext['active']);
if ($activated !== null)
    $result &= $activated;
if ($activated || ($activated === null))
    $exts[$i]['active'] = check_value('Active'.$i);
...
if (!$result) {
    display_error(_('Status change for some extensions failed.'));
    $Ajax->activate('ext_tbl');
} else
    display_notification(_('Current active extensions set has been saved.'));
```

So from `activate_extension($company, false)`: call `display_warning()` with your
message and `return false`. The warning renders on the activation screen, the
`active` flag is **not** flipped, `write_extensions()` still runs for the other
rows, and the summary line reads "Status change for some extensions failed."
This is exactly the pattern FA core uses for
`check_src_ext_version()` failing ("Package '%s' is incompatible with current
application version and cannot be activated."). `display_warning()` /
`display_error()` / `display_notification()` are all thin wrappers over
`trigger_error()` (`includes/ui/ui_msgs.inc`).

**How to test whether another module is active.** `get_company_extensions($id)`
(`admin/db/company_db.inc:68`) `include`s
`company/<id>/installed_extensions.php` and returns the per-company
`$installed_extensions` array. It **is** available during activation —
`admin/inst_module.php:23` includes `company_db.inc`. It is the authoritative
source, because per-company registry is what the Refresh/Update POST reads and
rewrites. Pair it with a hooks-registration probe when you need "installed and
wired up", not merely "flagged active":

```php
function activate_extension($company, $check_only = true)
{
    global $Hooks;
    $missing = [];
    foreach (array('ksf_Calendar') as $required) {   // array of module names
        $exts = get_company_extensions($company);
        $active = false;
        foreach ($exts as $ext) {
            if (($ext['package'] ?? '') === $required && !empty($ext['active'])) {
                $active = true;
                break;
            }
        }
        // $Hooks is the stronger check: it proves hooks.php was actually included.
        if (!$active || !isset($Hooks[$required])) {
            $missing[] = $required;
        }
    }
    if ($missing) {
        display_warning(sprintf(
            _('%s cannot be activated: the following module(s) must be activated first: %s'),
            $this->module_name, implode(', ', $missing)));
        return false;
    }
    if ($check_only) {
        return parent::activate_extension($company, $check_only);
    }
        // ... normal schema work (see §12.1)
    return parent::activate_extension($company, $check_only);
}
```

Schema work uses `$this->update_databases()` — a **method** on the `hooks` base
class, not a global function. Calling bare `update_databases(...)` fatals with
"Call to undefined function".

Caveats worth knowing:

- **`get_company_extensions($company)` is per-company.** A module active for
  company 1 and not company 2 passes for one and fails for the other. That is
  correct — per-company activation is the real gate.
- **The GLOBAL registry is not a substitute.** `company/installed_extensions.php`
  records availability/version; the per-company file records `active`. `ksf_FA_Calendar`
  has `StaleSchemaMessage` warning that `active => true` alone does not execute
  the installer, so also confirm the schema landed.
- **Reverse dependency is not enforceable this way.** If Calendar needs PM, and PM
  is being activated first, Calendar's `activate_extension` is not running yet.
  For mutual or reverse deps, degrade gracefully at runtime instead — see
  `ksf_FA_API`'s `CalendarController` which treats a `null` return from
  `hook_invoke_first('calendar_entries_query', ...)` as "Calendar not active" and
  returns an empty set.
- **Don't gate class availability on another module's activation.** Cross-module
  classes belong in a Packagist package (§12), not in a sibling module dir.
  `ksf_FA_Teams`/`ksf_FA_Calendar`/`ksf_FA_Mail` reference
  `dirname(__DIR__).'/ksf_FA_Common/src/Utils/ComposerDependencies.php'` — but
  `ksf_FA_Common` is **not** deployed under `fa_modules/`, so those references
  silently no-op in the deployed tree.

**Pattern to follow: a `ModuleDependencies` SRP class.** Yes — mirror the
existing `ComposerDependencies` shape. That class is
`final class ComposerDependencies` with a single static
`ensure(string $moduleDir): bool` in namespace
`ksfraser\FrontAccounting\{Module}\Utils`, invoked from `hooks.php` via
`require_once` + `::ensure(__DIR__)`, guarded by a namespace-derived constant
(`KSF_FA_COMPOSER_DEPENDENCIES_ . md5(__NAMESPACE__)`) so copies in different
namespaces can never redeclare each other. A sibling
`ModuleDependencies::assertActive(array $modules, $company): array` returning the
list of missing packages — returning rather than throwing, so the caller decides
whether to warn (activation) or degrade (runtime) — fits that house style
exactly. Place it in `ksf_FA_Common` (`src/Utils/`) as the shared implementation
and follow the template-copy convention so each module gets a namespace-renamed
copy without collisions.

Note there is currently **no** shared "is this module active?" helper anywhere
in the tree, and `ksf_FA_Common`'s `RbacGateway::isAvailable()` is misleadingly
named: it only does `function_exists('hook_invoke_all')`, which detects that FA's
hook dispatcher is loaded, **not** that `ksf_FA_RBAC` is active. Don't copy it.
`ksf_FA_Square/pages/export.php:92` is the only working runtime precedent
(`isset($Hooks[$pkg])` then `hook_invoke($pkg, 'getModuleConstants', ...)`).

### Event-Driven Architecture

**Every CRUD action that affects cross-module state MUST emit an event.** See
`ProjectDcs/Event-Driven Architecture.md` for full event taxonomy, payload schemas,
and workflow diagrams.

**Core principle:** Modules communicate through events, not direct calls. A module
emits without knowing listeners; a listener acts without knowing the emitter.

**Event naming:** `{object}_{action}` in snake_case (e.g., `stock_reserved`,
`suggested_po_created`, `po_created`).

**Standard payload:**
```php
$data = [
    'module'    => 'ksf_FA_StockReservations',
    'event'     => 'stock_reserved',
    'timestamp' => '2024-01-15 14:30:00',
    // ... event-specific fields
];
```

**Emitter rules:**
- Emit via `hook_invoke_all('{event}', $data)` after state is committed
- Include all standard fields (module, event, timestamp)
- Include relevant IDs for listeners (so_order_no, po_number, etc.)
- Emit even if no listeners (fire-and-forget)

**Listener rules:**
- Implement method named `{event_name}(array &$data)`
- Check team type is enabled before acting (for Teams module)
- Use `class_exists()` guard before using other modules' classes
- Log errors, don't throw (hook methods must be fault-tolerant)

## 12. FA module packaging

- `_init/config` file is **gzip-compressed** `Key: Value` lines (`Name:`, `Version:`,
  `Description:`), version like `2.4.3-<build>`.
- **`_init/config` is shipped in the module source and committed** (siblings keep it
  tracked, e.g. `ksf_FA_HRM` commit `chore(config): bump module version to 2.4.3-1`).
  The `Version:` FA displays and gates at activation is the installed module's
  `_init/config`; `admin/inst_module.php` refuses to activate when
  `check_src_ext_version()` (`includes/packages.inc`) finds the extension's numeric
  prefix below FA's `$src_version` (major.minor). Set `Version: 2.4.x-<build>` (e.g.
  `2.4.3-1`) and keep the module's own release in a separate field (`Build: 1.1.0`).
  Change it **in source** (`git add _init/config`) and let deployment + the FA
  *Install/Activate Extensions* UI reinstall refresh the running copy — never
  hand-edit the gzip on a live install, and never edit the version fields in
  `installed_extensions.php`.
- **The `Version:` in `_init/config` must match the major version of the FA
  platform** the module targets (e.g. `2.4.x` for FrontAccounting 2.4). FA uses
  this to gate module compatibility at install — a mismatched major (e.g. `3.x`
  vs FA `2.x`) makes the module appear incompatible. Keep the module's own
  release/build in a separate field; the FA-compat major is what FA checks.
- `install.sql` schema: hardcoded `0_` prefix; do not use `@TB_PREF@`/`{TB_PREF}`;
  probe existing tables with the bare table name.
- **Deactivation**: use `sql/uninstall.sql` (also hardcoded `0_` prefix), NOT manual
  `db_query()`. In `deactivate_extension()`, call `db_import()` with the **filename**
  (it takes a path, not a string) and the company connection array. See
  §12.1 for the corrected pattern and the `<table>_uninstall.sql` convention.
- Cross-module/owned classes live in a Packagist package, not a module dir.
  A module must never gate class availability on another module's activation.

### 12.1 Schema installer mechanics (verified against FA 2.4.3 source, 2026-09)

**Where DB work happens — `activate_`, not `install_`.** Three of the four base
hook methods in `includes/hooks.inc` are **no-op stubs that `return true`**:

| Method | Signature | Base behaviour |
|---|---|---|
| `install_extension` | `($check_only = true)` | `return true` — never touches the DB |
| `deactivate_extension` | `($company, $check_only = true)` | `return true` |
| `uninstall_extension` | `($check_only = true)` | `return true` |
| `activate_extension` | `($company, $check_only = true)` | **the only real one** |

All schema work therefore belongs in `activate_extension()` →
`update_databases()` → `db_import()`. `includes/packages.inc` only calls
`activate_hooks()` for packages whose registry `DefaultStatus` is active, and
`admin/inst_module.php` calls `activate_hooks($pkg, $comp, !$ext['active'])` for
the Refresh/Update POST. `activate_hooks($ext, $comp, $on = true)` dispatches
`$on ? activate_extension($comp, false) : deactivate_extension($comp, false)`.

**`update_databases()` gate.** This is a **method on the `hooks` base class**
(`includes/hooks.inc:31`), not a global function — call it as
`$this->update_databases(...)` from inside your hooks class. Signature
`update_databases($comp, $updates, $check_only = false)`, where `$updates` maps
`file.sql => array($table, $field = null, $properties = null)`. A file is
imported **only when `check_table($tbpref, $table, $field, $properties) != 0`**.

`check_table()` (`admin/db/maintenance_db.inc`) has a deliberately tiny
vocabulary: it issues only `SHOW TABLES LIKE` and `SHOW COLUMNS FROM`.

- table missing → non-zero → import
- table present → `0` → **skip**
- field present → `0` → **skip**
- column property mismatch → `3` → import (that's how FA re-applies
  `ALTER TABLE MODIFY` collation/type fixes)

**It has no concept of an index.** So a per-table file gated on its own table
never re-imports once the table exists, and an `ALTER` placed inside a
per-table file will **not** run on re-activation.

**Per-table file convention.** Ship one `<table>.sql` per table. Gate on
`array($this->module_name)` when a module has no natural "sentinel" table, or on
the real table when ordering matters. `FA_ProductAttributes` is the reference
implementation (36 per-table files).

**`db_import($filename, $connection, $force = true, $init = true, $protect = false, $return_errors = false)`**

- **One file per call.** It does `strpos($filename, ".gz")` then
  `file("" . $filename)` — passing an **array fatals**. Loop in PHP to import
  multiple files.
- It replaces the literal `0_` with the real company prefix.
- Allow-listed commands: `create`, `delimiter`, `alter table`, `insert`,
  `update`, `set names`, `drop table if exists`, `drop function if exists`,
  `drop trigger if exists`, `select`, `delete`. Anything else is silently skipped.
- **Ignored MySQL errors in forced mode:** `1022` (duplicate key), `1050` (table
  exists), `1060` (duplicate column), `1061` (duplicate key name), `1062`
  (duplicate key entry), `1091` (can't drop key/column). Therefore a file
  containing `ALTER TABLE ... ADD UNIQUE KEY` or `INSERT IGNORE` is **safe to
  re-run** — the only obstacle is the gate, not the SQL.

**Adding a key / constraint to an existing table.** Because `check_table()` can
only probe tables and columns, pick one of:

1. **Call `db_import()` directly** from `activate_extension()` on an idempotent
   `ALTER`-only file, with no `$updates` gate. Cleanest — `1061` is ignored, so
   it self-heals on every re-activation and needs no fake column or marker table.
2. The **column-probe idiom** — gate the upgrade file on a column that the same
   file adds. This is what `FA_ProductAttributes/sql/30_product_attribute_extras.sql`
   does: gated on missing `product_attribute_values.color`; absent on old installs
   → fires; present on fresh installs (base `02_product_attribute_values.sql`
   creates it) → skipped. Only works when the thing added **is** the thing probed,
   so it cannot express an index.
3. A sentinel/version table of your own.

**Deduplicate before adding a unique key.** `ADD UNIQUE KEY` fails with `1062`
on pre-existing duplicates. The file must `DELETE`/`UPDATE` the dupes first
(e.g. keep the lowest `id`, or coalesce into a survivor) and then add the index.
Note `1062` is on the ignore list, so a *failed* index add is **not** an error —
it fails silently and the constraint never lands. Always verify with a
deliberate duplicate insert afterwards.

**Uninstall: FA never prompts and never drops your tables.** Nothing in
`packages.inc`, `inst_module.php` or `maintenance_db.inc` asks about table
deletion; `db_import`'s `drop table if exists` is only there for files that
explicitly ask. Data is left behind on both flows. Two distinct paths:

- **Deactivate** (uncheck in "Activated for *&lt;company&gt;*"):
  `deactivate_extension($company, false)`. Module files are intact, so SQL files
  *are* readable here.
- **Uninstall** (repo-index `uninstall_package()`): `package::uninstall()` copies
  the module dir to `_back`, then **`flush_dir($targetdir, true); rmdir($targetdir);`
  deletes the whole module directory** — and only *then* calls
  `hook_invoke($pkg, 'uninstall_extension', $dummy)`. Your `sql/` directory is
  already gone, so `uninstall_extension()` can only use inline SQL or
  already-loaded state. Don't rely on reading files from your own dir there.

**`<table>_uninstall.sql` convention.** Ship one per table, mirroring the
install files, and **leave the `DROP` statements commented out by default**.
Deactivation is routinely a temporary switch for a single company, and FA
preserves data by default — silently dropping a module's tables on a
mis-click is unrecoverable. A sysadmin who genuinely wants them gone uncomments
the block, then:

```php
function deactivate_extension($company, $check_only = true)
{
    if ($check_only) {
        return parent::deactivate_extension($company, $check_only);
    }
    global $db_connections;
    foreach (glob(__DIR__ . '/sql/*_uninstall.sql') as $file) {
        $conn = $db_connections[($company == -1) ? 0 : $company];
        db_import($file, $conn);
    }
    remove_security_section(SS_ksf_FA_ModuleName);
    return parent::deactivate_extension($company, $check_only);
}
```

Shape of each file (all `0_`-prefixed, `DROP`s inert):

```sql
-- fa_crm_customer_types_uninstall.sql
-- 0_fa_crm_customer_types
-- DESTRUCTIVE: uncomment only when the data is knowingly being discarded.
-- DROP TABLE IF EXISTS 0_fa_crm_customer_types;
```

**No `run_db_import()` exists** in FA 2.4.3 or in any ksf module — an earlier
revision of this doc referenced it. The real helpers are `db_import()` (one
filename) and `check_table()`.

## 13. RBAC Architecture (ksf_FA_RBAC)

### Design Document
Full design: `ksf_FA_RBAC/ProjectDcs/RBAC_V2_DESIGN.md`

### Overview
RBAC v2 uses voter-based authorization inspired by Symfony Security, with:
- **Zend RBAC** (`zendframework/zend-permissions-rbac`) for role/permission hierarchy
- **Voter pattern** for CRUD authorization
- **Dynamic assertions** for record-level access
- **Field-level encryption** via `defuse/php-encryption`

### Hooks API

| Hook | Purpose | Returns |
|------|---------|---------|
| `authorize` | Check CRUD access | `true/false/null` |
| `filterRecordList` | Filter list view records | Modified SQL |
| `filterFields` | Restrict field visibility | Field array |
| `encryptField` | Encrypt/decrypt values | Encrypted string |

### Authorization Hook
```php
hook_invoke_all('ksf_FA_RBAC', 'authorize', [
    'user_id'   => $user_id,
    'action'    => 'view', // create, view, edit, delete, list, export
    'module'    => 'customer',
    'resource'  => $customer_obj, // optional for record-level
    'assertion' => function($user_id, $resource) {
        return $resource->isOwnedBy($user_id);
    }
]);
// true = allowed, false = denied, null = abstain (let other voters decide)
```

### Decision Strategies
- `affirmative`: grant if ANY voter allows
- `consensus`: grant if majority allows
- `unanimous`: grant if ALL allow

### Module ACL Registry
Each module declares permissions in `hooks.php` via `getModuleAcl()`:
```php
$data['customer'] = [
    'create' => ['admin', 'manager'],
    'view'   => ['admin', 'manager', 'salesman'],
    'edit'   => ['admin', 'manager'],
    'delete' => ['admin'],
];
```

### Dependency Behavior
| Module | Behavior |
|--------|----------|
| RBAC installed | Full voter-based authorization |
| RBAC missing | All hooks return `null` (native FA permissions) |
| CRM installed | Team-based access via `crm_company_contacts` |
| CRM missing | Record-level uses native `salesman_code` |

### Default Roles
| Role | Description | Inherits |
|------|-------------|----------|
| `admin` | Full access | - |
| `manager` | Business unit | `salesman` |
| `salesman` | Sales rep | `clerk` |
| `clerk` | Data entry | - |
| `ar_clerk` | AR entry | `clerk` |
| `ap_clerk` | AP entry | `clerk` |
| `warehouse` | Warehouse | `clerk` |
| `viewer` | Read-only | - |

## 14. Ecosystem docs

For the package/namespace/dependency map (which repo wraps which, monolith
splits, trait inventory), see the shared ecosystem docs — `MODULE_DIRECTORY.md`,
`PACKAGIST.md`, `APP_TAB_ARCHITECTURE.md` — hardlinked into each repo per
`AGENTS_APPENDIX.md` §Architecture-doc hardlinks. Keep ecosystem *facts* there,
not here.
