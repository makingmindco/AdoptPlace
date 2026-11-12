import { createContext, useContext, useEffect, useRef, useState } from 'react'
import { useAuth } from './AuthContext'
import { api } from '../services/api'
const FavoritesContext = createContext(null)
function guestFavorites() {
  try { const items = JSON.parse(localStorage.getItem('adoptplace:favorites')); return Array.isArray(items) ? items.filter(id => typeof id === 'string') : [] } catch { return [] }
}
export function FavoritesProvider({ children }) {
  const { user } = useAuth()
  return <FavoritesState key={user?.id || 'guest'} user={user}>{children}</FavoritesState>
}
function FavoritesState({ user, children }) {
  const [favorites, setFavorites] = useState(() => user ? [] : guestFavorites())
  const [ready, setReady] = useState(!user)
  const [error, setError] = useState('')
  const pending = useRef(new Set())
  useEffect(() => {
    if (!user) return
    let active = true
    api.favorites().then(items => { if (active) { setFavorites(items); setReady(true) } }).catch(() => { if (active) setError('Não foi possível carregar seus favoritos. Atualize a página para tentar novamente.') })
    return () => { active = false }
  }, [user])
  async function toggle(id) {
    if (!ready || pending.current.has(id)) return
    pending.current.add(id)
    const selected = !favorites.includes(id)
    try {
      if (user) await api.saveFavorite(id, selected)
      setFavorites(previous => {
        const next = selected ? [...new Set([...previous, id])] : previous.filter(item => item !== id)
        if (!user) { try { localStorage.setItem('adoptplace:favorites', JSON.stringify(next)) } catch { /* in-memory fallback */ } }
        return next
      })
      setError('')
    } catch { setError('Não foi possível salvar o favorito. Tente novamente.') }
    finally { pending.current.delete(id) }
  }
  return <FavoritesContext.Provider value={{ favorites, toggle, ready }}>
    {error && <p className="container form-alert" role="alert">{error}</p>}{children}
  </FavoritesContext.Provider>
}
// eslint-disable-next-line react-refresh/only-export-components
export const useFavorites = () => useContext(FavoritesContext)
