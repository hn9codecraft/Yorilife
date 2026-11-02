import { useState } from 'react'
import Modal from './Modal.jsx'

/**
 * Yes/no confirmation, e.g. before a (soft) delete or a restore.
 * `onConfirm` may return a promise: the dialog stays open (busy)
 * (repeat clicks ignored) until it settles, closes on success and shows the error message on failure.
 */
export default function ConfirmDialog({ open, onClose, onConfirm, title, children, confirmLabel = 'Confirm', variant = 'danger' }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const [wasOpen, setWasOpen] = useState(open)

  if (open !== wasOpen) {
    setWasOpen(open)
    if (open) {
      setBusy(false)
      setError(null)
    }
  }

  const confirm = async () => {
    if (busy) return
    setBusy(true)
    setError(null)
    try {
      await onConfirm()
      setBusy(false)
      onClose()
    } catch (err) {
      setBusy(false)
      setError(err?.message || 'Something went wrong. Please try again.')
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      footer={
        <>
          <button type="button" className="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" className={`btn btn-${variant}`} onClick={confirm} aria-disabled={busy || undefined} aria-busy={busy || undefined}>
            {busy && <span className="spinner-border spinner-border-sm" aria-hidden="true" />} {confirmLabel}
          </button>
        </>
      }
    >
      {error && (
        <div className="alert alert-danger" role="alert">
          <i className="bi bi-exclamation-octagon-fill" aria-hidden="true" />
          <div>{error}</div>
        </div>
      )}
      {children}
    </Modal>
  )
}
