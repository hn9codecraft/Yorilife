import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import DataTable from '../../components/DataTable.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import ExportCsvButton from '../../components/ExportCsvButton.jsx'
import FilterBar from '../../components/FilterBar.jsx'
import LoadingIndicator from '../../components/LoadingIndicator.jsx'
import TableSection, { TableSectionStack } from '../../components/TableSection.jsx'
import usePermission from '../../data/usePermission.js'
import { api } from '../../api/client.js'
import { formatMoney } from '../../data/productList.js'
import {
  MONTH_CSV,
  MONTHS,
  filterLines,
  formatQuantity,
  isFinancialYear,
  monthBonus,
  monthCsvRecord,
  normalizeQuantity,
  quantityError,
  stockistTotals,
} from '../../data/bonusCalc.js'

const QTY_FIELDS = [
  ['openingQty', 'OP'],
  ['newPurchaseQty', 'New purchase'],
  ['productSalesQty', 'Product sales'],
]

function fieldKey(line, field) {
  return `s${line.stockistId}-p${line.productId}-${field}`
}

function lineKey(line) {
  return `${line.stockistId}:${line.productId}`
}

export default function BonusCalcMonthPage() {
  const canManage = usePermission('sales.manage')
  const { fy, employeeId, month } = useParams()
  const monthNumber = MONTHS.some((item) => String(item.value) === month) ? Number(month) : null
  const yearOk = isFinancialYear(fy)
  const monthMeta = MONTHS.find((item) => item.value === monthNumber)
  const [sheet, setSheet] = useState(null)
  const [status, setStatus] = useState('loading')
  const [error, setError] = useState(null)
  const [reloadToken, setReloadToken] = useState(0)
  const [drafts, setDrafts] = useState({})
  const [query, setQuery] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (!yearOk || !monthNumber) {
      setStatus('invalid')
      return undefined
    }
    const controller = new AbortController()
    setStatus('loading')
    setError(null)
    api(`/api/bonus-calc/${fy}/${employeeId}/${monthNumber}`, { signal: controller.signal })
      .then((payload) => {
        setSheet(payload)
        setDrafts({})
        setStatus('ready')
      })
      .catch((err) => {
        if (err.name === 'AbortError') return
        setSheet(null)
        setError(err)
        setStatus('error')
      })
    return () => controller.abort()
  }, [fy, employeeId, monthNumber, yearOk, reloadToken])

  function valueOf(line, field) {
    const draft = drafts[lineKey(line)]
    if (draft && Object.prototype.hasOwnProperty.call(draft, field)) return draft[field]
    return line[field] ?? ''
  }

  const view = useMemo(() => {
    if (!sheet) return []
    return sheet.stockists.map((stockist) => {
      const lines = stockist.lines.map((line) => {
        const draft = drafts[`${line.stockistId}:${line.productId}`]
        const pick = (field) => (draft && Object.prototype.hasOwnProperty.call(draft, field) ? draft[field] : (line[field] ?? ''))
        return {
          ...line,
          stockistName: stockist.name,
          openingQty: pick('openingQty'),
          newPurchaseQty: pick('newPurchaseQty'),
          productSalesQty: pick('productSalesQty'),
        }
      })
      return { ...stockist, lines, shown: filterLines(lines, query) }
    })
  }, [sheet, drafts, query])

  const total = view.length ? monthBonus(view) : null
  const back = `/sales/bonus-calc?year=${encodeURIComponent(fy || '')}`
  const employee = sheet?.employee
  const subtitle = employee
    ? `${monthMeta?.label ?? ''} · Financial Year ${fy}${employee.employeeCode ? ` · ${employee.employeeCode}` : employee.id ? ` · ID ${employee.id}` : ''}`
    : undefined

  function setQty(line, field, next) {
    setSaved(false)
    setDrafts((current) => ({
      ...current,
      [lineKey(line)]: { ...(current[lineKey(line)] ?? {}), [field]: next },
    }))
  }

  async function save() {
    if (!sheet || saving) return
    const lines = []
    const errors = {}
    for (const stockist of sheet.stockists) {
      for (const line of stockist.lines) {
        const values = {
          openingQty: valueOf(line, 'openingQty'),
          newPurchaseQty: valueOf(line, 'newPurchaseQty'),
          productSalesQty: valueOf(line, 'productSalesQty'),
        }
        const touched = Object.values(values).some((item) => String(item).trim() !== '')
        if (!touched && !line.saved) continue
        for (const [field, label] of QTY_FIELDS) {
          const message = quantityError(values[field], label)
          if (message) errors[fieldKey(line, field)] = message
        }
        lines.push({
          stockistId: line.stockistId,
          productId: line.productId,
          openingQty: normalizeQuantity(values.openingQty) ?? '0.00',
          newPurchaseQty: normalizeQuantity(values.newPurchaseQty) ?? '0.00',
          productSalesQty: normalizeQuantity(values.productSalesQty) ?? '0.00',
        })
      }
    }
    if (Object.keys(errors).length) {
      setFieldErrors(errors)
      setFormError('Some quantities are not valid.')
      return
    }
    setSaving(true)
    setFormError(null)
    try {
      const payload = await api(`/api/bonus-calc/${fy}/${employeeId}/${monthNumber}`, { method: 'PUT', body: { lines } })
      setSheet(payload)
      setDrafts({})
      setFieldErrors({})
      setSaved(true)
    } catch (err) {
      setFormError(err.message || 'The month could not be saved.')
      setFieldErrors(err.fields ?? {})
    } finally {
      setSaving(false)
    }
  }

  const exportRecords = view.flatMap((stockist) => stockist.shown.map((line) => monthCsvRecord(stockist, line)))
  const noStockists = sheet && sheet.stockists.length === 0
  const noProducts = sheet && sheet.stockists.length > 0 && sheet.stockists.every((stockist) => stockist.lines.length === 0)

  return (
    <PageShell
      title={employee?.name || 'Bonus month'}
      subtitle={subtitle}
      actions={(
        <>
          <Link className="btn btn-outline-secondary" to={back}>Back</Link>
          {canManage && status === 'ready' && !noStockists && !noProducts && (
            <button type="button" className="btn btn-primary" onClick={save} aria-busy={saving || undefined} disabled={saving}>
              {saving && <span className="spinner-border spinner-border-sm" aria-hidden="true" />} {saving ? 'Saving…' : 'Save'}
            </button>
          )}
        </>
      )}
    >
      {status === 'loading' && <LoadingIndicator label="Loading month…" />}
      {status === 'invalid' && (
        <EmptyState icon="bi-calculator" title="That bonus month was not found" text="Check the financial year and month, then go back to Bonus Calc." action={<Link className="btn btn-outline-secondary" to={back}>Back</Link>} />
      )}
      {status === 'error' && (
        <div className="alert alert-danger" role="alert">
          <i className="bi bi-exclamation-octagon-fill" aria-hidden="true" />
          <div>
            {error?.message || 'This month could not be loaded.'}{' '}
            {error?.status !== 404 && (
              <button type="button" className="btn btn-sm btn-outline-danger ms-2" onClick={() => setReloadToken((token) => token + 1)}>Try again</button>
            )}
          </div>
        </div>
      )}
      {status === 'ready' && sheet && (
        <>
          <p className="bonus-note">
            Month bonus {total == null ? <span className="bonus-month__empty">-</span> : formatMoney(total)}.
            {' '}Rates are kept from the first save of each product, so a later price change does not change this month.
          </p>
          {saved && <div className="alert alert-success" role="status">Saved.</div>}
          {formError && (
            <div className="alert alert-danger" role="alert">
              <i className="bi bi-exclamation-octagon-fill" aria-hidden="true" />
              <div>{formError}</div>
            </div>
          )}
          {noStockists && (
            <EmptyState
              icon="bi-shop"
              title="No stockists assigned"
              text="Assign stockists to this employee on Stockist Entry. Each assigned stockist appears here with every product."
            />
          )}
          {noProducts && (
            <EmptyState
              icon="bi-box2"
              title="No products yet"
              text="Add products on the Product list. They appear here for every stockist."
            />
          )}
          {!noStockists && !noProducts && (
            <>
              <div className="bonus-month-filters">
              <FilterBar
                label="Filter products"
                search={{ value: query, onChange: setQuery, label: 'Search products', placeholder: 'Search product name' }}
                shown={exportRecords.length}
                total={sheet.stockists.reduce((count, stockist) => count + stockist.lines.length, 0)}
                noun="products"
                actions={(
                  <ExportCsvButton
                    name={`bonus-calc-${fy}-${monthMeta?.short ?? month}`}
                    headers={MONTH_CSV.headers}
                    auditKind="bonus_calc_month_csv"
                    mask={false}
                    records={exportRecords}
                  />
                )}
              />
              </div>
              <TableSectionStack>
                {view.map((stockist) => {
                  const totals = stockistTotals(stockist.shown)
                  const rows = stockist.shown.length === 0 ? [] : [
                    ...stockist.shown.map((line) => ({ ...line, isTotal: false })),
                    { id: `total-${stockist.id}`, isTotal: true, productName: 'Total', ...totals },
                  ]
                  return (
                    <TableSection key={stockist.id} title={stockist.name} count={stockist.lines.length}>
                      {(stockist.code || !stockist.assigned) && (
                        <p className="bonus-stockist-note">
                          {[stockist.code, stockist.assigned ? null : 'No longer assigned to this employee'].filter(Boolean).join(' · ')}
                        </p>
                      )}
                      <div className="bonus-sheet">
                        <DataTable
                          caption={`${stockist.name} products`}
                          striped
                          scroll
                          rowClassName={(row) => (row.isTotal ? 'bonus-total' : undefined)}
                          columns={sheetColumns({ canManage, fieldErrors, setQty })}
                          rows={rows}
                          rowKey={(row) => row.id ?? lineKey(row)}
                          emptyText="No products match your search"
                          emptyHint="Change the search to see the products for this stockist."
                          emptyIcon="bi-funnel"
                        />
                      </div>
                    </TableSection>
                  )
                })}
              </TableSectionStack>
            </>
          )}
        </>
      )}
    </PageShell>
  )
}

function sheetColumns({ canManage, fieldErrors, setQty }) {
  const blank = () => <span className="visually-hidden">Not applicable</span>
  const money = (key, header) => ({
    key,
    header,
    width: 'money',
    render: (row) => (row.isTotal ? blank() : formatMoney(row[key])),
  })
  const figure = (key, header, moneyColumn = false) => ({
    key,
    header,
    width: moneyColumn ? 'money' : 'sm',
    align: 'end',
    render: (row) => {
      const value = row[key]
      if (value == null || value === '') return null
      return moneyColumn ? formatMoney(value) : formatQuantity(value)
    },
  })
  const qty = (field, header) => ({
    key: field,
    header,
    width: 'sm',
    align: 'end',
    render: (row) => {
      if (row.isTotal) {
        const value = row[field]
        return value == null || value === '' ? null : formatQuantity(value)
      }
      const error = fieldErrors[fieldKey(row, field)]
      return (
        <input
          className={`form-control form-control-sm bonus-qty${error ? ' is-invalid' : ''}`}
          inputMode="decimal"
          autoComplete="off"
          aria-label={`${header} for ${row.productName} at ${row.stockistName}`}
          aria-invalid={error ? 'true' : undefined}
          title={error || undefined}
          value={row[field] ?? ''}
          readOnly={!canManage}
          onChange={(event) => setQty(row, field, event.target.value)}
        />
      )
    },
  })

  return [
    {
      key: 'productName',
      header: 'Product Name',
      sticky: 'start',
      title: (row) => row.productName,
      render: (row) => row.productName,
    },
    { key: 'packagingUnit', header: 'Packaging Unit', width: 'md', render: (row) => (row.isTotal ? blank() : row.packagingUnit) },
    money('mrp', 'MRP'),
    money('ptr', 'PTR'),
    money('pts', 'PTS'),
    { key: 'scheme', header: 'Scheme', width: 'sm', nowrap: true, render: (row) => (row.isTotal ? blank() : row.scheme) },
    money('netRate', 'Net Rate'),
    qty('openingQty', 'OP'),
    qty('newPurchaseQty', 'New Purchase'),
    figure('totalProduct', 'Total Product'),
    qty('productSalesQty', 'Product Sales'),
    figure('bonusAmount', 'Bonus Amount', true),
  ]
}
