import { useCallback, useEffect, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import LoadingIndicator from '../../components/LoadingIndicator.jsx'
import FormModal from '../../components/FormModal.jsx'
import Modal from '../../components/Modal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import PasswordInput from '../../components/PasswordInput.jsx'
import FormField, { SelectField, TextField } from '../../components/FormField.jsx'
import useAuth from '../../auth/useAuth.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import { api } from '../../api/client.js'
import { formatDateTime, matchesSearch } from '../../data/selectors.js'

const FALLBACK_MIN_PASSWORD = 12

function statusMeta(status) {
  if (status === 'locked') return { variant: 'warning', label: 'Locked' }
  if (status === 'active') return { variant: 'success', label: 'Active' }
  return { variant: 'secondary', label: 'Disabled' }
}

function UserForm({ formId, record, roles, isSelf, canAssignRole, minPassword, passwordHelp, onSaved }) {
  const isNew = !record
  // Roles the actor may assign, plus the user's current role so it still displays.
  const roleOptions = roles
    .filter((r) => r.assignable || r.slug === record?.role.slug)
    .map((r) => ({ value: r.slug, label: r.name }))
  const roleLocked = !isNew && (isSelf || !canAssignRole || !roles.find((r) => r.slug === record.role.slug)?.assignable)

  const form = useFormState(
    { name: record?.name ?? '', email: record?.email ?? '', role: record?.role.slug ?? '', password: '' },
    (v) => {
      const errors = {}
      if (!v.name.trim()) errors.name = 'Enter a name.'
      if (!v.email.trim()) errors.email = 'Enter an email address.'
      if (isNew && !v.role) errors.role = 'Choose a role.'
      if (isNew && v.password.length < minPassword) errors.password = `Use at least ${minPassword} characters.`
      return errors
    },
  )
  const [serverErrors, setServerErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [saving, setSaving] = useState(false)
  const errors = { ...serverErrors, ...form.errors }

  async function save(values) {
    if (saving) return
    setSaving(true)
    setServerErrors({})
    setFormError(null)
    const body = isNew
      ? { name: values.name, email: values.email, role: values.role, password: values.password }
      : Object.fromEntries(
          [['name', values.name], ['email', values.email], !roleLocked && ['role', values.role]]
            .filter(Boolean)
            .filter(([key, value]) => value !== (key === 'role' ? record.role.slug : record[key])),
        )
    if (!isNew && Object.keys(body).length === 0) {
      onSaved(record)
      return
    }
    try {
      const data = await api(isNew ? '/api/users' : `/api/users/${record.id}`, { method: isNew ? 'POST' : 'PATCH', body })
      onSaved(data.user)
    } catch (error) {
      setSaving(false)
      if (Object.keys(error.fields ?? {}).length) setServerErrors(error.fields)
      else setFormError(error.message)
    }
  }

  return (
    <form id={formId} className="row g-3" noValidate onSubmit={form.handleSubmit(save)}>
      {formError && (
        <div className="col-12">
          <div className="alert alert-danger mb-0" role="alert">
            <i className="bi bi-exclamation-octagon-fill" aria-hidden="true" />
            <div>{formError}</div>
          </div>
        </div>
      )}
      <TextField id={form.fieldId('name')} label="Full name" required value={form.values.name} onChange={form.set('name')} error={errors.name} autoComplete="off" />
      <TextField id={form.fieldId('email')} type="email" label="Email (used to sign in)" required value={form.values.email} onChange={form.set('email')} error={errors.email} autoComplete="off" spellCheck={false} />
      <SelectField
        id={form.fieldId('role')}
        label="Role"
        required={isNew}
        value={form.values.role}
        onChange={form.set('role')}
        options={roleOptions}
        placeholder={isNew ? 'Choose a role' : undefined}
        disabled={roleLocked}
        error={errors.role}
        help={roleLocked ? (isSelf ? 'You can’t change your own role.' : 'You can’t change this user’s role.') : undefined}
      />
      {isNew && (
        <FormField id={form.fieldId('password')} label="Initial password" required error={errors.password} help={passwordHelp || `At least ${minPassword} characters. Share it with the user securely.`}>
          <PasswordInput
            id={form.fieldId('password')}
            value={form.values.password}
            onChange={form.set('password')}
            invalid={Boolean(errors.password)}
            autoComplete="new-password"
            aria-describedby={`${form.fieldId('password')}-help${errors.password ? ` ${form.fieldId('password')}-error` : ''}`}
          />
        </FormField>
      )}
    </form>
  )
}

function UserDetails({ user, isSelf }) {
  const rows = [
    ['Name', user.name],
    ['Email', user.email],
    ['Role', user.role.name],
    ['Status', <StatusBadge key="s" variant={statusMeta(user.status).variant}>{statusMeta(user.status).label}</StatusBadge>],
    ['Authenticator', user.totpEnabled ? 'On' : 'Off'],
    ['Last sign-in', formatDateTime(user.lastLoginAt)],
    ['Created', formatDateTime(user.createdAt)],
    ['Last updated', formatDateTime(user.updatedAt)],
  ]
  return (
    <>
      {isSelf && <p className="text-muted">This is your account.</p>}
      <dl className="detail-list">
        {rows.map(([label, value]) => (
          <div key={label} className="detail-list__row">
            <dt>{label}</dt>
            <dd>{value}</dd>
          </div>
        ))}
      </dl>
    </>
  )
}

export default function UsersPage() {
  const auth = useAuth()
  const editor = useEditor()
  const [state, setState] = useState({ status: 'loading', users: [], roles: [], passwordPolicy: null, error: null })
  const [query, setQuery] = useState('')
  const [viewing, setViewing] = useState({ open: false, user: null })
  const [statusChange, setStatusChange] = useState({ open: false, user: null, next: 'disabled' })
  const [resetUser, setResetUser] = useState(null)
  const [resetPassword, setResetPassword] = useState('')
  const [resetError, setResetError] = useState(null)
  const [resetting, setResetting] = useState(false)
  const [notice, setNotice] = useState(null)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    Promise.all([api('/api/users', { signal: controller.signal }), api('/api/roles', { signal: controller.signal })])
      .then(([usersData, rolesData]) => setState({ status: 'ready', users: usersData.users, roles: rolesData.roles, passwordPolicy: usersData.passwordPolicy ?? null, error: null }))
      .catch((error) => {
        if (error.name !== 'AbortError') setState((s) => ({ ...s, status: 'error', error }))
      })
    return () => controller.abort()
  }, [reloadKey])

  const reload = () => {
    setState((s) => ({ ...s, status: 'loading' }))
    setReloadKey((k) => k + 1)
  }

  const upsertUser = useCallback((user) => {
    setState((s) => ({
      ...s,
      users: s.users.some((u) => u.id === user.id) ? s.users.map((u) => (u.id === user.id ? user : u)) : [...s.users, user],
    }))
  }, [])

  const can = auth.hasPermission
  const isSelf = (user) => user.id === auth.user.id
  // UI hint only: the server re-checks every change.
  const manageable = (user) => isSelf(user) || Boolean(state.roles.find((r) => r.slug === user.role.slug)?.assignable)

  const minPassword = state.passwordPolicy?.minLength ?? FALLBACK_MIN_PASSWORD

  async function confirmStatusChange() {
    const user = statusChange.user
    const status = statusChange.next
    try {
      const data = await api(`/api/users/${user.id}`, { method: 'PATCH', body: { status } })
      upsertUser(data.user)
      const label = status === 'active' ? 'active' : status === 'locked' ? 'locked' : 'disabled'
      setNotice({ variant: 'success', text: `${data.user.name} is now ${label}.` })
    } catch (error) {
      setNotice({ variant: 'danger', text: error.message })
    }
  }

  const rows = state.users
    .filter((u) => matchesSearch(u, query, ['name', 'email']))
    .sort((a, b) => a.name.localeCompare(b.name))

  const columns = [
    {
      key: 'name',
      header: 'Name',
      render: (u) => (
        <>
          <strong className="fw-semibold">{u.name}</strong>
          {isSelf(u) && <> <span className="badge text-primary-emphasis bg-primary-subtle">You</span></>}
        </>
      ),
    },
    { key: 'email', header: 'Email' },
    { key: 'role', header: 'Role', nowrap: true, render: (u) => u.role.name },
    {
      key: 'status',
      header: 'Status',
      render: (u) => {
        const meta = statusMeta(u.status)
        return <StatusBadge variant={meta.variant}>{meta.label}</StatusBadge>
      },
    },
    { key: 'lastLoginAt', header: 'Last sign-in', nowrap: true, render: (u) => formatDateTime(u.lastLoginAt) },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      nowrap: true,
      render: (u) => (
        <div className="table-actions">
          <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setViewing({ open: true, user: u })}>
            <i className="bi bi-eye" aria-hidden="true" /> View<span className="visually-hidden"> {u.name}</span>
          </button>
          {(can('users.update') || can('users.assign_role')) && manageable(u) && (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => editor.openEdit(u)}>
              <i className="bi bi-pencil" aria-hidden="true" /> Edit<span className="visually-hidden"> {u.name}</span>
            </button>
          )}
          {can('users.update') && !isSelf(u) && manageable(u) && (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => { setResetUser(u); setResetPassword(''); setResetError(null) }}>
              <i className="bi bi-key" aria-hidden="true" /> Reset password<span className="visually-hidden"> for {u.name}</span>
            </button>
          )}
          {can('users.change_status') && !isSelf(u) && manageable(u) && u.totpEnabled && (
            <button
              type="button"
              className="btn btn-sm btn-outline-secondary"
              onClick={async () => {
                if (!window.confirm(`Turn off the authenticator for ${u.name}? They will be signed out and must set it up again.`)) return
                try {
                  const data = await api(`/api/users/${u.id}/totp/reset`, { method: 'POST', body: {} })
                  upsertUser(data.user)
                  setNotice({ variant: 'success', text: `Authenticator turned off for ${data.user.name}. Their sessions and device keys were signed out.` })
                } catch (error) {
                  setNotice({ variant: 'danger', text: error.message })
                }
              }}
            >
              <i className="bi bi-shield-x" aria-hidden="true" /> Reset authenticator<span className="visually-hidden"> for {u.name}</span>
            </button>
          )}
          {can('users.change_status') && !isSelf(u) && manageable(u) && u.status !== 'disabled' && (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setStatusChange({ open: true, user: u, next: 'disabled' })}>
              <i className="bi bi-person-slash" aria-hidden="true" /> Disable<span className="visually-hidden"> {u.name}</span>
            </button>
          )}
          {can('users.change_status') && !isSelf(u) && manageable(u) && u.status === 'disabled' && (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setStatusChange({ open: true, user: u, next: 'active' })}>
              <i className="bi bi-person-check" aria-hidden="true" /> Activate<span className="visually-hidden"> {u.name}</span>
            </button>
          )}
          {can('users.change_status') && !isSelf(u) && manageable(u) && u.status !== 'locked' && (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setStatusChange({ open: true, user: u, next: 'locked' })}>
              <i className="bi bi-lock" aria-hidden="true" /> Lock<span className="visually-hidden"> {u.name}</span>
            </button>
          )}
          {can('users.change_status') && !isSelf(u) && manageable(u) && u.status === 'locked' && (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setStatusChange({ open: true, user: u, next: 'active' })}>
              <i className="bi bi-unlock" aria-hidden="true" /> Unlock<span className="visually-hidden"> {u.name}</span>
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <PageShell
      actions={
        can('users.create') && (
          <button type="button" className="btn btn-primary" onClick={editor.openNew} disabled={state.status !== 'ready'}>
            <i className="bi bi-person-plus" aria-hidden="true" /> Add user
          </button>
        )
      }
    >
      {notice && (
        <div className={`alert alert-${notice.variant} alert-dismissible`} role={notice.variant === 'danger' ? 'alert' : 'status'}>
          <i className={`bi ${notice.variant === 'danger' ? 'bi-exclamation-octagon-fill' : 'bi-check-circle-fill'}`} aria-hidden="true" />
          <div>{notice.text}</div>
          <button type="button" className="btn-close" aria-label="Dismiss" onClick={() => setNotice(null)} />
        </div>
      )}

      <TableCard
        title={`Users (${state.users.length})`}
        toolbar={<SearchInput value={query} onChange={setQuery} label="Search users" placeholder="Search name or email" />}
      >
        {state.status === 'loading' && <LoadingIndicator label="Loading users…" />}
        {state.status === 'error' && (
          <div className="card-body">
            <div className="alert alert-danger mb-0" role="alert">
              <i className="bi bi-exclamation-octagon-fill" aria-hidden="true" />
              <div>
                {state.error.message}{' '}
                <button type="button" className="btn btn-link p-0 align-baseline" onClick={reload}>Try again</button>
              </div>
            </div>
          </div>
        )}
        {state.status === 'ready' && (
          <DataTable caption="Users" columns={columns} rows={rows} emptyText={query ? 'No users match your search.' : 'No users yet.'} />
        )}
      </TableCard>

      <FormModal
        open={editor.open}
        onClose={editor.close}
        title={editor.record ? 'Edit user' : 'Add user'}
        submitLabel={editor.record ? 'Save' : 'Create user'}
        renderForm={(formId) => (
          <UserForm
            key={editor.session}
            formId={formId}
            record={editor.record}
            roles={state.roles}
            isSelf={editor.record ? isSelf(editor.record) : false}
            canAssignRole={can('users.assign_role')}
            minPassword={minPassword}
            passwordHelp={state.passwordPolicy?.summary}
            onSaved={(user) => {
              upsertUser(user)
              setNotice({ variant: 'success', text: editor.record ? `${user.name} was updated.` : `${user.name} was added.` })
              editor.close()
            }}
          />
        )}
      />

      <Modal open={viewing.open} onClose={() => setViewing((v) => ({ ...v, open: false }))} title={viewing.user ? viewing.user.name : 'User'}>
        {viewing.user && <UserDetails user={state.users.find((u) => u.id === viewing.user.id) ?? viewing.user} isSelf={isSelf(viewing.user)} />}
      </Modal>

      <ConfirmDialog
        open={statusChange.open}
        onClose={() => setStatusChange((s) => ({ ...s, open: false }))}
        onConfirm={confirmStatusChange}
        title={statusChange.next === 'locked' ? 'Lock user?' : statusChange.next === 'disabled' ? 'Disable user?' : 'Activate user?'}
        confirmLabel={statusChange.next === 'locked' ? 'Lock' : statusChange.next === 'disabled' ? 'Disable' : 'Activate'}
        variant={statusChange.next === 'active' ? 'primary' : 'danger'}
      >
        {statusChange.user && (
          <p className="mb-0">
            {statusChange.next === 'locked' && <><strong>{statusChange.user.name}</strong> will be signed out of the web and their device keys will stop working until the account is unlocked.</>}
            {statusChange.next === 'disabled' && <><strong>{statusChange.user.name}</strong> will be signed out, their device keys will stop working, and they won’t be able to sign in until reactivated.</>}
            {statusChange.next === 'active' && <><strong>{statusChange.user.name}</strong> will be able to sign in again.</>}
          </p>
        )}
      </ConfirmDialog>

      <FormModal
        open={Boolean(resetUser)}
        onClose={() => { if (!resetting) setResetUser(null) }}
        title={resetUser ? `Reset password for ${resetUser.name}` : 'Reset password'}
        submitLabel={resetting ? 'Saving…' : 'Reset password'}
        renderForm={(formId) => (
          <form
            id={formId}
            className="row g-3"
            onSubmit={async (event) => {
              event.preventDefault()
              if (!resetUser || resetting) return
              if (resetPassword.length < minPassword) {
                setResetError(`Use at least ${minPassword} characters.`)
                return
              }
              setResetting(true)
              setResetError(null)
              try {
                const data = await api(`/api/users/${resetUser.id}/password`, { method: 'POST', body: { password: resetPassword } })
                upsertUser(data.user)
                setNotice({ variant: 'success', text: `Password reset for ${data.user.name}. Their current sessions and device keys have been signed out. Share the new password securely. It is not stored in the audit log.` })
                setResetUser(null)
                setResetPassword('')
              } catch (error) {
                setResetError(error.fields?.password || error.message)
              } finally {
                setResetting(false)
              }
            }}
          >
            {resetError && <div className="col-12"><div className="alert alert-danger mb-0" role="alert">{resetError}</div></div>}
            <FormField id="reset-password" label="New password" required error={null} help={state.passwordPolicy?.summary || `At least ${minPassword} characters. It is not shown again and is not written to the audit log.`}>
              <PasswordInput id="reset-password" value={resetPassword} onChange={setResetPassword} autoComplete="new-password" />
            </FormField>
          </form>
        )}
      />
    </PageShell>
  )
}
