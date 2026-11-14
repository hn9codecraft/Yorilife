import TableCard from './TableCard.jsx'

/**
 * Several standard list cards on one page, stacked full width with clear
 * space between them (e.g. Products > Category: Product Type, Product Group).
 * Each card is the same TableCard used by the Sales Office lists, with that
 * table's own buttons (e.g. Add) on the right of its header.
 *
 *   <TableSectionStack>
 *     <TableSection title="Product Type" count={3} actions={<button …>Add product type</button>}>
 *       <FilterBar … />   // search + Export CSV, like every list
 *       <DataTable … />
 *     </TableSection>
 *   </TableSectionStack>
 */
export default function TableSection({ title, count, actions, children, footer, className = '' }) {
  return (
    <TableCard title={title} count={count} toolbar={actions || null} footer={footer} className={className}>
      {children}
    </TableCard>
  )
}

/** Full-width list cards stacked top to bottom (gap: --table-section-gap). */
export function TableSectionStack({ children, className = '' }) {
  return <div className={`table-section-stack${className ? ` ${className}` : ''}`}>{children}</div>
}