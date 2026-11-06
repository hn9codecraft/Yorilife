import { useEffect, useId, useState } from 'react'
import { api } from '../api/client.js'
import { RECIPIENT_TYPE } from '../utils/announcements.js'

const GROUPS = [
  { key: 'location', label: 'Location (state)', option: 'locations', help: 'Managers and employees in these states.' },
  { key: 'headquarters', label: 'Headquarters', option: 'headquarters', help: 'The 1st line manager of the HQ, the lines above, and employees linked to it.' },
  { key: 'managerLevel', label: 'Manager line', option: 'managerLevels', help: 'Managers of these lines only.' },
  { key: 'role', label: 'Role', option: 'roles', help: 'ERP users and employees with these roles.' },
  { key: 'employee', label: 'Employee list', option: 'employees', help: 'These employees are always added.' },
]

function ChoiceList({ group, options, selected, onChange }) {
  const [query, setQuery] = useState('')
  const labelId = useId()
  const list = options.map((option) => (option.value !== undefined ? { id: option.value, name: option.label } : option))
  const shown = query ? list.filter((option) => option.name.toLowerCase().includes(query.toLowerCase())) : list
  const isOn = (id) => selected.some((value) => String(value) === String(id))
  const toggle = (id) => onChange(isOn(id) ? selected.filter((value) => String(value) !== String(id)) : [...selected, id])

  return (
    <fieldset className="col-12 col-md-6 audience-picker__group">
      <legend className="form-label" id={labelId}>
        {group.label} {selected.length > 0 && <span className="badge text-bg-light">{selected.length}</span>}
      </legend>
      {list.length > 8 && (
        <input type="search" className="form-control form-control-sm mb-1" placeholder="Filter" aria-label={`Filter ${group.label}`} value={query} onChange={(event) => setQuery(event.target.value)} />
      )}
      <div className="choice-group choice-group--scroll" role="group" aria-labelledby={labelId}>
        {list.length === 0 && <span className="text-secondary small">Nothing to choose yet.</span>}
        {shown.map((option) => (
          <label key={option.id} className="form-check">
            <input type="checkbox" className="form-check-input" checked={isOn(option.id)} onChange={() => toggle(option.id)} />
            <span className="form-check-label">{option.name}</span>
          </label>
        ))}
      </div>
      <div className="form-text">{group.help}</div>
    </fieldset>
  )
}

/**
 * Audience: everyone, or filters (OR inside one filter, AND between filters)
 * plus picked employees. Shows a live count from the server.
 */
export default function AudiencePicker({ options, audienceAll, audience, onChange, error }) {
  const [preview, setPreview] = useState(null)
  const [previewError, setPreviewError] = useState(null)
  const name = useId()
  const body = JSON.stringify({ audienceAll, audience })

  useEffect(() => {
    let cancelled = false
    const timer = setTimeout(() => {
      api('/api/announcements/audience-preview', { method: 'POST', body: JSON.parse(body) })
        .then((data) => {
          if (cancelled) return
          setPreview(data.audience)
          setPreviewError(null)
        })
        .catch((err) => !cancelled && setPreviewError(err.message))
    }, 300)
    return () => {
      cancelled = true
      clearTimeout(timer)
    }
  }, [body])

  return (
    <div className="audience-picker">
      <div className="choice-group choice-group--inline mb-3" role="radiogroup" aria-label="Who receives it">
        <label className="form-check">
          <input type="radio" className="form-check-input" name={name} checked={audienceAll} onChange={() => onChange({ audienceAll: true, audience })} />
          <span className="form-check-label">All employees</span>
        </label>
        <label className="form-check">
          <input type="radio" className="form-check-input" name={name} checked={!audienceAll} onChange={() => onChange({ audienceAll: false, audience })} />
          <span className="form-check-label">Choose people</span>
        </label>
      </div>
      {!audienceAll && (
        <>
          <p className="small text-secondary mb-2">Inside one filter any match counts; when you use several filters a person must match all of them. People picked from the employee list are always added.</p>
          <div className="row g-3 mb-3">
            {GROUPS.map((group) => (
              <ChoiceList
                key={group.key}
                group={group}
                options={options?.[group.option] ?? []}
                selected={audience[group.key] ?? []}
                onChange={(values) => onChange({ audienceAll, audience: { ...audience, [group.key]: values } })}
              />
            ))}
          </div>
        </>
      )}
      {error && <div className="invalid-feedback d-block mb-2">{error}</div>}
      <div className="audience-picker__count" role="status" aria-live="polite" data-testid="audience-count">
        <i className="bi bi-people" aria-hidden="true" />
        {previewError ? <span className="text-danger">{previewError}</span> : preview ? (
          <span>
            <strong>{preview.total}</strong> {preview.total === 1 ? 'person' : 'people'} will receive it
            {' '}({Object.entries(preview.byType).map(([type, count]) => `${count} ${RECIPIENT_TYPE[type].toLowerCase()}${count === 1 ? '' : 's'}`).join(', ')})
          </span>
        ) : <span className="text-secondary">Counting...</span>}
      </div>
      {preview?.sample?.length > 0 && (
        <p className="small text-secondary mb-0 mt-1">
          For example: {preview.sample.slice(0, 5).map((person) => person.name).join(', ')}{preview.total > 5 ? ', ...' : ''}
        </p>
      )}
    </div>
  )
}