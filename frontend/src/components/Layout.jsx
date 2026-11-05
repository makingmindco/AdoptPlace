import { useState } from 'react'
import { Link, NavLink } from 'react-router-dom'
import logo from '../assets/adoptplace-logo.png'
export function Brand() {
  return <Link to="/" className="brand" aria-label="AdoptPlace, início"><img src={logo} alt="" /><span>adopt<span>place</span><small>ENCONTROS QUE TRANSFORMAM</small></span></Link>
}
export function Header() {
  const [open, setOpen] = useState(false)
  return <><a className="skip-link" href="#main">Pular para o conteúdo</a><header className="header"><div className="container header-inner"><Brand /><nav className={open ? 'is-open' : ''} aria-label="Navegação principal"><NavLink onClick={() => setOpen(false)} to="/" end>Adotar</NavLink><Link onClick={() => setOpen(false)} to="/#como-funciona">Como funciona</Link><Link onClick={() => setOpen(false)} to="/cadastro">Para ONGs</Link></nav><div className="header-actions"><Link className="button button-small button-outline" to="/login">Portal da ONG <span aria-hidden="true">↗</span></Link><button className="menu-button" type="button" aria-label={open ? 'Fechar menu' : 'Abrir menu'} aria-expanded={open} onClick={() => setOpen(!open)}><span /><span /></button></div></div></header></>
}
export function Footer() {
  return <footer className="footer"><div className="container footer-main"><div><Brand /><p>Uma plataforma sem fins lucrativos para aproximar<br />quem precisa de um lar de quem tem amor para dar.</p></div><div className="footer-links"><strong>Explore</strong><Link to="/#catalogo">Encontre um pet</Link><Link to="/#como-funciona">Como funciona</Link></div><div className="footer-links"><strong>Para protetores</strong><Link to="/cadastro">Cadastrar organização</Link><Link to="/login">Acessar portal</Link></div></div><div className="container footer-bottom"><span>© AdoptPlace · Feito para transformar encontros em histórias.</span><span className="footer-signature">Adotar muda <em>duas</em> vidas. ♡</span></div></footer>
}
