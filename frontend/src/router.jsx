import { cloneElement } from 'react'
import { Navigate, createBrowserRouter } from 'react-router'
import ProtectedApp from './layouts/ProtectedApp.jsx'
import RequireAuth from './auth/RequireAuth.jsx'
import RequirePermission from './auth/RequirePermission.jsx'
import LoginPage from './pages/LoginPage.jsx'
import ModulePlaceholderPage from './pages/ModulePlaceholderPage.jsx'
import DesignSystemPreview from './pages/DesignSystemPreview.jsx'
import NotFoundPage from './pages/NotFoundPage.jsx'
import HierarchyPage from './pages/sales-office/HierarchyPage.jsx'
import ManagersPage from './pages/sales-office/ManagersPage.jsx'
import StockistsPage from './pages/sales-office/StockistsPage.jsx'
import LocationPage from './pages/sales-office/LocationPage.jsx'
import HeadquartersPage from './pages/sales-office/HeadquartersPage.jsx'
import CatalogueTermsPage from './pages/products/CatalogueTermsPage.jsx'
import ProductsPage from './pages/products/ProductsPage.jsx'
import MasterSheetPage from './pages/products/MasterSheetPage.jsx'
import SalesPage from './pages/sales/SalesPage.jsx'
import UsersPage from './pages/system/UsersPage.jsx'
import AuditLogsPage from './pages/system/AuditLogsPage.jsx'
import ChangeHistoryPage from './pages/system/ChangeHistoryPage.jsx'
import SettingsPage from './pages/system/SettingsPage.jsx'
import SecurityPage from './pages/account/SecurityPage.jsx'
import PrivacyPage from './pages/system/PrivacyPage.jsx'
import ImportDataPage from './pages/imports/ImportDataPage.jsx'
import ImportHistoryPage from './pages/imports/ImportHistoryPage.jsx'
import AnnouncementsListPage from './pages/announcements/AnnouncementsListPage.jsx'
import AnnouncementFormPage from './pages/announcements/AnnouncementFormPage.jsx'
import AnnouncementDetailPage from './pages/announcements/AnnouncementDetailPage.jsx'
import AnnouncementPrintPage from './pages/announcements/AnnouncementPrintPage.jsx'
import AnnouncementTemplatesPage from './pages/announcements/AnnouncementTemplatesPage.jsx'
import EmployeesPage from './pages/announcements/EmployeesPage.jsx'
import { HOME_PATH, NAV_GROUPS, NAV_ITEMS } from './config/navigation.js'

// Pages that have a real screen. Every other menu item keeps the shared
// placeholder until its module is built. Paths must exist in navigation.js.
const PAGE_ELEMENTS = {
  '/products/group-categories': <CatalogueTermsPage kinds={['groupCategory']} />,
  '/products/categories': <CatalogueTermsPage kinds={['category']} />,
  '/products/tags-badges': <CatalogueTermsPage kinds={['tag', 'badge']} />,
  '/products/list': <ProductsPage />,
  '/products/master-sheet': <MasterSheetPage />,
  '/products/deleted': <ProductsPage deleted />,
  '/products/change-history': <ChangeHistoryPage module="products" />,
  '/sales-office/first-line-managers': <ManagersPage level={1} />,
  '/sales-office/second-line-managers': <ManagersPage level={2} />,
  '/sales-office/third-line-managers': <ManagersPage level={3} />,
  '/sales-office/stockists': <StockistsPage />,
  '/sales-office/headquarters': <HeadquartersPage />,
  '/sales-office/locations': <LocationPage />,
  '/sales/stockist-sales': <SalesPage variant="entry" />,
  '/sales/monthly': <SalesPage variant="monthly" />,
  '/sales/by-headquarters': <SalesPage variant="byHeadquarters" />,
  '/sales/by-stockist': <SalesPage variant="byStockist" />,
  '/imports/data': <ImportDataPage />,
  '/imports/history': <ImportHistoryPage />,
  '/system/users': <UsersPage />,
  '/system/audit-logs': <AuditLogsPage />,
  '/system/change-history': <ChangeHistoryPage />,
  '/account/security': <SecurityPage />,
  '/system/settings': <SettingsPage />,
  '/system/privacy': <PrivacyPage />,
  '/announcements/list': <AnnouncementsListPage />,
  '/announcements/new': <AnnouncementFormPage />,
  '/announcements/templates': <AnnouncementTemplatesPage />,
  '/announcements/employees': <EmployeesPage />,
}

const unknownPaths = Object.keys(PAGE_ELEMENTS).filter((path) => !NAV_ITEMS.some((item) => item.path === path))
if (unknownPaths.length) {
  throw new Error(`router.jsx PAGE_ELEMENTS has paths missing from navigation.js: ${unknownPaths.join(', ')}`)
}

// Every route is generated from the navigation config — nothing is listed twice.
// `key` remounts the page when two routes share a component (e.g. ProductsPage);
// items with a `permission` are wrapped in RequirePermission.
const moduleRoutes = NAV_ITEMS.map((item) => {
  const page = PAGE_ELEMENTS[item.path] ? cloneElement(PAGE_ELEMENTS[item.path], { key: item.path }) : <ModulePlaceholderPage />
  return {
    path: item.path,
    element: item.permission ? <RequirePermission permission={item.permission}>{page}</RequirePermission> : page,
  }
})

// Group and subgroup URLs (e.g. /products, /imports) have no page of their
// own; send them to the first page inside (preferring one without a permission).
const groupRedirects = NAV_GROUPS
  .filter((group) => !NAV_ITEMS.some((item) => item.path === group.basePath))
  .map((group) => {
    const pages = NAV_ITEMS.filter((item) => item.parents.some((parent) => parent.id === group.id))
    return {
      path: group.basePath,
      element: <Navigate to={(pages.find((page) => !page.permission) ?? pages[0]).path} replace />,
    }
  })

export const router = createBrowserRouter([
  // Public
  { path: '/login', element: <LoginPage /> },

  // Clean A4 print view of one announcement (no sidebar or top bar).
  {
    path: '/announcements/:id/print',
    element: (
      <RequireAuth>
        <RequirePermission permission="announcements.view"><AnnouncementPrintPage /></RequirePermission>
      </RequireAuth>
    ),
  },

  // Everything else requires a signed-in user (checked with the server on load).
  {
    path: '/',
    element: (
      <RequireAuth>
        <ProtectedApp />
      </RequireAuth>
    ),
    children: [
      { index: true, element: <Navigate to={HOME_PATH} replace /> },
      ...moduleRoutes,
      ...groupRedirects,
      { path: '/sales-office/hierarchy', element: <HierarchyPage /> },
      { path: '/announcements/:id', element: <RequirePermission permission="announcements.view"><AnnouncementDetailPage /></RequirePermission> },
      { path: '/announcements/:id/edit', element: <RequirePermission permission="announcements.manage"><AnnouncementFormPage key="edit" /></RequirePermission> },
      { path: '/design-system', element: <DesignSystemPreview /> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
