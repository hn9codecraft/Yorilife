import BrandLogo from './BrandLogo.jsx'
import LoadingIndicator from './LoadingIndicator.jsx'
import ThemeToggle from './ThemeToggle.jsx'

// Full-page brand screen for auth states shown outside the ERP layout
// (checking session, server unreachable).
export default function AuthStatusScreen({ loading = false, icon, title, text, action }) {
  return (
    <main className="auth-page">
      <div className="auth-page__toolbar">
        <ThemeToggle />
      </div>
      <div className="auth-card auth-card--status">
        <BrandLogo className="auth-card__logo" />
        {loading ? (
          <LoadingIndicator label={title} />
        ) : (
          <div className="text-center" role="alert">
            {icon && <i className={`bi ${icon} auth-card__status-icon`} aria-hidden="true" />}
            <h1 className="auth-card__title">{title}</h1>
            {text && <p className="text-muted">{text}</p>}
            {action}
          </div>
        )}
      </div>
    </main>
  )
}
