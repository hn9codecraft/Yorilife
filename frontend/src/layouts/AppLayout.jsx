import { useEffect, useRef, useState } from 'react'
import { flushSync } from 'react-dom'
import { Link, Outlet, ScrollRestoration } from 'react-router'
import Sidebar from './Sidebar.jsx'
import Topbar from './Topbar.jsx'
import useMediaQuery from '../hooks/useMediaQuery.js'
import useRouteMeta from '../hooks/useRouteMeta.js'
import { APP_TITLE } from '../config/navigation.js'

const CURRENT_YEAR = new Date().getFullYear()
const SIDEBAR_ID = 'app-sidebar'
const COLLAPSED_STORAGE_KEY = 'yorilife.sidebarCollapsed'
// Must match the lg breakpoint used for the sidebar in theme.css (Responsive section).
const DESKTOP_QUERY = '(min-width: 992px)'

// Shared application shell: sidebar, top bar, routed page content and footer.
export default function AppLayout() {
  const isDesktop = useMediaQuery(DESKTOP_QUERY)
  const { title } = useRouteMeta()

  const [collapsed, setCollapsed] = useState(() => localStorage.getItem(COLLAPSED_STORAGE_KEY) === 'true')
  const [mobileOpenRequested, setMobileOpenRequested] = useState(false)
  // Ignore a stale "open" request once the viewport grows to desktop.
  const mobileOpen = !isDesktop && mobileOpenRequested
  const railCollapsed = isDesktop && collapsed

  const toggleRef = useRef(null)
  const closeButtonRef = useRef(null)
  const restoreFocusRef = useRef(false)

  useEffect(() => {
    document.title = `${title} · ${APP_TITLE}`
  }, [title])

  useEffect(() => {
    localStorage.setItem(COLLAPSED_STORAGE_KEY, String(collapsed))
  }, [collapsed])

  // Mobile off-canvas: lock page scroll, move focus into the menu, close on Escape.
  useEffect(() => {
    if (!mobileOpen) {
      // Runs after React has removed `inert` from the page, so the toggle is focusable again.
      if (restoreFocusRef.current) {
        restoreFocusRef.current = false
        toggleRef.current?.focus()
      }
      return undefined
    }

    document.body.classList.add('is-scroll-locked')

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        closeMobileMenu()
      } else if (event.key === 'Tab') {
        keepFocusInSidebar(event)
      }
    }
    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.body.classList.remove('is-scroll-locked')
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [mobileOpen])

  // Wrap Tab / Shift+Tab inside the open drawer, like a modal dialog.
  function keepFocusInSidebar(event) {
    const sidebar = document.getElementById(SIDEBAR_ID)
    const focusable = [...sidebar.querySelectorAll('a[href], button:not([disabled])')]
      .filter((el) => el.checkVisibility({ visibilityProperty: true }))
    if (focusable.length === 0) return

    const first = focusable[0]
    const last = focusable[focusable.length - 1]
    const outside = !sidebar.contains(document.activeElement)

    if (event.shiftKey && (document.activeElement === first || outside)) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && (document.activeElement === last || outside)) {
      event.preventDefault()
      first.focus()
    }
  }

  function closeMobileMenu({ restoreFocus = true } = {}) {
    restoreFocusRef.current = restoreFocus
    setMobileOpenRequested(false)
  }

  function handleToggle() {
    if (isDesktop) {
      setCollapsed((value) => !value)
      return
    }
    // Commit the open drawer synchronously, then move focus into it before the
    // toggle (now inside the inert page) can lose focus to <body>.
    flushSync(() => setMobileOpenRequested(true))
    closeButtonRef.current?.focus()
  }

  const toggleLabel = isDesktop
    ? collapsed ? 'Expand sidebar' : 'Collapse sidebar'
    : 'Open navigation menu'

  const shellClassName = [
    'app-shell',
    railCollapsed && 'is-sidebar-collapsed',
    mobileOpen && 'is-sidebar-open',
  ].filter(Boolean).join(' ')

  return (
    <div className={shellClassName}>
      <a href="#main-content" className="visually-hidden-focusable" inert={mobileOpen}>
        Skip to main content
      </a>

      <Sidebar
        id={SIDEBAR_ID}
        collapsed={railCollapsed}
        inert={!isDesktop && !mobileOpen}
        closeButtonRef={closeButtonRef}
        onClose={() => closeMobileMenu()}
        onNavigate={() => closeMobileMenu({ restoreFocus: false })}
        onRequestExpand={() => setCollapsed(false)}
      />
      <div className="app-sidebar-backdrop" aria-hidden="true" onClick={() => closeMobileMenu()} />

      <div className="app-body" inert={mobileOpen}>
        <Topbar
          sidebarId={SIDEBAR_ID}
          toggleRef={toggleRef}
          toggleLabel={toggleLabel}
          toggleExpanded={isDesktop ? !collapsed : mobileOpen}
          onToggle={handleToggle}
        />

        <main id="main-content" className="app-main" tabIndex={-1}>
          <div className="app-container">
            <Outlet />
          </div>
        </main>

        <footer className="app-footer">
          <div className="app-container app-footer__inner">
            <span>© {CURRENT_YEAR} Yorilife Sciences Pvt. Ltd. · {APP_TITLE}</span>
            <Link to="/design-system">Design system</Link>
          </div>
        </footer>
      </div>

      <ScrollRestoration />
    </div>
  )
}
