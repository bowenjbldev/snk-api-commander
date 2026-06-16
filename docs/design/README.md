# SnakeCloud Commander — Design System & UX Guidelines

Este documento establece las directrices de diseño, patrones de interfaz y convenciones de usabilidad para el **SnakeCloud Commander**. Ha sido redactado con la mentalidad de un Diseñador Senior enfocado en aplicaciones de nivel empresarial ("enterprise-grade") basadas en el **IBM Carbon Design System**.

Nuestro mantra de diseño es claro: **Calm, dense with data, instantly scannable, zero fluff.** (Sereno, denso en datos, de escaneo instantáneo, cero relleno). 

El Commander es una herramienta de uso intensivo para un operador único. No es un producto de consumo B2C que necesite venderse visualmente con cada clic; es un panel de instrumentos de grado aeronáutico.

---

## 1. Filosofía Base

- **Respeto por el tiempo del operador:** Evitamos clics innecesarios. Minimizamos el scroll. Agrupamos la información de forma lógica.
- **Densidad de datos sobre aire:** Utilizamos el espacio de forma eficiente. Preferimos tablas compactas, fuentes monoespaciadas para datos precisos (IDs, fechas exactas, IPs, logs) y tarjetas que aprovechan el ancho total.
- **Previsibilidad absoluta:** Un buscador siempre está en el mismo sitio. Una acción destructiva siempre pide la misma confirmación. Un estado de "cargando" siempre se ve igual.
- **Semántica de colores estricta:** El color se usa exclusivamente para comunicar *estado* o *categoría*, nunca por pura decoración.

---

## 2. Paleta y Semántica de Estados

Heredamos la sobriedad de Carbon pero inyectamos nuestros tokens (`--scns-*`) para asegurar que la semántica de la plataforma SaaS esté en primera línea.

### Estados de Entidades (Despliegues, Clientes, Cuotas)
- **Active / Operational:** Verde (`--scns-success` / `#42be65` / `#24a148`). Todo funciona correctamente.
- **Grace / Warning:** Ámbar (`--scns-warning` / `--scns-amber-bg`). Alerta de cuotas, periodos de gracia o facturas pendientes. Requiere atención, pero el servicio no se ha interrumpido.
- **Suspended / Overdue / Error:** Rojo (`--scns-error` / `--scns-tag-red-bg`). HTTP 402, servicio interrumpido o límite superado con política de suspensión.

### Gráficos (Data Visualization)
- **Requests:** Púrpura IBM (`#6929c4`).
- **Transferencia (In/Out):** Verde azulado (`#005d5d`) y Púrpura.
- **Errores:** `4xx` usa Amarillo Carbon (`#d2a106`), `5xx` usa Rojo Carbon (`#fa4d56`).
- **Latencia:** Azul (`#1192e8`) para Promedios, Magenta (`#9f1853`) para p95.

---

## 3. Patrones Estructurales

### 3.1. Page Headers
Todas las páginas deben usar el componente global `<PageHeader>`.
- El título es grande (`24px`, peso `400`).
- El subtítulo proporciona contexto técnico u operativo (clase `scns-helper`, `13px`).
- Las acciones globales de la página (por ejemplo, "Crear cliente" o selectores de despliegue) deben inyectarse en el prop `actions`.

### 3.2. Tarjetas y Bordes (`.scns-card`)
Las tarjetas usan un fondo sólido (`--cds-layer`) y un borde sutil pero opaco (`--scns-card-border`).
- **Modo Oscuro:** Usa `var(--cds-border-subtle)` para un contraste elegante con el fondo negro profundo.
- **Modo Claro:** Usa un gris sólido (`#e0e0e0`), nunca un `rgba` transparente, para evitar problemas de renderizado (blending) en sub-cuadrículas como los clústeres de KPIs.

### 3.3. Formularios y Settings (Configuraciones)
Las páginas de configuración o formularios complejos deben seguir una estructura de **Dos Columnas**:
- **Izquierda:** Título del bloque (peso `600`) y descripción de contexto (`scns-helper`).
- **Derecha:** Entradas de datos (`TextInput`, `Toggle`, etc.) apiladas verticalmente.
- **Botones de Guardado (Save):** Siempre consolidados. En lugar de tener un botón por cada input, agrupamos la lógica y colocamos un único botón "Save preferences" en el pie de la tarjeta, con un fondo diferenciado (`--cds-layer-accent`). 
- **Feedback:** El botón "Save" debe estar `disabled` hasta que el estado del formulario sea *dirty* (hasta que haya cambios reales).

---

## 4. Tablas de Datos (`DataTableShell`)

La tabla es el corazón de Commander. Nunca reconstruyas una tabla de Carbon desde cero; usa siempre `<DataTableShell>`.

**Orden estricto de la Toolbar (Barra de herramientas):**
1. **Filtros (Izquierda):** Dropdowns, Toggles u otras herramientas de refinado de vista.
2. **Acciones Extra (Centro):** Botones de exportación CSV, recargas (Refresh).
3. **Buscador (Derecha):** El campo de búsqueda (Search) siempre, **siempre**, se ancla a la derecha.

**Estados de Carga (Loading):**
Para evitar saltos bruscos en el layout (Layout Shift) cuando la tabla lee datos síncronos del store, usamos el hook `useFirstLoad()`. Esto fuerza el renderizado del `DataTableSkeleton` (animación de barrido nativa de Carbon) durante 280ms, dando una sensación de pesadez y robustez a la carga de datos. Para peticiones reales (ej. `RequestLogs`), se combina el estado HTTP con este hook: `loading={state.loading || firstLoad}`.

---

## 5. Gráficos y Visualización (`ChartCard`)

- **Separación Título/Contenedor:** El título y subtítulo del gráfico **nunca** deben ir dentro de la tarjeta blanca. Se posicionan encima, alineados a la línea base (`alignItems: 'baseline'`), manteniendo la consistencia con cabeceras genéricas como "Recent activity".
- **Leyendas (Legends):** Deben vivir **dentro** de la tarjeta del gráfico (`scns-card`), ancladas en la esquina superior derecha.

---

## 6. Los "Empty States" (Zero Fluff Policy)

Hemos diseñado un componente unificado `<EmptyState>`. 
Las reglas sobre cuándo usar texto en él son estrictas:

1. **Vistas de Monitoreo / Logs (SIN Descripción):**
   Si la vista es un panel de estado evidente (e.g. "Recent Activity", "Top Deployments", "Attention Required"), **no se incluyen descripciones**.
   - ✅ Correcto: `title="No issues detected"`
   - ❌ Incorrecto: `title="No issues detected" description="There are no deployments needing attention right now."` (Es redundante y genera ruido cognitivo).

2. **Vistas Configurables o CTAs (CON Descripción):**
   Si el estado vacío es el paso previo a una acción que el usuario debe tomar, la descripción es obligatoria para hacer el *onboarding*.
   - ✅ Correcto: `title="No API tokens" description="Create a token to authenticate with Commander API."`

3. **Vistas de Error (CON Descripción):**
   Si la vista no tiene datos debido a un fallo (e.g. no se puede conectar al plano de control del cliente), la descripción debe incluir la razón técnica del error.

---

## 7. Tipografía y Estilo de Código

- Usamos **IBM Plex Sans** para la UI general.
- Usamos **IBM Plex Mono** (con la clase utilitaria `.scns-mono`) para cualquier dato que un ingeniero necesite leer con precisión: 
  - IDs de clientes y despliegues (`deploy_9c44ab...`)
  - Direcciones IP.
  - Cifras financieras y KPIs.
  - Rutas de API (`/api/v1/users`).
  - Métodos HTTP (`GET`, `POST`).
- Los tiempos relativos ("3h ago") usan el componente `<RelativeTime>` que incluye un `title` nativo, permitiendo ver la fecha absoluta en UTC al pasar el ratón (hover).

---

> "El buen diseño en herramientas internas no se trata de hacerlas bonitas, se trata de hacerlas invisibles. Si el operador puede tomar una decisión crítica en 3 segundos sin pensar en dónde hacer clic, el diseño es un éxito." 
> — *SnakeCloud Design Team*