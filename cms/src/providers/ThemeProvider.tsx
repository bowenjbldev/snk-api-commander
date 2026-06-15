import {
  createContext,
  useCallback,
  useContext,
  useLayoutEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { GlobalTheme } from '@carbon/react'

export type ThemeName = 'g100' | 'g10'

const STORAGE_KEY = 'snakecloud-cms.theme'

interface ThemeContextValue {
  theme: ThemeName
  toggle: () => void
  setTheme: (t: ThemeName) => void
}

const ThemeContext = createContext<ThemeContextValue | null>(null)

function readInitial(): ThemeName {
  try {
    return localStorage.getItem(STORAGE_KEY) === 'g10' ? 'g10' : 'g100'
  } catch {
    return 'g100'
  }
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setTheme] = useState<ThemeName>(readInitial)

  // Apply the active theme as a Carbon zone class on <body> so the whole page
  // (background included) picks up the token set. Carbon emits .cds--g100 /
  // .cds--g10 zone classes from @carbon/styles. useLayoutEffect runs before paint
  // to avoid a flash. GlobalTheme below provides the React context Carbon reads.
  useLayoutEffect(() => {
    const body = document.body
    body.classList.remove('cds--g100', 'cds--g10')
    body.classList.add(`cds--${theme}`)
    try {
      localStorage.setItem(STORAGE_KEY, theme)
    } catch {
      /* ignore */
    }
  }, [theme])

  const toggle = useCallback(() => {
    setTheme((t) => (t === 'g100' ? 'g10' : 'g100'))
  }, [])

  const value = useMemo(() => ({ theme, toggle, setTheme }), [theme, toggle])

  return (
    <ThemeContext.Provider value={value}>
      <GlobalTheme theme={theme}>{children}</GlobalTheme>
    </ThemeContext.Provider>
  )
}

export function useAppTheme(): ThemeContextValue {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useAppTheme must be used within ThemeProvider')
  return ctx
}
