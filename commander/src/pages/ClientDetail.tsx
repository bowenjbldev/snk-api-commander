import { useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { Button, InlineNotification, TextInput, TextArea, Select, SelectItem, Toggle, OverflowMenu, OverflowMenuItem, MenuButton, MenuItem, MenuItemDivider } from '@carbon/react'
import { Box, Money, Activity, MeterAlt, Save } from '@carbon/icons-react'
import { Breadcrumbs } from '../app/Breadcrumbs'
import { useStore, useClient, useProvisionedPackages } from '../store/store'
import { useCan } from '../store/auth'
import { useToast } from '../providers/ToastProvider'
import { KpiCluster, KpiTile } from '../components/KpiCluster'
import { Card } from '../components/Card'
import { EmptyState } from '../components/EmptyState'
import { RelativeTime } from '../components/RelativeTime'
import { ClientStatusTag, PackageStatusTag, BillingTag } from '../components/tags/StatusTag'
import { ProvisionModal } from '../components/modals/ProvisionModal'
import { InvoiceFormModal } from '../components/modals/InvoiceFormModal'
import { formatMonthYear, formatRelative } from '../lib/time'
import { formatCurrency, formatCurrencyShort, formatCompact, formatPercent } from '../lib/format'
import type { Client, Invoice, Package, Subscription, PaymentTerms } from '../store/types'

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

// Dedicated client record page (/clients/:id): header + KPIs, then a live view+edit form on the
// left and a compact Packages/Invoices rail on the right. Replaces the old drawer + edit modal.
export function ClientDetail() {
  const { id } = useParams()
  const client = useClient(id != null ? Number(id) : undefined)
  const allPackages = useProvisionedPackages()
  const subscriptions = useStore((s) => s.subscriptions)
  const invoicesAll = useStore((s) => s.invoices)
  const addInvoice = useStore((s) => s.addInvoice)
  const can = useCan()
  const canEdit = can('update clients')
  const canProvision = can('provision packages')
  const canCreateInvoice = can('create invoices')
  const toast = useToast()
  const navigate = useNavigate()
  const [provisionOpen, setProvisionOpen] = useState(false)
  const [invoiceOpen, setInvoiceOpen] = useState(false)

  const clientId = client?.id
  const packages = useMemo(
    () => allPackages.filter((p) => p.client_id === clientId),
    [allPackages, clientId],
  )
  const invoices = useMemo(
    () => invoicesAll.filter((i) => i.client_id === clientId),
    [invoicesAll, clientId],
  )
  const subByPackage = useMemo(
    () => new Map(subscriptions.filter((s) => s.ended_at == null).map((s) => [s.package_id, s])),
    [subscriptions],
  )

  const mrr = useMemo(
    () =>
      subscriptions
        .filter((s) => s.client_id === clientId && s.ended_at == null && s.billing_status !== 'overdue')
        .reduce((a, s) => a + Number(s.price_monthly || 0), 0),
    [subscriptions, clientId],
  )
  const requests24h = useMemo(() => packages.reduce((a, p) => a + Number(p.requests_24h || 0), 0), [packages])
  const worstHealth = useMemo(
    () => (packages.length ? Math.max(...packages.map((p) => Number(p.quota_health || 0))) : null),
    [packages],
  )
  const overdue = invoices.find((i) => i.status === 'overdue')
  const overduePackageLabel = overdue
    ? packages.find((p) => p.id === overdue.package_id)?.name ?? 'Unknown package'
    : ''

  if (!client) {
    return (
      <div className="scns-page">
        <EmptyState
          title="Client not found"
          description="It may have been removed, or the URL is incorrect."
          action={{ label: 'Back to clients', onClick: () => navigate('/clients') }}
        />
      </div>
    )
  }

  return (
    <div className="scns-page">
      <Breadcrumbs />

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16 }}>
        <div style={{ minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
            <h1 style={{ fontSize: 24, fontWeight: 400, margin: 0 }}>{client.name}</h1>
            <div className="scns-title-tags">
              <ClientStatusTag status={client.status} />
            </div>
          </div>
          <div className="scns-helper" style={{ fontSize: 13, marginTop: 6 }}>
            Customer account
            {client.company && client.company !== client.name ? <> · {client.company}</> : null}
            {' · since '}{formatMonthYear(client.created_at)}
          </div>
        </div>
        {(canProvision || canCreateInvoice || canEdit) && (
          <div style={{ flex: 'none' }}>
            <MenuButton label="Actions" kind="tertiary" size="md" menuAlignment="bottom-end">
              {canProvision && (
                <MenuItem label="New package for this client" onClick={() => setProvisionOpen(true)} />
              )}
              {canCreateInvoice && <MenuItem label="New invoice" onClick={() => setInvoiceOpen(true)} />}
              {(canProvision || canCreateInvoice) && canEdit && <MenuItemDivider />}
              {canEdit && (
                <MenuItem
                  label="Delete client…"
                  kind="danger"
                  onClick={() => toast.info('Delete client', 'Not available yet.')}
                />
              )}
            </MenuButton>
          </div>
        )}
      </div>

      {overdue && (
        <InlineNotification
          kind="error"
          lowContrast
          hideCloseButton
          title={`${overdue.number} · ${formatCurrency(overdue.amount)} overdue`}
          subtitle={`${formatRelative(overdue.due_at, Date.now()).replace(' ago', '')} overdue — ${overduePackageLabel} ${overdue.enforcement === 'suspended' ? 'auto-suspended' : overdue.enforcement === 'grace' ? 'in grace' : 'flagged'}.`}
          style={{ maxWidth: '100%', margin: '24px 0 0' }}
        />
      )}

      <div style={{ margin: '24px 0' }}>
        <KpiCluster columns={4}>
          <KpiTile label="Packages" value={packages.length} helper="provisioned" icon={<Box size={20} />} />
          <KpiTile label="MRR" value={mrr > 0 ? formatCurrencyShort(mrr) : '—'} helper="current terms" icon={<Money size={20} />} />
          <KpiTile label="Requests · 24h" value={formatCompact(requests24h)} helper="across packages" icon={<Activity size={20} />} />
          <KpiTile
            label="Quota health"
            value={worstHealth == null ? '—' : formatPercent(worstHealth)}
            helper="worst metric"
            valueClass={
              worstHealth == null ? undefined : worstHealth > 1 ? 'scns-val-red' : worstHealth >= 0.8 ? 'scns-val-amber' : undefined
            }
            icon={<MeterAlt size={20} />}
            iconClass={
              worstHealth == null ? undefined : worstHealth > 1 ? 'scns-val-red' : worstHealth >= 0.8 ? 'scns-val-amber' : undefined
            }
          />
        </KpiCluster>
      </div>

      <div className="scns-client-grid">
        <ClientEditForm key={client.id} client={client} canEdit={canEdit} />
        <aside className="scns-client-rail">
          <PackagesPanel
            packages={packages}
            subByPackage={subByPackage}
            onOpen={(pid) => navigate(`/packages/${pid}`)}
            onViewAll={() => navigate('/packages')}
          />
          <InvoicesPanel invoices={invoices} onViewAll={() => navigate('/invoices')} />
        </aside>
      </div>

      {provisionOpen && (
        <ProvisionModal
          open
          lockClientId={client.id}
          onClose={() => setProvisionOpen(false)}
          onProvisioned={() => setProvisionOpen(false)}
        />
      )}
      {invoiceOpen && (
        <InvoiceFormModal
          open
          mode="create"
          clientId={client.id}
          onClose={() => setInvoiceOpen(false)}
          onSubmit={(value) => {
            const num = addInvoice(value as Parameters<typeof addInvoice>[0])
            toast.success('Invoice created', num)
          }}
        />
      )}
    </div>
  )
}

function PackagesPanel({
  packages,
  subByPackage,
  onOpen,
  onViewAll,
}: {
  packages: Package[]
  subByPackage: Map<number, Subscription>
  onOpen: (id: number) => void
  onViewAll: () => void
}) {
  return (
    <Card
      title="Packages"
      bodyPadding="8px 16px 12px"
      action={
        <OverflowMenu size="sm" flipped aria-label="Packages actions">
          <OverflowMenuItem itemText="View all packages" onClick={onViewAll} />
        </OverflowMenu>
      }
    >
      {packages.length === 0 ? (
        <div className="scns-helper" style={{ fontSize: 13, padding: '4px 0' }}>No packages for this client.</div>
      ) : (
        <table className="scns-minitable">
          <thead>
            <tr>
              <th>Package</th>
              <th>Plan</th>
              <th className="scns-minitable__num">MRR</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {packages.map((p) => {
              const sub = subByPackage.get(p.id)
              return (
                <tr key={p.id} className="scns-minitable__row" onClick={() => onOpen(p.id)}>
                  <td><span className="scns-mono scns-link">{p.name}</span></td>
                  <td>{sub?.plan ?? '—'}</td>
                  <td className="scns-minitable__num">{sub ? formatCurrency(sub.price_monthly) : '—'}</td>
                  <td><PackageStatusTag pkg={p} /></td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}
    </Card>
  )
}

function InvoicesPanel({
  invoices,
  onViewAll,
}: {
  invoices: Invoice[]
  onViewAll: () => void
}) {
  return (
    <Card
      title="Invoices"
      bodyPadding="8px 16px 12px"
      action={
        <OverflowMenu size="sm" flipped aria-label="Invoices actions">
          <OverflowMenuItem itemText="View all invoices" onClick={onViewAll} />
        </OverflowMenu>
      }
    >
      {invoices.length === 0 ? (
        <div className="scns-helper" style={{ fontSize: 13, padding: '4px 0' }}>No invoices for this client.</div>
      ) : (
        <table className="scns-minitable">
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Due</th>
              <th className="scns-minitable__num">Amount</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {invoices.map((inv) => (
              <tr key={inv.id}>
                <td className="scns-mono">{inv.number}</td>
                <td><RelativeTime iso={inv.due_at} /></td>
                <td className="scns-minitable__num">{formatCurrency(inv.amount)}</td>
                <td><BillingTag status={inv.status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Card>
  )
}

// Always-on edit form: the fields ARE the record view. A sticky action bar appears only when
// there are unsaved edits. Mirrors the field set + validation of the old ClientFormModal.
function ClientEditForm({ client, canEdit }: { client: Client; canEdit: boolean }) {
  const updateClient = useStore((s) => s.updateClient)
  const toast = useToast()

  const [name, setName] = useState(client.name)
  const [company, setCompany] = useState(client.company ?? '')
  const [email, setEmail] = useState(client.email)
  const [phone, setPhone] = useState(client.phone ?? '')
  const [notes, setNotes] = useState(client.notes ?? '')
  const [active, setActive] = useState(client.status === 'active')
  const [billingEmail, setBillingEmail] = useState(client.billing_email ?? '')
  const [taxId, setTaxId] = useState(client.tax_id ?? '')
  const [address1, setAddress1] = useState(client.address_line1 ?? '')
  const [address2, setAddress2] = useState(client.address_line2 ?? '')
  const [city, setCity] = useState(client.city ?? '')
  const [postal, setPostal] = useState(client.postal_code ?? '')
  const [country, setCountry] = useState(client.country ?? '')
  const [terms, setTerms] = useState<PaymentTerms | ''>(client.payment_terms ?? '')
  const [submitted, setSubmitted] = useState(false)

  const reset = () => {
    setName(client.name)
    setCompany(client.company ?? '')
    setEmail(client.email)
    setPhone(client.phone ?? '')
    setNotes(client.notes ?? '')
    setActive(client.status === 'active')
    setBillingEmail(client.billing_email ?? '')
    setTaxId(client.tax_id ?? '')
    setAddress1(client.address_line1 ?? '')
    setAddress2(client.address_line2 ?? '')
    setCity(client.city ?? '')
    setPostal(client.postal_code ?? '')
    setCountry(client.country ?? '')
    setTerms(client.payment_terms ?? '')
    setSubmitted(false)
  }

  const nameError = !name.trim() ? 'Required' : ''
  const emailError = !email.trim() ? 'Required' : !EMAIL_RE.test(email) ? 'Enter a valid email' : ''
  const billingEmailError = billingEmail.trim() && !EMAIL_RE.test(billingEmail) ? 'Enter a valid email' : ''
  const countryError = country.trim() && country.trim().length !== 2 ? 'Use a 2-letter code' : ''
  const valid = !nameError && !emailError && !billingEmailError && !countryError

  const dirty =
    name.trim() !== client.name ||
    company.trim() !== (client.company ?? '') ||
    email.trim() !== client.email ||
    phone.trim() !== (client.phone ?? '') ||
    notes.trim() !== (client.notes ?? '') ||
    active !== (client.status === 'active') ||
    billingEmail.trim() !== (client.billing_email ?? '') ||
    taxId.trim() !== (client.tax_id ?? '') ||
    address1.trim() !== (client.address_line1 ?? '') ||
    address2.trim() !== (client.address_line2 ?? '') ||
    city.trim() !== (client.city ?? '') ||
    postal.trim() !== (client.postal_code ?? '') ||
    country.trim().toUpperCase() !== (client.country ?? '') ||
    (terms || '') !== (client.payment_terms ?? '')

  const save = () => {
    setSubmitted(true)
    if (!valid) return
    updateClient(client.id, {
      name: name.trim(),
      company: company.trim(),
      email: email.trim(),
      phone: phone.trim() || undefined,
      notes: notes.trim() || undefined,
      status: active ? 'active' : 'inactive',
      billing_email: billingEmail.trim() || null,
      tax_id: taxId.trim() || null,
      address_line1: address1.trim() || null,
      address_line2: address2.trim() || null,
      city: city.trim() || null,
      postal_code: postal.trim() || null,
      country: country.trim().toUpperCase() || null,
      payment_terms: (terms || null) as PaymentTerms | null,
    })
    setSubmitted(false)
    toast.success('Client updated', name.trim())
  }

  const ro = !canEdit

  const menu = canEdit ? (
    <OverflowMenu size="sm" flipped aria-label="Client actions">
      <OverflowMenuItem itemText="Discard changes" disabled={!dirty} onClick={reset} />
    </OverflowMenu>
  ) : undefined

  return (
    <>
    <Card title="Details" action={menu} bodyClassName="scns-client-form" bodyPadding={24}>
      <p className="scns-section-label">Contact</p>
      <div className="scns-form-grid">
        <TextInput id="cd-name" labelText="Name" value={name} disabled={ro} onChange={(e) => setName(e.target.value)} invalid={submitted && !!nameError} invalidText={nameError} />
        <TextInput id="cd-company" labelText="Company" value={company} disabled={ro} onChange={(e) => setCompany(e.target.value)} />
        <TextInput id="cd-email" labelText="Email" value={email} disabled={ro} onChange={(e) => setEmail(e.target.value)} invalid={submitted && !!emailError} invalidText={emailError} />
        <TextInput id="cd-phone" labelText="Phone (optional)" value={phone} disabled={ro} onChange={(e) => setPhone(e.target.value)} />
        <TextArea id="cd-notes" labelText="Notes (optional)" rows={2} value={notes} disabled={ro} onChange={(e) => setNotes(e.target.value)} />
        <div style={{ display: 'flex', alignItems: 'flex-end', paddingBottom: 4 }}>
          <Toggle id="cd-active" labelText="Status" labelA="Inactive" labelB="Active" toggled={active} disabled={ro} onToggle={setActive} />
        </div>
      </div>

      <p className="scns-section-label" style={{ marginTop: 32 }}>Billing</p>
      <div className="scns-form-grid">
        <TextInput id="cd-billing-email" labelText="Billing email (optional)" value={billingEmail} disabled={ro} onChange={(e) => setBillingEmail(e.target.value)} invalid={submitted && !!billingEmailError} invalidText={billingEmailError} />
        <TextInput id="cd-tax" labelText="Tax / VAT ID (optional)" value={taxId} disabled={ro} onChange={(e) => setTaxId(e.target.value)} />
        <TextInput id="cd-addr1" labelText="Address line 1 (optional)" value={address1} disabled={ro} onChange={(e) => setAddress1(e.target.value)} />
        <TextInput id="cd-addr2" labelText="Address line 2 (optional)" value={address2} disabled={ro} onChange={(e) => setAddress2(e.target.value)} />
        <TextInput id="cd-city" labelText="City (optional)" value={city} disabled={ro} onChange={(e) => setCity(e.target.value)} />
        <TextInput id="cd-postal" labelText="Postal code (optional)" value={postal} disabled={ro} onChange={(e) => setPostal(e.target.value)} />
        <TextInput id="cd-country" labelText="Country (ISO-2)" value={country} disabled={ro} onChange={(e) => setCountry(e.target.value)} invalid={submitted && !!countryError} invalidText={countryError} />
        <Select id="cd-terms" labelText="Payment terms (optional)" value={terms} disabled={ro} onChange={(e) => setTerms(e.target.value as PaymentTerms | '')}>
          <SelectItem value="" text="—" />
          <SelectItem value="prepaid" text="Prepaid" />
          <SelectItem value="net_15" text="Net 15" />
          <SelectItem value="net_30" text="Net 30" />
          <SelectItem value="net_60" text="Net 60" />
        </Select>
      </div>
    </Card>

    {canEdit && (
      <div className="scns-save-fab">
        <Button kind="primary" size="lg" renderIcon={Save} onClick={save} disabled={!dirty}>Save changes</Button>
      </div>
    )}
    </>
  )
}
