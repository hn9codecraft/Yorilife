/** Export columns and file names for the Sales Office lists (see components/SalesOfficeCsv.jsx). */
export const SALES_OFFICE_CSV = {
  state: {
    filename: 'states',
    headers: ['State name', 'Location code', 'Headquarters'],
  },
  headquarter: {
    filename: 'headquarters',
    headers: ['Headquarter name', 'State', 'State code', 'Stockists', 'Employees', 'Last updated'],
  },
  employee: {
    filename: 'employees',
    headers: [
      'First name', 'Last name', 'Employee code', 'Designation', 'States', 'Headquarters', 'Phone number', 'Email', 'Reports to',
    ],
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