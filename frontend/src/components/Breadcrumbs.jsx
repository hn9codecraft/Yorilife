import { Link } from 'react-router'

// Renders a breadcrumb trail: [{ label, to? }]. The last item is the current page.
export default function Breadcrumbs({ items }) {
  if (!items?.length) return null

  return (
    <nav aria-label="Breadcrumb">
      <ol className="breadcrumb">
        {items.map((crumb, index) => {
          const isLast = index === items.length - 1
          return (
            <li
              key={`${crumb.label}-${index}`}
              className={`breadcrumb-item${isLast ? ' active' : ''}`}
              aria-current={isLast ? 'page' : undefined}
            >
              {!isLast && crumb.to ? <Link to={crumb.to}>{crumb.label}</Link> : crumb.label}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
