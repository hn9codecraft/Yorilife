import ExportCsvButton from './ExportCsvButton.jsx'
import { SALES_OFFICE_CSV } from '../data/salesOfficeCsv.js'

/**
 * Export the rows currently shown on a Sales Office list (State, Headquarter,
 * Employee, Stockist). Columns and file names live in data/salesOfficeCsv.js;
 * the button, audit record and masking come from ExportCsvButton.
 */
export default function SalesOfficeCsv({ module, records }) {
  const spec = SALES_OFFICE_CSV[module]
  return <ExportCsvButton name={spec.filename} headers={spec.headers} records={records} />
}