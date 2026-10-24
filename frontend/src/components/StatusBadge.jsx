// Coloured status pill (dot + text) using Bootstrap's subtle colour utilities.
// variant: success | warning | danger | info | secondary | primary
export default function StatusBadge({ variant = 'secondary', children }) {
  return <span className={`badge status-badge text-${variant}-emphasis bg-${variant}-subtle`}>{children}</span>
}
