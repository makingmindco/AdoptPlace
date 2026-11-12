import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { FormAlert, SubmitButton } from '../components/AuthControls'

export default function Auth() {
  const [showPassword, setShowPassword] = useState(false)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const auth = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const verified = new URLSearchParams(location.search).get('verified') === '1'

  async function submit(event) {
    event.preventDefault()
    if (busy) return
    setMessage('')
    const values = Object.fromEntries(new FormData(event.currentTarget))
    setBusy(true)
    try {
      const user = await auth.login(values)
      navigate(user.role === 'adopter' ? '/#catalogo' : location.state?.from || '/dashboard', { replace: true })
    } catch (error) {
      setMessage(Object.values(error.errors || {}).flat().join(' ') || error.message)
    } finally {
      setBusy(false)
    }
  }

  return <main id="main" className="auth-page container">
    <section className="auth-story">
      <span className="eyebrow">UM ENCONTRO MUDA DUAS VIDAS</span>
      <h1>Quem cuida.<br />Quem acolhe.<br /><span>Quem transforma.</span></h1>
      <p>Um lugar para encontrar seu novo companheiro e apoiar quem dedica a vida a cuidar dos animais.</p>
      <div className="auth-quote">“Todo resgate merece<br />um final feliz.”<span>Esse é o nosso propósito. ♡</span></div>
    </section>
    <section className="auth-card">
      <span className="status">Bem-vindo ao AdoptPlace</span>
      <h2>Que bom ter você aqui.</h2>
      <p className="muted">Entre para cuidar de novos encontros.</p>
      {verified && <p className="auth-notice" role="status">E-mail confirmado! Você já pode entrar na sua conta.</p>}
      <form onSubmit={submit} aria-busy={busy}>
        <label className="field">E-mail<input name="email" type="email" required maxLength={254} autoComplete="username" disabled={busy} /></label>
        <div className="field"><label htmlFor="login-password">Senha</label><div className="password-input">
          <input id="login-password" name="password" type={showPassword ? 'text' : 'password'} required autoComplete="current-password" disabled={busy} />
          <button type="button" aria-pressed={showPassword} onClick={() => setShowPassword(value => !value)}>{showPassword ? 'Ocultar' : 'Mostrar'}</button>
        </div></div>
        <FormAlert message={message} />
        <SubmitButton busy={busy || auth.loading} busyLabel="Entrando na sua conta…">Entrar na minha conta ↗</SubmitButton>
      </form>
      <p className="auth-switch">Não tem cadastro ainda? <Link to="/cadastro">Crie sua conta gratuitamente</Link>.</p>
      <p className="small muted">Para quem quer adotar, ONGs e protetores individuais.</p>
    </section>
  </main>
}
