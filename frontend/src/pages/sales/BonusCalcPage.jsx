import PageShell from '../../components/PageShell.jsx'
import EmptyState from '../../components/EmptyState.jsx'

// Sales Management > Bonus Calc. Placeholder until the bonus calculation is defined.
export default function BonusCalcPage() {
  return (
    <PageShell>
      <EmptyState
        titleAs="h2"
        icon="bi-calculator"
        title="Bonus Calc is coming soon"
        text="The bonus calculation will appear here once it is defined."
      />
    </PageShell>
  )
}