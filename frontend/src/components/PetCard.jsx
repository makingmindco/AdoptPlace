import { Link } from 'react-router-dom'
import { useFavorites } from '../context/FavoritesContext'
export function FavoriteButton({ pet }) {
  const { favorites, toggle } = useFavorites()
  const selected = favorites.includes(pet.id)
  return <button className={`favorite ${selected ? 'selected' : ''}`} aria-label={`${selected ? 'Remover' : 'Adicionar'} ${pet.name} ${selected ? 'dos' : 'aos'} favoritos`} aria-pressed={selected} onClick={() => toggle(pet.id)}><span aria-hidden="true">{selected ? '♥' : '♡'}</span></button>
}
export default function PetCard({ pet }) {
  return <article className="pet-card"><div className="pet-photo"><Link to={`/pets/${pet.id}`} aria-label={`Conhecer ${pet.name}`}><img src={pet.image} alt={`${pet.species} ${pet.name} esperando uma família`} loading="lazy" /></Link><span className="photo-tag"><i /> À espera de um lar</span><FavoriteButton pet={pet} /></div><div className="pet-card-body"><div className="row"><h3><Link to={`/pets/${pet.id}`}>{pet.name}</Link></h3><span className="sex">{pet.sex === 'Macho' ? '♂' : '♀'} {pet.sex}</span></div><p className="muted">{pet.age} · Porte {pet.size.toLowerCase()}</p><div className="tags">{pet.personality.slice(0, 2).map(tag => <span key={tag}>{tag}</span>)}</div><div className="card-bottom"><span>⌖ {pet.city}</span><Link to={`/pets/${pet.id}`}><span>Conhecer</span> ↗</Link></div></div></article>
}
