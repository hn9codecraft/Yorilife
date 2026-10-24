// Accessible loading indicator for async content.
export default function LoadingIndicator({ label = 'Loading…' }) {
  return (
    <div className="loading-state" role="status" aria-live="polite">
      <span className="spinner-border spinner-border-sm" aria-hidden="true" />
      <span>{label}</span>
    </div>
  )
}
