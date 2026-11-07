import { useState } from 'react'
import useAuth from '../auth/useAuth.js'
import GlobalSearch from '../components/GlobalSearch.jsx'
import ThemeToggle from '../components/ThemeToggle.jsx'

/**
 * Sticky top bar: sidebar toggle on the left, global search in the center,
 * notification placeholder and account menu on the right.
 */
export default function Topbar({ sidebarId, toggleRef, toggleLabel, toggleExpanded, onToggle }) {
  const { user, logout } = useAuth()
  const [signingOut, setSigningOut] = useState(false)

  async function handleSignOut() {
    setSigningOut(true)
    await logout() // RequireAuth then redirects to /login
  }

  return (
    <header className="app-header">
      <div className="app-header__inner">
        <div className="app-header__start">
          <button
            ref={toggleRef}
            type="button"
            className="btn btn-outline-secondary btn-icon"
            aria-controls={sidebarId}
            aria-expanded={toggleExpanded}
            aria-label={toggleLabel}
            title={toggleLabel}
            onClick={onToggle}
          >
            <i className="bi bi-list" aria-hidden="true" />
          </button>
        </div>

        <GlobalSearch />

        <div className="app-header__actions">
          <ThemeToggle />
          <button
            type="button"
            className="btn btn-outline-secondary btn-icon"
            aria-disabled="true"
            aria-label="Notifications (coming soon)"
            title="Notifications (coming soon)"
          >
            <i className="bi bi-bell" aria-hidden="true" />
          </button>

          <div className="dropdown">
            <button
              type="button"
              className="btn btn-outline-secondary dropdown-toggle app-user-menu"
              data-bs-toggle="dropdown"
              aria-expanded="false"
              aria-label={`Account menu for ${user.name}`}
            >
              <i className="bi bi-person-circle" aria-hidden="true" />
              <span className="app-user-menu__label truncate">{user.name}</span>
            </button>
            <ul className="dropdown-menu dropdown-menu-end app-user-menu__menu">
              <li className="app-user-menu__identity">
                <span className="app-user-menu__name">{user.name}</span>
                <span className="app-user-menu__email">{user.email}</span>
                <span className="badge text-primary-emphasis bg-primary-subtle">{user.role.name}</span>
              </li>
              <li><hr className="dropdown-divider" /></li>
              <li>
                <button type="button" className="dropdown-item" disabled>
                  <i className="bi bi-person" aria-hidden="true" /> Profile (coming soon)
                </button>
              </li>
              <li>
                <button type="button" className="dropdown-item" onClick={handleSignOut} disabled={signingOut}>
                  <i className="bi bi-box-arrow-right" aria-hidden="true" /> {signingOut ? 'Signing out…' : 'Sign out'}
                </button>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </header>
  )
}
