import { useId } from 'react'
import Modal from './Modal.jsx'

/**
 * Modal containing a form. The footer's submit button is linked to the form
 * via the `form` attribute, so Enter in any field also submits.
 * `renderForm(formId)` must render a <form id={formId} onSubmit=...>.
 */
export default function FormModal({ open, onClose, title, submitLabel = 'Save', renderForm, size }) {
  const formId = useId()

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      size={size}
      footer={
        <>
          <button type="button" className="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" form={formId} className="btn btn-primary">{submitLabel}</button>
        </>
      }
    >
      {renderForm(formId)}
    </Modal>
  )
}
