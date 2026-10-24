import { useId } from 'react'

// Search box using the shared .search-field pattern.
export default function SearchInput({ value, onChange, label = 'Search', placeholder = 'Search…' }) {
  const id = useId()
  return (
    <div className="search-field search-field--compact">
      <label htmlFor={id} className="visually-hidden">{label}</label>
      <i className="bi bi-search search-field__icon" aria-hidden="true" />
      <input
        id={id}
        type="search"
        className="form-control"
        placeholder={placeholder}
        value={value}
        onChange={(event) => onChange(event.target.value)}
      />
    </div>
  )
}
