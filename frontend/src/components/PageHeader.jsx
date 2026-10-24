import Breadcrumbs from './Breadcrumbs.jsx'

// Standard page heading: optional breadcrumb, title, subtitle and action buttons.
export default function PageHeader({ title, subtitle, breadcrumbs = [], actions }) {
  return (
    <header className="page-header">
      <div className="min-w-0">
        <Breadcrumbs items={breadcrumbs} />
        <h1 className="page-header__title">{title}</h1>
        {subtitle && <p className="page-header__subtitle">{subtitle}</p>}
      </div>
      {actions && <div className="page-header__actions">{actions}</div>}
    </header>
  )
}
