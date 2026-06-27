// Small footer band for the in-card tables (`.scns-cardtable`): a row/record count that closes the
// card with a deliberate bottom edge. The full list lives on the entity's own page.
export function CardTableFooter({ label }: { label: string }) {
  return (
    <div className="scns-cardtable__foot">
      <span>{label}</span>
    </div>
  )
}
