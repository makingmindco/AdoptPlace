import { useState } from 'react'
import { Link, NavLink, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import logo from '../assets/adoptplace-logo.png'

export function Brand() {
  return <Link to="/" className="brand" aria-label="AdoptPlace, início"><img src={logo} alt="" /><span>adopt<span>place</span><small>ENCONTROS QUE TRANSFORMAM</small></span></Link>
}

export function Header() {
  const { pathname } = useLocation()
  const [open, setOpen] = useState(false)
  const { user, loading, logout } = useAuth()
  const [leaving, setLeaving] = useState(false)
  const [error, setError] = useState('')
  async function signOut() {
    setLeaving(true)
    setError('')
    try { await logout() } catch { setError('Não foi possível sair. Tente novamente.') } finally { setLeaving(false) }
  }
  if (['/administracao', '/minha-conta', '/dashboard'].includes(pathname)) return <a className="skip-link" href="#main">Pular para o conteúdo</a>
  return <><a className="skip-link" href="#main">Pular para o conteúdo</a><header className="header"><div className="container header-inner"><Brand /><nav className={open ? 'is-open' : ''} aria-label="Navegação principal"><NavLink onClick={() => setOpen(false)} to="/" end>Adotar</NavLink><Link onClick={() => setOpen(false)} to="/#como-funciona">Como funciona</Link><Link onClick={() => setOpen(false)} to="/cadastro">ONGs e protetores</Link></nav><div className="header-actions">{user && <Link to="/minha-conta" className="header-signout">Minha conta</Link>}{user?.role === 'admin' && <Link to="/administracao" className="header-signout">Moderação</Link>}{!loading && <Link className="button button-small button-outline" to={user ? user.role === 'adopter' ? '/#catalogo' : user.role === 'admin' ? '/administracao' : '/dashboard' : '/login'}>{user ? user.role === 'adopter' ? 'Explorar animais' : 'Meu painel' : 'Entrar'} <span aria-hidden="true">↗</span></Link>}{user && <button type="button" className="header-signout" disabled={leaving} onClick={signOut}>{leaving ? 'Saindo…' : 'Sair'}</button>}<button className="menu-button" type="button" aria-label={open ? 'Fechar menu' : 'Abrir menu'} aria-expanded={open} onClick={() => setOpen(!open)}><span /><span /></button></div></div>{error && <p className="container field-error" role="alert">{error}</p>}</header></>
}

export function Footer() {
  const { pathname } = useLocation()
  if (['/administracao', '/minha-conta', '/dashboard'].includes(pathname)) return null
  return <footer className="footer"><div className="container footer-main"><div><Brand /><p>Uma plataforma sem fins lucrativos para aproximar<br />quem precisa de um lar de quem tem amor para dar.</p></div><div className="footer-links"><strong>Explore</strong><Link to="/#catalogo">Encontre um pet</Link><Link to="/#como-funciona">Como funciona</Link></div><div className="footer-links"><strong>Para protetores</strong><Link to="/cadastro">Cadastrar organização</Link><Link to="/login">Acessar portal</Link></div></div><div className="container footer-bottom"><span>© AdoptPlace · Feito para transformar encontros em histórias.</span><span className="footer-signature">Adotar muda <em>duas</em> vidas. ♡</span></div></footer>
}
