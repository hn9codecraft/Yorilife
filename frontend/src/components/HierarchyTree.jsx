import { MANAGER_LEVELS } from '../data/constants.js'

function NodeLabel({ type, name, code }) {
  return (
    <span className="hierarchy-tree__node">
      <span className="hierarchy-tree__type">{type}</span>
      <span className="hierarchy-tree__name">{name}</span>
      {code && <span className="hierarchy-tree__code">{code}</span>}
    </span>
  )
}

function nest(managers, leaf) {
  if (managers.length === 0) return leaf
  const [manager, ...rest] = managers
  return (
    <li>
      <NodeLabel type={MANAGER_LEVELS[manager.level].label} name={manager.name} code={manager.code} />
      <ul>{nest(rest, leaf)}</ul>
    </li>
  )
}

/**
 * One branch from the top down: 3rd line, 2nd line, 1st line, headquarters, stockists.
 * `branch` is the result of buildHeadquartersBranch().
 */
export default function HierarchyTree({ branch, highlightStockistId }) {
  if (!branch?.headquarters) return null
  const { managers, headquarters, stockists } = branch
  const leaf = (
    <li>
      <NodeLabel type="Headquarters" name={headquarters.name} code={headquarters.code} />
      {stockists.length > 0 ? (
        <ul className="hierarchy-stockists">
          {stockists.map((stockist) => {
            const highlighted = stockist.id === highlightStockistId
            return (
              <li key={stockist.id} className={highlighted ? 'is-highlighted' : undefined} aria-current={highlighted ? 'true' : undefined}>
                <i className="bi bi-shop" aria-hidden="true" />
                <span>{stockist.name}</span>
                {stockist.code && <span className="hierarchy-tree__code">{stockist.code}</span>}
                {highlighted && <span className="badge text-primary-emphasis bg-primary-subtle">This stockist</span>}
              </li>
            )
          })}
        </ul>
      ) : (
        <p className="text-muted mb-0">No stockists mapped to this headquarters yet.</p>
      )}
    </li>
  )

  return <ul className="hierarchy-tree">{nest(managers, leaf)}</ul>
}
