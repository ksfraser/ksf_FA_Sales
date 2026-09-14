# AGENTS.md — KSF FrontAccounting Architecture Notes

Operational memory for the KSF FA infrastructure codebase.

Files live under `/home/kevin/Documents/.

Git repositories (dev trees) live under /home/kevin/Documents/<modulename>.

Files deployed for Integration Testing live under ksf_Infrastructure/fa_modules/`.
This doc captures cross-module architecture **decisions** and findings. 
Read this before designing or refactoring anything that spans modules.

> **Companion doc:** `AGENTS_ARCH.md` (co-located, hardlinked into each repo)
> holds the shared module **conventions** and cross-repo engineering standards
> (module layout, coding/testing standards, dev/deploy workflow, inter-module
> hook protocols, security-area registry, FA DB gotchas). This file holds only
> decisions.

## ksf_payment_destinations — development tree status

**`~/Documents/FA_PaymentDestinations/` is an ABORTED branch.** Do not develop
there. That directory is a bind mount (via `~/Documents/ksf_Infrastructure/`)
that was used for documentation only — it has no `src/`, no `Tests/`, no
PSR-4 refactoring, and no `@BABOK` traceability annotations. All development
happened in `~/Documents/ksf_payment_destinations/` which is the primary
working dev tree.

If you find yourself editing files in `FA_PaymentDestinations/` — STOP and
switch to `ksf_payment_destinations/` immediately.

## Cross-module facts (at a glance)

- **PHP 7.3 is the cross-module compatibility floor** (current prod runs 7.3 on
  Fedora 30 until a web container is stood up; the FA container runtime is 7.4).
  See `AGENTS_ARCH.md` §1.
- **ksf_FA_Common is now a pure Composer/Packagist package** (v1.0.9), not an FA
  module. It was gutted to a no-op module shell. Owning modules (RBAC, CRM,
  Calendar, HRM, Assets) register/unregister their `ksf_contact_types` on
  activate/deactivate via embedded `sql/retag_contact_types.sql`.
- **Square**: composer.json `config.platform.php = 7.4.33` pinned (commit
  `b0ef4da`) for the PHP 7.4 container; lock regeneration is blocked locally on
  the private `ksfraser/import-staging` package.
- **FA_ProductAttributes issue #52 (child not detected as read-only)** root cause
  found: two parallel, un-unified parent-relationship mechanisms. Full write-up
  is in that repo's `AGENTS.local.md` (migrated out of this file).

## The "generic data-dictionary + query-builder" direction (active design)

The user wants a **generic, transport-agnostic** data-dictionary + SQL query
builder package (suggested name `ksf_common_db`), NOT another DB class, and NOT
in `ksf_FA_Common` (whose naming is FA-module-specific). Core requirement:

> An interface defines the SQL query commands. A translation layer maps them to
> MySQL commands when outside FA, and to FA's procedural `db_*` functions when
> inside FA, so the correct implementation can be DI'd.

Requirements/goals captured from discussion:
- Want the **data dictionary + query builder** capabilities (from the legacy
  `ksf_modules_common` `MODEL`/`fa_MODEL` framework).
- **No third parallel DB-class abstraction** on top of what exists.
- Lifecycle (pre/post CRUD) hooks should be able to tie into this system.
- Package should be usable standalone (tests, CLI, other frameworks), not just
  inside FA.

### The pattern ALREADY exists / is partially realized (critical to not rebuild)
1. **RBAC's `DbAdapterInterface` + `FaDbAdapter` (now generalized)** — the exact
   two-part interface→FA-translation pattern the user described: `fetchAssoc`, `fetchAll`,
   `executeUpdate`, `lastInsertId`. `FaDbAdapter` substitutes `?` placeholders via
   `mysqli_real_escape_string` (FA has NO prepared statements) and regex table prefixing.
   This served as the proof of concept and has been **genericized into the
   `ksfraser/ksf-common-db` package** (`ksfraser\CommonDb\Contract\DbConnectionInterface` /
   `ksfraser\CommonDb\Adapter\FaDbAdapter`); the RBAC-local copies were deleted. See that
   package's `APPENDIX.md` for the migration.
2. **Legacy `ksf_modules_common` (procedural, older)** — the actual data-dictionary +
   query-builder the user remembers:
   - `class.MODEL.php` — `fields_array` + `table_details['tablename']`/`['primarykey']`
     dictionary; clause builders `buildSelect/From/Where/Join/GroupBy/Having/OrderBy/Limit`
     assembled by `buildSelectQuery()`; CRUD `select_row`, `select_table`,
     `insert_table`, `update_table`, `delete_table`, `ReplaceQuery`, `create_table`,
     `alter_table`; `define_table()` derives tablename from class name + company prefix.
   - `class.fa_MODEL.php` — FA subclass; sets `company_prefix = TB_PREF`.
   - `class.eventloop.php` — Observer pattern event dispatcher: `ObserverRegister`,
     `ObserverNotify` (with `'**'` wildcard = all), subscribers implement `notified()`.
   - **CAVEAT**: the `eventloop` Observer hooks are wired only for `NOTIFY_INIT_TABLES`
     → `create_table` and logging. They are NOT wired into `insert_table`/`update_table`/
     `delete_table` as pre/post CRUD points. The `tell_eventloop(..., "NOTIFY_LOG_*", ...)`
     calls are largely logging noise.
3. **Modern ksf_FA_Common traits (OOP, current recommendation for hooks)**
   - `src/Traits/WorkflowHooksTrait.php` — SuiteCRM-style lifecycle hooks. Register a
     record type → hook prefix via `registerWorkflowType($recordType, $hookPrefix)`.
     `fireWorkflowHook()` builds `{prefix}_{hookKey}` and calls `hook_invoke_all(...)`.
     Hook keys: `before_save`, `after_save`, `before_delete`, `after_delete`, `new`,
     `edited`, `linked`, `unlinked`. Convenience dispatchers + `fireWorkflowHooks()`
     runs the full ordered sequence.
   - `src/Traits/CrudOperationsTrait.php` — `createRecord()` / `deleteRecord()` wrappers
     that fire pre-save → create → new/edited → post-save (and pre-delete → delete →
     post-delete), delegating the actual DB work to overridable `*Internal()` methods.
   - Both live in `ksfraser\FrontAccounting\Common\Traits\` (namespace is FA-flavored).
   - Adoption is currently minimal: ksf_FA_Common's own unit tests +
     `ksf_FA_HRM/hooks.php`. Not yet rolled out module-wide.

### Naming observation
The `registerWorkflowType($recordType, $hookPrefix)` + `{prefix}_{operation}` scheme
already achieves what the user proposed as "DB class reads `$table_name` to build
pre/post hook name." `$table_name` should map to a hook **prefix**, not be the literal
hook name. Per-table hooks use `hook_invoke_all` (module-oriented dispatcher from
`includes/hooks.inc`) — consistent with FA, zero new infra. If literal table-derived
names are wanted, add a small table→prefix mapper, don't build a new dispatcher.

### PDO question (decided: YES, PDO is the standalone impl; FA uses its own adapter)
FA's DB layer is mysqli-based, NOT PDO (`includes/db/connect_db_mysqli.inc` uses
`mysqli_connect/query/insert_id/errno/fetch_row`; procedural `db_query`/`db_escape`/
`db_fetch_assoc`/`db_insert_id` wrappers; FA has NO prepared statements).

Design: **make PDO the consumer contract of the interface, not the transport.** Two
implementations of one `DbConnectionInterface` (PDO-style ops: query, executeWithParams,
fetchAll, fetchAssoc, insertId, quote, beginTransaction/commit/rollBack):
- `PdoDbAdapter` (standalone: tests/CLI/other frameworks) — nearly a 1:1 PDO wrapper,
  real prepared statements, multi-driver.
- `FaDbAdapter` (inside FA) — maps each method to FA `db_*`/mysqli calls, resolves
  `?`/`:name` placeholders to escaped literals (FA has no prepared statements), exactly
  like the FA adapter in `ksf-common-db` (`mysqli_real_escape_string` binding + regex
  prefixing).

**HARD RULE (user directive): FA modules MUST use native `db_*` calls at runtime.**
PDO is ONLY for the standalone/portable side (tests, CLI, non-FA embedding). The FA
adapter is the single, mandatory implementation inside FA and must delegate every
operation to FA's procedural `db_query`/`db_escape`/`db_fetch_assoc`/`db_insert_id`/
`db_num_rows`/`db_error` etc. — never a PDO handle, never raw mysqli. PDO is the
portable *contract shape*, not a runtime transport for FA. (Note: PDO is an optional
for the FA side: the only reason to depend on it at all is standalone usage; the FA
adapter itself needs no PDO.)

PDO and FA never meet; they are alternative DI implementations of a shared contract.
This satisfies the user's requirement: "interface that translates SQL to MySQL outside
FA but `db_*` inside FA so the right classes can be DI'd." This is realized by the
`ksf-common-db` package's `DbConnectionInterface` + `FaDbAdapter`/`PdoDbAdapter`.

### ksf_common_db package — implemented
The `ksfraser/ksf-common-db` package (namespace `ksfraser\CommonDb`) exists at
`~/Documents/ksf_common_db`, is published on Packagist (`v1.0.0`), and RBAC has
been migrated onto it. **Repo-specific implementation/packaging/migration/gotcha
notes live in that repo's `APPENDIX.md`** — see there for structure, consumers'
dependency convention, the RBAC migration, and the implementation gotchas. This
section only records the shared design decisions.

Structure (high level):
- `src/Contract/DbConnectionInterface.php` — PDO-shaped contract: `fetchAssoc`,
  `fetchAll`, `fetchScalar`, `executeUpdate`, `lastInsertId`, `quote`, `beginTransaction`,
  `commit`, `rollBack`. Accepts `?` (positional) or `:name` (named) placeholders.
- `src/Adapter/FaDbAdapter.php` — FA runtime adapter; native `db_*` only.
- `src/Adapter/PdoDbAdapter.php` — native PDO + prepared statements (NOT for FA runtime).
- `src/Dictionary/TableDefinition.php` — data dictionary + CREATE/INSERT/UPDATE/DELETE SQL.
- `src/Query/QueryBuilder.php` — fluent parameterized SELECT builder.

NEXT STEPS (cross-module): port other modules' DAOs onto `DbConnectionInterface` +
`TableDefinition`/`QueryBuilder`, then wire the pre/post workflow hooks
(`WorkflowHooksTrait`/`CrudOperationsTrait` from ksf_FA_Common) onto the DAO layer.

## FA core hook system (reference)

`includes/hooks.inc`:
- `hook_invoke($ext, $method, &$data, $opts)` / `hook_invoke_all($method, &$data, $opts)`
  / `hook_invoke_first` / `hook_invoke_last` — dispatch to `$Hooks` extension objects.
- Transaction-level DB hooks exist: `hook_db_prewrite`, `hook_db_postwrite`,
  `hook_db_prevoid` → `hook_invoke_all('db_prewrite'|'db_postwrite'|'db_prevoid', $cart,
  $type)`. These are CART/transaction scoped (sales orders, invoices, etc.), NOT
  per-row CRUD. Per-row CRUD hooks are not provided by core; they come from the
  traits/adapters above.

## FA_ProductAttributes — module/tree-specific findings moved to its own repo

Anything FA_ProductAttributes-specific that used to live here (issue #52 root
cause, Generate Combinations semantics, the ksf-fa blank-page/bootstrap logs for
that module) has been migrated OUT of this shared file into the repo's
`AGENTS.local.md` (`~/Documents/FA_ProductAttributes/AGENTS.local.md`). Working
on that module? Read that file. This shared doc keeps only cross-module
decisions and mechanics.

## FA extension install & activation mechanics (verified 2026-09, cross-module)

How FA 2.4.x actually installs/activates third-party extensions — applies to
every ksf_* module, and is why "clicks on the Extensions page" silently do
nothing on a fresh mount. Verified against `fa/2.4.3` source + live UAT box.

**Registries** — two PHP files of `$installed_extensions` arrays:
- GLOBAL: `company/installed_extensions.php` (what "Local<pkg>" and the
  repo-index install write to).
- PER-COMPANY: `company/<id>/installed_extensions.php` — this is what
  activation (Refresh/Update) actually reads and rewrites.
Both must be writable by the container's PHP/MySQL UID (see the per-instance
bind fix in the repo-local notes).

**Registering a local module**: the `Local<pkg>` button
(`local_extension()` in `admin/inst_module.php`) hardcodes
`'version' => '-', 'available' => ''` and copies nothing from the module's
`_init/config`. It includes the module's `hooks.php` and calls
`install_extension(false)`.

**Activivating** (the "Activated for '<company>'" view, `extset=<id>`):
- Checkboxes are named `Active<i>` where `$i` is the index in the MERGED +
  natural-sorted GLOBAL registry list, NOT the company registry. Map each row's
  checkbox to its package name from the page HTML before POSTing.
- POST `extset=<id>&Refresh=Update&Active<i>=1` (needs the current `_token`).
- Gate: `check_src_ext_version()` in `includes/packages.inc` rejects any
  version with a leading component below the app's (`2.4.3`). **A version of
  `'-'` ALWAYS fails** → "incompatible with current application version and
  cannot be activated". Private/local modules therefore MUST carry a numeric
  version in the registries (manually set what `_init/config` declares, e.g.
  `'2.4.4'`), the same value the repo index would have supplied.
- `activate_hooks($pkg, $comp, true)` then calls the module's
  `hooks_<pkg>::activate_extension($comp, false)`.

**SQL prefix convention in module `sql/` files**: the install engine
`db_import()` (`admin/db/maintenance_db.inc`) replaces ONLY the literal
`0_` → `TB_PREF`. It does NOT touch `{TB_PREF}` or `@TB_PREF@` (those only
work in ksf_FA_Common's own hand-rolled `install_schema()` helper). **All
extension `sql/*.sql` for the FA install path must use literal `0_` table
names** — `{TB_PREF}` yields "Table 'ksf_fa.{TB_PREF}x' doesn't exist".
(FA_ProductAttributes had 4 files with `{TB_PREF}`; fixed to `0_` in commit
`b9f484e`.)

**Login gotcha** (`includes/session.inc:545`): without `company_login_name`
in the POST, login always fails with 401 "Incorrect Password" even when the
user/password is right. Send `company_login_name=<id>` (+ `_token` from the
login page).

**Cross-module activation hazard — duplicate ksf-fa-common class load**:
`ksf_FA_Common` ships `src/autoload.php` as the canonical loader for the
`ksfraser\FrontAccounting\Common\*` namespaces and explicitly must NOT have a
PSR-4 pointing at a vendored copy. If another module's Composer autoloader
(vendored `ksfraser/ksf-fa-common`, e.g. FA_ProductAttributes') loads those
classes FIRST, then activating ksf_FA_Common (constructor `require_once
src/autoload.php`) redeclares the already-loaded classes → fatal that kills
the extension page mid-render (page shows footer only, no message, no
registry write). Symptom: activation "does nothing". On the UAT box the
workaround was manual activation (schema via raw SQL, `active => true` set
directly in both registries). Proper fix is order-independent guarded
loading in `src/autoload.php`.
