import { useId, useState } from 'react'
import { api, apiDownload, apiUpload } from '../api/client.js'
import { checkUpload, formatBytes } from '../utils/announcements.js'

/** Banner image and up to 5 attachments (PDF, JPG, PNG; 5 MB each) of a saved announcement. */
export default function AnnouncementFiles({ announcementId, attachments, onChanged, editable = true, maxFiles = 5 }) {
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)
  const bannerId = useId()
  const fileId = useId()
  const banner = attachments.find((file) => file.kind === 'banner')
  const files = attachments.filter((file) => file.kind === 'file')

  const upload = async (kind, input) => {
    const file = input.files?.[0]
    setError(null)
    const problem = checkUpload(file, kind)
    if (problem) {
      setError(problem)
      input.value = ''
      return
    }
    const form = new FormData()
    form.append('kind', kind)
    form.append('file', file)
    setBusy(kind)
    try {
      await apiUpload(`/api/announcements/${announcementId}/attachments`, form)
      onChanged()
    } catch (err) {
      setError(err.fields?.file ?? err.message)
    } finally {
      setBusy(null)
      input.value = ''
    }
  }

  const remove = async (file) => {
    setError(null)
    setBusy(`remove-${file.id}`)
    try {
      await api(`/api/announcements/${announcementId}/attachments/${file.id}/delete`, { method: 'POST', body: {} })
      onChanged()
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(null)
    }
  }

  const download = (file) => apiDownload(`/api/announcements/${announcementId}/attachments/${file.id}`, file.name).catch((err) => setError(err.message))

  const row = (file) => (
    <li key={file.id} className="announcement-files__item">
      <i className={`bi ${file.mime === 'application/pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image'}`} aria-hidden="true" />
      <button type="button" className="btn btn-link btn-sm p-0 text-break" onClick={() => download(file)}>{file.name}</button>
      <span className="small text-secondary">{formatBytes(file.size)}</span>
      {editable && (
        <button type="button" className="btn btn-sm btn-outline-danger btn-icon ms-auto" aria-label={`Remove ${file.name}`} disabled={busy !== null} onClick={() => remove(file)}>
          <i className="bi bi-trash3" aria-hidden="true" />
        </button>
      )}
    </li>
  )

  return (
    <div className="announcement-files">
      {error && <div className="alert alert-danger py-2" role="alert">{error}</div>}
      <h3 className="h6">Banner image</h3>
      {banner ? (
        <>
          <img className="announcement-files__banner" src={`/api/announcements/${announcementId}/attachments/${banner.id}?inline=1`} alt="Banner preview" />
          <ul className="list-unstyled mb-2">{row(banner)}</ul>
        </>
      ) : <p className="small text-secondary mb-2">No banner. Optional, JPG or PNG.</p>}
      {editable && (
        <div className="mb-3">
          <label htmlFor={bannerId} className="form-label small">{banner ? 'Replace banner' : 'Add banner'}</label>
          <input id={bannerId} type="file" className="form-control form-control-sm" accept=".jpg,.jpeg,.png,image/jpeg,image/png" disabled={busy !== null} onChange={(event) => upload('banner', event.target)} />
        </div>
      )}
      <h3 className="h6">Attachments ({files.length}/{maxFiles})</h3>
      {files.length > 0 ? <ul className="list-unstyled mb-2">{files.map(row)}</ul> : <p className="small text-secondary mb-2">No files attached.</p>}
      {editable && files.length < maxFiles && (
        <div>
          <label htmlFor={fileId} className="form-label small">Attach a file</label>
          <input id={fileId} type="file" className="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" disabled={busy !== null} onChange={(event) => upload('file', event.target)} />
          <div className="form-text">PDF, JPG or PNG, up to 5 MB each.</div>
        </div>
      )}
      {busy && <p className="small text-secondary mt-2 mb-0" role="status">Working...</p>}
    </div>
  )
}