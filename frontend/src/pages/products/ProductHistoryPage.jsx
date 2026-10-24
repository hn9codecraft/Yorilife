import { useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import PreviewNotice from '../../components/PreviewNotice.jsx'
import TableCard from '../../components/TableCard.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import ProductHistoryTable from '../../components/ProductHistoryTable.jsx'
import useErpData from '../../data/useErpData.js'

// Every product create / update / delete recorded in this session.
export default function ProductHistoryPage() {
  const { state } = useErpData()
  const [query, setQuery] = useState('')
  const q = query.trim().toLowerCase()
  const entries = state.productHistory.filter((e) => !q || e.productName.toLowerCase().includes(q))

  return (
    <PageShell>
      <PreviewNotice />
      <TableCard
        title={`Changes (${state.productHistory.length})`}
        toolbar={<SearchInput value={query} onChange={setQuery} label="Search by product" placeholder="Search product" />}
      >
        <ProductHistoryTable
          entries={entries}
          emptyText={q ? 'No changes for that product.' : 'No product changes yet. Changes appear here when products are added, edited or deleted.'}
        />
      </TableCard>
    </PageShell>
  )
}
