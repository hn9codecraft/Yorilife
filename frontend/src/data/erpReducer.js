/**
 * In-memory ERP data for the preview UI. Mirrors the proposed tables in
 * docs/DATA_MODEL.md so the screens can later switch to the API unchanged.
 *
 * Record shapes
 *   headquarters  { id, name, code, createdAt, updatedAt }
 *   managers      { id, level: 1|2|3, name, code, parentType, parentId, createdAt, updatedAt }
 *                   parentType: 'headquarters' | 'manager1' | 'manager2'
 *   stockists     { id, name, code, headquartersId, createdAt, updatedAt }
 *   terms         { id, kind: 'groupCategory'|'category'|'tag'|'badge', name, createdAt, updatedAt, deletedAt }
 *   products      { id, name, code, categoryId, tagIds[], badgeIds[], createdAt, updatedAt, deletedAt }
 *   productHistory      { id, productId, productName, action, changes: [{ field, from, to }], at, by }
 *   masterSheet         { [year]: { [productId]: { inclusion: 'included'|'excluded', scheme, updatedAt } } }
 *   masterSheetHistory  { id, year, productId, productName, field, from, to, at, by }
 *   sales         { id, month: 'YYYY-MM', headquartersId, stockistId,
 *                   achievementValue, bonus, deduction, finalValue, createdAt, updatedAt }
 *
 * Deletion is always soft (deletedAt timestamp); nothing is ever removed.
 * The reducer is pure: ids and timestamps arrive on the action.
 */

export const initialErpState = {
  headquarters: [],
  managers: [],
  stockists: [],
  locations: [],
  terms: [],
  products: [],
  productHistory: [],
  masterSheet: {},
  masterSheetHistory: [],
  sales: [],
}

function upsert(list, record) {
  return list.some((item) => item.id === record.id)
    ? list.map((item) => (item.id === record.id ? { ...item, ...record } : item))
    : [...list, record]
}

const termName = (terms, id) => terms.find((term) => term.id === id)?.name ?? null
const termNames = (terms, ids = []) =>
  ids.map((id) => termName(terms, id)).filter(Boolean).sort().join(', ') || null

// Human-readable snapshot of the fields tracked in product history.
function productSnapshot(product, terms) {
  return {
    Name: product.name || null,
    Code: product.code || null,
    Category: termName(terms, product.categoryId),
    Tags: termNames(terms, product.tagIds),
    Badges: termNames(terms, product.badgeIds),
  }
}

function diffSnapshots(before, after) {
  return Object.keys(after)
    .filter((field) => (before[field] ?? null) !== (after[field] ?? null))
    .map((field) => ({ field, from: before[field] ?? null, to: after[field] ?? null }))
}

export function erpReducer(state, action) {
  switch (action.type) {
    case 'save':
      return { ...state, [action.collection]: upsert(state[action.collection], action.record) }

    case 'softDelete':
      return {
        ...state,
        [action.collection]: state[action.collection].map((item) =>
          item.id === action.id ? { ...item, deletedAt: action.at } : item,
        ),
      }

    case 'saveProduct': {
      const previous = state.products.find((p) => p.id === action.record.id)
      const merged = { ...previous, ...action.record }
      const changes = diffSnapshots(
        previous ? productSnapshot(previous, state.terms) : {},
        productSnapshot(merged, state.terms),
      )
      if (previous && changes.length === 0) return state

      return {
        ...state,
        products: upsert(state.products, merged),
        productHistory: [
          ...state.productHistory,
          {
            id: action.historyId,
            productId: merged.id,
            productName: merged.name,
            action: previous ? 'updated' : 'created',
            changes,
            at: action.at,
            by: null,
          },
        ],
      }
    }

    case 'deleteProduct': {
      const product = state.products.find((p) => p.id === action.id)
      if (!product || product.deletedAt) return state
      return {
        ...state,
        products: state.products.map((p) => (p.id === action.id ? { ...p, deletedAt: action.at } : p)),
        productHistory: [
          ...state.productHistory,
          {
            id: action.historyId,
            productId: product.id,
            productName: product.name,
            action: 'deleted',
            changes: [{ field: 'Status', from: 'Active', to: 'Deleted' }],
            at: action.at,
            by: null,
          },
        ],
      }
    }

    // Each year is stored separately, so editing one year never touches another.
    case 'setMasterSheetEntry': {
      const { year, productId, inclusion, scheme, at, historyIds } = action
      const yearEntries = state.masterSheet[year] ?? {}
      const previous = yearEntries[productId]
      const productName = state.products.find((p) => p.id === productId)?.name ?? null

      const changes = []
      if ((previous?.inclusion ?? null) !== inclusion) {
        changes.push({ field: 'Inclusion', from: previous?.inclusion ?? null, to: inclusion })
      }
      if ((previous?.scheme || null) !== (scheme || null)) {
        changes.push({ field: 'Scheme', from: previous?.scheme || null, to: scheme || null })
      }
      if (changes.length === 0) return state

      return {
        ...state,
        masterSheet: {
          ...state.masterSheet,
          [year]: { ...yearEntries, [productId]: { inclusion, scheme: scheme || '', updatedAt: at } },
        },
        masterSheetHistory: [
          ...state.masterSheetHistory,
          ...changes.map((change, index) => ({
            id: historyIds[index],
            year,
            productId,
            productName,
            ...change,
            at,
            by: null,
          })),
        ],
      }
    }

    default:
      throw new Error(`Unknown ERP action: ${action.type}`)
  }
}
