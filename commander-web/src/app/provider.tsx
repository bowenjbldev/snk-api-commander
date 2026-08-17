import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ROLES } from '@services/api/mock/commander-mock';
import { createCommanderApi } from '@services/api/commanderApi';
import type { ApiContext, CommanderApiClient } from '@services/api/commanderApi';
import type { Operator, Store } from '@services/api/types';

const TOKEN_KEY = 'commander:token:v1';

/**
 * Rol del operador. Commander solo siembra `admin` y la API no expone catálogo de roles,
 * así que es fijo; el día que `GET /auth/me` devuelva el suyo, sale de la sesión.
 */
const ROLE = 'admin';

interface AppValue {
  store: Store;
  api: CommanderApiClient;
  role: string;
  token: string | null;
  operator: Operator | null;
  permissions: string[];
  can: (perm: string) => boolean;
  signIn: (token: string, operator: Operator) => void;
  signOut: () => void;
  /** Cambia en cada refresco global; `useResource` lo vigila para repetir su petición. */
  dataVersion: number;
  refresh: () => void;
}

const AppContext = createContext<AppValue | null>(null);

export function AppProvider({ children }: { children: ReactNode }) {
  const ctxRef = useRef<ApiContext>({ role: ROLE });
  const apiRef = useRef<ReturnType<typeof createCommanderApi> | null>(null);
  if (apiRef.current === null) apiRef.current = createCommanderApi(ctxRef.current);

  const [token, setToken] = useState<string | null>(() => sessionStorage.getItem(TOKEN_KEY));
  const [operator, setOperator] = useState<Operator | null>(
    () => apiRef.current!.store.operators[0] ?? null,
  );

  const [dataVersion, setDataVersion] = useState(0);
  const refresh = useCallback(() => setDataVersion((v) => v + 1), []);

  const signIn = useCallback((newToken: string, who: Operator) => {
    sessionStorage.setItem(TOKEN_KEY, newToken);
    setToken(newToken);
    setOperator(who);
  }, []);

  const signOut = useCallback(() => {
    sessionStorage.removeItem(TOKEN_KEY);
    setToken(null);
  }, []);

  const permissions = useMemo(() => apiRef.current!.client.permissions(), []);

  // La interfaz oculta lo no autorizado, pero el servidor sigue siendo la autoridad: el
  // mock rechaza igual con 403 si se llama a algo sin permiso.
  const can = useCallback((perm: string) => {
    const perms = ROLES[ROLE].perms;
    return perms.includes('*') || perms.includes(perm);
  }, []);

  const value = useMemo<AppValue>(
    () => ({
      store: apiRef.current!.store,
      api: apiRef.current!.client,
      role: ROLE,
      token,
      operator,
      permissions,
      can,
      signIn,
      signOut,
      dataVersion,
      refresh,
    }),
    [token, operator, permissions, can, signIn, signOut, dataVersion, refresh],
  );

  return <AppContext.Provider value={value}>{children}</AppContext.Provider>;
}

export function useApp(): AppValue {
  const value = useContext(AppContext);
  if (!value) throw new Error('useApp fuera de AppProvider');
  return value;
}
