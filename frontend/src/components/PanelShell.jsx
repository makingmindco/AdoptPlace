import { useState } from 'react'
import { Link, NavLink } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Brand } from './Layout'
import '../panel.css'

export function PanelIcon({ name = 'grid' }) {
  const paths = { grid: 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z', heart: 'M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z', shield: 'M12 3 3 7v5c0 5 9 9 9 9s9-4 9-9V7L12 3Z M8 12l3 3 5-6', arrow: 'M7 17 17 7 M7 7h10v10', message: 'M21 11.5a8.5 8.5 0 0 1-8.5 8.5H4l-1 1v-9.5a8.5 8.5 0 1 1 18 0Z M7 9h10 M7 13h6', person: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z M4 21v-2a8 8 0 0 1 16 0v2', exit: 'M9 4H4v16h5 M13 8l4 4-4 4 M8 12h13' }
  return <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={paths[name] || paths.grid} /></svg>
}

export default function PanelShell({ title, subtitle, children, action }) {
  const { user, logout } = useAuth()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const admin = user?.role === 'admin'
  async function signOut() {
    setBusy(true)
    try { await logout() } catch { setError('Não foi possível sair. Tente novamente.'); setBusy(false) }
  }
  return <div className="workspace">
    <aside className="workspace-sidebar"><Brand /><span className="workspace-label">{admin ? 'CENTRAL DE SUPORTE' : 'SEU ESPAÇO'}</span>
      <nav aria-label="Navegação do painel">
        {admin && <NavLink to="/administracao"><PanelIcon name="shield" />Administração</NavLink>}
        {!admin && user?.role !== 'adopter' && <NavLink to="/dashboard"><PanelIcon />Painel da organização</NavLink>}
        <NavLink to="/minha-conta"><PanelIcon name="person" />Minha conta</NavLink>
        <Link to="/#catalogo"><PanelIcon name="heart" />Catálogo de animais<PanelIcon name="arrow" /></Link>
      </nav>
      <div className="workspace-help"><PanelIcon name="heart" /><strong>Cada cuidado importa.</strong><p>{admin ? 'Seu trabalho mantém os encontros seguros e a comunidade acolhedora.' : 'Um espaço para acompanhar encontros que transformam vidas.'}</p></div>
      <div className="workspace-user"><span className="workspace-avatar">{user?.name?.slice(0, 1).toUpperCase()}</span><div><strong>{user?.name}</strong><small>{admin ? 'Administrador' : user?.role === 'adopter' ? 'Adotante' : 'Organização / protetor'}</small></div><button type="button" onClick={signOut} disabled={busy} aria-label="Sair da conta"><PanelIcon name="exit" /></button></div>
    </aside>
    <div className="workspace-body"><div className="workspace-topbar"><span>AdoptPlace <span>/</span> {admin ? 'Gestão da plataforma' : 'Área pessoal'}</span><Link to="/">Visitar plataforma <PanelIcon name="arrow" /></Link></div>
      <main id="main" className="workspace-main"><header className="workspace-heading"><div><span className="workspace-label">{admin ? 'CUIDADO QUE CONECTA' : 'SEUS PRÓXIMOS ENCONTROS'}</span><h1>{title}</h1><p>{subtitle}</p></div>{action}</header>{error && <p role="alert" className="workspace-feedback">{error}</p>}{children}</main>
      <footer className="workspace-footer"><span>AdoptPlace · Adoção responsável</span><span>Feito para aproximar vidas.</span></footer></div>
  </div>
}
