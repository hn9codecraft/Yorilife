import { useState } from 'react'
import { Link, NavLink, useLocation } from 'react-router'
import BrandLogo from '../components/BrandLogo.jsx'
import useAuth from '../auth/useAuth.js'
import { HOME_PATH, NAV_MENU, getRouteMeta, isGroup } from '../config/navigation.js'

// Drop pages the user can't open, then any group left empty
// (UI convenience only; the API enforces access).
function visibleEntries(entries, hasPermission) {
  return entries
    .map((entry) => (isGroup(entry) ? { ...entry, items: visibleEntries(entry.items, hasPermission) } : entry))
    .filter((entry) => (isGroup(entry) ? entry.items.length > 0 : !entry.permission || hasPermission(entry.permission)))
}

/** Nested subgroup ids under a group (one level deep in practice). */
function nestedGroupIds(group) {
  return group.items.filter(isGroup).flatMap((sub) => [sub.id, ...nestedGroupIds(sub)])
}

/** Every expandable group id in the menu tree (top-level + nested). */
function allGroupIds(entries) {
  return entries.filter(isGroup).flatMap((group) => [group.id, ...nestedGroupIds(group)])
}

/**
 * Main navigation built from NAV_MENU: top-level links, expandable groups and
 * one level of nested subgroups (e.g. Sales Office → Data Import).
 * Accordion: only one top-level group open at a time; nested subgroups follow
 * the same rule among siblings and close when their parent closes.
 * - Desktop: persistent; `collapsed` shrinks it to an icon rail.
 * - Mobile: off-canvas; AppLayout controls visibility and passes `inert` when hidden.
 */
export default function Sidebar({ id, collapsed, inert, onClose, onNavigate, onRequestExpand, closeButtonRef }) {
  const { pathname } = useLocation()
  const { hasPermission } = useAuth()
  const activeIds = getRouteMeta(pathname).activeIds
  const menu = visibleEntries(NAV_MENU, hasPermission)

  const [expanded, setExpanded] = useState(() => new Set(activeIds))
  const [trackedPath, setTrackedPath] = useState(pathname)

  // Keep only the active page's ancestor groups open when the route changes
  // (adjusting state during render avoids an extra effect pass).
  if (pathname !== trackedPath) {
    setTrackedPath(pathname)
    setExpanded(new Set(activeIds))
  }

  function openOnly(groupId, { keepParentId } = {}) {
    const next = new Set()
    if (keepParentId) next.add(keepParentId)
    next.add(groupId)
    return next
  }

  function toggle(groupId) {
    const topLevel = menu.find((entry) => isGroup(entry) && entry.id === groupId)
    const parent = !topLevel
      ? menu.find((entry) => isGroup(entry) && entry.items.some((item) => isGroup(item) && item.id === groupId))
      : null

    if (collapsed) {
      // Rail mode has no room for sub-items: expand the sidebar and open only this group.
      onRequestExpand()
      if (topLevel) {
        setExpanded(openOnly(groupId))
      } else if (parent) {
        setExpanded(openOnly(groupId, { keepParentId: parent.id }))
      } else {
        setExpanded(new Set([groupId]))
      }
      return
    }

    setExpanded((prev) => {
      const isOpen = prev.has(groupId)

      if (topLevel) {
        if (isOpen) {
          const next = new Set(prev)
          next.delete(groupId)
          for (const childId of nestedGroupIds(topLevel)) next.delete(childId)
          return next
        }
        return openOnly(groupId)
      }

      if (parent) {
        if (isOpen) {
          const next = new Set(prev)
          next.delete(groupId)
          return next
        }
        // Accordion among sibling subgroups; keep the parent open.
        const next = new Set(prev)
        for (const siblingId of nestedGroupIds(parent)) next.delete(siblingId)
        // Drop other top-level groups so only this branch stays expanded.
        for (const idToClose of allGroupIds(menu)) {
          if (idToClose !== parent.id) next.delete(idToClose)
        }
        next.add(parent.id)
        next.add(groupId)
        return next
      }

      const next = new Set(prev)
      if (next.has(groupId)) next.delete(groupId)
      else next.add(groupId)
      return next
    })
  }

  function renderGroupItems(group) {
    return group.items.map((entry) =>
      isGroup(entry) ? (
        renderSubgroup(entry)
      ) : (
        <li key={entry.path}>
          <NavLink end to={entry.path} className="sidebar-link" onClick={onNavigate}>
            {entry.label}
          </NavLink>
        </li>
      ),
    )
  }

  // Nested group inside a group's panel (e.g. Data Import under Sales Office).
  function renderSubgroup(subgroup) {
    const isActive = activeIds.includes(subgroup.id)
    const isOpen = !collapsed && expanded.has(subgroup.id)
    const panelId = `nav-group-${subgroup.id}`

    return (
      <li key={subgroup.id} className={`sidebar-subgroup${isOpen ? ' is-open' : ''}${isActive ? ' is-active' : ''}`}>
        <button
          type="button"
          className="sidebar-subgroup__toggle"
          aria-expanded={isOpen}
          aria-controls={panelId}
          onClick={() => toggle(subgroup.id)}
        >
          <span className="sidebar-subgroup__label">{subgroup.label}</span>
          {isActive && <span className="visually-hidden"> (current section)</span>}
          <i className="bi bi-chevron-down sidebar-group__chevron" aria-hidden="true" />
        </button>
        <div id={panelId} className="sidebar-group__panel">
          <ul className="sidebar-group__list">{renderGroupItems(subgroup)}</ul>
        </div>
      </li>
    )
  }

  return (
    <aside id={id} className="app-sidebar" aria-label="Sidebar" inert={inert}>
      <div className="app-sidebar__header">
        <Link to={HOME_PATH} className="app-brand" aria-label="Yorilife ERP — go to dashboard" onClick={onNavigate}>
          <BrandLogo />
          <span className="app-brand__divider" aria-hidden="true" />
          <span className="app-brand__name">ERP</span>
        </Link>
        <button
          ref={closeButtonRef}
          type="button"
          className="btn btn-outline-secondary btn-icon app-sidebar__close"
          aria-label="Close navigation menu"
          onClick={onClose}
        >
          <i className="bi bi-x-lg" aria-hidden="true" />
        </button>
      </div>

      <nav className="app-sidebar__nav" aria-label="Main navigation">
        <ul className="sidebar-nav">
          {menu.map((entry) => {
            // Top-level page without a submenu (Dashboard, Announcements).
            if (!isGroup(entry)) {
              return (
                <li key={entry.id} className="sidebar-group">
                  <NavLink
                    end
                    to={entry.path}
                    className="sidebar-group__toggle sidebar-toplink"
                    title={collapsed ? entry.label : undefined}
                    onClick={onNavigate}
                  >
                    <i className={`bi ${entry.icon} sidebar-group__icon`} aria-hidden="true" />
                    <span className="sidebar-group__label">{entry.label}</span>
                  </NavLink>
                </li>
              )
            }

            const isActive = activeIds.includes(entry.id)
            const isOpen = !collapsed && expanded.has(entry.id)
            const panelId = `nav-group-${entry.id}`

            return (
              <li key={entry.id} className={`sidebar-group${isOpen ? ' is-open' : ''}${isActive ? ' is-active' : ''}`}>
                <button
                  type="button"
                  className="sidebar-group__toggle"
                  aria-expanded={isOpen}
                  aria-controls={panelId}
                  title={collapsed ? entry.label : undefined}
                  onClick={() => toggle(entry.id)}
                >
                  <i className={`bi ${entry.icon} sidebar-group__icon`} aria-hidden="true" />
                  <span className="sidebar-group__label">{entry.label}</span>
                  {isActive && <span className="visually-hidden"> (current section)</span>}
                  <i className="bi bi-chevron-down sidebar-group__chevron" aria-hidden="true" />
                </button>

                <div id={panelId} className="sidebar-group__panel">
                  <ul className="sidebar-group__list">{renderGroupItems(entry)}</ul>
                </div>
              </li>
            )
          })}
        </ul>
      </nav>
    </aside>
  )
}
