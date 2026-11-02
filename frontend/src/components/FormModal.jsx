import { useId, useMemo, useState } from 'react'
import Modal from './Modal.jsx'
import { FormModalContext } from './FormModalContext.js'

/**
 * Modal containing a form. The footer's submit button is linked to the form
 * via the `form` attribute, so Enter in any field also submits.
 * `renderForm(formId)` must render a <form id={formId} onSubmit=...>.
 * While an async save runs the submit button shows "Saving…" and further
 * submits are ignored (no double submit; buttons stay focusable so Escape and
 * focus keep working); a
 * server error that isn't tied to a field is shown above the form.
 */
export default function FormModal({ open, onClose, title, submitLabel = 'Save', renderForm, size }) {
  const formId = useId()
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)

  const [wasOpen, setWasOpen] = useState(open)

  // Each opening starts without the previous error (state reset during render).
  if (open !== wasOpen) {
    setWasOpen(open)
    if (open) {
      setError(null)
      setSaving(false)
    }
  }

  const context = useMemo(() => ({ setSaving, setError }), [])

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      size={size}
      footer={
        <>
          <button type="button" className="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" form={formId} className="btn btn-primary" aria-disabled={saving || undefined} aria-busy={saving || undefined}>
            {saving && <span className="spinner-border spinner-border-sm" aria-hidden="true" />} {saving ? 'Saving…' : submitLabel}
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
      <FormModalContext.Provider value={context}>{renderForm(formId)}</FormModalContext.Provider>
    </Modal>
  )
}
