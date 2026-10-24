import { HOME_PATH } from '../config/navigation.js'

/**
 * Where to go after signing in. Only same-app paths are allowed, so a crafted
 * link like /login?next=https://evil.example can't send users off-site
 * (open redirect). Anything doubtful falls back to the dashboard.
 */
export function safeRedirect(next) {
  if (typeof next !== 'string' || next.length > 2000) return HOME_PATH
  if (!next.startsWith('/') || next.startsWith('//') || next.startsWith('/\\')) return HOME_PATH
  // Control characters (tabs, newlines…) can trick URL parsing.
  if ([...next].some((char) => char.charCodeAt(0) < 0x20)) return HOME_PATH

  let url
  try {
    url = new URL(next, 'http://app.local')
  } catch {
    return HOME_PATH
  }
  if (url.origin !== 'http://app.local') return HOME_PATH
  if (url.pathname === '/login' || url.pathname.startsWith('/login/')) return HOME_PATH

  return url.pathname + url.search + url.hash
}

export function loginPathFor(location) {
  const next = location.pathname + location.search
  return next === '/' || next === HOME_PATH ? '/login' : `/login?next=${encodeURIComponent(next)}`
}
