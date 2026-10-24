import { useId, useState } from 'react'

/**
 * Minimal form state for modal forms.
 *   const form = useFormState(initialValues, validate)
 *   form.values, form.set(field)(value), form.errors, form.fieldId(field)
 *   form.handleSubmit(onValid) -> onSubmit handler (validates, focuses the first invalid field)
 * `validate(values)` returns { field: 'message' } for invalid fields.
 */
export default function useFormState(initialValues, validate = () => ({})) {
  const [values, setValues] = useState(initialValues)
  const [errors, setErrors] = useState({})
  const prefix = useId()

  const fieldId = (field) => `${prefix}-${field}`

  return {
    values,
    errors,
    fieldId,
    set: (field) => (value) => setValues((current) => ({ ...current, [field]: value })),
    setMany: (patch) => setValues((current) => ({ ...current, ...patch })),
    handleSubmit: (onValid) => (event) => {
      event.preventDefault()
      const found = validate(values)
      setErrors(found)
      const first = Object.keys(found)[0]
      if (first) {
        document.getElementById(fieldId(first))?.focus()
        return
      }
      onValid(values)
    },
  }
}
