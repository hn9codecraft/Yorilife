import StatusBadge from './StatusBadge.jsx'
import { PRIORITY, formatIst } from '../utils/announcements.js'

/**
 * How an announcement reads in the (future) employee app and in print:
 * banner, type and priority, title, date, sender and the formatted message.
 * `bodyHtml` from the API is already cleaned on the server.
 */
export default function AnnouncementView({ announcement, bannerUrl, attachments = [], onDownload }) {
  const priority = PRIORITY[announcement.priority] ?? PRIORITY.normal
  const typeLabel = announcement.typeLabel ?? announcement.type
  return (
    <article className={`announcement-view announcement-view--${announcement.priority}`} aria-label="Announcement preview">
      {bannerUrl && <img className="announcement-view__banner" src={bannerUrl} alt="" />}
      <div className="announcement-view__meta">
        <StatusBadge variant="primary">{typeLabel}</StatusBadge>
        {announcement.priority !== 'normal' && <StatusBadge variant={priority.variant}>{priority.label}</StatusBadge>}
        {announcement.isPinned && <span className="announcement-view__pin"><i className="bi bi-pin-angle-fill" aria-hidden="true" /> Pinned</span>}
        {announcement.isUpdated && <StatusBadge variant="info">Updated</StatusBadge>}
      </div>
      <h2 className="announcement-view__title">{announcement.title || 'Untitled announcement'}</h2>
      <p className="announcement-view__byline">
        {formatIst(announcement.publishedAt ?? announcement.publishAt ?? announcement.updatedAt)}
        {announcement.publishedBy || announcement.createdBy ? ` · ${announcement.publishedBy ?? announcement.createdBy}` : ''}
      </p>
      <div className="announcement-body" dangerouslySetInnerHTML={{ __html: announcement.bodyHtml || '<p><em>No message yet.</em></p>' }} />
      {attachments.length > 0 && (
        <ul className="announcement-view__files">
          {attachments.map((file) => (
            <li key={file.id}>
              <i className={`bi ${file.mime === 'application/pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image'}`} aria-hidden="true" />{' '}
              {onDownload ? <button type="button" className="btn btn-link btn-sm p-0" onClick={() => onDownload(file)}>{file.name}</button> : file.name}
            </li>
          ))}
        </ul>
      )}
      {announcement.requiresAck && (
        <div className="announcement-view__ack">
          <button type="button" className="btn btn-sm btn-primary" disabled>I have read this</button>
          <span className="small text-secondary">Employees must confirm they have read it (in the app).</span>
        </div>
      )}
    </article>
  )
}