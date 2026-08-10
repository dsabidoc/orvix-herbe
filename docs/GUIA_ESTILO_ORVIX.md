# Guia de estilo Orvix

Esta guia sirve para que otro sistema pueda replicar el estilo visual de Orvix Prestamos con consistencia. El objetivo es una interfaz operativa, clara, sobria y rapida de usar, no una landing page.

## Personalidad visual

Orvix usa una estetica de sistema financiero operativo:

- Limpio, administrativo y enfocado en datos.
- Fondos claros con tarjetas blancas y bordes suaves.
- Verde teal como color de accion y marca.
- Colores suaves solo para resaltar estados o KPIs.
- Tipografia compacta y legible.
- Mucha informacion, pero organizada en tablas, filtros y bloques.

Evitar:

- Gradientes decorativos.
- Cards enormes tipo marketing.
- Fondos oscuros dominantes.
- Bordes demasiado redondeados.
- Ilustraciones o elementos puramente decorativos.
- Textos explicativos largos dentro de la app.

## Tokens base

### Tipografia

Fuente principal:

```css
Instrument Sans, ui-sans-serif, system-ui, sans-serif
```

Usar tamanos compactos:

- Texto normal: `text-sm`
- Labels: `text-sm font-semibold`
- Titulos de pagina: `text-2xl font-bold`
- Titulos de card/seccion: `font-bold`
- Numeros KPI: `text-2xl font-bold`
- Texto auxiliar: `text-sm text-slate-500`
- Encabezados de tabla: `text-xs uppercase text-slate-500`

No escalar fuentes con viewport. No usar letter-spacing negativo.

### Colores

Marca / accion principal:

```txt
Teal principal: #0d9488
Teal texto:     #0f766e
Teal suave:     #e6f7f4
```

Base neutral:

```txt
Fondo app:      #f4f7fb
Texto fuerte:   slate-950 / #172033
Texto medio:    slate-700 / slate-600
Texto auxiliar: slate-500
Borde:          slate-200
Input borde:    slate-300
Card fondo:     white
Seccion suave:  slate-50
```

Estados y KPIs:

```txt
Azul:      bg-blue-50/80 border-blue-200 text-blue-700 dot #2563eb
Naranja:   bg-orange-50/80 border-orange-200 text-orange-700 dot #f97316
Amarillo:  bg-yellow-50/80 border-yellow-200 text-yellow-700 dot #eab308
Verde:     bg-emerald-50/80 border-emerald-200 text-emerald-700 dot #10b981
Rojo:      bg-red-50/80 border-red-200 text-red-700 dot #ef4444
Neutral:   bg-slate-100 text-slate-700
```

## Layout principal

### App shell escritorio

Usar sidebar fijo desde `lg`:

```html
<div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">
  <aside class="hidden border-r border-slate-200 bg-white px-5 py-6 lg:block">
    ...
  </aside>
  <main>...</main>
</div>
```

El contenido va en:

```html
<section class="px-4 py-6 sm:px-6 lg:px-8">
```

Header:

```html
<header class="border-b border-slate-200 bg-white px-4 py-4 sm:px-6 lg:px-8">
  <p class="text-sm font-semibold text-[#0f766e]">America/Merida · MXN · efectivo con confirmacion</p>
  <h2 class="mt-1 text-2xl font-bold text-slate-950">Titulo</h2>
</header>
```

### Menu responsive

En escritorio/tablet grande se usa sidebar fijo. En celular y tablet pequena se usa drawer hamburguesa:

- Boton hamburguesa visible solo `lg:hidden`.
- Drawer `fixed inset-y-0 left-0 z-50`.
- Overlay `fixed inset-0 z-40 bg-slate-950/40`.
- Cerrar con X, overlay y Escape.

El drawer debe contener el mismo orden de menu que escritorio.

Orden recomendado:

1. Dashboard
2. Cobranza
3. Simulador
4. Cartera
5. Cortes
6. Solicitudes
7. Clientes
8. Expedientes
9. Configuracion

## Logo y favicon

Usar SVG de marca.

```html
<link rel="icon" type="image/svg+xml" href="/assets/favicon-orvix.svg">
<img class="h-12 w-auto" src="/assets/logo-orvix.svg" alt="Orvix Prestamos">
```

En login puede usarse `h-14`, en sidebar `h-12`, en drawer movil `h-10`.

## Componentes

### Cards / secciones

Card estandar:

```html
<section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
  <h3 class="font-bold text-slate-950">Titulo</h3>
  <p class="mt-1 text-sm text-slate-500">Descripcion corta.</p>
</section>
```

Card con tabla:

```html
<section class="rounded-lg border border-slate-200 bg-white shadow-sm">
  <div class="border-b border-slate-200 px-5 py-4">
    <h3 class="font-bold text-slate-950">Titulo</h3>
    <p class="mt-1 text-sm text-slate-500">Texto auxiliar.</p>
  </div>
  ...
</section>
```

Reglas:

- Usar `rounded-lg` para cards principales.
- Usar `rounded-md` para inputs, botones y elementos internos.
- Usar `shadow-sm`, no sombras pesadas.
- No meter cards dentro de cards salvo modales o items repetidos pequenos.

### Botones

Primario:

```html
<button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">
  Guardar
</button>
```

Secundario:

```html
<button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
  Cancelar
</button>
```

Peligro:

```html
<button class="rounded-md bg-red-700 px-4 py-2 text-sm font-bold text-white">
  Eliminar
</button>
```

Boton icono:

```html
<button class="grid size-8 place-items-center rounded-md border border-slate-200 bg-white text-slate-600 hover:text-[#0f766e]" title="Descargar">
  <!-- svg icon -->
</button>
```

Usar iconos para acciones rapidas: descargar, eliminar, cerrar, menu, dinero, etc.

### Inputs y filtros

Input:

```html
<input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
```

Label:

```html
<label class="text-sm font-semibold text-slate-700">Campo</label>
```

Contenedor de filtros:

```html
<form class="mb-4 flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:flex-row md:items-end">
```

### Tablas

Tabla desktop:

```html
<table class="w-full text-left text-sm">
  <thead class="bg-slate-50 text-xs uppercase text-slate-500">
    <tr>
      <th class="px-5 py-3">Cliente</th>
      <th class="px-5 py-3 text-right">Monto</th>
    </tr>
  </thead>
  <tbody class="divide-y divide-slate-100">
    <tr class="hover:bg-slate-50">
      <td class="px-5 py-3">...</td>
      <td class="px-5 py-3 text-right font-semibold">...</td>
    </tr>
  </tbody>
</table>
```

Para tablas anchas:

```html
<div class="overflow-x-auto">
  <table class="w-full min-w-[980px] text-left text-sm">
```

En movil, si la tabla es compleja, usar cards/lista:

```html
<table class="hidden w-full text-left text-sm md:table">...</table>
<div class="divide-y divide-slate-100 md:hidden">...</div>
```

### KPIs / datos duros

Usar tarjetas de colores suaves con punto de color.

```html
<article class="rounded-lg border border-blue-200 bg-blue-50/80 p-4 shadow-sm">
  <div class="flex items-center justify-between gap-3">
    <p class="text-sm font-bold text-blue-700">Cartera activa</p>
    <span class="size-2.5 rounded-full" style="background-color: #2563eb"></span>
  </div>
  <p class="mt-3 text-2xl font-bold text-slate-950">$1,636,996.00</p>
  <p class="mt-1 text-sm text-slate-600">Saldo contractual pendiente</p>
</article>
```

Orden de colores usado:

- Cartera activa: azul.
- Esperado semanal: naranja.
- Esperado periodo/mes: amarillo.
- Reportado pendiente/autorizado: verde.
- Vencido/rechazado: rojo.

Grid:

```html
<div class="mb-4 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
```

### Graficas

Para resumen visual usar dona simple con `conic-gradient`, sin libreria si no hace falta:

```html
<div class="grid size-56 place-items-center rounded-full" style="background: conic-gradient(#10b981 0% 30%, #f97316 30% 45%, #ef4444 45% 100%);">
  <div class="grid size-28 place-items-center rounded-full bg-white text-center shadow-sm">
    <p class="text-xs font-semibold uppercase text-slate-500">Total</p>
    <p class="mt-1 text-sm font-bold text-slate-950">$100,000.00</p>
  </div>
</div>
```

Leyenda:

- Punto de color.
- Nombre.
- Porcentaje.
- Barra `h-2 rounded-full`.
- Valor debajo.

### Badges de estado

Base:

```html
<span class="rounded px-2 py-1 text-xs font-bold">Estado</span>
```

Colores:

```txt
En revision:   bg-blue-50 text-blue-700
Autorizada:    bg-emerald-50 text-emerald-700
Por confirmar: bg-amber-50 text-amber-700
Vencida:       bg-red-50 text-red-700
Pendiente:     bg-slate-100 text-slate-700
Pagada:        bg-emerald-50 text-emerald-700
```

### Modales

Usar `<dialog>` centrado por CSS global:

```css
dialog[open] {
  position: fixed;
  inset: 50% auto auto 50%;
  margin: 0;
  transform: translate(-50%, -50%);
}
```

Estructura:

```html
<dialog class="w-[min(92vw,420px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
  <form method="dialog">
    <div class="border-b border-slate-200 px-5 py-4">
      <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Confirmar</p>
      <h3 class="mt-1 text-lg font-bold text-slate-950">Pregunta del modal</h3>
    </div>
    <div class="px-5 py-4">
      <p class="text-sm leading-6 text-slate-600">Texto claro y corto.</p>
    </div>
    <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
      <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Cancelar</button>
      <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white">Confirmar</button>
    </div>
  </form>
</dialog>
```

Para eliminar, usar rojo en titulo y boton principal:

```txt
text-red-700
bg-red-700 text-white
```

## Login

Login centrado:

```html
<div class="grid min-h-screen place-items-center px-4">
  <div class="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
    <img class="h-14 w-auto" src="/assets/logo-orvix.svg" alt="Orvix Prestamos">
    <p class="mt-3 text-sm font-semibold text-slate-500">Acceso operativo</p>
    ...
  </div>
</div>
```

## Impresion

Usar carta vertical:

```css
@media print {
  @page {
    size: letter portrait;
    margin: 0.45in;
  }
}
```

Ocultar:

```css
aside,
header,
.no-print {
  display: none !important;
}
```

Hoja imprimible:

```html
<section class="print-sheet rounded-lg border border-slate-200 bg-white shadow-sm">
```

En print:

```css
.print-sheet {
  border: 0 !important;
  box-shadow: none !important;
  width: 100% !important;
  max-width: 7.6in !important;
}
```

## Responsive

Breakpoints recomendados:

- Movil: default.
- Tablet: `md`.
- Sidebar fijo desde: `lg`.
- Layouts amplios: `xl`.

Reglas:

- En movil no mostrar menu completo como botones horizontales; usar drawer.
- Tablas anchas deben tener `overflow-x-auto` o convertirse en lista.
- Formularios usan `flex-col` en movil y `md:flex-row` en pantallas medianas.
- Cards KPI usan `md:grid-cols-2` y `xl:grid-cols-5`.

## Copy y tono de interfaz

Usar texto directo y operativo:

- `Guardar por confirmar`
- `Registrar Cobro`
- `Solicitar Prestamo`
- `Descargar / imprimir`
- `Confirmar y aplicar`
- `No hay archivos para mostrar`

Evitar:

- Textos largos que expliquen lo obvio.
- Lenguaje de marketing.
- Terminos internos que el usuario no entiende.
- Estados en ingles.

## Checklist para Codex al replicar

1. Usar logo y favicon SVG.
2. Montar shell con sidebar fijo desde `lg` y drawer movil.
3. Aplicar fondo `#f4f7fb`.
4. Usar cards blancas con `border-slate-200`, `rounded-lg`, `shadow-sm`.
5. Usar teal `#0d9488` para acciones primarias.
6. Usar tablas compactas con encabezados `bg-slate-50 text-xs uppercase`.
7. Agregar KPIs con tonos suaves cuando haya metricas.
8. Usar modales `<dialog>` centrados para confirmaciones.
9. Traducir todos los estados al espanol.
10. Probar escritorio, tablet y movil antes de entregar.
