import { Link, useLocation } from 'react-router'
import PageShell from '../components/PageShell.jsx'
import EmptyState from '../components/EmptyState.jsx'
import useRouteMeta from '../hooks/useRouteMeta.js'
import { HOME_PATH, normalisePath } from '../config/navigation.js'

// One page for every planned ERP module. Content is driven by the navigation
// config; no data, forms or actions until the module is built.
export default function ModulePlaceholderPage() {
  const { title, icon } = useRouteMeta()
  const isHome = normalisePath(useLocation().pathname) === HOME_PATH

  return (
    <PageShell
      actions={<span className="badge status-badge text-info-emphasis bg-info-subtle">Planned</span>}
    >
      <EmptyState
        titleAs="h2"
        icon={icon}
        title={`${title} is coming soon`}
        text="This module will be built in a future sprint. No data is shown here yet."
        action={
          !isHome && (
            <Link to={HOME_PATH} className="btn btn-outline-secondary btn-sm">
              <i className="bi bi-arrow-left" aria-hidden="true" /> Back to dashboard
            </Link>
          )
        }
      />
    </PageShell>
  )
}
