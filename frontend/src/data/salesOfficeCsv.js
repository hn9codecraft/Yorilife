/** Export columns and file names for the Sales Office lists. */
export const SALES_OFFICE_CSV = {
  headquarters: {
    filename: 'headquarters',
    headers: ['Headquarters name', '1st line manager', 'State', 'Code', 'Headquarters address', 'Pincode', 'Phone number', 'Email'],
  },
  stockist: {
    filename: 'stockists',
    headers: [
      'Stockist name', 'Stockist code', 'Headquarter', 'State', 'Assigned to', 'Address', 'Pincode', 'Phone number', 'Email',
      'Drug licence number', 'Drug licence valid up to', 'Food licence number', 'Food licence valid up to',
      'FSSAI licence number', 'FSSAI licence valid up to',
    ],
  },
}
