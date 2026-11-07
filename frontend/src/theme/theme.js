// Colour mode for the whole ERP. The matching script in index.html applies
// the same key before CSS loads, so the first paint already has the right theme.

export const THEME_STORAGE_KEY = 'yorilife.theme'

export const THEME_OPTIONS = [
  { id: 'light', label: 'Light', icon: 'bi-sun' },
  { id: 'dark', label: 'Dark', icon: 'bi-moon-stars' },
  { id: 'system', label: 'System', icon: 'bi-circle-half' },
]

const THEME_COLOR = {
  light: '#24AEB1',
  dark: '#0e1419',
}

export function readThemePreference(value) {
  return value === 'light' || value === 'dark' || value === 'system' ? value : 'system'
}

export function resolveTheme(preference, systemPrefersDark) {
  if (preference === 'light' || preference === 'dark') return preference
  return systemPrefersDark ? 'dark' : 'light'
}

export function applyTheme(preference, systemPrefersDark) {
  const theme = resolveTheme(preference, systemPrefersDark)
  const root = document.documentElement
  root.setAttribute('data-bs-theme', theme)
  root.setAttribute('data-theme-preference', preference)
  root.style.colorScheme = theme
  const meta = document.querySelector('meta[name="theme-color"]')
  if (meta) meta.setAttribute('content', THEME_COLOR[theme])
  return theme
}

export function setThemePreference(preference) {
  const next = readThemePreference(preference)
  localStorage.setItem(THEME_STORAGE_KEY, next)
  applyTheme(next, window.matchMedia('(prefers-color-scheme: dark)').matches)
  window.dispatchEvent(new CustomEvent('yorilife-theme', { detail: next }))
  return next
}

// Keeps data-bs-theme in step with the saved choice and the operating system.
export function subscribeTheme(onChange) {
  const media = window.matchMedia('(prefers-color-scheme: dark)')

  function sync() {
    const preference = readThemePreference(localStorage.getItem(THEME_STORAGE_KEY))
    applyTheme(preference, media.matches)
    onChange?.(preference, media.matches)
  }

  sync()
  media.addEventListener('change', sync)
  window.addEventListener('storage', sync)
  window.addEventListener('yorilife-theme', sync)

  return () => {
    media.removeEventListener('change', sync)
    window.removeEventListener('storage', sync)
    window.removeEventListener('yorilife-theme', sync)
  }
}
