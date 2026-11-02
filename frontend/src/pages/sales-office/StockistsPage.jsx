import { useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import usePermission from '../../data/usePermission.js'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import ListFilter from '../../components/ListFilter.jsx'
import SalesOfficeCsv from '../../components/SalesOfficeCsv.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import Modal from '../../components/Modal.jsx'
import HierarchyTree from '../../components/HierarchyTree.jsx'
import FormField, { SelectField, TextField } from '../../components/FormField.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import { nextStSequence, stCodeSuffix } from '../../data/stCode.js'
import { buildHeadquartersBranch, byName, findById, matchesFilters, matchesSearch } from '../../data/selectors.js'

function StockistForm({ formId, record, stockists, headquarters, onSave }) {
  const legacyCode = record?.code && stCodeSuffix(record.code) === null ? record.code : ''
  const form = useFormState(
    {
      name: record?.name ?? '',
      code: record?.code ? (stCodeSuffix(record.code) ?? '') : nextStSequence(stockists),
      address: record?.address ?? '',
      pincode: record?.pincode ?? '',
      phone: record?.phone ?? '',
      email: record?.email ?? '',
      headquartersId: record?.headquartersId ?? '',
    },
    (v) => {
      const errors = {}
      if (!v.name.trim()) errors.name = 'Enter the stockist name.'
      const digits = v.code.trim()
      const fullCode = digits ? `ST-${digits}` : legacyCode
      if (!/^\d*$/.test(digits)) errors.code = 'Enter numbers only.'
      else if (!digits && !legacyCode) errors.code = 'Enter the stockist code.'
      else if (stockists.some((stockist) => stockist.id !== record?.id && stockist.code && stockist.code.toLowerCase() === fullCode.toLowerCase())) {
        errors.code = 'This code is already in use.'
      }
      if (v.pincode.trim() && !/^[1-9][0-9]{5}$/.test(v.pincode.trim())) errors.pincode = 'Enter a 6-digit pincode.'
      if (v.phone.trim() && !/^\+?[0-9][0-9\s().-]{5,19}$/.test(v.phone.trim())) errors.phone = 'Enter a valid phone number.'
      const email = v.email.trim().toLowerCase()
      if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = 'Enter a valid email address.'
      if (!v.headquartersId) errors.headquartersId = 'Select the headquarters this stockist is mapped to.'
      return errors
    },
  )
  const codeId = form.fieldId('code')

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((v) => onSave({
        name: v.name.trim(),
        code: v.code.trim() ? `ST-${v.code.trim()}` : legacyCode,
        address: v.address.trim(),
        pincode: v.pincode.trim(),
        phone: v.phone.trim(),
        email: v.email.trim(),
        headquartersId: v.headquartersId,
      }))}
    >
      <TextField id={form.fieldId('name')} label="Stockist name" required value={form.values.name} onChange={form.set('name')} error={form.errors.name} />
      <FormField id={codeId} label="Stockist code" required error={form.errors.code} help="Numbers only. Leading zeros are kept." className="col-12 col-md-6">
        <div className="input-group">
          <span className="input-group-text" id={`${codeId}-prefix`}>ST-</span>
          <input
            id={codeId}
            className={`form-control${form.errors.code ? ' is-invalid' : ''}`}
            inputMode="numeric"
            autoComplete="off"
            maxLength={10}
            placeholder="001"
            aria-describedby={`${codeId}-prefix`}
            value={form.values.code}
            onChange={(event) => form.set('code')(event.target.value.replace(/\D/g, '').slice(0, 10))}
          />
        </div>
      </FormField>
      <SelectField
        id={form.fieldId('headquartersId')}
        label="Headquarters"
        required
        value={form.values.headquartersId}
        onChange={form.set('headquartersId')}
        options={headquarters.map((hq) => ({ value: hq.id, label: hq.code ? `${hq.name} (${hq.code})` : hq.name }))}
        placeholder="Select headquarters"
        error={form.errors.headquartersId}
        help="Stockists map directly to a headquarters. Its managers are shown in the stockist’s hierarchy view."
      />
      <TextField id={form.fieldId('address')} label="Address" value={form.values.address} onChange={form.set('address')} error={form.errors.address} help="Optional." />
      <TextField id={form.fieldId('pincode')} label="Pincode" value={form.values.pincode} onChange={form.set('pincode')} error={form.errors.pincode} inputMode="numeric" help="Optional." className="col-12 col-md-4" />
      <TextField id={form.fieldId('phone')} label="Phone number" value={form.values.phone} onChange={form.set('phone')} error={form.errors.phone} inputMode="tel" maxLength={20} help="Optional. A country code or a leading zero is kept." className="col-12 col-md-8" />
      <TextField id={form.fieldId('email')} label="Email" type="email" value={form.values.email} onChange={form.set('email')} error={form.errors.email} help="Optional." />
    </form>
  )
}

export default function StockistsPage() {
  const { state, actions } = useErpData()
  const canManage = usePermission('sales_office.manage')
  const editor = useEditor()
  const [query, setQuery] = useState('')
  const [filters, setFilters] = useState({ stateIds: [], reportIds: [] })
  const [viewing, setViewing] = useState({ open: false, id: null })
  const [deleting, setDeleting] = useState(null)

  const headquarters = [...state.headquarters].sort(byName)
  const locations = state.locations ?? []
  const rows = state.stockists
    .filter((stockist) => {
      const hq = findById(state.headquarters, stockist.headquartersId)
      const stateIds = locations
        .filter((location) => location.name.toLowerCase() === String(hq?.state ?? '').toLowerCase())
        .map((location) => location.id)
      return matchesSearch(stockist, query, ['name', 'code', 'address', 'pincode', 'phone', 'email']) && matchesFilters(stateIds, null, filters)
    })
    .sort(byName)
  const viewed = findById(state.stockists, viewing.id)

  const columns = [
    { key: 'name', header: 'Stockist', render: (s) => <strong className="fw-semibold">{s.name}</strong> },
    { key: 'code', header: 'Code', nowrap: true, render: (s) => s.code || '—' },
    { key: 'hq', header: 'Headquarters', render: (s) => findById(state.headquarters, s.headquartersId)?.name ?? '—' },
    { key: 'state', header: 'State', render: (s) => findById(state.headquarters, s.headquartersId)?.state || '—' },
    { key: 'address', header: 'Address', render: (s) => s.address || '—' },
    { key: 'pincode', header: 'Pincode', nowrap: true, render: (s) => s.pincode || '—' },
    { key: 'phone', header: 'Phone Number', nowrap: true, render: (s) => s.phone || '—' },
    { key: 'email', header: 'Email', render: (s) => s.email || '—' },
    {
      key: 'actions',
      header: 'Actions',
      align: 'end',
      nowrap: true,
      render: (s) => (
        <div className="table-actions">
          <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`View ${s.name} hierarchy`} title="View hierarchy" onClick={() => setViewing({ open: true, id: s.id })}>
            <i className="bi bi-diagram-3" aria-hidden="true" />
          </button>
          {canManage && (
            <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`Edit ${s.name}`} title="Edit" onClick={() => editor.openEdit(s)}>
              <i className="bi bi-pencil" aria-hidden="true" />
            </button>
          )}
          {canManage && (
            <button type="button" className="btn btn-sm btn-outline-danger btn-icon" aria-label={`Delete ${s.name}`} title="Delete" onClick={() => setDeleting(s)}>
              <i className="bi bi-trash" aria-hidden="true" />
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <PageShell
      actions={
        canManage && (
          <button type="button" className="btn btn-primary" onClick={editor.openNew} disabled={headquarters.length === 0}>
            <i className="bi bi-plus-lg" aria-hidden="true" /> Add stockist
          </button>
        )
      }
    >
      <DataStatusNotice />

      {canManage && state.status === 'ready' && headquarters.length === 0 && (
        <div className="alert alert-info" role="note">
          <i className="bi bi-info-circle-fill" aria-hidden="true" />
          <div>
            Every stockist is mapped to a headquarters. <Link to="/sales-office/headquarters">Add a headquarters</Link> first.
          </div>
        </div>
      )}

      <TableCard
        title={`Stockists (${state.stockists.length})`}
        toolbar={
          <>
            <SearchInput value={query} onChange={setQuery} label="Search stockists" placeholder="Search name or code" />
            <ListFilter
              locations={locations}
              reports={null}
              applied={filters}
              onApply={setFilters}
            />
            <SalesOfficeCsv
              module="stockist"
              records={rows.map((stockist) => {
                const hq = findById(state.headquarters, stockist.headquartersId)
                return {
                  'Stockist name': stockist.name,
                  'Stockist code': stockist.code ?? '',
                  Headquarters: hq?.code || hq?.name || '',
                  State: hq?.state ?? '',
                  Address: stockist.address ?? '',
                  Pincode: stockist.pincode ?? '',
                  'Phone number': stockist.phone ?? '',
                  Email: stockist.email ?? '',
                }
              })}
            />
          </>
        }
      >
        <DataTable
          caption="Stockists"
          columns={columns}
          rows={rows}
          emptyText={state.stockists.length ? 'No stockists match these filters.' : 'No stockists yet.'}
          empty={state.status === 'ready' && state.stockists.length === 0 && (
            <div className="card-body">
              <EmptyState icon="bi-shop" title="No stockists yet" text="Add a stockist and map it to a headquarters." />
            </div>
          )}
        />
      </TableCard>

      <FormModal
        open={editor.open}
        onClose={editor.close}
        title={editor.record ? 'Edit stockist' : 'Add stockist'}
        renderForm={(formId) => (
          <StockistForm
            key={editor.session}
            formId={formId}
            record={editor.record}
            stockists={state.stockists}
            headquarters={headquarters}
            onSave={async (values) => {
              await actions.saveRecord('stockists', { ...editor.record, ...values })
              editor.close()
            }}
          />
        )}
      />

      <ConfirmDialog
        open={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        title="Delete stockist"
        confirmLabel="Delete"
        onConfirm={() => actions.deleteStockist(deleting.id)}
      >
        Delete {deleting?.name}? Sales entries for this stockist are removed with it.
      </ConfirmDialog>

      <Modal
        open={viewing.open}
        onClose={() => setViewing((v) => ({ ...v, open: false }))}
        title={viewed ? `${viewed.name} — hierarchy` : 'Stockist hierarchy'}
        size="lg"
      >
        {viewed && (
          <>
            <p className="text-muted">
              Stockists are mapped to a headquarters. The managers below are everyone linked under that headquarters.
            </p>
            <HierarchyTree branch={buildHeadquartersBranch(state, viewed.headquartersId)} highlightStockistId={viewed.id} />
          </>
        )}
      </Modal>
    </PageShell>
  )
}
