import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

export default function Auth({ register = false }) {
  const [showPassword, setShowPassword] = useState(false)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const auth = useAuth()
  const navigate = useNavigate()
  const location = useLocation()

  async function submit(event) {
    event.preventDefault()
    setMessage('')
    const values = Object.fromEntries(new FormData(event.currentTarget))
    if (register && values.password !== values.password_confirmation) {
      setMessage('As senhas precisam ser iguais.')
      return
    }
    setBusy(true)
    try {
      await auth[register ? 'register' : 'login'](values)
      navigate(location.state?.from || '/dashboard', { replace: true })
    } catch (error) {
      setMessage(Object.values(error.errors || {}).flat().join(' ') || error.message)
    } finally {
      setBusy(false)
    }
  }

  return <main id="main" className="auth-page container">
    <section className="auth-story">
      <span className="eyebrow">PARA QUEM FAZ A DIFERENÇA</span>
      <h1>Mais visibilidade.<br />Mais encontros.<br /><span>Mais lares.</span></h1>
      <p>Você dedica seu tempo a salvar vidas.<br />Nós ajudamos essas histórias a encontrarem<br />quem vai cuidar do próximo capítulo.</p>
      <div className="auth-quote">“Todo resgate merece<br />um final feliz.”<span>Esse é o nosso propósito. ♡</span></div>
    </section>
    <section className="auth-card">
      <span className="status">Portal de ONGs e protetores</span>
      <h2>{register ? 'Vamos transformar vidas?' : 'Que bom ter você aqui.'}</h2>
      <p className="muted">{register ? 'Crie o espaço da sua organização, gratuitamente.' : 'Entre para cuidar de novos encontros.'}</p>
      <div className="auth-tabs">
        <Link className={!register ? 'active' : ''} to="/login">Entrar</Link>
        <Link className={register ? 'active' : ''} to="/cadastro">Cadastrar ONG</Link>
      </div>
      <form onSubmit={submit}>
        {register && <>
          <label className="field">Nome da ONG ou protetor<input name="name" required maxLength={120} autoComplete="organization" /></label>
          <label className="field">Seu nome<input name="owner_name" required maxLength={120} autoComplete="name" /></label>
          <label className="field">Tipo<select name="type"><option value="ngo">ONG</option><option value="independent_protector">Protetor independente</option></select></label>
          <label className="field">Cidade<input name="city" required maxLength={100} autoComplete="address-level2" /></label>
          <label className="field">Estado (UF)<input name="state" required minLength={2} maxLength={2} autoComplete="address-level1" placeholder="SP" /></label>
        </>}
        <label className="field">E-mail<input name="email" type="email" required maxLength={254} autoComplete="email" /></label>
        <label className="field">Senha<div className="password-input">
          <input name="password" type={showPassword ? 'text' : 'password'} required minLength={8} autoComplete={register ? 'new-password' : 'current-password'} />
          <button type="button" onClick={() => setShowPassword(value => !value)}>{showPassword ? 'Ocultar' : 'Mostrar'}</button>
        </div></label>
        {register && <>
          <label className="field">Confirme sua senha<input name="password_confirmation" type={showPassword ? 'text' : 'password'} required minLength={8} autoComplete="new-password" /></label>
          <label className="checkbox-label"><input type="checkbox" required name="responsible_adoption" value="1" /> Quero divulgar adoções responsáveis e manter os dados atualizados.</label>
        </>}
        <button className="button full-width" disabled={busy}>{busy ? 'Enviando…' : register ? 'Criar cadastro gratuito ↗' : 'Entrar no portal ↗'}</button>
        {message && <div className="form-alert" role="alert" aria-live="assertive"><span aria-hidden="true">!</span><p>{message}</p></div>}
      </form>
      <p className="small muted">Feito para conectar quem cuida com quem quer adotar.</p>
    </section>
  </main>
}
