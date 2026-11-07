import { useCallback, useEffect, useMemo, useState } from 'react'
import { AuthContext } from './AuthContext.js'
import { api, onUnauthorized, setCsrfToken } from '../api/client.js'

/**
 * Authentication state for the whole app. On load it asks the server who is
 * signed in (GET /api/auth/me) instead of trusting anything stored in the
 * browser. Nothing auth-related is written to localStorage.
 *
 * status: 'loading' | 'authenticated' | 'two_factor' | 'unauthenticated' | 'error'
 * twoFactor (when status is two_factor): 'pending' | 'enroll'
 * reason (when unauthenticated): null | 'expired' | 'signed_out'
 */
function needsSecondStep(data) {
  return data?.twoFactor === 'pending' || data?.twoFactor === 'enroll'
}

export default function AuthProvider({ children }) {
  const [state, setState] = useState({ status: 'loading', user: null, twoFactor: 'ok', reason: null, error: null })
  const [attempt, setAttempt] = useState(0)

  const signedIn = useCallback((data) => {
    setCsrfToken(data.csrfToken)
    setState({ status: 'authenticated', user: data.user, twoFactor: 'ok', reason: null, error: null })
  }, [])

  const holdForSecondStep = useCallback((data) => {
    setCsrfToken(data.csrfToken)
    setState({ status: 'two_factor', user: data.user, twoFactor: data.twoFactor, reason: null, error: null })
  }, [])

  const signedOut = useCallback((reason) => {
    setCsrfToken(null)
    setState({ status: 'unauthenticated', user: null, reason, error: null })
  }, [])

  // Restore the session from the server on load (and on "Try again").
  useEffect(() => {
    const controller = new AbortController()
    api('/api/auth/me', { signal: controller.signal, notifyUnauthorized: false })
      .then((data) => (needsSecondStep(data) ? holdForSecondStep(data) : signedIn(data)))
      .catch((error) => {
        if (error.name === 'AbortError') return
        if (error.status === 401) signedOut(null)
        else setState({ status: 'error', user: null, reason: null, error })
      })
    return () => controller.abort()
  }, [attempt, signedIn, signedOut, holdForSecondStep])

  // Any API call answered with 401 means the session ended (expired, disabled, signed out elsewhere).
  useEffect(() => onUnauthorized(() => signedOut('expired')), [signedOut])

  const login = useCallback(
    async (email, password) => {
      const data = await api('/api/auth/login', { method: 'POST', body: { email, password }, notifyUnauthorized: false })
      if (needsSecondStep(data)) holdForSecondStep(data)
      else signedIn(data)
      return data
    },
    [signedIn, holdForSecondStep],
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
      completeSignIn: signedIn,
      retry: () => {
        setState({ status: 'loading', user: null, twoFactor: 'ok', reason: null, error: null })
        setAttempt((n) => n + 1)
      },
      // UI hint only — the server checks every request.
      hasPermission: (permission) => Boolean(state.user?.permissions?.includes(permission)),
    }),
    [state, login, logout, signedIn],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
