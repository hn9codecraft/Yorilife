import useErpData from '../data/useErpData.js'

// Loading / load-error state of the ERP data on screens backed by the API.
// Renders nothing once the data has loaded.
export default function DataStatusNotice() {
  const { state, actions } = useErpData()

  if (state.status === 'loading') {
    return (
      <div className="alert alert-light d-flex align-items-center gap-2" role="status">
        <span className="spinner-border spinner-border-sm" aria-hidden="true" />
        <div>Loading saved records…</div>
      </div>
    )
  }
  if (state.status === 'error') {
    return (
      <div className="alert alert-danger" role="alert">
        <i className="bi bi-exclamation-octagon-fill" aria-hidden="true" />
        <div>
          {state.error?.message || 'The records could not be loaded.'}{' '}
          <button type="button" className="btn btn-sm btn-outline-danger ms-2" onClick={actions.reload}>Try again</button>
        </div>
      </div>
    )
  }
  return null
}
