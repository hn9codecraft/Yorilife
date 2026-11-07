import { useEffect } from 'react'
import { RouterProvider } from 'react-router'
import { router } from './router.jsx'
import AuthProvider from './auth/AuthProvider.jsx'
import { subscribeTheme } from './theme/theme.js'

export default function App() {
  useEffect(() => subscribeTheme(), [])

  return (
    <AuthProvider>
      <RouterProvider router={router} />
    </AuthProvider>
  )
}
