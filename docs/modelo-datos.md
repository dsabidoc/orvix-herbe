# Modelo de datos

## Principios

- Montos en `DECIMAL(15,2)`; tasas en `DECIMAL(8,6)`.
- Folios o ULIDs publicos para entidades sensibles.
- Sin cascadas destructivas sobre informacion financiera.
- Movimientos confirmados se corrigen por anulacion y compensacion, no por edicion.
- Saldos historicos se reconstruyen desde ledgers o se guardan como snapshots auditables.

## Tablas base creadas

- `operators`, `clients`, `client_references`, `vehicles`
- `simulations`, `loan_applications`, `application_status_history`
- `loans`, `loan_terms_versions`, `installments`
- `collection_movements`, `payment_allocations`
- `weekly_cuts`, `weekly_cut_items`, `operator_ledger_entries`
- `document_requirements`, `documents`
- `promissory_notes`, `custody_events`
- `settlement_quotes`, `settlements`
- `investors`, `investments`, `investment_ledger_entries`
- `audit_events`

## Fuentes de verdad

- Contrato: `loans`, `loan_terms_versions`, `installments`.
- Reporte del operador: `collection_movements.confirmation_status=reported`.
- Entrega confirmada: `weekly_cuts.confirmed_at` y `operator_ledger_entries`.
- Auditoria: `audit_events`.

## Pendientes antes de produccion

- Confirmar campos sensibles que deben cifrarse.
- Definir folios definitivos por modulo.
- Validar el esquema contra MySQL/MariaDB real de Hostinger.
