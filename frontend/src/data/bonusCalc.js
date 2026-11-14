// Bonus Calc figures. The same rules run in PHP (BonusCalcMath): quantities are
// non-negative with at most two decimal places, total product is opening plus
// new purchase, and bonus is product sales times net rate rounded half up.

export const DEFAULT_FINANCIAL_YEAR = '2026-27'

export const MONTHS = Object.freeze([
  { value: 1, short: 'Jan', label: 'January' },
  { value: 2, short: 'Feb', label: 'February' },
  { value: 3, short: 'Mar', label: 'March' },
  { value: 4, short: 'Apr', label: 'April' },
  { value: 5, short: 'May', label: 'May' },
  { value: 6, short: 'Jun', label: 'June' },
  { value: 7, short: 'Jul', label: 'July' },
  { value: 8, short: 'Aug', label: 'August' },
  { value: 9, short: 'Sep', label: 'September' },
  { value: 10, short: 'Oct', label: 'October' },
  { value: 11, short: 'Nov', label: 'November' },
  { value: 12, short: 'Dec', label: 'December' },
])

const QTY_MAX_CENTS = 999999999999n
const BONUS_MAX_CENTS = 9999999999999999n
const qtyFormat = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** April–March label such as 2026-27. */
export function isFinancialYear(value) {
  const match = /^(\d{4})-(\d{2})$/.exec(String(value ?? '').trim())
  if (!match) return false
  const start = Number(match[1])
  const end = Number(match[2])
  return start >= 2000 && start <= 2100 && end === (start + 1) % 100
}

function yearLabel(start) {
  return `${start}-${String((start + 1) % 100).padStart(2, '0')}`
}

/** Years the picker offers, plus any extra year already stored. */
export function financialYearOptions(extra = []) {
  const years = []
  for (let start = 2024; start <= 2032; start += 1) years.push(yearLabel(start))
  for (const year of extra) {
    if (isFinancialYear(year) && !years.includes(year)) years.push(year)
  }
  return years.sort()
}

/** Integer cents, or null when the text is not a non-negative 2-decimal amount within DECIMAL(12,2). */
export function toCents(value) {
  const text = String(value ?? '').trim()
  if (!/^\d+(\.\d{1,2})?$/.test(text)) return null
  const [whole, frac = ''] = text.split('.')
  const cents = BigInt(whole) * 100n + BigInt((frac + '00').slice(0, 2))
  return cents > QTY_MAX_CENTS ? null : cents
}

export function formatCents(cents) {
  const digits = cents.toString().padStart(3, '0')
  return `${digits.slice(0, -2)}.${digits.slice(-2)}`
}

/** "10.50", "0.00" for a blank, or null when the text is not a valid quantity. */
export function normalizeQuantity(value) {
  const text = String(value ?? '').trim()
  if (!text) return '0.00'
  const cents = toCents(text)
  return cents === null ? null : formatCents(cents)
}

/** Validation message, or null. A blank is valid and means zero. */
export function quantityError(value, label) {
  const text = String(value ?? '').trim()
  if (!text) return null
  if (text.startsWith('-')) return `${label} cannot be negative.`
  if (!/^\d+(\.\d{1,2})?$/.test(text)) return `${label} must be a number with at most two decimal places.`
  if (toCents(text) === null) return `${label} is too large.`
  return null
}

export function addMoney(left, right) {
  const a = toCents(left)
  const b = toCents(right)
  if (a === null || b === null) return null
  return formatCents(a + b)
}

/** Product sales × net rate, half up. A missing rate counts as zero. Null when the result cannot be stored. */
export function multiplyMoney(qty, rate) {
  const q = toCents(qty)
  const r = toCents(rate == null || String(rate).trim() === '' ? '0' : rate)
  if (q === null || r === null) return null
  const cents = (q * r + 50n) / 100n
  if (cents > BONUS_MAX_CENTS) return null
  return formatCents(cents)
}

export function sumMoney(amounts) {
  let total = 0n
  for (const amount of amounts) {
    if (amount == null || amount === '') continue
    const cents = toCents(amount)
    if (cents === null) return null
    total += cents
  }
  return formatCents(total)
}

const blank = (value) => String(value ?? '').trim() === ''

/**
 * One product row. Blank quantities stay blank in the auto columns until the
 * user types; a typed value treats the other quantity as zero.
 */
export function lineFigures({ netRate, openingQty, newPurchaseQty, productSalesQty }) {
  const opening = blank(openingQty) ? '0.00' : normalizeQuantity(openingQty)
  const purchase = blank(newPurchaseQty) ? '0.00' : normalizeQuantity(newPurchaseQty)
  const sales = blank(productSalesQty) ? '0.00' : normalizeQuantity(productSalesQty)
  const totalProduct = opening !== null && purchase !== null && (!blank(openingQty) || !blank(newPurchaseQty))
    ? addMoney(opening, purchase)
    : null
  const rate = netRate == null || String(netRate).trim() === '' ? '0.00' : netRate
  const bonusAmount = sales !== null && !blank(productSalesQty) ? multiplyMoney(sales, rate) : null
  return { totalProduct, bonusAmount }
}

/** Sum a column. All blank stays blank; a typed value counts blanks as zero. */
export function columnTotal(values) {
  const texts = values.map((value) => String(value ?? '').trim())
  if (texts.every((text) => text === '')) return null
  if (texts.some((text) => text !== '' && normalizeQuantity(text) === null)) return null
  return sumMoney(texts.map((text) => (text === '' ? '0.00' : normalizeQuantity(text))))
}

export function stockistTotals(lines) {
  const figures = lines.map(lineFigures)
  return {
    openingQty: columnTotal(lines.map((line) => line.openingQty)),
    newPurchaseQty: columnTotal(lines.map((line) => line.newPurchaseQty)),
    totalProduct: columnTotal(figures.map((figure) => figure.totalProduct)),
    productSalesQty: columnTotal(lines.map((line) => line.productSalesQty)),
    bonusAmount: columnTotal(figures.map((figure) => figure.bonusAmount)),
  }
}

/** Sum of bonus amounts across stockists, or null when nobody has a figure yet. */
export function monthBonus(stockists) {
  const totals = stockists.map((stockist) => stockistTotals(stockist.lines).bonusAmount)
  if (totals.every((total) => total == null)) return null
  return sumMoney(totals.map((total) => total ?? '0.00'))
}

export function formatQuantity(value) {
  if (value == null || value === '') return null
  const number = Number(value)
  return Number.isFinite(number) ? qtyFormat.format(number) : null
}

export function employeeOptionLabel(employee) {
  const name = employee?.name || 'Unnamed employee'
  return employee?.employeeCode ? `${name} (${employee.employeeCode})` : name
}

export function filterBonusRows(rows = [], query = '') {
  const needle = query.trim().toLowerCase()
  if (!needle) return rows
  return rows.filter((row) => [row.employeeName, row.employeeCode, row.employeeId]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
    .includes(needle))
}

export function filterLines(lines = [], query = '') {
  const needle = query.trim().toLowerCase()
  if (!needle) return lines
  return lines.filter((line) => [line.productName, line.packagingUnit, line.scheme]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
    .includes(needle))
}

export const BONUS_CSV = Object.freeze({
  filename: 'bonus-calc',
  headers: ['Employee', 'Employee code', ...MONTHS.map((month) => month.short), 'Total'],
})

export function bonusCsvRecord(row) {
  const record = {
    Employee: row.employeeName ?? '',
    'Employee code': row.employeeCode ?? '',
    Total: row.total ?? '',
  }
  for (const month of MONTHS) record[month.short] = row.months?.[String(month.value)] ?? ''
  return record
}

export const MONTH_CSV = Object.freeze({
  headers: ['Stockist', 'Stockist code', 'Product', 'Packaging unit', 'MRP', 'PTR', 'PTS', 'Scheme', 'Net rate', 'OP', 'New purchase', 'Total product', 'Product sales', 'Bonus amount'],
})

export function monthCsvRecord(stockist, line) {
  const figures = lineFigures(line)
  return {
    Stockist: stockist.name ?? '',
    'Stockist code': stockist.code ?? '',
    Product: line.productName ?? '',
    'Packaging unit': line.packagingUnit ?? '',
    MRP: line.mrp ?? '',
    PTR: line.ptr ?? '',
    PTS: line.pts ?? '',
    Scheme: line.scheme ?? '',
    'Net rate': line.netRate ?? '',
    OP: normalizeQuantity(line.openingQty) && String(line.openingQty ?? '').trim() ? normalizeQuantity(line.openingQty) : '',
    'New purchase': String(line.newPurchaseQty ?? '').trim() ? normalizeQuantity(line.newPurchaseQty) ?? '' : '',
    'Total product': figures.totalProduct ?? '',
    'Product sales': String(line.productSalesQty ?? '').trim() ? normalizeQuantity(line.productSalesQty) ?? '' : '',
    'Bonus amount': figures.bonusAmount ?? '',
  }
}
