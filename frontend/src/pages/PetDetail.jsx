import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { pets } from '../data/pets'
import { FavoriteButton } from '../components/PetCard'
export default function PetDetail() {
  const { id } = useParams()
  const pet = pets.find(item => item.id === id)
  const [message, setMessage] = useState('')
  if (!pet) return <main id="main" className="container empty"><h1>Esse bichinho não foi encontrado</h1><Link className="button" to="/">Explorar catálogo</Link></main>
  async function share() {
    try { await navigator.clipboard.writeText(window.location.href); setMessage('Link copiado! Compartilhe essa história.') } catch { setMessage('Copie o endereço desta página para compartilhar.') }
  }
  return <main id="main" className="container detail"><div className="detail-top"><Link to="/#catalogo">← Voltar para o catálogo</Link><button className="text-button" onClick={share}>Compartilhar ↗</button></div><p className="notice">Perfil demonstrativo. As informações e a ONG são fictícias; o contato será habilitado com os cadastros reais.</p><div className="detail-grid"><div><div className="detail-photo"><img src={pet.image} alt={`${pet.name}, ${pet.species.toLowerCase()} para adoção`} /><FavoriteButton pet={pet} /></div><section className="detail-story"><span className="eyebrow">UM POUQUINHO SOBRE MIM</span><h2>Oi, eu sou {pet.name}!</h2><p>{pet.description}</p><div className="tags">{pet.personality.map(tag => <span key={tag}>{tag}</span>)}</div><h3>Cuidados de saúde</h3><div className="health">{pet.health.map(item => <span key={item}>✓ {item}</span>)}</div><p className="muted">Confirme o histórico e as necessidades de saúde diretamente com a ONG antes da adoção.</p></section></div><aside className="adoption-card"><span className="status">● À espera de uma família</span><h1>{pet.name}</h1><p className="muted">⌖ {pet.city}</p><div className="pet-facts">{[['Espécie', pet.species], ['Idade', pet.age], ['Porte', pet.size], ['Sexo', pet.sex]].map(([label, value]) => <div key={label}><small>{label}</small><strong>{value}</strong></div>)}</div><hr /><h3>Um lar é um compromisso para a vida.</h3><p>Converse com quem cuida de {pet.name} e descubra se vocês combinam.</p><button className="button full-width" disabled>Contato disponível em breve</button><p className="small muted">A adoção é gratuita e segue os critérios de cada ONG.</p><div className="organization"><span className="org-avatar">♡</span><div><strong>Amigos dos Bichos</strong><small>ONG demonstrativa · {pet.city}</small></div></div><Link className="text-link" to="/#como-funciona">Saiba como funciona a adoção →</Link></aside></div><p role="status" className="feedback">{message}</p></main>
}
