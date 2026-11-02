import useAuth from '../auth/useAuth.js'

// UI hint only — the API checks every request again.
export default function usePermission(permission) {
  return useAuth().hasPermission(permission)
}
