import { Link } from 'react-router'
import useAuth from './useAuth.js'
import PageShell from '../components/PageShell.jsx'
import EmptyState from '../components/EmptyState.jsx'
import { HOME_PATH } from '../config/navigation.js'

/**
 * Shows a "no access" page instead of `children` when the signed-in user lacks
 * the permission. This is a convenience for the UI only: the API enforces the
 * same permission on every request.
 */
export default function RequirePermission({ permission, children }) {
  const { hasPermission } = useAuth()
  if (hasPermission(permission)) return children

  return (
    <PageShell>
      <EmptyState
        titleAs="h2"
        icon="bi-shield-lock"
        title="You don’t have access to this page"
        text="Your role doesn’t include this area. Ask an administrator if you need access."
        action={<Link to={HOME_PATH} className="btn btn-outline-secondary btn-sm">Back to dashboard</Link>}
      />
    </PageShell>
  )
}
