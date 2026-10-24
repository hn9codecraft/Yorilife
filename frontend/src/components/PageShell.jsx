import PageHeader from './PageHeader.jsx'
import useRouteMeta from '../hooks/useRouteMeta.js'

// Standard page frame. Title, description and breadcrumbs come from the
// navigation config for the current URL, so pages only supply their content.
export default function PageShell({ actions, children }) {
  const { title, description, breadcrumbs } = useRouteMeta()

  return (
    <>
      <PageHeader title={title} subtitle={description} breadcrumbs={breadcrumbs} actions={actions} />
      {children}
    </>
  )
}
