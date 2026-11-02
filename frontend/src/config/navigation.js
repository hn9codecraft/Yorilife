// Single source of truth for ERP navigation.
// The sidebar, router, breadcrumbs, page titles and placeholder pages all read
// from NAV_MENU — add, move or rename a page here and every consumer follows.

export const HOME_PATH = '/dashboard'
export const HOME_LABEL = 'Dashboard'
export const APP_TITLE = 'Yorilife ERP'

/**
 * Sidebar menu, in display order. Three kinds of entry:
 *   - link:     { id, label, icon, path, description }            top-level page, no submenu
 *   - group:    { id, label, icon, basePath, items: [...] }       expandable menu
 *   - subgroup: a group nested inside a group's `items` (one level deep)
 * Pages ("leaves") have `path`, `label`, `icon`, `description` and an optional
 * `permission` (shown and routed only for users who have it).
 */
export const NAV_MENU = [
  {
    id: 'dashboard',
    label: 'Dashboard',
    icon: 'bi-speedometer2',
    path: '/dashboard',
    description: 'A summary view of key ERP activity across the organisation.',
  },
  {
    id: 'products',
    label: 'Products',
    icon: 'bi-box-seam',
    basePath: '/products',
    items: [
      {
        path: '/products/group-categories',
        label: 'Product Group Categories',
        icon: 'bi-diagram-3',
        description: 'Group categories. Each product category belongs to one group, and products are associated through that category.',
      },
      {
        path: '/products/categories',
        label: 'Product Categories',
        icon: 'bi-tags',
        description: 'Product categories and the group each one belongs to. Products are associated with one category.',
      },
      {
        path: '/products/tags-badges',
        label: 'Product Tags & Badges',
        icon: 'bi-award',
        description: 'Tags and badges, each in its own table, and the products associated with them.',
      },
      {
        path: '/products/list',
        label: 'Product List',
        icon: 'bi-list-ul',
        description: 'Active products, their group and product category, and the current scheme. Deleting a product moves it to Deleted Products; nothing is erased.',
      },
      {
        path: '/products/master-sheet',
        label: 'Master Product Sheet',
        icon: 'bi-file-earmark-spreadsheet',
        description: 'Financial-year prices and Extra Bonus / Incentive eligibility. The scheme is the product’s current scheme.',
      },
      {
        path: '/products/deleted',
        label: 'Deleted Products',
        icon: 'bi-trash3',
        description: 'Products removed from the Product List. Their records are kept for history.',
      },
      {
        path: '/products/change-history',
        label: 'Product Change History',
        icon: 'bi-clock-history',
        description: 'Product creation, edits, scheme changes and deletions, with previous and updated values.',
      },
    ],
  },
  {
    id: 'sales',
    label: 'Sales Management',
    icon: 'bi-graph-up-arrow',
    basePath: '/sales',
    items: [
      {
        path: '/sales/stockist-sales',
        label: 'Stockist Sales',
        icon: 'bi-receipt',
        description: 'Enter sales per stockist: achievement value, bonus, deduction and final value.',
      },
      {
        path: '/sales/monthly',
        label: 'Monthly Sales',
        icon: 'bi-calendar3',
        description: 'Sales entries for a chosen month.',
      },
      {
        path: '/sales/by-headquarters',
        label: 'Sales by Headquarters',
        icon: 'bi-building',
        description: 'Sales entries for one headquarters.',
      },
      {
        path: '/sales/by-stockist',
        label: 'Sales by Stockist',
        icon: 'bi-shop-window',
        description: 'Sales entries for one stockist within its headquarters.',
      },
      {
        path: '/sales/calculation-history',
        label: 'Sales Calculation History',
        icon: 'bi-calculator',
        description: 'A record of past sales calculation runs.',
      },
      {
        id: 'reports',
        label: 'Reports',
        icon: 'bi-bar-chart-line',
        basePath: '/reports',
        items: [
          {
            path: '/reports/headquarters',
            label: 'Headquarters Reports',
            icon: 'bi-file-earmark-bar-graph',
            description: 'Reports grouped by headquarters.',
          },
          {
            path: '/reports/stockists',
            label: 'Stockist Reports',
            icon: 'bi-file-earmark-text',
            description: 'Reports grouped by stockist.',
          },
          {
            path: '/reports/monthly-sales',
            label: 'Monthly Sales Reports',
            icon: 'bi-calendar-range',
            description: 'Reports on sales by month.',
          },
          {
            path: '/reports/product-performance',
            label: 'Product Performance Reports',
            icon: 'bi-graph-up',
            description: 'Reports on how individual products perform.',
          },
        ],
      },
      // CSV Import is not a top-level menu in the approved order; it stays
      // available here (same URLs) next to the sales data it imports.
      {
        id: 'imports',
        label: 'CSV Import',
        icon: 'bi-filetype-csv',
        basePath: '/imports',
        items: [
          {
            path: '/imports/data',
            label: 'Import Data',
            icon: 'bi-upload',
            description: 'Choose a CSV file and check its structure before importing.',
          },
          {
            path: '/imports/history',
            label: 'Import History',
            icon: 'bi-clock-history',
            description: 'A log of past CSV imports.',
          },
        ],
      },
    ],
  },
  {
    id: 'sales-office',
    label: 'Sales Office',
    icon: 'bi-buildings',
    basePath: '/sales-office',
    items: [
      {
        path: '/sales-office/stockists',
        label: 'Stockist Entry',
        icon: 'bi-shop',
        description: 'Stockists and the headquarters each one is mapped to.',
      },
      {
        path: '/sales-office/headquarters',
        label: 'Headquarters',
        icon: 'bi-building',
        description: 'Headquarters linked to a location headquarters and a 1st line manager.',
      },
      {
        path: '/sales-office/first-line-managers',
        label: '1st Line Manager',
        icon: 'bi-person-badge',
        description: '1st line managers. Each one belongs to a 2nd line manager.',
      },
      {
        path: '/sales-office/second-line-managers',
        label: '2nd Line Manager',
        icon: 'bi-people',
        description: '2nd line managers. Each one belongs to a 3rd line manager.',
      },
      {
        path: '/sales-office/third-line-managers',
        label: '3rd Line Manager',
        icon: 'bi-diagram-2',
        description: '3rd line managers. Each one is at the top of the hierarchy.',
      },
      {
        path: '/sales-office/locations',
        label: 'Location',
        icon: 'bi-geo-alt',
        description: 'States, location codes, and the headquarters names configured for each state.',
      },
    ],
  },
  {
    // Formerly "Sales Application" (the sales app's entries). Contents to be confirmed.
    id: 'sales-app',
    label: 'Application Details',
    icon: 'bi-phone',
    basePath: '/sales-app',
    items: [
      {
        path: '/sales-app/overview',
        label: 'Sales Entry Overview',
        icon: 'bi-clipboard-data',
        description: 'An overview of sales entries submitted through the sales application.',
      },
      {
        path: '/sales-app/submission-status',
        label: 'Submission Status',
        icon: 'bi-check2-circle',
        description: 'The status of sales entries submitted through the sales application.',
      },
      {
        path: '/sales-app/history',
        label: 'Sales Entry History',
        icon: 'bi-journal-text',
        description: 'A record of past sales entries from the sales application.',
      },
    ],
  },
  {
    id: 'announcements',
    label: 'Announcements',
    icon: 'bi-megaphone',
    path: '/announcements',
    description: 'Announcements for ERP users.',
  },
  {
    id: 'system',
    label: 'System & Audit',
    icon: 'bi-shield-check',
    basePath: '/system',
    items: [
      {
        path: '/system/users',
        label: 'Users',
        icon: 'bi-people',
        description: 'Who can sign in to the ERP, their roles and account status.',
        // Shown and routed only for users with this permission (the API enforces it too).
        permission: 'users.view',
      },
      {
        path: '/system/audit-logs',
        label: 'Audit Logs',
        icon: 'bi-journal-check',
        description: 'A log of significant actions taken in the system.',
      },
      {
        path: '/system/change-history',
        label: 'Change History',
        icon: 'bi-clock-history',
        description: 'A record of changes made across the system.',
      },
      {
        path: '/system/settings',
        label: 'Settings',
        icon: 'bi-gear',
        description: 'System-wide configuration.',
      },
    ],
  },
]

// Routes that exist but are not in the sidebar.
export const STANDALONE_PAGES = [
  {
    path: '/design-system',
    label: 'Design System',
    description: 'Yorilife ERP brand tokens and standard components — reference for developers.',
  },
  {
    path: '/sales-office/hierarchy',
    label: 'Sales Office Hierarchy',
    description: 'Shyam, then each 2nd line manager, 1st line manager, headquarters, and stockists.',
  },
]

export const NOT_FOUND_PAGE = {
  label: 'Page not found',
  description: 'The page you are looking for does not exist or has moved.',
}

export const isGroup = (entry) => Array.isArray(entry.items)

/**
 * Every group and subgroup (for redirects from their basePath).
 * Each has `parents`: the groups above it.
 */
export const NAV_GROUPS = (function collect(entries, parents = []) {
  return entries.filter(isGroup).flatMap((group) => [
    { ...group, parents },
    ...collect(group.items, [...parents, group]),
  ])
})(NAV_MENU)

/**
 * Flat list of every page in the menu. Each has `parents` (enclosing groups,
 * outermost first) and `group` (its top-level group, or null for top-level links).
 */
export const NAV_ITEMS = (function collect(entries, parents = []) {
  return entries.flatMap((entry) =>
    isGroup(entry)
      ? collect(entry.items, [...parents, entry])
      : [{ ...entry, parents, group: parents[0] ?? null }],
  )
})(NAV_MENU)

export function normalisePath(pathname) {
  const trimmed = pathname.replace(/\/+$/, '')
  return trimmed === '' ? '/' : trimmed
}

/**
 * Describes the page at `pathname` for the layout: title, description, its
 * enclosing groups and breadcrumb trail.
 * Breadcrumbs: Dashboard › [Group] › [Subgroup] › Page. Group crumbs are plain
 * text because groups have no page of their own.
 * `activeIds` lists the menu entries to highlight/expand (groups + top-level link).
 */
export function getRouteMeta(pathname) {
  const path = normalisePath(pathname)
  const home = { label: HOME_LABEL, to: HOME_PATH }

  const item = NAV_ITEMS.find((entry) => entry.path === path)
  if (item) {
    const breadcrumbs = item.path === HOME_PATH
      ? [{ label: item.label }]
      : [home, ...item.parents.map((parent) => ({ label: parent.label })), { label: item.label }]

    return {
      title: item.label,
      description: item.description,
      icon: item.icon,
      group: item.group,
      activeIds: [...item.parents.map((parent) => parent.id), ...(item.id ? [item.id] : [])],
      breadcrumbs,
    }
  }

  const page = STANDALONE_PAGES.find((entry) => entry.path === path) ?? NOT_FOUND_PAGE
  return {
    title: page.label,
    description: page.description,
    icon: null,
    group: null,
    activeIds: [],
    breadcrumbs: [home, { label: page.label }],
  }
}
