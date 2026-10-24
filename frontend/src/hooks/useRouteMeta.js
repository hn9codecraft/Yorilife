import { useMemo } from 'react'
import { useLocation } from 'react-router'
import { getRouteMeta } from '../config/navigation.js'

// Title, description, nav group and breadcrumbs for the current URL.
export default function useRouteMeta() {
  const { pathname } = useLocation()
  return useMemo(() => getRouteMeta(pathname), [pathname])
}
