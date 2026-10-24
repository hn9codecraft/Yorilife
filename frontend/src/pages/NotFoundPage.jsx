import { Link } from 'react-router'
import PageShell from '../components/PageShell.jsx'
import EmptyState from '../components/EmptyState.jsx'
import { HOME_PATH } from '../config/navigation.js'

// Shown for any URL that is not in the route configuration.
export default function NotFoundPage() {
  return (
    <PageShell>
      <EmptyState
        titleAs="h2"
        icon="bi-signpost-split"
        title="We couldn't find that page"
        text="Check the address, or use the navigation menu to find what you need."
        action={
          <Link to={HOME_PATH} className="btn btn-primary btn-sm">
            <i className="bi bi-house-door" aria-hidden="true" /> Go to dashboard
          </Link>
        }
      />
    </PageShell>
  )
}
