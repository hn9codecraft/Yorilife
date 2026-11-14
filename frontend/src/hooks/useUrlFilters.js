import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router'

/**
 * List filters kept in the URL query string, so a filtered list can be
 * reloaded, bookmarked or shared. `defaults` must be a constant object
 * ({ key: defaultValue }); values equal to the default are left out of the URL.
 *
 *   const [filters, setFilter, clearFilters] = useUrlFilters(DEFAULTS)
 *   setFilter('hq', '12')     setFilter({ state: '3', hq: '' })     clearFilters()     clearFilters(['q'])
 * Pass an object to change several keys at once (separate calls in one
 * event would overwrite each other).
 */
export default function useUrlFilters(defaults) {
  const [params, setParams] = useSearchParams()

  const values = useMemo(
    () => Object.fromEntries(Object.keys(defaults).map((key) => [key, params.get(key) ?? defaults[key]])),
    [params, defaults],
  )

  const setFilter = useCallback((keyOrPatch, value) => {
    const patch = typeof keyOrPatch === 'object' && keyOrPatch !== null ? keyOrPatch : { [keyOrPatch]: value }
    setParams((previous) => {
      const next = new URLSearchParams(previous)
      Object.entries(patch).forEach(([key, nextValue]) => {
        if (nextValue == null || nextValue === '' || nextValue === defaults[key]) next.delete(key)
        else next.set(key, String(nextValue))
      })
      return next
    }, { replace: true })
  }, [setParams, defaults])

  const clearFilters = useCallback((keys = Object.keys(defaults)) => {
    setParams((previous) => {
      const next = new URLSearchParams(previous)
      keys.forEach((key) => next.delete(key))
      return next
    }, { replace: true })
  }, [setParams, defaults])

  return [values, setFilter, clearFilters]
}