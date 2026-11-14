/** Full name from the two optional parts. Empty parts are left out. */
export function fullName(first, last) {
  return [first, last].map((part) => (part ?? '').trim()).filter(Boolean).join(' ')
}

/** List, search, and manager labels: full name, then code, then a numbered fallback. */
export function employeeLabel(person) {
  if (!person) return 'employee'
  return fullName(person.firstName, person.lastName) || person.name || person.employeeCode || (person.id ? `Employee ${person.id}` : 'employee')
}

/**
 * Headquarters from the State List / Headquarter List.
 * With no state selected, the toolbar shows every headquarters.
 * The employee form passes requireState so the list stays empty until a state is chosen.
 */
export function headquartersChoices(locations, stateIds, { requireState = false } = {}) {
  const selected = (Array.isArray(stateIds) ? stateIds : (stateIds ? [stateIds] : []))
    .map((id) => String(id))
    .filter(Boolean)
  if (requireState && selected.length === 0) return []

  const choices = []
  for (const location of locations ?? []) {
    if (selected.length > 0 && !selected.includes(String(location.id))) continue
    for (const office of location.headquarters ?? []) {
      choices.push({
        value: String(office.id),
        label: selected.length === 1 ? office.name : `${office.name} (${location.name})`,
        locationId: String(location.id),
      })
    }
  }
  return choices.sort((a, b) => a.label.localeCompare(b.label))
}

/** Drop headquarters whose state is no longer selected. */
export function headquartersInStates(headquartersIds, locations, stateIds) {
  const allowed = new Set(headquartersChoices(locations, stateIds, { requireState: true }).map((choice) => choice.value))
  return (headquartersIds ?? []).map(String).filter((id) => allowed.has(id))
}
