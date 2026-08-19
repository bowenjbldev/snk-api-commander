/** Tramos de precio del filtro de planes. Los cortes son comerciales, no técnicos. */
export const PLAN_PRICE_BANDS = [
  { value: 'low', label: 'Menos de 200 €' },
  { value: 'mid', label: '200 – 700 €' },
  { value: 'high', label: 'Más de 700 €' },
];

/** Los extremos caen dentro del tramo medio: 200 y 700 son precios de catálogo reales. */
export function priceBand(price: number): string {
  return price < 200 ? 'low' : price <= 700 ? 'mid' : 'high';
}
