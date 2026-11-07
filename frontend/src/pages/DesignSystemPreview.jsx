import { useEffect, useRef } from 'react'
import { Tooltip } from 'bootstrap'
import PageShell from '../components/PageShell.jsx'
import EmptyState from '../components/EmptyState.jsx'
import LoadingIndicator from '../components/LoadingIndicator.jsx'

// Sprint 1 design-system reference page. Shows tokens and standard component
// styles only — the sample table rows are neutral placeholders, not business data.

const SWATCHES = [
  { name: 'Primary (brand teal)', token: '--color-primary', value: '#24AEB1' },
  { name: 'Primary 600 (fills/links)', token: '--color-primary-600', value: '#1A7F81' },
  { name: 'Secondary (brand navy)', token: '--color-secondary', value: '#373F85' },
  { name: 'Accent', token: '--color-accent', value: '#EB3A7B' },
  { name: 'Text', token: '--color-text', value: '#1D2A38' },
  { name: 'Text muted', token: '--color-text-muted', value: '#5F6B7E' },
  { name: 'Background', token: '--color-background', value: '#F5F8FA' },
  { name: 'Border', token: '--color-border', value: '#E3E8EE' },
  { name: 'Success', token: '--color-success', value: '#1F8A5B' },
  { name: 'Warning', token: '--color-warning', value: '#D98E04' },
  { name: 'Danger', token: '--color-danger', value: '#D23C47' },
  { name: 'Info', token: '--color-info', value: '#3165C8' },
]

const STATUS_VARIANTS = [
  { label: 'Active', variant: 'success' },
  { label: 'Pending', variant: 'warning' },
  { label: 'Inactive', variant: 'danger' },
  { label: 'Draft', variant: 'info' },
  { label: 'Archived', variant: 'secondary' },
]

const SAMPLE_ROWS = [
  { code: 'SAMPLE-001', name: 'Sample row one', status: 'success', label: 'Active', value: '1,250.00' },
  { code: 'SAMPLE-002', name: 'Sample row two with a longer descriptive name', status: 'warning', label: 'Pending', value: '980.50' },
  { code: 'SAMPLE-003', name: 'Sample row three', status: 'danger', label: 'Inactive', value: '0.00' },
]

function Section({ title, children }) {
  return (
    <section className="page-section">
      <h2 className="page-section__title">{title}</h2>
      {children}
    </section>
  )
}

export default function DesignSystemPreview() {
  const rootRef = useRef(null)

  useEffect(() => {
    const tooltips = [...rootRef.current.querySelectorAll('[data-bs-toggle="tooltip"]')].map(
      (el) => new Tooltip(el),
    )
    return () => tooltips.forEach((tooltip) => tooltip.dispose())
  }, [])

  return (
    <div ref={rootRef}>
      <PageShell
        actions={
          <>
            <button type="button" className="btn btn-outline-secondary">
              <i className="bi bi-download" aria-hidden="true" /> Export
            </button>
            <button type="button" className="btn btn-primary">
              <i className="bi bi-plus-lg" aria-hidden="true" /> New item
            </button>
          </>
        }
      />

      <Section title="Brand colours">
        <p className="text-muted">Chips follow the active colour mode. The hex value is the light-theme brand colour.</p>
        <div className="row row-cols-2 row-cols-sm-3 row-cols-lg-6 g-3">
          {SWATCHES.map((swatch) => (
            <div className="col" key={swatch.token}>
              <div className="swatch">
                <div className="swatch__color" style={{ backgroundColor: `var(${swatch.token})` }} />
                <div className="swatch__meta">
                  <span className="swatch__name">{swatch.name}</span>
                  <span className="swatch__value">{swatch.token}</span>
                  <span className="swatch__value">Light {swatch.value}</span>
                </div>
              </div>
            </div>
          ))}
        </div>
      </Section>

      <Section title="Typography">
        <div className="card">
          <div className="card-body">
            <span className="eyebrow mb-2">Poppins · 400 / 500 / 600 / 700</span>
            <h1>Heading 1 — Yorilife ERP</h1>
            <h2>Heading 2 — Section title</h2>
            <h3>Heading 3 — Card title</h3>
            <h4>Heading 4 — Group label</h4>
            <p className="mb-2">
              Body text at 14px is the ERP default for dense, readable data screens.{' '}
              <a href="#typography">Inline link</a> uses the accessible teal shade.
            </p>
            <p className="text-muted small mb-0">Muted helper text for descriptions and metadata.</p>
          </div>
        </div>
      </Section>

      <Section title="Buttons">
        <div className="card">
          <div className="card-body d-flex flex-wrap gap-2 align-items-center">
            <button type="button" className="btn btn-primary">Primary</button>
            <button type="button" className="btn btn-secondary">Secondary</button>
            <button type="button" className="btn btn-outline-primary">Outline primary</button>
            <button type="button" className="btn btn-outline-secondary">Outline secondary</button>
            <button type="button" className="btn btn-danger">Danger</button>
            <button type="button" className="btn btn-primary" disabled>Disabled</button>
            <button type="button" className="btn btn-link">Link</button>
            <button type="button" className="btn btn-primary btn-sm">Small</button>
            <button
              type="button"
              className="btn btn-outline-secondary btn-icon"
              aria-label="Settings"
              data-bs-toggle="tooltip"
              data-bs-title="Icon button with tooltip"
            >
              <i className="bi bi-gear" aria-hidden="true" />
            </button>
          </div>
        </div>
      </Section>

      <Section title="Form controls">
        <div className="card">
          <div className="card-body">
            <form className="row g-3" noValidate onSubmit={(e) => e.preventDefault()}>
              <div className="col-12 col-md-6">
                <label htmlFor="ds-text" className="form-label">
                  Text input<span className="required" aria-hidden="true">*</span>
                </label>
                <input id="ds-text" type="text" className="form-control" placeholder="Enter a value" required />
                <div className="form-text">Helper text explains the expected format.</div>
              </div>
              <div className="col-12 col-md-6">
                <label htmlFor="ds-select" className="form-label">Select</label>
                <select id="ds-select" className="form-select" defaultValue="">
                  <option value="" disabled>Choose an option</option>
                  <option value="1">Option one</option>
                  <option value="2">Option two</option>
                </select>
              </div>
              <div className="col-12 col-md-6">
                <label htmlFor="ds-valid" className="form-label">Valid state</label>
                <input id="ds-valid" type="text" className="form-control is-valid" defaultValue="Looks good" />
                <div className="valid-feedback">Value accepted.</div>
              </div>
              <div className="col-12 col-md-6">
                <label htmlFor="ds-invalid" className="form-label">Invalid state</label>
                <input
                  id="ds-invalid"
                  type="text"
                  className="form-control is-invalid"
                  defaultValue="Wrong value"
                  aria-describedby="ds-invalid-feedback"
                />
                <div id="ds-invalid-feedback" className="invalid-feedback">Please enter a valid value.</div>
              </div>
              <div className="col-12 col-md-6">
                <label htmlFor="ds-search" className="form-label">Search field</label>
                <div className="search-field">
                  <i className="bi bi-search search-field__icon" aria-hidden="true" />
                  <input id="ds-search" type="search" className="form-control" placeholder="Search…" />
                </div>
              </div>
              <div className="col-12 col-md-6">
                <label htmlFor="ds-disabled" className="form-label">Disabled</label>
                <input id="ds-disabled" type="text" className="form-control" defaultValue="Read only value" disabled />
              </div>
              <div className="col-12">
                <label htmlFor="ds-textarea" className="form-label">Textarea</label>
                <textarea id="ds-textarea" className="form-control" rows="3" placeholder="Add a note" />
              </div>
              <div className="col-12 col-md-6">
                <fieldset>
                  <legend className="form-label">Checkboxes</legend>
                  <div className="form-check">
                    <input className="form-check-input" type="checkbox" id="ds-check-1" defaultChecked />
                    <label className="form-check-label" htmlFor="ds-check-1">Checked option</label>
                  </div>
                  <div className="form-check">
                    <input className="form-check-input" type="checkbox" id="ds-check-2" />
                    <label className="form-check-label" htmlFor="ds-check-2">Unchecked option</label>
                  </div>
                  <div className="form-check form-switch">
                    <input className="form-check-input" type="checkbox" role="switch" id="ds-switch" defaultChecked />
                    <label className="form-check-label" htmlFor="ds-switch">Switch</label>
                  </div>
                </fieldset>
              </div>
              <div className="col-12 col-md-6">
                <fieldset>
                  <legend className="form-label">Radio buttons</legend>
                  <div className="form-check">
                    <input className="form-check-input" type="radio" name="ds-radio" id="ds-radio-1" defaultChecked />
                    <label className="form-check-label" htmlFor="ds-radio-1">First choice</label>
                  </div>
                  <div className="form-check">
                    <input className="form-check-input" type="radio" name="ds-radio" id="ds-radio-2" />
                    <label className="form-check-label" htmlFor="ds-radio-2">Second choice</label>
                  </div>
                </fieldset>
              </div>
            </form>
          </div>
        </div>
      </Section>

      <Section title="Badges & status labels">
        <div className="card">
          <div className="card-body d-flex flex-wrap gap-2">
            {STATUS_VARIANTS.map(({ label, variant }) => (
              <span key={variant} className={`badge status-badge text-${variant}-emphasis bg-${variant}-subtle`}>
                {label}
              </span>
            ))}
            <span className="badge text-bg-primary">Solid primary</span>
            <span className="badge text-bg-secondary">Solid secondary</span>
          </div>
        </div>
      </Section>

      <Section title="Alerts">
        <div className="d-grid gap-2">
          <div className="alert alert-success mb-0" role="alert">
            <i className="bi bi-check-circle-fill" aria-hidden="true" />
            <div>Changes were saved successfully.</div>
          </div>
          <div className="alert alert-warning mb-0" role="alert">
            <i className="bi bi-exclamation-triangle-fill" aria-hidden="true" />
            <div>Some fields need your attention before continuing.</div>
          </div>
          <div className="alert alert-danger mb-0" role="alert">
            <i className="bi bi-x-octagon-fill" aria-hidden="true" />
            <div>Something went wrong. Please try again.</div>
          </div>
          <div className="alert alert-info mb-0" role="alert">
            <i className="bi bi-info-circle-fill" aria-hidden="true" />
            <div>Informational message for the user.</div>
          </div>
        </div>
      </Section>

      <Section title="Table, dropdown & pagination">
        <div className="card">
          <div className="card-header">
            <h3 className="card-title">Sample table</h3>
            <div className="d-flex gap-2 flex-wrap">
              <div className="search-field search-field--compact">
                <i className="bi bi-search search-field__icon" aria-hidden="true" />
                <input type="search" className="form-control" placeholder="Search rows" aria-label="Search rows" />
              </div>
              <div className="dropdown">
                <button
                  type="button"
                  className="btn btn-outline-secondary dropdown-toggle"
                  data-bs-toggle="dropdown"
                  aria-expanded="false"
                >
                  <i className="bi bi-funnel" aria-hidden="true" /> Filter
                </button>
                <ul className="dropdown-menu dropdown-menu-end">
                  <li><button type="button" className="dropdown-item active">All</button></li>
                  <li><button type="button" className="dropdown-item">Active</button></li>
                  <li><button type="button" className="dropdown-item">Pending</button></li>
                  <li><hr className="dropdown-divider" /></li>
                  <li><button type="button" className="dropdown-item">Reset filters</button></li>
                </ul>
              </div>
            </div>
          </div>
          <div className="table-container border-0 rounded-0">
            <table className="table table-hover">
              <thead>
                <tr>
                  <th scope="col">Code</th>
                  <th scope="col">Name</th>
                  <th scope="col">Status</th>
                  <th scope="col" className="text-end">Value</th>
                  <th scope="col" className="text-end">
                    <span className="visually-hidden">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {SAMPLE_ROWS.map((row) => (
                  <tr key={row.code}>
                    <td className="font-mono text-nowrap" data-label="Code">{row.code}</td>
                    <td data-label="Name">{row.name}</td>
                    <td data-label="Status">
                      <span className={`badge status-badge text-${row.status}-emphasis bg-${row.status}-subtle`}>
                        {row.label}
                      </span>
                    </td>
                    <td className="text-end text-nowrap" data-label="Value">{row.value}</td>
                    <td className="text-end" data-label="Actions">
                      <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`Actions for ${row.code}`}>
                        <i className="bi bi-three-dots" aria-hidden="true" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="card-footer bg-surface d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span className="text-muted small">Showing 1–3 of 3</span>
            <nav aria-label="Table pagination">
              <ul className="pagination pagination-sm">
                <li className="page-item disabled"><span className="page-link">Previous</span></li>
                <li className="page-item active" aria-current="page"><span className="page-link">1</span></li>
                <li className="page-item"><a className="page-link" href="#page-2">2</a></li>
                <li className="page-item"><a className="page-link" href="#page-2">Next</a></li>
              </ul>
            </nav>
          </div>
        </div>
      </Section>

      <Section title="Cards, modal, empty & loading states">
        <div className="row g-3">
          <div className="col-12 col-md-6 col-xl-4">
            <div className="card h-100">
              <div className="card-header">
                <h3 className="card-title">Card panel</h3>
                <span className="badge text-primary-emphasis bg-primary-subtle">Info</span>
              </div>
              <div className="card-body">
                <p className="text-muted">Cards group related content with a header, body and footer.</p>
                <button
                  type="button"
                  className="btn btn-outline-primary"
                  data-bs-toggle="modal"
                  data-bs-target="#ds-modal"
                >
                  Open modal
                </button>
              </div>
            </div>
          </div>
          <div className="col-12 col-md-6 col-xl-4">
            <EmptyState
              title="No records yet"
              text="When items are added they will appear here."
              action={<button type="button" className="btn btn-primary btn-sm">Add first item</button>}
            />
          </div>
          <div className="col-12 col-xl-4">
            <div className="card h-100">
              <div className="card-body">
                <LoadingIndicator label="Loading data…" />
                <div className="d-grid gap-2" aria-hidden="true">
                  <span className="skeleton w-75" />
                  <span className="skeleton w-50" />
                  <span className="skeleton w-100" />
                </div>
              </div>
            </div>
          </div>
        </div>
      </Section>

      <div className="modal fade" id="ds-modal" tabIndex={-1} aria-labelledby="ds-modal-title" aria-hidden="true">
        <div className="modal-dialog modal-dialog-centered">
          <div className="modal-content">
            <div className="modal-header">
              <h2 className="modal-title" id="ds-modal-title">Modal title</h2>
              <button type="button" className="btn-close" data-bs-dismiss="modal" aria-label="Close" />
            </div>
            <div className="modal-body">
              <p className="mb-0">Modal dialogs are used for confirmations and short forms.</p>
            </div>
            <div className="modal-footer">
              <button type="button" className="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" className="btn btn-primary" data-bs-dismiss="modal">Confirm</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
