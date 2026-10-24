import { useEffect, useId, useRef } from 'react'
import { Modal as BootstrapModal } from 'bootstrap'

/**
 * React-controlled wrapper around Bootstrap's modal (keeps Bootstrap's focus
 * trap, Escape handling, backdrop and the Yorilife .modal theme).
 * `open` drives visibility; `onClose` fires however the modal is dismissed.
 * Content stays mounted while closed (so the fade-out isn't blank); give forms
 * a fresh `key` each time the modal opens to reset them. Keep the <Modal>
 * itself mounted — put such keys on its children, not on the Modal.
 */
export default function Modal({ open, onClose, title, children, footer, size }) {
  const elementRef = useRef(null)
  const instanceRef = useRef(null)
  const onCloseRef = useRef(onClose)
  const returnFocusRef = useRef(null)
  const titleId = useId()

  useEffect(() => {
    onCloseRef.current = onClose
  })

  useEffect(() => {
    const element = elementRef.current
    const instance = new BootstrapModal(element)
    instanceRef.current = instance
    let hiding = false
    const handleHide = () => { hiding = true }
    const handleHidden = () => {
      hiding = false
      onCloseRef.current?.()
      // Bootstrap only restores focus for data-api triggers; do it for programmatic opens too.
      const target = returnFocusRef.current
      returnFocusRef.current = null
      if (target?.isConnected) target.focus()
    }
    // Bootstrap focuses the dialog itself; for forms, start on the first field instead.
    const handleShown = () => {
      element.querySelector('.modal-body :is(input, select, textarea):not([type="hidden"]):not(:disabled)')?.focus()
    }
    element.addEventListener('hide.bs.modal', handleHide)
    element.addEventListener('hidden.bs.modal', handleHidden)
    element.addEventListener('shown.bs.modal', handleShown)

    return () => {
      element.removeEventListener('hide.bs.modal', handleHide)
      element.removeEventListener('hidden.bs.modal', handleHidden)
      element.removeEventListener('shown.bs.modal', handleShown)
      const wasShown = element.classList.contains('show')
      // Disposing mid fade-out makes Bootstrap's queued callback hit a null
      // element; let the fade finish first (it also removes the backdrop).
      if (hiding) setTimeout(() => instance.dispose(), 500)
      else instance.dispose()
      // Unmounted while open (e.g. route change): undo Bootstrap's page changes.
      if (wasShown) {
        document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove())
        document.body.classList.remove('modal-open')
        document.body.style.removeProperty('overflow')
        document.body.style.removeProperty('padding-right')
      }
    }
  }, [])

  useEffect(() => {
    if (!open) {
      instanceRef.current.hide()
      return undefined
    }
    returnFocusRef.current = document.activeElement
    // Next frame, cancellable: if React unmounts first (StrictMode's dev
    // double-mount, fast navigation) Bootstrap never starts a show it can't finish.
    const frame = requestAnimationFrame(() => instanceRef.current.show())
    return () => cancelAnimationFrame(frame)
  }, [open])

  return (
    <div ref={elementRef} className="modal fade" tabIndex={-1} aria-labelledby={titleId} aria-hidden="true">
      <div className={`modal-dialog modal-dialog-centered modal-dialog-scrollable${size ? ` modal-${size}` : ''}`}>
        <div className="modal-content">
          <div className="modal-header">
            <h2 className="modal-title" id={titleId}>{title}</h2>
            <button type="button" className="btn-close" data-bs-dismiss="modal" aria-label="Close" />
          </div>
          <div className="modal-body">{children}</div>
          {footer && <div className="modal-footer">{footer}</div>}
        </div>
      </div>
    </div>
  )
}
