/**
 * One metric on a dashboard row. Label, a large value, an optional unit and
 * an optional muted line. Shared by any page that shows a few figures together.
 *
 *   <StatCardGroup label="Grand total">
 *     <StatCard label="OP" value="0.00" hint="3 products" />
 *     <StatCard label="Bonus" value="₹ 0.00" hint="3 products" accent />
 *   </StatCardGroup>
 */
export function StatCard({ label, value, unit = null, hint = null, accent = false }) {
  return (
    <article className={`stat-card${accent ? ' stat-card--accent' : ''}`} role="listitem">
      <p className="stat-card__label">{label}</p>
      <p className="stat-card__value">
        <span className="stat-card__number">{value}</span>
        {unit ? <span className="stat-card__unit">{unit}</span> : null}
      </p>
      {hint ? <p className="stat-card__hint">{hint}</p> : null}
    </article>
  )
}

export function StatCardGroup({ label, children }) {
  return (
    <div className="stat-card-group" role="list" aria-label={label}>
      {children}
    </div>
  )
}
