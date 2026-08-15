/** Tramos de precio del filtro de planes. Los cortes son comerciales, no técnicos. */
export const PLAN_PRICE_BANDS = [
  { value: 'lt200', label: 'Menos de 200 €' },
  { value: '200-600', label: 'Entre 200 y 600 €' },
  { value: 'gt600', label: 'Más de 600 €' },
];

export function matchesPriceBand(price: number, band: string): boolean {
  if (band === 'lt200') return price < 200;
  if (band === 'gt600') return price > 600;
  return price >= 200 && price <= 600;
}
