import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import FormField, { SelectField, TextField } from '../../components/FormField.jsx'
import RichTextEditor from '../../components/RichTextEditor.jsx'
import useFormState from '../../hooks/useFormState.js'
import usePermission from '../../data/usePermission.js'
import { api } from '../../api/client.js'
import { PRIORITIES, PRIORITY, TYPES } from '../../utils/announcements.js'

function TemplateForm({ formId, template, onSaved }) {
  const form = useFormState(
    template ? { name: template.name, title: template.title, type: template.type, priority: template.priority, requiresAck: template.requiresAck, bodyHtml: template.bodyHtml } : { name: '', title: '', type: 'general', priority: 'normal', requiresAck: false, bodyHtml: '' },
    (values) => {
      const errors = {}
      if (!values.name.trim()) errors.name = 'Enter the template name.'
      if (!values.title.trim()) errors.title = 'Enter the title.'
      return errors
    },
  )
  const { values, errors, set, fieldId } = form
  return (
    <form id={formId} noValidate onSubmit={form.handleSubmit(async (body) => {
      const data = template
        ? await api(`/api/announcement-templates/${template.id}`, { method: 'PATCH', body })
        : await api('/api/announcement-templates', { method: 'POST', body })
      onSaved(data.template)
    })}>
      <div className="row g-3">
        <TextField id={fieldId('name')} label="Template name" required value={values.name} onChange={set('name')} error={errors.name} maxLength={100} help="For example: Holiday notice" />
        <TextField id={fieldId('title')} label="Announcement title" required value={values.title} onChange={set('title')} error={errors.title} maxLength={150} />
        <SelectField id={fieldId('type')} className="col-6" label="Type" value={values.type} onChange={set('type')} options={TYPES} error={errors.type} />
        <SelectField id={fieldId('priority')} className="col-6" label="Priority" value={values.priority} onChange={set('priority')} options={PRIORITIES} error={errors.priority} />
        <FormField id={fieldId('bodyHtml')} label="Message" error={errors.bodyHtml}>
          <RichTextEditor id={fieldId('bodyHtml')} value={values.bodyHtml} onChange={set('bodyHtml')} resetKey={template?.id ?? 'new'} />
        </FormField>
        <div className="col-12">
          <label className="form-check">
            <input type="checkbox" className="form-check-input" checked={values.requiresAck} onChange={(event) => set('requiresAck')(event.target.checked)} />
            <span className="form-check-label">Employees must confirm &quot;I have read this&quot;</span>
          </label>
        </div>
      </div>
    </form>
  )
}

export default function AnnouncementTemplatesPage() {
  const canManage = usePermission('announcements.manage')
  const [rows, setRows] = useState(null)
  const [error, setError] = useState(null)
  const [editing, setEditing] = useState(null)
  const [deleting, setDeleting] = useState(null)
  const [formKey, setFormKey] = useState(0)
  const [version, setVersion] = useState(0)

  useEffect(() => {
    let cancelled = false
    api('/api/announcement-templates').then((data) => !cancelled && setRows(data.templates)).catch((err) => !cancelled && setError(err.message))
    return () => {
      cancelled = true
    }
  }, [version])

  const open = (template) => {
    setFormKey((key) => key + 1)
    setEditing(template ?? 'new')
  }

  const columns = [
    { key: 'name', header: 'Template', render: (row) => <><strong>{row.name}</strong><div className="small text-secondary">{row.title}</div></> },
    { key: 'type', header: 'Type', nowrap: true, render: (row) => row.typeLabel },
    { key: 'priority', header: 'Priority', nowrap: true, render: (row) => <StatusBadge variant={PRIORITY[row.priority]?.variant}>{row.priorityLabel}</StatusBadge> },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      nowrap: true,
      render: (row) => (
        <div className="btn-group btn-group-sm" role="group" aria-label={`${row.name} actions`}>
          <Link to={`/announcements/new?template=${row.id}`} className="btn btn-outline-primary">Use template</Link>
          {canManage && <button type="button" className="btn btn-outline-secondary" onClick={() => open(row)}>Edit</button>}
          {canManage && <button type="button" className="btn btn-outline-danger" onClick={() => setDeleting(row)}>Delete</button>}
        </div>
      ),
    },
  ]

  return (
    <PageShell actions={canManage && <button type="button" className="btn btn-primary" onClick={() => open(null)}><i className="bi bi-plus-lg" aria-hidden="true" /> New template</button>}>
      {error && <div className="alert alert-danger" role="alert">{error}</div>}
      <TableCard title={`Templates (${rows?.length ?? 0})`}>
        <DataTable caption="Announcement templates" columns={columns} rows={rows ?? []} emptyText={rows === null ? 'Loading...' : 'No templates yet. Save common notices (holiday, policy) as templates.'} />
      </TableCard>
      <FormModal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing && editing !== 'new' ? 'Edit template' : 'New template'}
        submitLabel="Save template"
        variant="wide"
        renderForm={(formId) => (
          <TemplateForm
            key={formKey}
            formId={formId}
            template={editing && editing !== 'new' ? editing : null}
            onSaved={() => {
              setEditing(null)
              setVersion((v) => v + 1)
            }}
          />
        )}
      />
      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title="Delete template"
        confirmLabel="Delete"
        onConfirm={async () => {
          await api(`/api/announcement-templates/${deleting.id}/delete`, { method: 'POST', body: {} })
          setVersion((v) => v + 1)
        }}
      >
        Delete the template &quot;{deleting?.name}&quot;? Announcements made from it are not changed.
      </ConfirmDialog>
    </PageShell>
  )
}