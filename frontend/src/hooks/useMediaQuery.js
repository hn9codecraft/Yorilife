import { useCallback, useSyncExternalStore } from 'react'

// Live boolean for a CSS media query, e.g. useMediaQuery('(min-width: 992px)').
export default function useMediaQuery(query) {
  const subscribe = useCallback(
    (onChange) => {
      const mql = window.matchMedia(query)
      mql.addEventListener('change', onChange)
      return () => mql.removeEventListener('change', onChange)
    },
    [query],
  )

  return useSyncExternalStore(subscribe, () => window.matchMedia(query).matches)
}
