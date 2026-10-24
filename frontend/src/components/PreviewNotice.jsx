// Shown on every screen backed by the in-memory preview store, so nobody
// mistakes it for saved data.
export default function PreviewNotice() {
  return (
    <div className="alert alert-warning" role="note">
      <i className="bi bi-exclamation-triangle-fill" aria-hidden="true" />
      <div>
        <strong>Preview — not saved.</strong> Records you add here are kept only in this browser tab and
        are cleared when the page is reloaded. Nothing is sent to the database yet.
      </div>
    </div>
  )
}
