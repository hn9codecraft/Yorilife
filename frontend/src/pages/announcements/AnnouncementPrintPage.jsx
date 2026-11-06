import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import BrandLogo from '../../components/BrandLogo.jsx'
import { api } from '../../api/client.js'
import { PRIORITY, formatIst } from '../../utils/announcements.js'

/**
 * Clean A4 print view (outside the ERP shell). "Print / Save as PDF" uses the
 * browser's print dialog; no PDF library is needed. Same layout the app can reuse.
 */
export default function AnnouncementPrintPage() {
  const { id } = useParams()
  const [record, setRecord] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    api(`/api/announcements/${id}`)
      .then((data) => {
        if (cancelled) return
        setRecord(data.announcement)
        document.title = `${data.announcement.reference} - ${data.announcement.title}`
      })
      .catch((err) => !cancelled && setError(err.message))
    return () => {
      cancelled = true
    }
  }, [id])

  if (error) return <div className="print-page"><div className="alert alert-danger" role="alert">{error}</div></div>
  if (!record) return <div className="print-page"><p>Loading...</p></div>

  const files = (record.attachments ?? []).filter((file) => file.kind === 'file')
  const priority = PRIORITY[record.priority] ?? PRIORITY.normal
  return (
    <div className="print-page">
      <div className="print-page__actions no-print">
        <Link to={`/announcements/${id}`} className="btn btn-outline-secondary btn-sm"><i className="bi bi-arrow-left" aria-hidden="true" /> Back</Link>
        <button type="button" className="btn btn-primary btn-sm" onClick={() => window.print()}><i className="bi bi-printer" aria-hidden="true" /> Print / Save as PDF</button>
      </div>
      <article className="print-sheet" aria-label="Printable announcement">
        <header className="print-sheet__header">
          <BrandLogo className="print-sheet__logo" />
          <div className="print-sheet__ref">
            <div><strong>{record.reference}</strong></div>
            <div>{record.typeLabel} · {priority.label} priority</div>
            {record.status !== 'published' && <div className="print-sheet__status">{record.status.toUpperCase()}</div>}
          </div>
        </header>
        {record.bannerId && <img className="print-sheet__banner" src={`/api/announcements/${id}/attachments/${record.bannerId}?inline=1`} alt="" />}
        <h1 className="print-sheet__title">{record.title}</h1>
        <dl className="print-sheet__meta">
          <div><dt>Date</dt><dd>{formatIst(record.publishedAt ?? record.publishAt ?? record.updatedAt)}</dd></div>
          <div><dt>Published by</dt><dd>{record.publishedBy ?? record.createdBy ?? '\u2014'}</dd></div>
          <div><dt>Audience</dt><dd>{record.audienceSummary}</dd></div>
          {record.expiresAt && <div><dt>Valid until</dt><dd>{formatIst(record.expiresAt)}</dd></div>}
        </dl>
        <div className="announcement-body print-sheet__body" dangerouslySetInnerHTML={{ __html: record.bodyHtml }} />
        {files.length > 0 && (
          <section className="print-sheet__files">
            <h2>Attachments</h2>
            <ul>{files.map((file) => <li key={file.id}>{file.name}</li>)}</ul>
          </section>
        )}
        {record.requiresAck && <p className="print-sheet__ack">Please confirm you have read this announcement.</p>}
        <footer className="print-sheet__footer">Yorilife Sciences Pvt. Ltd. · {record.reference}{record.version > 1 ? ` · version ${record.version}` : ''}</footer>
      </article>
    </div>
  )
}