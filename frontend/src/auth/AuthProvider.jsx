import { useCallback, useEffect, useMemo, useState } from 'react'
import { AuthContext } from './AuthContext.js'
import { api, onUnauthorized, setCsrfToken } from '../api/client.js'

/**
 * Authentication state for the whole app. On load it asks the server who is
 * signed in (GET /api/auth/me) instead of trusting anything stored in the
 * browser. Nothing auth-related is written to localStorage.
 *
 * status: 'loading' | 'authenticated' | 'unauthenticated' | 'error'
 * reason (when unauthenticated): null | 'expired' | 'signed_out'
 */
export default function AuthProvider({ children }) {
  const [state, setState] = useState({ status: 'loading', user: null, reason: null, error: null })
  const [attempt, setAttempt] = useState(0)

  const signedIn = useCallback((data) => {
    setCsrfToken(data.csrfToken)
    setState({ status: 'authenticated', user: data.user, reason: null, error: null })
  }, [])

  const signedOut = useCallback((reason) => {
    setCsrfToken(null)
    setState({ status: 'unauthenticated', user: null, reason, error: null })
  }, [])

  // Restore the session from the server on load (and on "Try again").
  useEffect(() => {
    const controller = new AbortController()
    api('/api/auth/me', { signal: controller.signal, notifyUnauthorized: false })
      .then(signedIn)
      .catch((error) => {
        if (error.name === 'AbortError') return
        if (error.status === 401) signedOut(null)
        else setState({ status: 'error', user: null, reason: null, error })
      })
    return () => controller.abort()
  }, [attempt, signedIn, signedOut])

  // Any API call answered with 401 means the session ended (expired, disabled, signed out elsewhere).
  useEffect(() => onUnauthorized(() => signedOut('expired')), [signedOut])

  const login = useCallback(
    async (email, password) => {
      const data = await api('/api/auth/login', { method: 'POST', body: { email, password }, notifyUnauthorized: false })
      signedIn(data)
      return data.user
    },
    [signedIn],
  )

  const logout = useCallback(async () => {
    try {
      await api('/api/auth/logout', { method: 'POST', notifyUnauthorized: false })
    } catch {
      // Already expired or server unreachable: the local state is cleared either way.
    }
    signedOut('signed_out')
  }, [signedOut])

  const value = useMemo(
    () => ({
      ...state,
      login,
      logout,
      retry: () => {
        setState({ status: 'loading', user: null, reason: null, error: null })
        setAttempt((n) => n + 1)
      },
      // UI hint only — the server checks every request.
      hasPermission: (permission) => Boolean(state.user?.permissions?.includes(permission)),
    }),
    [state, login, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
