import { useEffect, useState } from 'react'
import {
  THEME_OPTIONS,
  THEME_STORAGE_KEY,
  readThemePreference,
  resolveTheme,
  setThemePreference,
  subscribeTheme,
} from '../theme/theme.js'

// Light, dark, or match the device. The choice is stored in localStorage.
export default function ThemeToggle() {
  const [preference, setPreference] = useState(() => readThemePreference(localStorage.getItem(THEME_STORAGE_KEY)))
  const [systemDark, setSystemDark] = useState(() => window.matchMedia('(prefers-color-scheme: dark)').matches)

  useEffect(() => subscribeTheme((next, isDark) => {
    setPreference(next)
    setSystemDark(isDark)
  }), [])

  const current = THEME_OPTIONS.find((option) => option.id === preference) ?? THEME_OPTIONS[2]
  const resolved = resolveTheme(preference, systemDark)
  const detail = preference === 'system' ? `System (${resolved})` : current.label

  return (
    <div className="dropdown theme-toggle">
      <button
        type="button"
        className="btn btn-outline-secondary btn-icon dropdown-toggle"
        data-bs-toggle="dropdown"
        aria-expanded="false"
        aria-label={`Colour theme: ${detail}. Change theme`}
        title={`Theme: ${detail}`}
      >
        <i className={`bi ${current.icon}`} aria-hidden="true" />
      </button>
      <ul className="dropdown-menu dropdown-menu-end theme-toggle__menu">
        {THEME_OPTIONS.map((option) => {
          const selected = preference === option.id
          return (
            <li key={option.id}>
              <button
                type="button"
                className={`dropdown-item${selected ? ' active' : ''}`}
                aria-pressed={selected}
                onClick={() => setThemePreference(option.id)}
              >
                <i className={`bi ${option.icon}`} aria-hidden="true" />
                {option.label}
              </button>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
