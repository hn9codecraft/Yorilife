import { useContext } from 'react'
import { ErpDataContext } from './ErpDataContext.js'

export default function useErpData() {
  const value = useContext(ErpDataContext)
  if (!value) throw new Error('useErpData must be used inside <ErpDataProvider>')
  return value
}
