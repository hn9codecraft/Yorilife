import { RouterProvider } from 'react-router'
import { router } from './router.jsx'
import AuthProvider from './auth/AuthProvider.jsx'

export default function App() {
  return (
    <AuthProvider>
      <RouterProvider router={router} />
    </AuthProvider>
  )
}
