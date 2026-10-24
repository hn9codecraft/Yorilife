import { Navigate, useLocation } from 'react-router'
import useAuth from './useAuth.js'
import { loginPathFor } from './safeRedirect.js'
import AuthStatusScreen from '../components/AuthStatusScreen.jsx'

/**
 * Gate for every ERP page. Waits for the server's answer before deciding,
 * so a reload never flashes the login page for a signed-in user.
 */
export default function RequireAuth({ children }) {
  const auth = useAuth()
  const location = useLocation()

  if (auth.status === 'loading') {
    return <AuthStatusScreen loading title="Checking your session…" />
  }

  if (auth.status === 'error') {
    return (
      <AuthStatusScreen
        icon="bi-cloud-slash"
        title="We couldn’t check your session"
        text={auth.error?.message}
        action={<button type="button" className="btn btn-primary" onClick={auth.retry}>Try again</button>}
      />
    )
  }

  if (auth.status === 'unauthenticated') {
    // After an explicit sign-out, don't bounce back to the last page on next sign-in.
    const to = auth.reason === 'signed_out' ? '/login' : loginPathFor(location)
    return <Navigate to={to} replace />
  }

  return children
}
