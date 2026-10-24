import { useId } from 'react'
import { MANAGER_LEVELS } from '../data/constants.js'

function ManagerNode({ node }) {
  const { manager, children } = node
  return (
    <li>
      <span className="hierarchy-tree__node">
        <span className="hierarchy-tree__type">{MANAGER_LEVELS[manager.level].label}</span>
        <span className="hierarchy-tree__name">{manager.name}</span>
        {manager.code && <span className="hierarchy-tree__code">{manager.code}</span>}
      </span>
      {children.length > 0 && (
        <ul>
          {children.map((child) => <ManagerNode key={child.manager.id} node={child} />)}
        </ul>
      )}
    </li>
  )
}

/**
 * Renders the result of buildHeadquartersTree(): the headquarters, every
 * manager linked under it (at any level), and the stockists mapped to it.
 * `highlightStockistId` marks one stockist (used by the stockist view).
 */
export default function HierarchyTree({ tree, highlightStockistId }) {
  const headingId = useId()
  const { headquarters, managers, stockists } = tree
  if (!headquarters) return null

  return (
    <div className="hierarchy">
      <section aria-labelledby={`${headingId}-managers`}>
        <h3 id={`${headingId}-managers`} className="eyebrow mb-2">Management hierarchy</h3>
        <ul className="hierarchy-tree">
          <li>
            <span className="hierarchy-tree__node hierarchy-tree__node--root">
              <span className="hierarchy-tree__type">Headquarters</span>
              <span className="hierarchy-tree__name">{headquarters.name}</span>
              {headquarters.code && <span className="hierarchy-tree__code">{headquarters.code}</span>}
            </span>
            {managers.length > 0 ? (
              <ul>
                {managers.map((node) => <ManagerNode key={node.manager.id} node={node} />)}
              </ul>
            ) : (
              <p className="hierarchy-tree__empty">No managers linked to this headquarters yet.</p>
            )}
          </li>
        </ul>
      </section>

      <section aria-labelledby={`${headingId}-stockists`} className="mt-4">
        <h3 id={`${headingId}-stockists`} className="eyebrow mb-2">Stockists mapped to {headquarters.name} ({stockists.length})</h3>
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
      </section>
    </div>
  )
}
