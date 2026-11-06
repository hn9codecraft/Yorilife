import { useEffect, useId, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import { SelectField, TextField } from '../../components/FormField.jsx'
import FormField from '../../components/FormField.jsx'
import RichTextEditor from '../../components/RichTextEditor.jsx'
import AudiencePicker from '../../components/AudiencePicker.jsx'
import AnnouncementView from '../../components/AnnouncementView.jsx'
import AnnouncementFiles from '../../components/AnnouncementFiles.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import { api } from '../../api/client.js'
import { ANNOUNCEMENT_STATUS, EMPTY_AUDIENCE, PRIORITIES, TYPES, htmlHasText, toIstInput } from '../../utils/announcements.js'

const BLANK = { title: '', bodyHtml: '', type: 'general', priority: 'normal', requiresAck: false, audienceAll: true, audience: EMPTY_AUDIENCE, expiresAt: '', markUpdated: false }

function fromAnnouncement(a) {
  return {
    title: a.title,
    bodyHtml: a.bodyHtml ?? '',
    type: a.type,
    priority: a.priority,
    requiresAck: a.requiresAck,
    audienceAll: a.audienceAll,
    audience: { ...EMPTY_AUDIENCE, ...a.audience },
    expiresAt: toIstInput(a.expiresAt),
    markUpdated: false,
  }
}

/** New announcement (draft) or edit an existing one. */
export default function AnnouncementFormPage() {
  const { id } = useParams()
  const editing = Boolean(id)
  const [search] = useSearchParams()
  const templateId = search.get('template')
  const navigate = useNavigate()
  const prefix = useId()
  const [options, setOptions] = useState(null)
  const [record, setRecord] = useState(null)
  const [values, setValues] = useState(BLANK)
  const [resetKey, setResetKey] = useState(0)
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState(null)
  const [loaded, setLoaded] = useState(!editing && !templateId)
  const [showPreview, setShowPreview] = useState(false)

  const set = (field) => (value) => setValues((current) => ({ ...current, [field]: value }))
  const published = record?.status === 'published'

  useEffect(() => {
    let cancelled = false
    api('/api/announcements/options').then((data) => !cancelled && setOptions(data)).catch((err) => !cancelled && setError(err.message))
    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    let cancelled = false
    if (editing) {
      api(`/api/announcements/${id}`)
        .then((data) => {
          if (cancelled) return
          setRecord(data.announcement)
          setValues(fromAnnouncement(data.announcement))
          setResetKey((key) => key + 1)
          setLoaded(true)
        })
        .catch((err) => !cancelled && setError(err.message))
    } else if (templateId) {
      api('/api/announcement-templates')
        .then((data) => {
          if (cancelled) return
          const template = data.templates.find((t) => String(t.id) === templateId)
          if (template) {
            setValues({ ...BLANK, title: template.title, bodyHtml: template.bodyHtml, type: template.type, priority: template.priority, requiresAck: template.requiresAck })
            setNotice(`Started from the template "${template.name}".`)
            setResetKey((key) => key + 1)
          }
          setLoaded(true)
        })
        .catch((err) => !cancelled && setError(err.message))
    }
    return () => {
      cancelled = true
    }
  }, [editing, id, templateId])

  const reloadRecord = () => api(`/api/announcements/${id}`).then((data) => setRecord(data.announcement)).catch((err) => setError(err.message))

  const submit = async (event) => {
    event.preventDefault()
    if (saving) return
    const found = {}
    if (!values.title.trim()) found.title = 'Enter the title.'
    if (!htmlHasText(values.bodyHtml)) found.bodyHtml = 'Write the message.'
    setErrors(found)
    if (Object.keys(found).length) {
      document.getElementById(`${prefix}-${Object.keys(found)[0]}`)?.focus()
      return
    }
    const body = {
      title: values.title,
      bodyHtml: values.bodyHtml,
      type: values.type,
      priority: values.priority,
      requiresAck: values.requiresAck,
      audienceAll: values.audienceAll,
      audience: values.audience,
      expiresAt: values.expiresAt || null,
    }
    if (published) body.markUpdated = values.markUpdated
    setSaving(true)
    setError(null)
    try {
      const data = editing
        ? await api(`/api/announcements/${id}`, { method: 'PUT', body })
        : await api('/api/announcements', { method: 'POST', body })
      navigate(`/announcements/${data.announcement.id}`, { state: { notice: editing ? 'Changes saved.' : 'Draft saved. Add files, preview, then publish or schedule it.' } })
    } catch (err) {
      setSaving(false)
      const fields = err.fields ?? {}
      setErrors(fields)
      if (Object.keys(fields).length === 0 || fields.audience) setError(fields.audience ?? err.message)
    }
  }

  if (editing && record && !record.can.edit) {
    return (
      <PageShell>
        <div className="alert alert-warning">This announcement is {ANNOUNCEMENT_STATUS[record.status].label.toLowerCase()} and cannot be edited. <Link to={`/announcements/${id}`}>Back to the announcement</Link></div>
      </PageShell>
    )
  }

  const titleId = `${prefix}-title`
  const bodyLabelId = `${prefix}-body-label`

  return (
    <PageShell
      actions={(
        <>
          <button type="button" className="btn btn-outline-secondary" aria-pressed={showPreview} onClick={() => setShowPreview((value) => !value)}>
            <i className="bi bi-eye" aria-hidden="true" /> {showPreview ? 'Hide preview' : 'Preview'}
          </button>
          <Link to={editing ? `/announcements/${id}` : '/announcements/list'} className="btn btn-outline-secondary">Cancel</Link>
        </>
      )}
    >
      {notice && <div className="alert alert-info" role="status">{notice}</div>}
      {error && <div className="alert alert-danger" role="alert">{error}</div>}
      {record && (
        <p className="mb-3">
          <StatusBadge variant={ANNOUNCEMENT_STATUS[record.status].variant}>{ANNOUNCEMENT_STATUS[record.status].label}</StatusBadge>{' '}
          <span className="text-secondary small">{record.reference}</span>
        </p>
      )}
      {published && <div className="alert alert-warning" role="status">This announcement is live. Your changes are saved in its history. The audience cannot change after publishing.</div>}
      {!loaded ? <p className="text-secondary">Loading...</p> : (
        <div className={`announcement-editor${showPreview ? ' is-previewing' : ''}`}>
          <form className="card" onSubmit={submit} noValidate aria-label="Announcement">
            <div className="card-body">
              <div className="row g-3">
                <TextField
                  id={titleId}
                  label="Title"
                  required
                  value={values.title}
                  onChange={set('title')}
                  maxLength={150}
                  error={errors.title}
                  help={`${values.title.length}/150`}
                />
                <SelectField id={`${prefix}-type`} className="col-6 col-md-4" label="Type" value={values.type} onChange={set('type')} options={TYPES} error={errors.type} />
                <SelectField id={`${prefix}-priority`} className="col-6 col-md-4" label="Priority" value={values.priority} onChange={set('priority')} options={PRIORITIES} error={errors.priority} />
                <TextField
                  id={`${prefix}-expiresAt`}
                  className="col-12 col-md-4"
                  type="datetime-local"
                  label="Expires (India time)"
                  value={values.expiresAt}
                  onChange={set('expiresAt')}
                  error={errors.expiresAt}
                  help="Optional. Hidden from the app after this."
                />
                <div className="col-12">
                  <span className="form-label d-block" id={bodyLabelId}>Message<span className="required" aria-hidden="true">*</span></span>
                  <RichTextEditor
                    id={`${prefix}-bodyHtml`}
                    value={values.bodyHtml}
                    onChange={set('bodyHtml')}
                    resetKey={resetKey}
                    invalid={Boolean(errors.bodyHtml)}
                    labelledBy={bodyLabelId}
                    describedBy={errors.bodyHtml ? `${prefix}-bodyHtml-error` : undefined}
                  />
                  {errors.bodyHtml && <div id={`${prefix}-bodyHtml-error`} className="invalid-feedback d-block">{errors.bodyHtml}</div>}
                </div>
                <div className="col-12">
                  <label className="form-check">
                    <input type="checkbox" className="form-check-input" checked={values.requiresAck} onChange={(event) => set('requiresAck')(event.target.checked)} />
                    <span className="form-check-label">Employees must confirm &quot;I have read this&quot;</span>
                  </label>
                  {published && (
                    <label className="form-check">
                      <input type="checkbox" className="form-check-input" checked={values.markUpdated} onChange={(event) => set('markUpdated')(event.target.checked)} />
                      <span className="form-check-label">Major change: mark it &quot;Updated&quot; for readers</span>
                    </label>
                  )}
                </div>
                <FormField id={`${prefix}-audience`} label="Who receives it">
                  {published ? (
                    <p className="mb-0" id={`${prefix}-audience`}>{record.audienceSummary} ({record.recipientCount} recipients)</p>
                  ) : (
                    <AudiencePicker
                      options={options}
                      audienceAll={values.audienceAll}
                      audience={values.audience}
                      error={errors.audience}
                      onChange={(next) => setValues((current) => ({ ...current, ...next }))}
                    />
                  )}
                </FormField>
              </div>
            </div>
            <div className="card-footer bg-surface d-flex flex-wrap gap-2 justify-content-end">
              <button type="submit" className="btn btn-primary" aria-busy={saving || undefined} aria-disabled={saving || undefined}>
                {saving && <span className="spinner-border spinner-border-sm" aria-hidden="true" />} {editing ? 'Save changes' : 'Save draft'}
              </button>
            </div>
          </form>
          {showPreview && (
            <section className="card announcement-editor__preview" aria-label="Preview">
              <div className="card-header"><h2 className="card-title">Preview (app and print look)</h2></div>
              <div className="card-body">
                <AnnouncementView
                  announcement={{ ...record, ...values, typeLabel: TYPES.find((t) => t.value === values.type)?.label, publishedBy: record?.publishedBy, createdBy: record?.createdBy, isPinned: record?.isPinned, isUpdated: record?.isUpdated || values.markUpdated }}
                  bannerUrl={record?.bannerId ? `/api/announcements/${id}/attachments/${record.bannerId}?inline=1` : null}
                  attachments={(record?.attachments ?? []).filter((file) => file.kind === 'file')}
                />
              </div>
            </section>
          )}
          <section className="card mt-3" aria-label="Banner and files">
            <div className="card-header"><h2 className="card-title">Banner and files</h2></div>
            <div className="card-body">
              {editing && record ? (
                <AnnouncementFiles announcementId={id} attachments={record.attachments ?? []} onChanged={reloadRecord} maxFiles={options?.limits?.maxFiles ?? 5} />
              ) : (
                <p className="text-secondary mb-0">Save the draft first, then add a banner image and up to 5 files (PDF, JPG, PNG; 5 MB each).</p>
              )}
            </div>
          </section>
        </div>
      )}
    </PageShell>
  )
}