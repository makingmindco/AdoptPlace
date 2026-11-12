import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '../services/api'
import PasswordStrength from '../components/PasswordStrength'
import { FormAlert, SubmitButton } from '../components/AuthControls'

export default function PasswordRecovery({ reset = false }) {
  const [params] = useSearchParams()
  const [password, setPassword] = useState('')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  async function submit(event) {
    event.preventDefault(); setBusy(true); setError(''); setMessage('')
    const data = Object.fromEntries(new FormData(event.currentTarget))
    try { const result = await api[reset ? 'resetPassword' : 'forgotPassword']({ ...data, token: params.get('token') }); setMessage(result.message) } catch (err) { setError(Object.values(err.errors || {}).flat().join(' ') || err.message) } finally { setBusy(false) }
  }
  return <main id="main" className="container detail"><section className="auth-card recovery-card"><h1>{reset ? 'Crie uma nova senha' : 'Recuperar acesso'}</h1><p>{reset ? 'Escolha uma senha para voltar à sua conta.' : 'Enviaremos um link de recuperação para seu e-mail.'}</p>
    {message ? <p role="status" className="auth-notice">{message}</p> : <form onSubmit={submit}><label className="field">E-mail<input name="email" type="email" required defaultValue={params.get('email') || ''} autoComplete="email" /></label>
      {reset && <><label className="field">Nova senha<input name="password" type="password" required minLength={8} autoComplete="new-password" value={password} onChange={event => setPassword(event.target.value)} /></label><PasswordStrength password={password} /><label className="field">Confirme a nova senha<input name="password_confirmation" type="password" required minLength={8} autoComplete="new-password" /></label></>}
      <FormAlert message={error} /><SubmitButton busy={busy} busyLabel="Enviando…">{reset ? 'Atualizar senha' : 'Enviar link de recuperação'}</SubmitButton></form>}
    <p className="auth-switch"><Link to="/login">Voltar para entrar</Link></p>
  </section></main>
}
