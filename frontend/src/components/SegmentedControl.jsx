import { Fragment, useId } from 'react'

// Single-choice filter rendered as a Bootstrap button group of radios.
// options: [{ value, label, count? }]
export default function SegmentedControl({ label, options, value, onChange }) {
  const name = useId()
  return (
    <div className="btn-group btn-group-sm segmented-control" role="group" aria-label={label}>
      {options.map((option) => {
        const id = `${name}-${option.value}`
        return (
          <Fragment key={option.value}>
            <input
              type="radio"
              className="btn-check"
              name={name}
              id={id}
              checked={value === option.value}
              onChange={() => onChange(option.value)}
            />
            <label className="btn btn-outline-secondary" htmlFor={id}>
              {option.label}
              {option.count !== undefined && <span className="segmented-control__count">{option.count}</span>}
            </label>
          </Fragment>
        )
      })}
    </div>
  )
}
