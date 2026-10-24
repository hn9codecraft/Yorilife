import { useMemo, useReducer } from 'react'
import { ErpDataContext } from './ErpDataContext.js'
import { erpReducer, initialErpState } from './erpReducer.js'

const newId = () => crypto.randomUUID()
const now = () => new Date().toISOString()

/**
 * PREVIEW ONLY: keeps ERP records in memory for the current browser tab.
 * Nothing is sent to the backend or saved to the database; a reload clears it.
 * When the API exists, replace these actions with API calls — the screens
 * only depend on { state, actions }.
 */
export default function ErpDataProvider({ children }) {
  const [state, dispatch] = useReducer(erpReducer, initialErpState)

  const actions = useMemo(
    () => ({
      // Create (no id) or update (with id) a record in any simple collection.
      saveRecord(collection, values) {
        const at = now()
        const record = values.id
          ? { ...values, updatedAt: at }
          : { deletedAt: null, ...values, id: newId(), createdAt: at, updatedAt: at }
        dispatch({ type: 'save', collection, record })
        return record.id
      },

      softDelete(collection, id) {
        dispatch({ type: 'softDelete', collection, id, at: now() })
      },

      saveProduct(values) {
        const at = now()
        const record = values.id
          ? { ...values, updatedAt: at }
          : { deletedAt: null, ...values, id: newId(), createdAt: at, updatedAt: at }
        dispatch({ type: 'saveProduct', record, at, historyId: newId() })
        return record.id
      },

      deleteProduct(id) {
        dispatch({ type: 'deleteProduct', id, at: now(), historyId: newId() })
      },

      setMasterSheetEntry(year, productId, { inclusion, scheme }) {
        dispatch({
          type: 'setMasterSheetEntry',
          year,
          productId,
          inclusion,
          scheme: scheme.trim(),
          at: now(),
          historyIds: [newId(), newId()],
        })
      },
    }),
    [],
  )

  const value = useMemo(() => ({ state, actions }), [state, actions])

  return <ErpDataContext.Provider value={value}>{children}</ErpDataContext.Provider>
}
