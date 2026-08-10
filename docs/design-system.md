# Sistema visual

## Fuente

Se recibio el archivo `Finstack - Finance Dashboard UI Kit.fig`. Se inspecciono su `thumbnail.png`: dashboard financiero con sidebar oscuro, superficies blancas, tarjetas KPI compactas, graficas lineales y acentos azulados. La interfaz de Orvix adopta el mismo enfoque operativo: navegacion lateral, tarjetas KPI, tablas compactas, badges de estado y acciones primarias claras.

## Tokens iniciales

- Fondo de app: `#f4f7fb`.
- Superficies: `#ffffff`.
- Texto principal: `#172033`.
- Marca/acento inicial Orvix: `#0d9488`.
- Acento Finstack observado: azul saturado sobre panel financiero.
- Acento suave: `#e6f7f4`.
- Bordes: escala slate de Tailwind.
- Radios: `0.375rem` a `0.5rem`.
- Sombras: suaves, solo en tarjetas/herramientas.

## Componentes iniciales

- Layout con sidebar desktop.
- Header con acciones principales.
- Tarjetas KPI.
- Badges de estado.
- Tabla compacta para calendario.
- Formularios de registro de cobro y confirmacion.
- Listados responsivos que cambian tabla por tarjetas en movil.

## Pendientes

- Importar el `.fig` en Figma para extraer tokens exactos.
- Consolidar componentes Blade/Livewire reutilizables para botones, tablas, filtros, modales y estados vacios.
