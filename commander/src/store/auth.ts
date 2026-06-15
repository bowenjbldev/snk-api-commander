import { create } from 'zustand'
import { authApi, getToken, setToken, setUnauthorizedHandler, type User } from '../lib/api'

type AuthStatus = 'loading' | 'anon' | 'authed'

interface AuthState {
  status: AuthStatus
  user: User | null
  error: string | null
  /** On boot: validate any stored token via /auth/me, else go anonymous. */
  bootstrap: () => Promise<void>
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
}

export const useAuth = create<AuthState>((set) => ({
  status: 'loading',
  user: null,
  error: null,

  bootstrap: async () => {
    if (!getToken()) {
      set({ status: 'anon' })
      return
    }
    try {
      const { user } = await authApi.me()
      set({ status: 'authed', user })
    } catch {
      setToken(null)
      set({ status: 'anon', user: null })
    }
  },

  login: async (email, password) => {
    set({ error: null })
    try {
      const { token, user } = await authApi.login(email, password)
      setToken(token)
      set({ status: 'authed', user })
    } catch (e) {
      set({ error: e instanceof Error ? e.message : 'Login failed' })
      throw e
    }
  },

  logout: async () => {
    try {
      await authApi.logout()
    } catch {
      // best-effort; we drop the token regardless
    }
    setToken(null)
    set({ status: 'anon', user: null })
  },
}))

// Any 401 from the API client (expired/invalid token) → drop the session.
setUnauthorizedHandler(() => {
  setToken(null)
  useAuth.setState({ status: 'anon', user: null })
})
