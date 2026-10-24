// Domain constants shared by the Sales Office, Products, Sales and Import screens.
// Values marked "to confirm" are placeholders for business decisions that are
// still open — see docs/SPRINT_PLAN.md "Needs business clarification".

// ---- Sales Office hierarchy ------------------------------------------------

export const MANAGER_LEVELS = {
  1: { label: '1st Line Manager', plural: '1st Line Managers' },
  2: { label: '2nd Line Manager', plural: '2nd Line Managers' },
  3: { label: '3rd Line Manager', plural: '3rd Line Managers' },
}

// What a manager can report to. `level` = the parent's manager level.
export const PARENT_TYPES = {
  headquarters: { label: 'Headquarters' },
  manager1: { label: '1st Line Manager', level: 1 },
  manager2: { label: '2nd Line Manager', level: 2 },
}

// Allowed parent types per manager level (from the business requirements):
// 1st → Headquarters; 2nd → 1st Line Manager; 3rd → any one of the three.
export const ALLOWED_PARENT_TYPES = {
  1: ['headquarters'],
  2: ['manager1'],
  3: ['headquarters', 'manager1', 'manager2'],
}

// ---- Products --------------------------------------------------------------

// Tags, badges and categories are separate concepts that share one record shape.
export const TERM_KINDS = {
  groupCategory: { label: 'Product group category', plural: 'Product group categories', icon: 'bi-diagram-3' },
  category: { label: 'Product category', plural: 'Product categories', icon: 'bi-tags' },
  tag: { label: 'Tag', plural: 'Tags', icon: 'bi-tag' },
  badge: { label: 'Badge', plural: 'Badges', icon: 'bi-award' },
}

// ---- Master Product Sheet ---------------------------------------------------

// Example years from the requirements. How new years are added: to confirm.
export const MASTER_SHEET_YEARS = ['2024-2025', '2025-2026', '2026-2027']

export const INCLUSION_STATUS = {
  included: { label: 'Included', variant: 'success' },
  excluded: { label: 'Excluded', variant: 'secondary' },
  unset: { label: 'Not set', variant: 'warning' },
}

// ---- Imports ---------------------------------------------------------------

// Which datasets can be imported and their column templates: to confirm.
export const IMPORT_TYPES = [{ value: 'sales', label: 'Sales entries' }]

// Upload size limit: to confirm.
export const CSV_MAX_BYTES = 10 * 1024 * 1024

// ---- Audit -----------------------------------------------------------------

// There is no sign-in yet, so changes cannot be attributed to an administrator.
export const CHANGED_BY_UNAVAILABLE = 'Not available — sign-in not built yet'
