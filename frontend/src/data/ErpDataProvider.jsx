import { useCallback, useEffect, useMemo, useState } from 'react'
import { api } from '../api/client.js'
import { ErpDataContext } from './ErpDataContext.js'
import { initialErpState } from './erpReducer.js'

function applyPayload(data) {
  return {
    ...initialErpState,
    ...data,
    masterSheet: data.masterSheet && !Array.isArray(data.masterSheet) ? data.masterSheet : {},
    financialYears: data.financialYears ?? [],
    locations: data.locations ?? [],
    status: 'ready',
    error: null,
  }
}

/**
 * Business records from the PHP API. Screens still read { state, actions }.
 * A reload and a later sign-in both load the same saved rows.
 */
export default function ErpDataProvider({ children }) {
  const [state, setState] = useState({ ...initialErpState, financialYears: [], status: 'loading', error: null })

  const refresh = useCallback(async () => {
    const data = await api('/api/erp')
    setState(applyPayload(data))
  }, [])

  const load = useCallback(async () => {
    setState((current) => ({ ...current, status: 'loading', error: null }))
    try {
      await refresh()
    } catch (error) {
      setState((current) => ({ ...current, status: 'error', error }))
    }
  }, [refresh])

  useEffect(() => {
    let cancelled = false
    api('/api/erp')
      .then((data) => {
        if (!cancelled) setState(applyPayload(data))
      })
      .catch((error) => {
        if (!cancelled) setState((current) => ({ ...current, status: 'error', error }))
      })
    return () => {
      cancelled = true
    }
  }, [])

  const actions = useMemo(() => {
    const save = async (path, method, body) => {
      await api(path, { method, body })
      await refresh()
    }

    return {
      reload: load,

      saveRecord(collection, values) {
        if (collection === 'headquarters') {
          const body = values.locationHeadquartersId
            ? {
                locationHeadquartersId: values.locationHeadquartersId,
                parentManagerId: values.parentManagerId,
              }
            : {
                name: values.name,
                address: values.address,
                pincode: values.pincode,
                phone: values.phone,
                code: values.code ?? '',
                email: values.email ?? '',
                parentManagerId: values.parentManagerId,
                locationId: values.locationId,
              }
          return values.id ? save(`/api/headquarters/${values.id}`, 'PATCH', body) : save('/api/headquarters', 'POST', body)
        }
        if (collection === 'managers') {
          const body = {
            name: values.name,
            code: values.code ?? '',
            level: values.level,
          }
          if (values.level !== 3) {
            body.parentType = values.parentType
            body.parentId = values.parentId
          } else {
            body.stateIds = values.stateIds ?? []
          }
          if (values.level === 1 || values.level === 2 || values.level === 3) {
            body.email = values.email ?? ''
            body.phone = values.phone ?? ''
          }
          if (values.level === 1 || values.level === 2) {
            body.stateIds = values.stateIds ?? []
          }
          return values.id ? save(`/api/managers/${values.id}`, 'PATCH', body) : save('/api/managers', 'POST', body)
        }
        if (collection === 'locations') {
          const body = { name: values.name, code: values.code }
          return values.id ? save(`/api/locations/${values.id}`, 'PATCH', body) : save('/api/locations', 'POST', body)
        }
        if (collection === 'stockists') {
          const body = {
            name: values.name,
            code: values.code ?? '',
            headquartersId: values.headquartersId,
            address: values.address ?? '',
            pincode: values.pincode ?? '',
            phone: values.phone ?? '',
            email: values.email ?? '',
          }
          return values.id ? save(`/api/stockists/${values.id}`, 'PATCH', body) : save('/api/stockists', 'POST', body)
        }
        if (collection === 'terms') {
          if (values.id) {
            const body = { name: values.name }
            if (values.parentId !== undefined) body.parentId = values.parentId || null
            return save(`/api/catalogue-terms/${values.id}`, 'PATCH', body)
          }
          const body = { name: values.name, kind: values.kind }
          if (values.kind === 'category') body.parentId = values.parentId
          return save('/api/catalogue-terms', 'POST', body)
        }
        if (collection === 'sales') {
          const body = {
            month: values.month,
            headquartersId: values.headquartersId,
            stockistId: values.stockistId,
            achievementValue: values.achievementValue ?? null,
            bonus: values.bonus ?? null,
            deduction: values.deduction ?? null,
            finalValue: values.finalValue ?? null,
          }
          return values.id ? save(`/api/sales-entries/${values.id}`, 'PATCH', body) : save('/api/sales-entries', 'POST', body)
        }
        return Promise.reject(new Error('This record cannot be saved here.'))
      },

      deleteLocation(id) {
        return save(`/api/locations/${id}/delete`, 'POST', {})
      },

      createLocationHeadquarters(values) {
        const body = {
          locationId: values.locationId,
          name: values.name,
          code: values.code,
          address: values.address,
          pincode: values.pincode,
          phone: values.phone,
          email: values.email,
        }
        return values.id
          ? save(`/api/location-headquarters/${values.id}`, 'PATCH', body)
          : save('/api/location-headquarters', 'POST', body)
      },

      deleteLocationHeadquarters(id) {
        return save(`/api/location-headquarters/${id}/delete`, 'POST', {})
      },

      deleteManager(id) {
        return save(`/api/managers/${id}/delete`, 'POST', {})
      },

      deleteHeadquarters(id) {
        return save(`/api/headquarters/${id}/delete`, 'POST', {})
      },

      deleteStockist(id) {
        return save(`/api/stockists/${id}/delete`, 'POST', {})
      },

      softDelete(collection, id) {
        if (collection !== 'terms') return Promise.reject(new Error('This record cannot be deleted here.'))
        return save(`/api/catalogue-terms/${id}/delete`, 'POST', {})
      },

      restoreTerm(id) {
        return save(`/api/catalogue-terms/${id}/restore`, 'POST', {})
      },

      saveProduct(values) {
        const body = {
          name: values.name,
          code: values.code ?? '',
          groupCategoryId: values.groupCategoryId,
          categoryId: values.categoryId,
          scheme: values.scheme ?? '',
        }
        return values.id ? save(`/api/products/${values.id}`, 'PATCH', body) : save('/api/products', 'POST', body)
      },

      deleteProduct(id) {
        return save(`/api/products/${id}/delete`, 'POST', {})
      },

      restoreProduct(id) {
        return save(`/api/products/${id}/restore`, 'POST', {})
      },

      setMasterSheetEntry(year, productId, values) {
        const amount = (value) => (value === '' || value == null ? null : value)
        return save(`/api/master-sheet/${encodeURIComponent(year)}/${productId}`, 'PUT', {
          inclusion: values.inclusion || null,
          stripPack: values.stripPack ?? '',
          mrp: amount(values.mrp),
          ptr: amount(values.ptr),
          pts: amount(values.pts),
          scheme: values.scheme ?? '',
        })
      },

      scanMasterSheet(year) {
        return api(`/api/master-sheet/${encodeURIComponent(year)}/scan`)
      },

      async updateMasterSheetProducts(year) {
        const result = await api(`/api/master-sheet/${encodeURIComponent(year)}/update-products`, { method: 'POST', body: {} })
        await refresh()
        return result
      },

      async addMasterSheetProducts(year, productIds) {
        const result = await api(`/api/master-sheet/${encodeURIComponent(year)}/add-products`, {
          method: 'POST',
          body: { productIds },
        })
        await refresh()
        return result
      },

      createFinancialYear(label) {
        return save('/api/financial-years', 'POST', { label: label.trim() })
      },
    }
  }, [load, refresh])

  const value = useMemo(() => ({ state, actions }), [state, actions])

  return <ErpDataContext.Provider value={value}>{children}</ErpDataContext.Provider>
}
