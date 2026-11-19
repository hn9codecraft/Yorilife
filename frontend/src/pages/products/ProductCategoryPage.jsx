import { useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import TableSection, { TableSectionStack } from '../../components/TableSection.jsx'
import DataTable from '../../components/DataTable.jsx'
import FilterBar from '../../components/FilterBar.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import ExportCsvButton from '../../components/ExportCsvButton.jsx'
import { LabelNote, TextField } from '../../components/FormField.jsx'
import { CellStack, CountValue } from '../../components/TableCells.jsx'
import useErpData from '../../data/useErpData.js'
import usePermission from '../../data/usePermission.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import useUrlFilters from '../../hooks/useUrlFilters.js'
import { formatDateTime } from '../../data/headquarterList.js'
import { PRODUCT_CATEGORY_CSV, nameTaken } from '../../data/productList.js'

const FILTER_DEFAULTS = Object.freeze({ qt: '', qg: '' })

/** Product Type and Product Group share one shape: a unique name. */
const KINDS = {
  type: {
    title: 'Product Type',
    plural: 'product types',
    label: 'Product Type',
    emptyHint: 'Add the kinds of product you sell. Products pick one product type.',
    collection: 'productTypes',
    filterKey: 'qt',
    productFilter: 'type',
    placeholder: 'e.g. Tablet, Capsule, Syrup',
  },
  group: {
    title: 'Product Group',
    plural: 'product groups',
    label: 'Product Group',
    emptyHint: 'Add groups like Focus Product or Best Selling. Products pick one product group.',
    collection: 'productGroups',
    filterKey: 'qg',
    productFilter: 'group',
    placeholder: 'e.g. Focus Product, Best Selling, Popular',
  },
}

function CategoryForm({ formId, kind, record, records, onSave }) {
  const config = KINDS[kind]
  const form = useFormState({ name: record?.name ?? '' }, (values) => {
    const errors = {}
    const name = values.name.trim()
    if (!name) errors.name = `Enter the ${config.label.toLowerCase()}.`
    else if (nameTaken(records, name, record?.id)) errors.name = `This ${config.label.toLowerCase()} already exists.`
    return errors
  })

  return (
    <form id={formId} className="row g-3" noValidate onSubmit={form.handleSubmit((values) => onSave({ name: values.name.trim() }))}>
      <TextField
        id={form.fieldId('name')}
        label={<LabelNote label={config.label} note="unique, not case-sensitive" />}
        required
        value={form.values.name}
        onChange={form.set('name')}
        error={form.errors.name}
        maxLength={100}
        autoComplete="off"
        placeholder={config.placeholder}
        className="col-12"
      />
    </form>
  )
}

function CategoryTable({ kind, records, canManage, query, onQuery, onAdd, onEdit, onDelete }) {
  const config = KINDS[kind]
  const q = query.trim().toLowerCase()
  const rows = records.filter((record) => !q || (record.name ?? '').toLowerCase().includes(q))

  const locked = 'Products linked hova thi delete nathi thatu'
  const columns = [
    { key: 'name', header: config.title, sticky: 'start', truncate: true, title: (record) => record.name, render: (record) => <CellStack strong primary={record.name} /> },
    {
      key: 'products',
      header: 'Products',
      width: 'sm',
      fixed: true,
      align: 'end',
      render: (record) => (
        <CountValue
          count={record.productCount}
          to={record.productCount > 0 ? `/products/list?${config.productFilter}=${record.id}` : null}
          title={record.productCount > 0 ? `Show the ${record.productCount} products` : 'No products'}
        />
      ),
    },
    {
      key: 'updated',
      header: 'Last Updated',
      width: 'lg',
      fixed: true,
      render: (record) => (record.updatedAt
        ? <CellStack primary={formatDateTime(record.updatedAt)} secondary={record.createdAt ? `Created ${formatDateTime(record.createdAt)}` : null} />
        : null),
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'end',
      nowrap: true,
      sticky: 'end',
      render: (record) => canManage ? (
        <div className="table-actions">
          <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`Edit ${record.name}`} title="Edit" onClick={() => onEdit(record)}>
            <i className="bi bi-pencil" aria-hidden="true" />
          </button>
          <span className="table-action" title={record.productCount > 0 ? locked : 'Delete'}>
            <button
              type="button"
              className="btn btn-sm btn-outline-danger btn-icon"
              aria-label={record.productCount > 0 ? `Delete ${record.name}. ${locked}` : `Delete ${record.name}`}
              disabled={record.productCount > 0}
              onClick={() => onDelete(record)}
            >
              <i className="bi bi-trash" aria-hidden="true" />
            </button>
          </span>
        </div>
      ) : null,
    },
  ]

  return (
    <TableSection
      title={config.title}
      count={records.length}
      actions={canManage && (
        <button type="button" className="btn btn-primary" onClick={onAdd}>
          <i className="bi bi-plus-lg" aria-hidden="true" /> Add {config.label.toLowerCase()}
        </button>
      )}
    >
      <FilterBar
        label={`Filter ${config.plural}`}
        search={{ value: query, onChange: onQuery, label: `Search ${config.plural}`, placeholder: `Search ${config.label.toLowerCase()}` }}
        chips={query ? [{ key: 'q', label: `Search: ${query}`, onRemove: () => onQuery('') }] : []}
        onClearAll={() => onQuery('')}
        shown={rows.length}
        total={records.length}
        noun={config.plural}
        actions={(
          <ExportCsvButton
            name={PRODUCT_CATEGORY_CSV[kind].filename}
            headers={PRODUCT_CATEGORY_CSV[kind].headers}
            auditKind="product_categories_csv"
            mask={false}
            records={rows.map((record) => ({
              [PRODUCT_CATEGORY_CSV[kind].headers[0]]: record.name ?? '',
              Products: record.productCount ?? 0,
              'Last updated': record.updatedAt ? formatDateTime(record.updatedAt) : '',
            }))}
          />
        )}
      />
      <DataTable
        caption={config.title}
        striped
        fixedLayout
        columns={columns}
        rows={rows}
        emptyIcon={records.length ? 'bi-search' : 'bi-inbox'}
        emptyText={records.length ? `No ${config.plural} match "${query.trim()}"` : `No ${config.plural} yet`}
        emptyHint={records.length ? 'Try a different search, or clear it to see all.' : config.emptyHint}
        emptyAction={records.length
          ? <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => onQuery('')}>Clear search</button>
          : canManage && (
            <button type="button" className="btn btn-primary btn-sm" onClick={onAdd}>
              <i className="bi bi-plus-lg" aria-hidden="true" /> Add {config.label.toLowerCase()}
            </button>
          )}
      />
    </TableSection>
  )
}

export default function ProductCategoryPage() {
  const { state, actions } = useErpData()
  const canManage = usePermission('catalogue.manage')
  const editor = useEditor()
  const [editingKind, setEditingKind] = useState('type')
  const [deleting, setDeleting] = useState(null)
  const [filters, setFilter] = useUrlFilters(FILTER_DEFAULTS)

  const recordsOf = (kind) => state[KINDS[kind].collection] ?? []
  const openNew = (kind) => { setEditingKind(kind); editor.openNew() }
  const openEdit = (kind, record) => { setEditingKind(kind); editor.openEdit(record) }
  const config = KINDS[editingKind]

  return (
    <PageShell>
      <DataStatusNotice />
      {/* Product Type on top, Product Group below; each has its own Add button. */}
      <TableSectionStack>
        {['type', 'group'].map((kind) => (
          <CategoryTable
            key={kind}
            kind={kind}
            records={recordsOf(kind)}
            canManage={canManage}
            query={filters[KINDS[kind].filterKey]}
            onQuery={(value) => setFilter(KINDS[kind].filterKey, value)}
            onAdd={() => openNew(kind)}
            onEdit={(record) => openEdit(kind, record)}
            onDelete={(record) => setDeleting({ kind, record })}
          />
        ))}
      </TableSectionStack>

      <FormModal
        size="lg"
        variant="wide"
        open={editor.open}
        onClose={editor.close}
        title={`${editor.record?.id ? 'Edit' : 'Add'} ${config.label.toLowerCase()}`}
        renderForm={(formId) => (
          <CategoryForm
            key={`${editingKind}-${editor.session}`}
            formId={formId}
            kind={editingKind}
            record={editor.record}
            records={recordsOf(editingKind)}
            onSave={async (values) => {
              await actions.saveProductCategory(editingKind, { id: editor.record?.id, ...values })
              editor.close()
            }}
          />
        )}
      />

      <ConfirmDialog
        open={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        title={`Delete ${deleting ? KINDS[deleting.kind].label.toLowerCase() : ''}`}
        confirmLabel="Delete"
        onConfirm={() => actions.deleteProductCategory(deleting.kind, deleting.record.id)}
      >
        Delete {deleting?.record.name}? It cannot be deleted while a product uses it.
      </ConfirmDialog>
    </PageShell>
  )
}