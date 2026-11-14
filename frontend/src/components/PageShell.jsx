import { useLocation } from 'react-router'
import PageHeader from './PageHeader.jsx'
import useRouteMeta from '../hooks/useRouteMeta.js'

// Standard page frame. Title, description and breadcrumbs come from the
// navigation config for the current URL, so pages only supply their content.
// Sales Office pages show the title only.
export default function PageShell({ actions, children, title, subtitle, breadcrumbs }) {
  const meta = useRouteMeta()
  const { pathname } = useLocation()
  const salesOffice = pathname === '/sales-office' || pathname.startsWith('/sales-office/')
  const resolvedSubtitle = subtitle !== undefined ? subtitle : (salesOffice ? undefined : meta.description)

  return (
    <>
      <PageHeader
        title={title ?? meta.title}
        subtitle={resolvedSubtitle || undefined}
        breadcrumbs={breadcrumbs ?? meta.breadcrumbs}
        actions={actions}
      />
      {children}
    </>
  )
}
