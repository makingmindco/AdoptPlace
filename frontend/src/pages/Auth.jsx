import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../services/api'
export default function Auth({ register = false }) {
  const [showPassword, setShowPassword] = useState(false)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const enabled = import.meta.env.VITE_AUTH_ENABLED === 'true'
  async function submit(event) {
    event.preventDefault()
    setMessage('')
    const values = Object.fromEntries(new FormData(event.currentTarget))
    if (register && values.password !== values.password_confirmation) { setMessage('As senhas precisam ser iguais.'); return }
    if (!enabled) { setMessage('O portal está em preparação. Nenhum dado foi enviado ou salvo. Conheça o painel demonstrativo abaixo.'); return }
    setBusy(true)
    try {
      await api[register ? 'register' : 'login'](values)
      setMessage(register ? 'Cadastro recebido. A integração de sessão será concluída na etapa do backend.' : 'Dados validados pelo servidor. A integração de sessão será concluída na etapa do backend.')
    } catch (error) { setMessage(Object.values(error.errors || {}).flat().join(' ') || error.message) }
    finally { setBusy(false) }
  }
  return <main id="main" className="auth-page container"><section className="auth-story"><span className="eyebrow">PARA QUEM FAZ A DIFERENÇA</span><h1>Mais visibilidade.<br />Mais encontros.<br /><span>Mais lares.</span></h1><p>Você dedica seu tempo a salvar vidas.<br />Nós ajudamos essas histórias a encontrarem<br />quem vai cuidar do próximo capítulo.</p><div className="auth-quote">“Todo resgate merece<br />um final feliz.”<span>Esse é o nosso propósito. ♡</span></div></section><section className="auth-card"><span className="status">Portal de ONGs e protetores</span><h2>{register ? 'Vamos transformar vidas?' : 'Que bom ter você aqui.'}</h2><p className="muted">{register ? 'Crie o espaço da sua organização, gratuitamente.' : 'Entre para cuidar de novos encontros.'}</p><div className="auth-tabs"><Link className={!register ? 'active' : ''} to="/login">Entrar</Link><Link className={register ? 'active' : ''} to="/cadastro">Cadastrar ONG</Link></div><form onSubmit={submit}>{register && <><label className="field">Nome da ONG ou protetor<input name="name" required autoComplete="organization" maxLength={120} placeholder="Como sua organização se chama?" /></label><label className="field">Cidade e estado<input name="city" required autoComplete="address-level2" maxLength={120} placeholder="Ex.: São Paulo, SP" /></label></>}<label className="field">E-mail<input name="email" type="email" required autoComplete="email" maxLength={254} placeholder="voce@suaong.org" /></label><label className="field">Senha<div className="password-input"><input name="password" type={showPassword ? 'text' : 'password'} required minLength={8} autoComplete={register ? 'new-password' : 'current-password'} placeholder={register ? 'Pelo menos 8 caracteres' : 'Sua senha'} /><button type="button" aria-label={showPassword ? 'Ocultar senha' : 'Mostrar senha'} aria-pressed={showPassword} onClick={() => setShowPassword(!showPassword)}>{showPassword ? 'Ocultar' : 'Mostrar'}</button></div></label>{register && <><label className="field">Confirme sua senha<input name="password_confirmation" type={showPassword ? 'text' : 'password'} required minLength={8} autoComplete="new-password" placeholder="Digite sua senha novamente" /></label><label className="checkbox-label"><input type="checkbox" required name="responsible_adoption" /> Quero divulgar adoções responsáveis e manter as informações dos animais atualizadas.</label></>}<p className="notice small">Portal em preparação. Login e cadastro dependem da integração com o backend.</p><button className="button full-width" disabled={busy}>{busy ? 'Enviando…' : register ? 'Criar cadastro gratuito ↗' : 'Entrar no portal ↗'}</button><p className="feedback" role="status">{message}</p></form><Link to="/dashboard" className="text-link">Conhecer o painel demonstrativo →</Link><p className="small muted">Feito para conectar quem cuida com quem quer adotar.</p></section></main>
}
