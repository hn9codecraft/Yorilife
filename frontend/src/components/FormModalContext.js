import { createContext } from 'react'

// Lets a form inside <FormModal> report "saving…" and server errors to the
// modal footer and body (see useFormState). Null outside a FormModal.
export const FormModalContext = createContext(null)
