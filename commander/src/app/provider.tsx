import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ROLES } from '@services/api/mock/commander-mock';
import { createCommanderApi } from '@services/api/commanderApi';
import type { ApiContext, CommanderApiClient } from '@services/api/commanderApi';
import type { Operator, Store } from '@services/api/types';

const TOKEN_KEY = 'commander:token:v1';
const ROLE_KEY = 'commander:role:v1';

export interface Simulation {
  role: string;
  latency: 'normal' | 'slow';
  fail: boolean;
}

interface AppValue {
  store: Store;
  api: CommanderApiClient;
  sim: Simulation;
  setSim: (patch: Partial<Simulation>) => void;
  token: string | null;
  operator: Operator | null;
  permissions: string[];
  can: (perm: string) => boolean;
  signIn: (token: string, operator: Operator) => void;
  signOut: () => void;
}

const AppContext = createContext<AppValue | null>(null);

export function AppProvider({ children }: { children: ReactNode }) {
  // `ctx` se muta en caliente (rol, latencia, fallo) y el cliente lo lee en cada
  // llamada: por eso se muta este objeto en vez de sustituirlo.
  const ctxRef = useRef<ApiContext>({ latency: 'normal', fail: false, role: 'admin' });
  const apiRef = useRef<ReturnType<typeof createCommanderApi> | null>(null);
  if (apiRef.current === null) apiRef.current = createCommanderApi(ctxRef.current);

  const [sim, setSimState] = useState<Simulation>({ ...ctxRef.current });
  const [token, setToken] = useState<string | null>(() => sessionStorage.getItem(TOKEN_KEY));
  const [operator, setOperator] = useState<Operator | null>(
    () => apiRef.current!.store.operators[0] ?? null,
  );

  const setSim = useCallback((patch: Partial<Simulation>) => {
    Object.assign(ctxRef.current, patch);
    if (patch.role) sessionStorage.setItem(ROLE_KEY, patch.role);
    setSimState({ ...ctxRef.current });
  }, []);

  const signIn = useCallback((newToken: string, who: Operator) => {
    sessionStorage.setItem(TOKEN_KEY, newToken);
    setToken(newToken);
    setOperator(who);
  }, []);

  const signOut = useCallback(() => {
    sessionStorage.removeItem(TOKEN_KEY);
    setToken(null);
  }, []);

  const permissions = useMemo(() => apiRef.current!.client.permissions(), [sim.role]);

  const can = useCallback(
    (perm: string) => {
      const perms = ROLES[sim.role].perms;
      return perms.includes('*') || perms.includes(perm);
    },
    [sim.role],
  );

  const value = useMemo<AppValue>(
    () => ({
      store: apiRef.current!.store,
      api: apiRef.current!.client,
      sim,
      setSim,
      token,
      operator,
      permissions,
      can,
      signIn,
      signOut,
    }),
    [sim, setSim, token, operator, permissions, can, signIn, signOut],
  );

  return <AppContext.Provider value={value}>{children}</AppContext.Provider>;
}

export function useApp(): AppValue {
  const value = useContext(AppContext);
  if (!value) throw new Error('useApp fuera de AppProvider');
  return value;
}
