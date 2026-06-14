import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { Theme } from '@carbon/react'

export type ThemeName = 'g100' | 'g10'
const STORAGE_KEY = 'snakecloud-theme'

interface ThemeCtx {
  theme: ThemeName
  setTheme: (t: ThemeName) => void
  toggle: () => void
}

const Ctx = createContext<ThemeCtx | null>(null)

function readInitial(): ThemeName {
  try {
    const v = localStorage.getItem(STORAGE_KEY)
    if (v === 'g10' || v === 'g100') return v
  } catch {
    /* ignore */
  }
  return 'g100' // default: dark
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setThemeState] = useState<ThemeName>(readInitial)

  const setTheme = useCallback((t: ThemeName) => {
    setThemeState(t)
    try {
      localStorage.setItem(STORAGE_KEY, t)
    } catch {
      /* ignore */
    }
  }, [])

  const toggle = useCallback(
    () => setTheme(theme === 'g100' ? 'g10' : 'g100'),
    [theme, setTheme],
  )

  // Keep the document background in sync with the active theme zone.
  useEffect(() => {
    document.documentElement.classList.toggle('cds--g100', theme === 'g100')
    document.documentElement.classList.toggle('cds--g10', theme === 'g10')
  }, [theme])

  const value = useMemo(
    () => ({ theme, setTheme, toggle }),
    [theme, setTheme, toggle],
  )

  return (
    <Ctx.Provider value={value}>
      <Theme theme={theme} className="sc-app" style={{ minHeight: '100vh' }}>
        {children}
      </Theme>
    </Ctx.Provider>
  )
}

export function useTheme(): ThemeCtx {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useTheme must be used within ThemeProvider')
  return ctx
}
