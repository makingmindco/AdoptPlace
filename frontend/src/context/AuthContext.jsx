import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { api } from '../services/api'

const AuthContext = createContext(null)
const AUTH_SYNC_KEY = 'adoptplace:auth-sync'

// eslint-disable-next-line react-refresh/only-export-components
export function defaultPathForUser(user) {
  if (!user) return '/login'
  if (user.role === 'admin') return '/administracao'
  if (user.role === 'adopter') return '/animais'
  return '/dashboard'
}

// eslint-disable-next-line react-refresh/only-export-components
export function resolvePostAuthDestination(user, from) {
  const fallback = defaultPathForUser(user)
  if (typeof from !== 'string' || !from.startsWith('/') || from.startsWith('//')) {
    return fallback
  }

  const normalized = from === '/#catalogo' || from.startsWith('/#catalogo?') ? '/animais' : from
  const pathname = normalized.split('?')[0].split('#')[0]

  if (['/login', '/cadastro', '/recuperar-senha', '/redefinir-senha'].includes(pathname)) {
    return fallback
  }

  if (pathname.startsWith('/administracao')) {
    return user?.role === 'admin' ? normalized : fallback
  }

  if (pathname.startsWith('/dashboard')) {
    return user?.role === 'organization_owner' ? normalized : fallback
  }

  if (pathname.startsWith('/minha-conta')) {
    return user?.role === 'adopter' ? normalized : fallback
  }

  return normalized
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)
  const [loggingOut, setLoggingOut] = useState(false)
  const lastRefreshAtRef = useRef(0)

  const refreshUser = useCallback(async ({ force = true } = {}) => {
    const now = Date.now()
    if (!force && now - lastRefreshAtRef.current < 5000) {
      return null
    }
    lastRefreshAtRef.current = now
    try {
      const fresh = await api.me()
      setUser(current => (JSON.stringify(current) === JSON.stringify(fresh) ? current : fresh))
      return fresh
    } catch {
      setUser(null)
      return null
    }
  }, [])

  const notifyEmailVerified = useCallback(() => {
    try {
      if (typeof BroadcastChannel !== 'undefined') {
        const channel = new BroadcastChannel('adoptplace-auth')
        channel.postMessage({ type: 'email-verified', at: Date.now() })
        channel.close()
      }
      if (typeof window !== 'undefined' && window.localStorage) {
        window.localStorage.setItem(AUTH_SYNC_KEY, String(Date.now()))
      }
    } catch {
      // Ignore storage or broadcast errors in restricted environments
    }
  }, [])

  useEffect(() => {
    api.me().then(setUser).catch(() => setUser(null)).finally(() => setLoading(false))
  }, [])

  const userId = user?.id
  const userVerifiedAt = user?.email_verified_at

  useEffect(() => {
    if (!userId || userVerifiedAt) return undefined

    const handlePassiveSync = () => {
      refreshUser({ force: false })
    }
    const handleVisibility = () => {
      if (document.visibilityState === 'visible') {
        refreshUser({ force: false })
      }
    }
    const handleStorage = event => {
      if (event.key === AUTH_SYNC_KEY) {
        refreshUser({ force: true })
      }
    }

    let channel = null
    try {
      if (typeof BroadcastChannel !== 'undefined') {
        channel = new BroadcastChannel('adoptplace-auth')
        channel.onmessage = event => {
          if (event?.data?.type === 'email-verified') {
            refreshUser({ force: true })
          }
        }
      }
    } catch {
      channel = null
    }

    window.addEventListener('focus', handlePassiveSync)
    window.addEventListener('storage', handleStorage)
    document.addEventListener('visibilitychange', handleVisibility)

    return () => {
      window.removeEventListener('focus', handlePassiveSync)
      window.removeEventListener('storage', handleStorage)
      document.removeEventListener('visibilitychange', handleVisibility)
      channel?.close()
    }
  }, [userId, userVerifiedAt, refreshUser])

  const value = useMemo(() => ({
    user,
    loading,
    loggingOut,
    refreshUser,
    notifyEmailVerified,
    async login(credentials) {
      const result = await api.login(credentials)
      setLoggingOut(false)
      setUser(result)
      return result
    },
    async register(payload) {
      const result = await api.register(payload)
      setLoggingOut(false)
      setUser(result)
      return result
    },
    async logout() {
      setLoggingOut(true)
      await api.logout()
      if (typeof window !== 'undefined' && window.history?.state?.usr) {
        window.history.replaceState({ ...window.history.state, usr: null }, '')
      }
      setUser(null)
    },
  }), [user, loading, loggingOut, refreshUser, notifyEmailVerified])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth precisa estar dentro de AuthProvider')
  return context
}
