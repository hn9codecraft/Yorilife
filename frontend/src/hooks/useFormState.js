import { useContext, useId, useRef, useState } from 'react'
import { FormModalContext } from '../components/FormModalContext.js'

/**
 * Minimal form state for modal forms.
 *   const form = useFormState(initialValues, validate)
 *   form.values, form.set(field)(value), form.errors, form.fieldId(field)
 *   form.handleSubmit(onValid) -> onSubmit handler (validates, focuses the first invalid field)
 * `validate(values)` returns { field: 'message' } for invalid fields.
 * `onValid` may return a promise (an API save): while it runs further submits
 * are ignored; if it rejects with field errors from the server (ApiError
 * .fields) they are shown on the fields, any other error above the form.
 */
export default function useFormState(initialValues, validate = () => ({})) {
  const [values, setValues] = useState(initialValues)
  const [errors, setErrors] = useState({})
  const [submitting, setSubmitting] = useState(false)
  const busy = useRef(false)
  const modal = useContext(FormModalContext)
  const prefix = useId()

  const fieldId = (field) => `${prefix}-${field}`
  const focusFirst = (found) => {
    const first = Object.keys(found)[0]
    if (first) document.getElementById(fieldId(first))?.focus()
    return Boolean(first)
  }
  const setBusy = (value) => {
    busy.current = value
    setSubmitting(value)
    modal?.setSaving(value)
  }

  return {
    values,
    errors,
    submitting,
    fieldId,
    set: (field) => (value) => setValues((current) => ({ ...current, [field]: value })),
    setMany: (patch) => setValues((current) => ({ ...current, ...patch })),
    handleSubmit: (onValid) => async (event) => {
      event.preventDefault()
      if (busy.current) return
      const found = validate(values)
      setErrors(found)
      if (focusFirst(found)) return

      modal?.setError(null)
      const result = onValid(values)
      if (!result || typeof result.then !== 'function') return

      setBusy(true)
      try {
        await result
        setBusy(false)
      } catch (error) {
        setBusy(false)
        const fields = error?.fields ?? {}
        const known = Object.fromEntries(Object.entries(fields).filter(([field]) => field in values))
        const other = Object.entries(fields).filter(([field]) => !(field in values)).map(([, message]) => message)
        setErrors(known)
        if (Object.keys(known).length === 0 || other.length > 0) {
          const message = other.length > 0 ? other.join(' ') : error?.message
          modal?.setError(message || 'Something went wrong. Please try again.')
        }
        focusFirst(known)
      }
    },
  }
}
