/** Previous / next pager for a limit/offset list. Hidden when one page is enough. */
export default function ListPager({ total, limit, offset, onPage, label = 'Pagination' }) {
  if (!total || total <= limit) return null
  const page = Math.floor(offset / limit) + 1
  const pages = Math.max(1, Math.ceil(total / limit))
  const from = offset + 1
  const to = Math.min(offset + limit, total)

  return (
    <div className="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <p className="mb-0 small text-muted">Showing {from}–{to} of {total}</p>
      <nav aria-label={label}>
        <ul className="pagination pagination-sm mb-0">
          <li className={`page-item${page <= 1 ? ' disabled' : ''}`}>
            <button type="button" className="page-link" onClick={() => onPage(page - 1)} disabled={page <= 1}>Previous</button>
          </li>
          <li className="page-item disabled"><span className="page-link">{page} / {pages}</span></li>
          <li className={`page-item${page >= pages ? ' disabled' : ''}`}>
            <button type="button" className="page-link" onClick={() => onPage(page + 1)} disabled={page >= pages}>Next</button>
          </li>
        </ul>
      </nav>
    </div>
  )
}
