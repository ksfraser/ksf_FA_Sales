# KSF FrontAccounting — App / Tab Plugin Architecture

> **Canonical architecture reference.** Read together with `MODULE_DIRECTORY.md`
> (ecosystem map) and `PACKAGIST.md` (our composer packages). Copies of this file
> are hardlinked into each module repo (mode `0444`). Editing follows the ritual in
> `AGENTS_APPENDIX.md`.

| Field           | Value                                                      |
|-----------------|------------------------------------------------------------|
| Status          | Current state + unified-tabs roadmap (v2.4 concept)        |
| Canonical copy  | `/home/kevin/Documents/APP_TAB_ARCHITECTURE.md`            |
| Last updated    | 2026-09-03                                                 |

---

## 1. Problem statement

FrontAccounting's item detail page and application shells are procedural. Tabs in
FA core are page-local helpers; there is **no object-oriented, module-level tab
system** where a *module* registers tabs onto a *host* page/app cleanly.

The KSF ecosystem goal:

> Drop a module in, install/activate it, and it auto-registers its tabs onto its
> parent app (items page, CRM app, HRM app, ProjectManagement app) with **no
> hard-coding in the host**.

`FA_ProductAttributes` is the first **working exemplar** (items-page host).
CRM / HRM / ProjectManagement are the intended **app hosts** (roadmap §7).

## 2. Roles in the pattern

| Role                     | Repo (composer package)                  | Namespace                                       | What it provides                                   |
|--------------------------|------------------------------------------|--------------------------------------------------|----------------------------------------------------|
| Shared plugin layer      | `ksf_FA_Common` (`ksfraser/ksf-fa-common`) | `ksfraser\FrontAccounting\Common\Plugin`      | `PluginRegistry`, `AbstractPlugin`, `PluginInterface` (generic discover/register/activate) |
| Tab base classes         | `FA_ProductAttributes_Core` (`ksfraser/fa-product-attributes-core`) | `FrontAccounting\ProductAttributes\Plugin` | `AbstractTab`, `ProductAttributeTabInterface`, `TabRegistry` — the tab contract |
| Generic traits           | `Traits` (`ksfraser/traits`)                | `Ksfraser\Traits`                                | `InlineTabRendererTrait`, `InlinePostActionsTrait` — render + POST flow |
| Exemplar host adapter    | `FA_ProductAttributes` (`ksfraser/fa-product-attributes`) | `FrontAccounting\ProductAttributes\Hooks`  | wires the tab plugins onto `items.php` via `item_display_tab_*` |
| App hosts (roadmap)      | `ksf_FA_CRM`, `ksf_FA_HRM`, `ksf_FA_ProjectManagement` | `FrontAccounting\...\Application` (`extends application`) | `install_tabs()` + `add_application()`; future `display_tab_*` hosts |

```
             HOST (page or app shell)
  items.php | crm_app.php | hrm_app.php | pm_app.php
        │  hook method (host-defined name)
        ▼
   Module hooks (FA_ProductAttributes/hooks.php) ── hard-coded registry today
        │
        ▼
   Tab plugins (AbstractTab / ProductAttributeTabInterface)
        │  renderTabContent() / handleSave() / handleDelete()
        ▼
   UI renderer class (via InlineTabRendererTrait $tabClassName)
```

## 3. Namespace & autoload map

| Prefix                          | Autoload source                    | Maps to                       |
|---------------------------------|------------------------------------|-------------------------------|
| `ksfraser\FrontAccounting\Common\`            | ksf_FA_Common composer psr-4  | `src/`                        |
| `Ksfraser\Frontaccounting\HTML\`              | ksf_FA_Common composer psr-4  | `src/HTML/`                   |
| `FrontAccounting\ProductAttributes\`          | product-attributes-core psr-4 | `src/FrontAccounting/ProductAttributes/` |
| `Ksfraser\Traits\`                            | traits psr-4                   | `src/Ksfraser/Traits/`        |
| `KsfCommon\*` (legacy)             | **NOT autoloaded**            | aliased by `ksf_FA_Common/src/compat.php` |

**Gotcha — `KsfCommon\Plugin\*` is a legacy alias, not a real autoloaded
namespace.** `ksf_FA_Common/src/compat.php` (lines 58-69) keeps old callers working
via `class_alias()` for `AbstractPlugin`, `PluginInterface`, and `PluginRegistry`.
New code should import `ksfraser\FrontAccounting\Common\Plugin\*` directly.

## 4. Current tab flow — items.php host (implemented today)

1. Host page FA `inventory/items.php` invokes the module's hook methods via
   `hook_invoke_all()`.
2. `FA_ProductAttributes/hooks.php:195` — `item_display_tab_headers($tabs, $stockId)`
   merges the module's registered tabs into the FA tab array.
3. `FA_ProductAttributes/hooks.php:227` — `item_display_tab_content($stockId, $selectedTab)`
   renders the selected tab through the tab plugin's `renderTabContent()`.

**Registration today is a deliberate hard-coded list** of `new XTab()` objects in
`FA_ProductAttributes/hooks.php`. It is stable and covered by the 919-test suite —
**do not refactor a working module**; evolution happens via §6/§7 for new work.

### Tab plugin contract (`ProductAttributeTabInterface`)

| Method                                     | Purpose                                                         |
|--------------------------------------------|------------------------------------------------------------------|
| `getTabKey(): string`                      | Unique key in the FA tab array and `_tabs_sel` POST var |
| `getTabLabel(): string`                    | Label shown in the tab bar                                       |
| `isAvailable(string $stockId): bool`       | Show/hide for an item. `AbstractTab` default: `$stockId !== ''`  |
| `renderTabContent(string $stockId): void`  | Emit tab HTML                                                    |
| `handleSave(string $stockId, array $postData): void` | Persist on item save                                     |
| `handleDelete(string $stockId): void`      | Cleanup on item delete                                           |

## 5. Traits — where the shared logic lives

### `Ksfraser\Traits` (package `ksfraser/traits`)

- **`InlineTabRendererTrait`** (since 1.4.0) — shared render flow. The using class
  MUST declare `$tabClassName` (FQCN of the UI renderer); the trait manages `$tab`.
  `renderTabContent($stockId)` ⇒ `handlePostActions()` then `createTab()->render($stockId)`
  where `createTab()` = `new $this->tabClassName($this->dao)`.
- **`InlinePostActionsTrait`** — `initUpsertClass()`, `handlePostActions($stockId)`,
  `handleSave($stockId, $postData)`, `handleDelete($stockId)`, `localise()`.
- Others: `HookQueryProviderTrait`, `CrudEventEmitterTrait`, `EntityStateTrait`,
  `EventEmitterTrait`, `EnforceDeclaredPropsTrait`, `TimestampTrait`,
  `ValidatableTrait`, `LoggerAwareTrait`.

### `ksfraser\FrontAccounting\Common\Traits` (package `ksf-fa-common`)

- `CrudOperationsTrait`, `WorkflowHooksTrait`, `CalendarRegistrationTrait`, `FlashMessageTrait`.

## 6. Discovery layer — the road to auto-registration

`PluginRegistry` (`ksfraser\FrontAccounting\Common\Plugin\PluginRegistry`):

| Method                                   | Purpose                                      |
|------------------------------------------|----------------------------------------------|
| `discover(string $directory): void`      | Scan a directory for plugin contributions    |
| `loadFile(string $file): void`           | Load a single contributed file               |
| `register(PluginInterface $plugin): void`| Register a plugin instance                   |
| `getAll(): array`                        | All registered plugins                       |
| `getActive(): array`                     | Plugins whose `isActive()` is truthy         |
| `get(string $name): ?PluginInterface`    | Lookup by name                               |
| `has(string $name): bool`                | Existence check                              |
| `clear(): void`                          | Reset registry                               |

`AbstractPlugin` supplies no-op defaults for every hook point, so concrete tabs
override only what they use.

## 7. Unified tabs concept (roadmap v2.4)

**Maturity path:** hard-coded host registry → `PluginRegistry::discover(module_dir)`
→ active plugins auto-register onto their parent app.

**Host contract:** each host page/app defines the hook method names it invokes.

- **items host:** `item_display_tab_headers` / `item_display_tab_content` (implemented).
- **app hosts (CRM / HRM / PM):** `display_tab_headers` / `display_tab_content` —
  these are NOT FA core functions; the host app must invoke them via
  `hook_invoke()`. FA core does not call them for app pages.

**Template:** `ksf_Infrastructure/doc/templates/hooks-template.php` generalizes the
pattern for a new tab module. Treat it as **scaffold to be verified**, not spec.

## 8. Known template issues (`hooks-template.php`)

Concrete copy/paste bugs found while distilling the template (see §7):

1. **`$resolvedindex` casing** is inconsistent (`$resolvedindex` vs `$resolvedIndex`)
   across the inline tab resolution logic.
2. **`SA_<MODULE>MANAGE` bit bug** — defined as `SS_ksf_FA_<ModuleName> | 1`,
   duplicating the VIEW bit; it must be `| 2`.
3. **`has_<module>_access()`** is an un-substituted placeholder.
4. **Inline OO `display_tab_headers()`/`display_tab_content()`** on the module hooks
   class are fine as hook *method names*, but the host app must actually invoke them;
   do not assume FA core provides them on app pages.

## 9. Quick picks from `PACKAGIST.md`

| Need                                                        | Package                       |
|-------------------------------------------------------------|--------------------------------|
| Tab render + POST flow (traits)                             | `ksfraser/traits`             |
| Plugin registry / extension points                          | `ksfraser/ksf-fa-common`      |
| Tab plugin base classes (items host)                        | `ksfraser/fa-product-attributes-core` |
| Item tabs adapter (the exemplar)                            | `ksfraser/fa-product-attributes` |
| DAO / cross-platform data access                            | `ksfraser/ksf-modules-dao`    |
| Entities / DTOs + repositories (business logic pattern)     | `ksfraser/staging-dto`        |
| FA function mocks (unit tests)                              | `ksfraser/famock`             |
| Validation traits/helpers (PHP 7.3+)                        | `ksfraser/validation`         |

## 10. Form container constraint for tab content (verified 2026-09-03)

**Finding:** A module tab's content is rendered **inside** the host page's single
`<form>`, so a tab cannot emit its own `<form>` without creating invalid nested
forms.

Empirical probe against the live FA container (`items.php`, Variations tab):

| Probe                                            | Result |
|--------------------------------------------------|--------|
| `<form>` count on the whole items page           | `1` (the one opened by `start_form(true)` at `items.php:559`) |
| Tab panel `#_tabs_div` a descendant of the form? | `true` |
| Action buttons resolve to which form?            | `idx:0` (the page form) |
| `gcInSameFormAsTabsSel`                           | `true` — buttons share the host form with the hidden `_tabs_sel` |
| Nested `<form>` already present inside the panel?| `0` |

`items.php:559` (`start_form(true)`) opens the form; `tabbed_content_start()` at
`items.php:611` then renders all tab panels (including the module's tab content,
emitted via `item_display_tab_content` inside the `switch` at 613-658) **inside**
that form. `end_form()` only runs at `items.php:670`, after the tab panels.

**Consequence for HTML:** `<form>` cannot be nested. Browsers ignore the inner
`<form>` start tag (or auto-close the outer one), silently dropping the inner
form's fields and breaking the outer form's data. This is exactly the class of
bug #15/#16. The Variations tab therefore must NOT render its own `<form>` tag —
enforced by the regression test `testRenderDoesNotContainFormTag`.

**Working approach (option A, adopted):** stay inside the host form and drive
tab action buttons through FA's `ajaxsubmit`/`JsHttpRequest` path — the reusable
SRP renderers `Ksfraser\Frontaccounting\HTML\MasterSummaryTable`,
`FormFooter`, `TabContext` already emit `ajaxsubmit` submit buttons
+ `formnovalidate` + hidden `record_id` / `_tabs_sel`. No nested form, no broken
native submit. `handlePostActions()` reads `$_POST['_tabs_sel']` (the host form
still carries it) plus the button name.

### 10.1 Considered alternative — the `</form><form>` "second form" trick

Idea: emit an explicit `</form>` then a fresh `<form>` so the module's tab content
closes out of the host form and opens its own, producing a second (non-nested)
form.

**Verdict: rejected / fragile, do not adopt.**

- HTML5 parsing treats an *implied* `</form>` (a `</form>` without a matching open
  tag in a parser context) as a parse-structure fixup, so the exact behaviour is
  order- and browser-dependent and does not reliably produce the intended result.
- Even when it "works" as a second sibling form, the host form's fields that render
  **after** the tab panel — e.g. `hidden('fixed_asset', ...)` at `items.php:665`,
  the `br()`/`div_end()` at 660-663, and any post-tab content — would no longer be
  associated with the host form. They become orphans, silently breaking the item
  page's own form/field gating. It is safe only if the module tab is guaranteed to
  be the **last** element before `end_form()`, which is not the case here.
- It reintroduces the #15/#16 fragility this test suite exists to prevent.

If a module ever needs a **true** self-contained form with its own `action`
independent of the host form, the host must be structured so the tab panel lives
**outside** the host form (a host-side restructure of `items.php`, not a module
string-emit) — or the module should use a dedicated standalone page/endpoint
instead of a tab panel.

---

## Appendix A — editing this document (intentional-write ritual)

1. `chmod 644` the canonical file (this affects every hardlink — same inode).
2. Edit on disk.
3. `chmod 444` again.
4. **Re-run the hardlink step** (`ln -f`) for any repo where a git operation
   (pull/checkout/clone) replaced the file — hardlinks do **not** survive git.

## Appendix B — hardlink inventory / carrier repos

Docs hardlinked into repos: `MODULE_DIRECTORY.md`, `APP_TAB_ARCHITECTURE.md`,
`PACKAGIST.md` (+ any future `*_ARCHITECTURE.md` notes). See
`AGENTS_APPENDIX.md` §Architecture-doc hardlinks for the authoritative list and
the rule for **new** FA/WP related repos.