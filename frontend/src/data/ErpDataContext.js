import { createContext } from 'react'

// Holds { state, actions } from ErpDataProvider. Read it with useErpData().
export const ErpDataContext = createContext(null)
