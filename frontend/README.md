# Yorilife ERP — Frontend

React 19 + Vite 8 + React Router 7 + Bootstrap 5.3, styled by the Yorilife design system in `src/styles/`.

```bash
npm install
npm run dev      # http://localhost:5173 (proxies /api to http://localhost:8000)
npm run build
npm run lint
```

## Structure

```
public/assets/images/yorilife-logo.png   Official logo (unmodified, 792x365)
src/
  main.jsx                 Entry: Bootstrap JS + styles/main.css
  App.jsx                  Mounts the preview data store + router
  router.jsx               Route table, generated from config/navigation.js (+ PAGE_ELEMENTS for built screens)
  config/navigation.js     Single source of truth: menu groups, pages, paths, icons, descriptions
  data/                    PREVIEW data store (in memory, per tab; nothing is saved)
    constants.js           Manager levels, allowed parent types, term kinds, Master Sheet years, import types
    erpReducer.js          Record shapes + pure reducer (soft delete, product & Master Sheet history)
    ErpDataProvider.jsx    Provides { state, actions }; swap actions for API calls later
    useErpData.js, ErpDataContext.js
    selectors.js           Hierarchy helpers (reporting chain, HQ tree), search, formatting
  utils/csv.js             CSV parser + structural checks for the import screen
  hooks/
    useRouteMeta.js        Title, description, group and breadcrumbs for the current URL
    useMediaQuery.js       Live media-query boolean (desktop vs mobile sidebar)
    useEditor.js           Add/edit modal state (open, record, session key)
    useFormState.js        Form values, validation, focus first invalid field
  layouts/
    AppLayout.jsx          Shell: sidebar + top bar + routed page + footer; sidebar state
    Sidebar.jsx            Collapsible, grouped navigation (desktop rail / mobile drawer)
    Topbar.jsx             Sidebar toggle, page context, notification + account placeholders
  components/
    PageShell.jsx          Standard page frame (header from route config + content)
    PageHeader.jsx         Breadcrumbs, h1, description, actions
    Breadcrumbs.jsx        Breadcrumb trail from [{ label, to? }]
    Modal.jsx              React-controlled Bootstrap modal (focus trap, Escape, focus restore)
    FormModal.jsx, ConfirmDialog.jsx
    FormField.jsx          FormField / TextField / SelectField with accessible errors
    DataTable.jsx, TableCard.jsx, SearchInput.jsx, SegmentedControl.jsx, StatusBadge.jsx
    ChangeValue.jsx        "old → new" display; ProductHistoryTable.jsx uses it
    HierarchyTree.jsx      HQ → managers (all levels) + mapped stockists
    PreviewNotice.jsx      "Preview — not saved" banner on every data screen
    EmptyState.jsx, LoadingIndicator.jsx, BrandLogo.jsx
  pages/
    sales-office/          HeadquartersPage, ManagersPage (levels 1–3), StockistsPage
    products/              CatalogueTermsPage (categories, tags, badges), ProductsPage (list + deleted),
                           ProductHistoryPage, MasterSheetPage
    sales/SalesPage.jsx    Sales entry, Monthly, by Headquarters, by Stockist (one component, 4 variants)
    imports/               ImportDataPage, ImportHistoryPage
    ModulePlaceholderPage.jsx  Shared placeholder for modules not built yet
    NotFoundPage.jsx           404 for unknown URLs
    DesignSystemPreview.jsx    Component reference at /design-system (not in the menu)
  styles/
    main.css               Import order: Bootstrap -> fonts -> variable.css -> theme.css
    variable.css           Design tokens (CSS variables) + Bootstrap variable bridge
    theme.css              All Yorilife styles, in numbered sections:
                             1. Reset       base element rules, focus-visible, reduced motion
                             2. Typography  Poppins, heading scale, text helpers
                             3. Layout      app shell, sidebar, top bar, page structure
                             4. Components  Bootstrap theming + search/empty/loading patterns
                             5. Utilities   helpers Bootstrap does not provide
                             6. Responsive  mobile-first breakpoint overrides (keep last)
```

## Sign-in and access control

```
src/api/client.js            fetch wrapper: same-origin cookie, CSRF header, ApiError, 401 → session ended
src/auth/                    AuthProvider (state from GET /api/auth/me), useAuth, RequireAuth,
                             RequirePermission, safeRedirect (+ .test.js)
src/layouts/ProtectedApp.jsx Signed-in shell (preview data store re-created per user)
src/pages/LoginPage.jsx      /login — outside the ERP layout
src/pages/system/UsersPage.jsx  System & Audit → Users (permission users.view)
e2e/                         Playwright browser tests (npm run test:e2e)
```

- **Protected routes:** every route except `/login` sits behind `RequireAuth`.
- **Permission-gated menu items:** add `permission: '…'` to a menu item in `config/navigation.js`. The sidebar then hides it and the route shows a "no access" page for users without that permission. This is UI only; the API must enforce the same permission.
- **Calling the API:** use `api()` from `src/api/client.js`; it adds the CSRF token automatically.

The architecture, endpoints and security rules are in [../docs/ARCHITECTURE.md](../docs/ARCHITECTURE.md#authentication--authorization).

## Preview data (not persisted)

The Sales Office, Products, Master Sheet, Sales and Import screens are fully interactive, but their data lives in `src/data/ErpDataProvider.jsx`. That is **in memory for the current tab only: a reload clears it, and nothing reaches the backend.** Every such screen shows a "Preview — not saved" notice. The app contains no sample business records.

Rules the preview follows:
- **Deletes are always soft** (`deletedAt`); nothing is ever removed.
- **Product and Master Sheet changes are appended to history** with old and new values.
- **Master Sheet years are stored separately**, so one year can't overwrite another.
- **Final value is never calculated**, because the formula isn't confirmed yet.

The proposed database tables are in [../docs/DATA_MODEL.md](../docs/DATA_MODEL.md). When the API exists, replace the action bodies in `ErpDataProvider.jsx` with API calls; the screens only use `{ state, actions }`.

## Navigation and routing

Everything navigational comes from `src/config/navigation.js`:

- **Sidebar** renders `NAV_MENU`, in order. It has top-level links, expandable groups, and one level of nested subgroups.
- **Router** creates one route per page (`NAV_ITEMS`); pages without a real screen render `ModulePlaceholderPage`. Plus:
  - `/` → redirect to `/dashboard`
  - group and subgroup URLs such as `/products` or `/reports` → redirect to their first page
  - `/design-system` → Sprint 1 component reference
  - `*` → `NotFoundPage`
- **Breadcrumbs, `<h1>`, page description, top-bar title and `document.title`** come from `getRouteMeta(pathname)`. The trail is `Dashboard › Group › [Subgroup] › Page`, and on `/dashboard` itself just `Dashboard`. Group crumbs are plain text because groups have no page of their own.

To add a page, add it to the right group in `navigation.js`. It appears in the menu, gets a route, and gets a title and breadcrumbs. Give it a real page component in `router.jsx` when the module is built.

Sidebar order (approved):

| # | Menu | Pages |
| - | ---- | ----- |
| 1 | Dashboard (link) | `/dashboard` |
| 2 | Products | `/products/group-categories`, `/products/categories`, `/products/tags-badges`, `/products/list`, `/products/master-sheet`, `/products/deleted`, `/products/change-history` |
| 3 | Sales Management | `/sales/stockist-sales`, `/sales/monthly`, `/sales/by-headquarters`, `/sales/by-stockist`, `/sales/calculation-history` |
|   | ↳ Reports | `/reports/headquarters`, `/reports/stockists`, `/reports/monthly-sales`, `/reports/product-performance` |
|   | ↳ CSV Import | `/imports/data`, `/imports/history` |
| 4 | Sales Office | `/sales-office/headquarters`, `/sales-office/first-line-managers`, `/sales-office/second-line-managers`, `/sales-office/third-line-managers`, `/sales-office/stockists` |
| 5 | Application Details | `/sales-app/overview`, `/sales-app/submission-status`, `/sales-app/history` (the former "Sales Application" pages; contents to be confirmed) |
| 6 | Announcements (link) | `/announcements` (placeholder) |
| 7 | System & Audit | `/system/users` (permission `users.view`), `/system/audit-logs`, `/system/change-history`, `/system/settings` |

### Layout behaviour

| Width | Sidebar |
| ----- | ------- |
| ≥ 992px | Persistent. The top-bar button collapses it to a 76px icon rail; the choice is saved in `localStorage` (`yorilife.sidebarCollapsed`). Clicking a group icon in the rail expands the sidebar and opens that group. |
| < 992px | Off-canvas drawer with overlay. Opening it moves focus to its close button. Tab and Shift+Tab stay inside the drawer, and the rest of the page is `inert` and scroll-locked. Escape, the overlay, the close button or choosing a page closes it. Escape and the close button return focus to the menu button. |

The group containing the current page always opens on navigation. The current page is marked with `aria-current="page"`, a teal bar, bold text and a tinted background, so the state is never shown by colour alone.

### Deep links in production

Vite's dev and preview servers already serve `index.html` for every route. A production web server must do the same. For Apache, put a rewrite in `dist/.htaccess` (or the vhost) that serves `index.html` for any request that isn't a real file. Leave `/api` to the PHP backend.

## Typography

All font sizes come from the fluid `--font-size-*` tokens in `variable.css`. Each is a `clamp()` that grows linearly from a 320px viewport to a 1440px viewport. For example, body text goes from 14px to 15px and page titles from 19px to 24px. **Never add a media query just to change a font size; pick a token.** Icon glyph sizes use `--icon-size-sm/md/lg`, which are aliases of the same scale.

## Rules of thumb

- Use tokens (`var(--color-primary)`, `var(--font-size-sm)`), never raw hex values or font sizes, outside `variable.css`.
- Add new styles to the matching section of `theme.css`. Media-query overrides go in section 6, and they are for layout only.
- Prefer Bootstrap markup and utilities. Add custom CSS only where the Yorilife brand needs it, and theme components by overriding their `--bs-*` variables.
- New pages use `PageShell` so the title, description and breadcrumbs stay in sync with the menu.
- Wrap every data table in `.table-container` so it scrolls horizontally on small screens.
- Filled teal surfaces with white text use `--color-primary-600`. The brand teal `--color-primary` (#24AEB1) has only 2.7:1 contrast with white, so use it for accents, borders, focus rings and icons.
