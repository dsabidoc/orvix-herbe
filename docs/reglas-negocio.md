# Reglas de negocio

## Confirmadas por el brief

- Total contractual inicial: `capital + (capital * tasa_mensual * meses)`.
- Ejemplo validado: capital `$143,000`, plazo `36`, tasa mensual `2%`, total `$245,960`.
- Letras 2 a 36: `$6,830`; primera letra: `$6,910`.
- La suma exacta de letras debe coincidir con el total contractual.
- Adelantos se aplican desde la ultima letra hacia atras.
- Operador no puede confirmar su propio corte.
- Corte confirmado queda bloqueado.
- Recargos del operador y conceptos externos no reducen saldo contractual sin regla explicita.
- Registros financieros confirmados no se editan ni eliminan.
- Samuel puede cubrir letras aunque el cliente final no le haya pagado; para Orvix cuenta como pagado cuando Samuel entrega.
- Dario no debe acumular tres letras por credito; debe cubrir, liquidar o resolver con su proceso externo.
- Adriana puede preparar/recibir listas y controlar pagares/documentos, pero la confirmacion final de dinero la hace el dueno/administrador.
- Reportes mensuales para Adriana nacen de los cortes semanales confirmados.

## Configurables o pendientes de confirmacion

- Regla de primera fecha de pago.
- Incremento de redondeo y letra que absorbe diferencia.
- Politica de liquidacion y vigencia de cotizacion.
- Reparto de inversionistas por inversion.
- Reglas por operador: tolerancia, letras vencidas, faltantes y cobertura obligatoria.

## Implementado

- `LoanScheduleCalculator` encapsula formula, redondeo, calendario y validaciones.
- Pruebas unitarias garantizan el ejemplo financiero y la suma exacta.
- `PaymentApplicationService` aplica cobros confirmados a letras dentro de transaccion.
- Los cobros reportados quedan en `collection_movements.confirmation_status=reported` y no alteran `installments`.
- La confirmacion cambia el movimiento a `applied`, crea `payment_allocations` y actualiza saldos de letras.
- Los adelantos se asignan desde la ultima letra pendiente hacia atras.
- Seed demo con casos de Samuel, Dario, Santiago, Adriana y BM de Victor.
- Cobranza mensual muestra solo la cartera autorizada del usuario; los operadores no pueden ver ni abrir letras/prestamos de otros operadores.
- Marcar una letra como `Pagado` crea un movimiento `reported` ligado a esa letra. El corte semanal toma esos movimientos marcados.
- Si una letra vence y no fue marcada como pagada, aparece como `Atrasado sin marcar` en el corte de la semana siguiente.
