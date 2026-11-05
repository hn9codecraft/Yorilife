/** Export columns and file names for the Sales Office lists. */
export const SALES_OFFICE_CSV = {
  'manager-3': {
    filename: '3rd-line-managers',
    headers: ['Name', 'Employee Code', 'Email', 'Phone Number', 'State List'],
  },
  'manager-2': {
    filename: '2nd-line-managers',
    headers: ['Name', 'Employee Code', 'Email', 'Phone Number', 'Reports To', 'State List'],
  },
  'manager-1': {
    filename: '1st-line-managers',
    headers: ['Name', 'Employee Code', 'Reports To'],
  },
  headquarters: {
    filename: 'headquarters',
    headers: ['Headquarters name', '1st line manager', 'State', 'Code', 'Headquarters address', 'Pincode', 'Phone number', 'Email'],
  },
  stockist: {
    filename: 'stockists',
    headers: ['Stockist name', 'Stockist code', 'Headquarters', 'State', 'Address', 'Pincode', 'Phone number', 'Email'],
  },
}
