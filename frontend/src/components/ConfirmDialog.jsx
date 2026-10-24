import Modal from './Modal.jsx'

// Yes/no confirmation, e.g. before a (soft) delete.
export default function ConfirmDialog({ open, onClose, onConfirm, title, children, confirmLabel = 'Confirm', variant = 'danger' }) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      footer={
        <>
          <button type="button" className="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button
            type="button"
            className={`btn btn-${variant}`}
            data-bs-dismiss="modal"
            onClick={onConfirm}
          >
            {confirmLabel}
          </button>
        </>
      }
    >
      {children}
    </Modal>
  )
}
