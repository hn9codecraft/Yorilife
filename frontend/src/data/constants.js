// Domain constants shared by the Sales Office, Products, Sales and Import screens.
// Values marked "to confirm" are placeholders for business decisions that are
// still open — see docs/SPRINT_PLAN.md "Needs business clarification".

// ---- Sales Office hierarchy ------------------------------------------------

export const MANAGER_LEVELS = {
  1: { label: '1st Line Manager', plural: '1st Line Managers' },
  2: { label: '2nd Line Manager', plural: '2nd Line Managers' },
  3: { label: '3rd Line Manager', plural: '3rd Line Managers' },
}

// What a record can belong to. `level` is the parent's manager level.
// Top to bottom: 3rd line → 2nd line → 1st line → headquarters → stockist.
// A 3rd line manager has no parent. Stockists belong only to a headquarters.
export const PARENT_TYPES = {
  headquarters: { label: 'Headquarters' },
  manager1: { label: '1st Line Manager', level: 1 },
  manager2: { label: '2nd Line Manager', level: 2 },
  manager3: { label: '3rd Line Manager', level: 3 },
}

export const ALLOWED_PARENT_TYPES = {
  1: ['manager2'],
  2: ['manager3'],
  3: [],
}

// 28 states and 8 union territories. The API rejects any other value.
export const INDIAN_STATES = [
  'Andhra Pradesh',
  'Arunachal Pradesh',
  'Assam',
  'Bihar',
  'Chhattisgarh',
  'Goa',
  'Gujarat',
  'Haryana',
  'Himachal Pradesh',
  'Jharkhand',
  'Karnataka',
  'Kerala',
  'Madhya Pradesh',
  'Maharashtra',
  'Manipur',
  'Meghalaya',
  'Mizoram',
  'Nagaland',
  'Odisha',
  'Punjab',
  'Rajasthan',
  'Sikkim',
  'Tamil Nadu',
  'Telangana',
  'Tripura',
  'Uttar Pradesh',
  'Uttarakhand',
  'West Bengal',
  'Andaman and Nicobar Islands',
  'Chandigarh',
  'Dadra and Nagar Haveli and Daman and Diu',
  'Delhi',
  'Jammu and Kashmir',
  'Ladakh',
  'Lakshadweep',
  'Puducherry',
]

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

// ---- Audit -----------------------------------------------------------------

export const CHANGED_BY_UNAVAILABLE = 'Not recorded'
