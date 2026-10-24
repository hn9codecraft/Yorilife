import AppLayout from './AppLayout.jsx'
import useAuth from '../auth/useAuth.js'
import ErpDataProvider from '../data/ErpDataProvider.jsx'

// The ERP shell for a signed-in user. The in-memory preview data is keyed by
// user, so a different account never sees the previous user's unsaved entries.
export default function ProtectedApp() {
  const { user } = useAuth()
  return (
    <ErpDataProvider key={user.id}>
      <AppLayout />
    </ErpDataProvider>
  )
}
