import AppLayout from './AppLayout.jsx'
import useAuth from '../auth/useAuth.js'
import ErpDataProvider from '../data/ErpDataProvider.jsx'

// The ERP shell for a signed-in user. Business data is loaded from the API
// for each signed-in account. Every user currently sees the same records.
export default function ProtectedApp() {
  const { user } = useAuth()
  return (
    <ErpDataProvider key={user.id}>
      <AppLayout />
    </ErpDataProvider>
  )
}
