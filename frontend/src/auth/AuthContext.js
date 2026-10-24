import { createContext } from 'react'

// { status, user, reason, error, login, logout, retry, hasPermission } from AuthProvider.
export const AuthContext = createContext(null)
