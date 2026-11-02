import { useMemo, useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import useErpData from '../../data/useErpData.js'
import { MANAGER_LEVELS } from '../../data/constants.js'
import { buildSalesOfficeForest, matchesSearch, unplacedSalesOfficeRecords } from '../../data/selectors.js'

const LEVEL_PATH = {
  1: '/sales-office/first-line-managers',
  2: '/sales-office/second-line-managers',
  3: '/sales-office/third-line-managers',
}

function NodeLabel({ type, name, code, to, count, countLabel }) {
  return (
    <span className="hierarchy-tree__node">
      <span className="hierarchy-tree__type">{type}</span>
      <Link className="hierarchy-tree__name" to={to}>{name}</Link>
      {code && <span className="hierarchy-tree__code">{code}</span>}
      {countLabel && (
        <span className="badge text-secondary-emphasis bg-secondary-subtle">{count} {countLabel}</span>
      )}
    </span>
  )
}

function textOf(record, extra = []) {
  return [record?.name, record?.code, ...extra].filter(Boolean).join(' ')
}

function filterForest(forest, query) {
  const q = query.trim()
  if (!q) return forest

  const hit = (record, extra) => matchesSearch({ name: textOf(record, extra) }, q)

  return forest.map((third) => {
    if (hit(third.manager)) return third
    const children = third.children.map((second) => {
      if (hit(second.manager)) return second
      const firsts = second.children.map((first) => {
        if (hit(first.manager)) return first
        const offices = first.headquarters.map((office) => {
          if (hit(office.headquarters, [office.headquarters.state])) return office
          const stockists = office.stockists.filter((stockist) => hit(stockist))
          return stockists.length ? { ...office, stockists } : null
        }).filter(Boolean)
        return offices.length ? { ...first, headquarters: offices } : null
      }).filter(Boolean)
      return firsts.length ? { ...second, children: firsts } : null
    }).filter(Boolean)
    return children.length ? { ...third, children } : null
  }).filter(Boolean)
}

export default function HierarchyPage() {
  const { state } = useErpData()
  const ready = state.status === 'ready'
  const [query, setQuery] = useState('')
  const forest = useMemo(() => buildSalesOfficeForest(state), [state])
  const unplaced = useMemo(() => unplacedSalesOfficeRecords(state), [state])
  const visible = useMemo(() => filterForest(forest, query), [forest, query])
  const hasUnplaced = unplaced.managers.length + unplaced.headquarters.length + unplaced.stockists.length > 0

  return (
    <PageShell>
      <DataStatusNotice />
      <SearchInput label="Search the sales office hierarchy" value={query} onChange={setQuery} placeholder="Search a manager, headquarters, or stockist" />
      {!ready ? null : visible.length === 0 && !hasUnplaced ? (
        <EmptyState
          title={query ? 'No matches' : 'No 3rd line managers yet'}
          text={query ? 'Try a different name, code or state.' : 'A 3rd line manager is the top of the sales office. Add one to start the hierarchy.'}
        />
      ) : (
        <div className="d-grid gap-3">
          {visible.map((third) => (
            <details key={third.manager.id} className="card" open>
              <summary className="card-header hierarchy-branch__summary">
                <NodeLabel
                  type={MANAGER_LEVELS[3].label}
                  name={third.manager.name}
                  code={third.manager.code}
                  to={LEVEL_PATH[3]}
                  count={third.children.length}
                  countLabel="2nd line"
                />
              </summary>
              <div className="card-body">
                {third.children.length === 0 ? (
                  <p className="text-muted mb-0">No 2nd line managers under this 3rd line manager yet.</p>
                ) : (
                  <ul className="hierarchy-tree">
                    {third.children.map((second) => (
                      <li key={second.manager.id}>
                        <details className="hierarchy-branch" open>
                          <summary>
                            <NodeLabel
                              type={MANAGER_LEVELS[2].label}
                              name={second.manager.name}
                              code={second.manager.code}
                              to={LEVEL_PATH[2]}
                              count={second.children.length}
                              countLabel="1st line"
                            />
                          </summary>
                          {second.children.length === 0 ? (
                            <p className="text-muted">No 1st line managers under this 2nd line manager yet.</p>
                          ) : (
                            <ul>
                              {second.children.map((first) => (
                                <li key={first.manager.id}>
                                  <details className="hierarchy-branch" open>
                                    <summary>
                                      <NodeLabel
                                        type={MANAGER_LEVELS[1].label}
                                        name={first.manager.name}
                                        code={first.manager.code}
                                        to={LEVEL_PATH[1]}
                                        count={first.headquarters.length}
                                        countLabel="headquarters"
                                      />
                                    </summary>
                                    {first.headquarters.length === 0 ? (
                                      <p className="text-muted">No headquarters under this 1st line manager yet.</p>
                                    ) : (
                                      <ul>
                                        {first.headquarters.map((office) => (
                                          <li key={office.headquarters.id}>
                                            <details className="hierarchy-branch" open>
                                              <summary>
                                                <NodeLabel
                                                  type="Headquarters"
                                                  name={office.headquarters.name}
                                                  code={office.headquarters.code}
                                                  to="/sales-office/headquarters"
                                                  count={office.stockists.length}
                                                  countLabel="stockists"
                                                />
                                                <span className="text-muted small ms-2">{office.headquarters.state}</span>
                                              </summary>
                                              {office.stockists.length === 0 ? (
                                                <p className="text-muted">No stockists mapped to this headquarters yet.</p>
                                              ) : (
                                                <ul className="hierarchy-stockists">
                                                  {office.stockists.map((stockist) => (
                                                    <li key={stockist.id}>
                                                      <i className="bi bi-shop" aria-hidden="true" />
                                                      <Link to="/sales-office/stockists">{stockist.name}</Link>
                                                      {stockist.code && <span className="hierarchy-tree__code">{stockist.code}</span>}
                                                    </li>
                                                  ))}
                                                </ul>
                                              )}
                                            </details>
                                          </li>
                                        ))}
                                      </ul>
                                    )}
                                  </details>
                                </li>
                              ))}
                            </ul>
                          )}
                        </details>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </details>
          ))}
          {hasUnplaced && !query && (
            <section className="card">
              <div className="card-header">Records not in the hierarchy</div>
              <div className="card-body">
                <p className="text-muted">These records do not have one unambiguous parent at the level above. They are listed here instead of being placed under a guessed parent.</p>
                <ul className="mb-0">
                  {unplaced.managers.map((manager) => <li key={manager.id}>{MANAGER_LEVELS[manager.level].label}: {manager.name}</li>)}
                  {unplaced.headquarters.map((office) => <li key={office.id}>Headquarters: {office.name}</li>)}
                  {unplaced.stockists.map((stockist) => <li key={stockist.id}>Stockist: {stockist.name}</li>)}
                </ul>
              </div>
            </section>
          )}
        </div>
      )}
    </PageShell>
  )
}
