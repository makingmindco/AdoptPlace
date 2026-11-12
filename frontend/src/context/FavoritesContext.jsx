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
  const [revision, setRevision] = useState(0)
  const [error, setError] = useState('')
  const pending = useRef(new Set())
  const favoritesRef = useRef(favorites)
  const initPromiseRef = useRef(null)

  useEffect(() => {
    favoritesRef.current = favorites
  }, [favorites])

  useEffect(() => {
    if (!user) return
    let active = true
    const loadPromise = api.favorites()
      .then(items => {
        const normalized = Array.isArray(items) ? items : []
        if (active) {
          favoritesRef.current = normalized
          setFavorites(normalized)
          setReady(true)
        }
        return normalized
      })
      .catch(() => {
        if (active) {
          setError('Não foi possível carregar seus favoritos. Atualize a página para tentar novamente.')
          setReady(true)
        }
        return []
      })
    initPromiseRef.current = loadPromise
    return () => { active = false }
  }, [user])

  async function toggle(id) {
    if (pending.current.has(id)) return
    pending.current.add(id)
    try {
      if (!ready && initPromiseRef.current) {
        await initPromiseRef.current
      }
      const selected = !favoritesRef.current.includes(id)
      if (user) await api.saveFavorite(id, selected)
      setFavorites(previous => {
        const next = selected ? [...new Set([...previous, id])] : previous.filter(item => item !== id)
        favoritesRef.current = next
        if (!user) { try { localStorage.setItem('adoptplace:favorites', JSON.stringify(next)) } catch { /* in-memory fallback */ } }
        return next
      })
      setRevision(value => value + 1)
      setError('')
    } catch { setError('Não foi possível salvar o favorito. Tente novamente.') }
    finally { pending.current.delete(id) }
  }
  return <FavoritesContext.Provider value={{ favorites, toggle, ready, revision }}>
    {error && <p className="container form-alert" role="alert">{error}</p>}{children}
  </FavoritesContext.Provider>
}
// eslint-disable-next-line react-refresh/only-export-components
export const useFavorites = () => useContext(FavoritesContext)
