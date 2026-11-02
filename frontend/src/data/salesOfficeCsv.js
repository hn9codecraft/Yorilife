/** Column templates for the five Sales Office lists. Headers are matched without regard to case. */
export const SALES_OFFICE_CSV = {
  'manager-3': {
    filename: '3rd-line-managers',
    headers: ['Name', 'Employee Code', 'Email', 'Phone Number', 'State List'],
    required: ['Name', 'Employee Code', 'State List'],
  },
  'manager-2': {
    filename: '2nd-line-managers',
    headers: ['Name', 'Employee Code', 'Email', 'Phone Number', 'Reports To', 'State List'],
    required: ['Name', 'Employee Code', 'Reports To', 'State List'],
  },
  'manager-1': {
    filename: '1st-line-managers',
    headers: ['Name', 'Employee Code', 'Reports To'],
    required: ['Name', 'Employee Code', 'Reports To'],
  },
  headquarters: {
    filename: 'headquarters',
    headers: ['Headquarters name', '1st line manager', 'State', 'Code', 'Headquarters address', 'Pincode', 'Phone number', 'Email'],
    required: ['Headquarters name', '1st line manager', 'State', 'Headquarters address', 'Pincode', 'Phone number'],
  },
  stockist: {
    filename: 'stockists',
    headers: ['Stockist name', 'Stockist code', 'Headquarters', 'State', 'Address', 'Pincode', 'Phone number', 'Email'],
    required: ['Stockist name', 'Headquarters'],
  },
}

export function missingCsvHeaders(header, required) {
  const present = new Set(header.map((name) => name.trim().toLowerCase()))
  return required.filter((name) => !present.has(name.toLowerCase()))
}
