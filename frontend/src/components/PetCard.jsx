import { Link } from 'react-router-dom'
import { useFavorites } from '../context/FavoritesContext'

export function FavoriteButton({ pet }) {
  const { favorites, toggle } = useFavorites()
  const selected = favorites.includes(pet.id)
  return <button className={`favorite ${selected ? 'selected' : ''}`} aria-label={`${selected ? 'Remover' : 'Adicionar'} ${pet.name} ${selected ? 'dos' : 'aos'} favoritos`} aria-pressed={selected} onClick={() => toggle(pet.id)}><span aria-hidden="true">{selected ? '♥' : '♡'}</span></button>
}

export default function PetCard({ pet }) {
  const slug = pet.slug || pet.id
  const image = pet.cover_photo || pet.photos?.[0]?.url || pet.image
  const sex = { male: 'Macho', female: 'Fêmea', unknown: 'A confirmar' }[pet.sex] || pet.sex
  const size = { small: 'pequeno', medium: 'médio', large: 'grande' }[pet.size] || pet.size?.toLowerCase()
  const city = pet.location?.city || pet.city
  const age = pet.approximate_age || pet.age || 'Idade a confirmar'
  return <article className="pet-card"><div className="pet-photo"><Link to={`/pets/${slug}`} aria-label={`Conhecer ${pet.name}`}>{image ? <img src={image} alt={`${pet.name} esperando uma família`} loading="lazy" /> : <span className="empty">Foto em breve</span>}</Link><span className="photo-tag"><i /> À espera de um lar</span><FavoriteButton pet={pet} /></div><div className="pet-card-body"><div className="row"><h3><Link to={`/pets/${slug}`}>{pet.name}</Link></h3><span className="sex">{sex === 'Macho' ? '♂' : '♀'} {sex}</span></div><p className="muted">{age} · Porte {size}</p><div className="tags">{(pet.personality || []).slice(0, 2).map(tag => <span key={tag}>{tag}</span>)}</div><div className="card-bottom"><span>⌖ {city}</span><Link to={`/pets/${slug}`}><span>Conhecer</span> ↗</Link></div></div></article>
}
