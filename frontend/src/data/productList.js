// Helpers for Products > Category (Product Type, Product Group) and
// Products > Product (the product list). Pure functions, unit tested.

/** URL filter keys for the product list. */
export const PRODUCT_FILTER_DEFAULTS = Object.freeze({ q: '', type: '', group: '' })

/** Filter value meaning "no product type / no product group set". */
export const NONE = 'none'

/** Largest amount a DECIMAL(12,2) column holds. */
export const MAX_AMOUNT = 9999999999.99

export const PRODUCT_CSV = Object.freeze({
  filename: 'products',
  headers: ['Product name', 'SKU', 'Product type', 'Packaging unit', 'Product group', 'MRP', 'PTR', 'PTS', 'Scheme', 'Net rate'],
})

export const PRODUCT_CATEGORY_CSV = Object.freeze({
  type: { filename: 'product-types', headers: ['Product type', 'Products', 'Last updated'] },
  group: { filename: 'product-groups', headers: ['Product group', 'Products', 'Last updated'] },
})

const clean = (value) => String(value ?? '').trim()

/** Map of id (string) -> record, for type / group lookups. */
export function byId(records = []) {
  return new Map(records.map((record) => [String(record.id), record]))
}

/** Is `name` already used by another record (case-insensitive, trimmed)? */
export function nameTaken(records = [], name, exceptId = null) {
  const wanted = clean(name).toLowerCase()
  if (!wanted) return false
  return records.some((record) => String(record.id) !== String(exceptId ?? '') && clean(record.name).toLowerCase() === wanted)
}

/** Validation message for an optional money field, or null when valid. */
export function moneyError(value, label) {
  const text = clean(value)
  if (!text) return null
  if (!/^\d+(\.\d{1,2})?$/.test(text)) {
    return text.startsWith('-') ? `${label} cannot be negative.` : `${label} must be a number with at most two decimal places.`
  }
  if (Number(text) > MAX_AMOUNT) return `${label} is too large.`
  return null
}

const moneyFormat = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** "₹ 1,234.50", or null when there is no amount. */
export function formatMoney(value) {
  if (value === null || value === undefined || value === '') return null
  const number = Number(value)
  return Number.isFinite(number) ? `₹ ${moneyFormat.format(number)}` : null
}

/** Products that match the search (name / SKU) and the type / group filters. */
export function filterProducts(products = [], filters = PRODUCT_FILTER_DEFAULTS) {
  const q = clean(filters.q).toLowerCase()
  const matchRef = (value, wanted) => {
    if (!wanted) return true
    if (wanted === NONE) return value == null || value === ''
    return String(value ?? '') === String(wanted)
  }
  return products.filter((product) => {
    if (!matchRef(product.productTypeId, filters.type)) return false
    if (!matchRef(product.productGroupId, filters.group)) return false
    if (!q) return true
    return [product.name, product.sku ?? product.code].filter(Boolean).join(' ').toLowerCase().includes(q)
  })
}

/** One CSV row for a product (keys are PRODUCT_CSV.headers). */
export function productCsvRecord(product, types, groups) {
  return {
    'Product name': product.name ?? '',
    SKU: product.sku ?? product.code ?? '',
    'Product type': types.get(String(product.productTypeId ?? ''))?.name ?? '',
    'Packaging unit': product.packagingUnit ?? '',
    'Product group': groups.get(String(product.productGroupId ?? ''))?.name ?? '',
    MRP: product.mrp ?? '',
    PTR: product.ptr ?? '',
    PTS: product.pts ?? '',
    Scheme: product.scheme ?? '',
    'Net rate': product.netRate ?? '',
  }
}