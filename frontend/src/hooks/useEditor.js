import { useState } from 'react'

/**
 * State for an add/edit modal. `session` changes every time it opens, so use
 * it as the form's `key` to start each edit with fresh form state.
 *   const editor = useEditor()
 *   editor.openNew() / editor.openEdit(record) / editor.close()
 *   editor.open, editor.record (null when adding), editor.session
 */
export default function useEditor() {
  const [state, setState] = useState({ open: false, record: null, session: 0 })

  return {
    ...state,
    openNew: () => setState((s) => ({ open: true, record: null, session: s.session + 1 })),
    openEdit: (record) => setState((s) => ({ open: true, record, session: s.session + 1 })),
    close: () => setState((s) => ({ ...s, open: false })),
  }
}
