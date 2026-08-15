// Tipos del módulo de datos simulado. El .js se copia sin tocar desde `design/`.
import type {
  Account,
  Activity,
  Application,
  Client,
  Instance,
  Invoice,
  LogEntry,
  Measurement,
  Operator,
  Plan,
  Quota,
  Settings,
  Store,
  Subscription,
  SubscriptionItem,
  UsageBucket,
} from '../types';

export interface Catalog<T = Record<string, unknown>> {
  [key: string]: { label: string } & T;
}

export const PROVISIONING: Catalog<{ tone: string; sev: string }>;
export const SERVICE: Catalog<{ tone: string; note: string }>;
export const SUB_STATUS: Catalog<{ tone: string }>;
export const INVOICE_STATUS: Catalog<{ tone: string }>;
export const ENTITY_STATUS: Catalog<{ tone: string }>;
export const QUOTA_ACTION: Catalog<{ tone: string; help: string }>;
export const METRICS: Catalog<{
  name: string;
  unit: string;
  window: string;
  kind: string;
  meter: string;
}>;
export const ROLES: Catalog<{ perms: string[]; hint: string }>;

export function fmtNum(n: number | null | undefined): string;
export function fmtMoney(n: number, cur?: string): string;
export function fmtDateTime(s: string | null | undefined): string;
export function fmtDate(s: string | null | undefined): string;
export function fmtRel(s: string | null | undefined): string;
export function fmtMb(mb: number | null | undefined): string;
export function pct(v: number | null, l: number | null): number | null;
export function now(): Date;

export function createStore(): Store;

export interface ApiContext {
  latency: 'normal' | 'slow';
  fail: boolean;
  role: string;
}

export interface ApiError extends Error {
  status: number;
  fields: Record<string, string> | null;
}

export interface ProvisionPayload {
  mode: 'new_client' | 'extra_app' | 'group_item';
  application_id?: number | string;
  plan_id?: number | string;
  client_id?: number | string;
  subscription_id?: number | string;
  account_id?: number | string | null;
  account: Partial<Account>;
  client: Partial<Client>;
  admin: { name?: string; email?: string; password?: string };
  simulate_failure?: boolean;
}

export interface CommanderApi {
  can(perm: string): boolean;
  permissions(): string[];
  login(email: string, password: string): Promise<{ token: string; operator: Operator; role: string }>;
  list(coll: 'accounts'): Promise<Account[]>;
  list(coll: 'clients'): Promise<Client[]>;
  list(coll: 'applications'): Promise<Application[]>;
  list(coll: 'plans'): Promise<Plan[]>;
  list(coll: 'subscriptions'): Promise<Subscription[]>;
  list(coll: 'items'): Promise<SubscriptionItem[]>;
  list(coll: 'instances'): Promise<Instance[]>;
  list(coll: 'quotas'): Promise<Quota[]>;
  list(coll: 'invoices'): Promise<Invoice[]>;
  list(coll: 'activity'): Promise<Activity[]>;
  list(coll: 'logs'): Promise<LogEntry[]>;
  list(coll: 'usage'): Promise<UsageBucket[]>;
  list(coll: 'operators'): Promise<Operator[]>;
  get(coll: 'accounts', id: string | number): Promise<Account>;
  get(coll: 'clients', id: string | number): Promise<Client>;
  get(coll: 'applications', id: string | number): Promise<Application>;
  get(coll: 'plans', id: string | number): Promise<Plan>;
  get(coll: 'subscriptions', id: string | number): Promise<Subscription>;
  get(coll: 'instances', id: string | number): Promise<Instance>;
  get(coll: 'quotas', id: string | number): Promise<Quota>;
  get(coll: 'invoices', id: string | number): Promise<Invoice>;
  get(coll: 'activity', id: string | number): Promise<Activity>;
  get(coll: 'operators', id: string | number): Promise<Operator>;
  measurements(quotaId: string | number): Promise<Measurement[]>;
  instanceUsage(id: string | number): Promise<UsageBucket[]>;
  instanceLogs(id: string | number): Promise<LogEntry[]>;
  createAccount(data: Partial<Account>): Promise<Account>;
  createClient(data: Partial<Client>): Promise<Client>;
  savePlan(data: Partial<Plan> & { id?: number }): Promise<Plan>;
  createSubscription(data: Partial<Subscription>): Promise<Subscription>;
  updateSubscription(id: string | number, data: Partial<Subscription>): Promise<Subscription>;
  createInvoice(data: Partial<Invoice>): Promise<Invoice>;
  updateInvoice(id: string | number, data: Partial<Invoice>): Promise<Invoice>;
  updateQuota(id: string | number, data: Partial<Quota>): Promise<Quota>;
  createSharedQuota(data: Partial<Quota> & { subscription_id: number }): Promise<Quota>;
  saveSettings(data: Partial<Settings>): Promise<Settings>;
  provision(payload: ProvisionPayload): Promise<{
    accepted: boolean;
    instance: Instance;
    account: Account;
    client: Client;
    subscription: Subscription;
  }>;
  instanceAction(
    id: string | number,
    action: 'grace' | 'suspend' | 'resume' | 'change-plan' | 'retry' | 'deprovision',
    data?: Record<string, unknown>,
  ): Promise<Instance>;
}

export function createApi(store: Store, ctx: ApiContext): CommanderApi;
