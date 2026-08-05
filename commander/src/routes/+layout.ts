// SPA pura: nada se renderiza en servidor ni se prerenderiza. El adaptador estático emite un solo
// index.html y el enrutado vive entero en el cliente.
export const ssr = false;
export const prerender = false;
export const trailingSlash = 'never';
