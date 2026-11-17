# Contrato API v1 entre Cajas y Préstamos

**Contrato v1 cerrado. Documento compartido entre Cajas y Préstamos.**

Herbe confirma **una sola vez en Cajas**. Esa autorización envía la versión conciliada del corte y dispara la aplicación automática de los cobros en Préstamos. Herbe autoriza **cada crédito nuevo por separado en Préstamos**, desde un formulario precargado. Cajas es la fuente oficial del efectivo, los recibos, las compensaciones, los desembolsos y los traspasos. Préstamos es la fuente oficial de las letras del calendario, los saldos contractuales y los retornos de los inversionistas.

Este contrato define el flujo objetivo y los formatos de intercambio. El [ensayo local](../../rehearsal/prestamos/README.md) tiene rutas y formatos de prueba propios y todavía requiere una acción manual en Préstamos para aplicar cobros.

## Endpoints

| Quién llama | Quién responde | Endpoint | Uso |
| --- | --- | --- | --- |
| Cajas | Préstamos | `GET /api/v1/cajas/pagares` | Buscar o actualizar pagarés antes de capturar. |
| Cajas | Préstamos | `POST /api/v1/cajas/cortes/validaciones` | Revisar montos, versiones y desglose antes de confirmar. |
| Cajas | Préstamos | `POST /api/v1/cajas/cortes` | Enviar la versión autorizada y disparar la aplicación. |
| Préstamos | Cajas | `POST /api/v1/cajas/autorizaciones/{approval_id}/verificar` | Consultar el permiso vigente de Herbe antes de aceptar por primera vez un corte. |
| Cajas | Préstamos | `GET /api/v1/cajas/cortes/{cut_id}/versiones/{version}` | Consultar el resultado de esa versión, incluso si se perdió el acuse. |
| Cajas | Préstamos | `GET /api/v1/cajas/creditos-pendientes/{pending_credit_id}` | Consultar la resolución del crédito y su vínculo con la cartera. |
| Cajas | Préstamos | `POST /api/v1/cajas/creditos-pendientes/{pending_credit_id}/fondeo` | Confirmar, cancelar o sustituir el fondeo de un crédito pendiente. |
| Cajas | Préstamos | `GET /api/v1/cajas/capital-disponible` | Consultar capital de inversionistas disponible para prestar. |
| Préstamos | Cajas | `GET /api/v1/cajas/cuentas/{cash_account_id}/saldo` | Leer el saldo oficial de Caja Herbe y los traspasos en tránsito. |
| Préstamos | Cajas | `GET /api/v1/cajas/cuentas/{cash_account_id}/movimientos` | Consultar los asientos confirmados de Caja Herbe para cotejarlos. |
| Cajas | Préstamos | `GET /api/v1/cajas/conciliaciones/{close_id}/esperado-financiero` | Leer el saldo esperado calculado desde el libro de Préstamos para un cierre común. |
| Cajas | Préstamos | `POST /api/v1/cajas/cortes/{cut_id}/correcciones` | Aplicar una corrección financiera autorizada sobre un pago aplicado. |
| Cajas | Préstamos | `GET /api/v1/cajas/pagos-directos/{payment_id}` | Verificar un pago directo ya aplicado y cobrado en efectivo. |
| Cajas | Préstamos | `POST /api/v1/cajas/confirmaciones-custodia` | Confirmar el ingreso físico de un pago directo o una devolución física vinculada a una corrección. |

### Identificadores y fechas

Los campos `*_id` identifican recursos mediante referencias públicas, estables y opacas. `operator` también contiene un ID público. Los folios son referencias visibles para búsqueda, recibos y soporte. Todos los folios de ejemplo son ilustrativos. Cajas conserva el ID y el folio que devuelve Préstamos por cada crédito y movimiento aplicado.

`installment_id` identifica una letra del calendario. El documento de pagaré en custodia y la fila interna de base de datos tienen identidades propias. Si una letra se sustituye, Préstamos conserva el ID anterior como referencia histórica, lo marca sustituido y asigna un ID nuevo a cada letra que la reemplaza. Una consulta exacta permite recuperar el estado y los IDs sustitutos autorizados. Validar o aplicar contra la letra anterior devuelve `INSTALLMENT_REPLACED`. Cajas actualiza la selección y vuelve a obtener aprobación para la nueva versión del corte.

La versión del corte es un entero creciente emitido por Cajas. La versión de la letra es opaca y cambia si cambian su saldo o componentes, los pagos aplicados o por confirmar, el vencimiento, el estado de la letra o del crédito, el operador o el congelamiento del crédito. Si una letra conocida del corte pasa a otro operador, la validación y el resultado de aplicación devuelven el conflicto sin exponer el nuevo operador, saldo ni sustitutos. La consulta directa respeta el alcance vigente.

Los importes con sufijo `_cents` son enteros en centavos y heredan `currency: "MXN"`. Las fechas de negocio usan `YYYY-MM-DD` con zona `America/Merida`. Los instantes usan ISO 8601 en UTC. Consulta, validación y aplicación usan el mismo `cut_on`, incluso cuando se procesan en otro día o mes.

Las llamadas usan HTTPS, identidad de servicio y permisos por operación y entorno. El receptor verifica el alcance vigente sobre el recurso. Cajas firma `Approval-Proof` con una clave que Préstamos puede verificar. La prueba vincula emisor, destinatario, entorno, operación, recurso, operador, versión del corte, ID de la autorización, preparador, revisor, vigencia y SHA-256 de los bytes exactos del cuerpo JSON. Cajas conserva esos bytes y la clave de idempotencia desde la aprobación.

Para aceptar un corte por primera vez, Préstamos verifica la firma y vigencia de `Approval-Proof` y consulta en Cajas si Herbe conserva el permiso para la operación y el operador. El revisor debe ser distinto de quien preparó el corte. Una prueba inválida o vencida, o una respuesta de Cajas que confirma la falta o revocación del permiso, devuelve `403 APPROVAL_INVALID`. Si Cajas está inaccesible o la consulta vence, Préstamos responde `503 APPROVAL_CHECK_UNAVAILABLE`. El envío queda pendiente de reintento en Cajas y Préstamos todavía no lo registra como aceptado ni lo aplica.

Para un servicio con acceso vigente al recurso, Préstamos busca primero si la ejecución ya está registrada. Con la misma clave y cuerpo devuelve la respuesta registrada, aunque haya vencido la prueba o hayan cambiado los permisos del revisor. La consulta por GET también requiere acceso vigente del servicio. Un cuerpo distinto para una clave ya registrada devuelve `409 IDEMPOTENCY_KEY_REUSED`. Las correcciones y los eventos de fondeo conservan sus verificaciones de primera aceptación y se recuperan con la misma regla de acceso, clave y cuerpo.

### Acceso de producción

El perfil de producción utiliza orígenes HTTPS explícitos con certificados verificados. Cajas usa credenciales de servicio separadas para consultar capital, consultar catálogo y resultados o validar un corte, y enviar operaciones. Préstamos usa credenciales independientes para verificar el permiso en Cajas y para leer sus saldos y movimientos. Las asignaciones vigentes limitan el acceso por operador y caja. En la activación inicial se habilita un solo operador por su ID público y se acuerda la fecha de corte del canal.

Las pruebas Ed25519 de producción declaran `environment: production` y se verifican con la clave pública de producción. Cajas custodia la clave privada. El piloto conserva su propio entorno `pilot`, credenciales y bases. Los registros de envío se identifican por entorno. El perfil de producción y las nuevas admisiones comienzan desactivados. Con el perfil habilitado y las admisiones en pausa, Préstamos admite consultas y recuperaciones autorizadas de resultados existentes y responde `503 INTEGRATION_PAUSED` ante una primera aceptación nueva. La pausa conserva el canal asignado al operador. Cajas conserva los recibos, la autorización, el cuerpo y la clave. Una consulta de un corte aún desconocido durante la pausa conserva el envío pendiente sin generar un primer POST.

En la rotación coordinada, Préstamos puede aceptar temporalmente las credenciales entrantes y la clave pública de producción anteriores con vencimiento UTC de hasta 24 horas. Ambos servicios revocan el material sustituido al cerrar la ventana, o de inmediato ante exposición. Se preservan las claves de idempotencia y los resultados registrados.

## 1. Consultar pagarés

**Cajas envía** una consulta sin cuerpo. `operator` y `cut_on` son obligatorios. Puede filtrar por `folio`, `model`, `day`, `installment_id` o el parámetro opcional `client_name`. El catálogo se pagina con `page` desde 1, hasta 50 resultados por página. La búsqueda general devuelve letras elegibles para la fecha. La consulta exacta por ID puede devolver una letra ya inelegible dentro del alcance, con su `status`, `version` y `allowed_payment_types: []`. Cuando fue sustituida, incluye `replacement_installment_ids` dentro del mismo alcance.

```http
GET /api/v1/cajas/pagares?operator=op_42&cut_on=2026-11-13&model=Versa&day=15&page=1
```

`client_name` es una cadena opcional de hasta 150 caracteres. Préstamos recorta los espacios exteriores; ausente, nula o vacía después de ese recorte equivale a no filtrar por nombre. Busca un fragmento contiguo literal del nombre completo `TRIM(first_name + " " + last_name)` del cliente. La comparación de MariaDB/MySQL usa la semántica de `utf8mb4_unicode_ci`, sin distinguir mayúsculas o acentos. Conserva el orden de las palabras y los espacios interiores; no realiza búsqueda aproximada ni reordena palabras. `%`, `_` y `=` se interpretan literalmente. La consulta utiliza parámetros enlazados y escape explícito `=` para esos símbolos; no se admiten comodines introducidos por el usuario. Un tipo no nulo distinto de cadena o una cadena que exceda el límite devuelve `422` con el formato de validación vigente.

El filtro se combina mediante AND con el operador, fecha y demás filtros, incluida una consulta exacta por `installment_id`. Se aplica en Préstamos antes de evaluar elegibilidad y paginar. No amplía permisos, alcance o modalidades admitidas; tampoco modifica la estructura de la respuesta, versiones o estados. Omitirlo conserva el comportamiento anterior. Cajas muestra «Nombre del cliente», conserva el valor al cambiar de página y comienza en página 1 al realizar una nueva búsqueda. «Limpiar filtros» elimina también el nombre. El adelanto general por folio conserva su preparación cuando el folio es el único filtro de búsqueda; añadir un nombre, modelo o día no ofrece un grupo de adelanto que ignore ese filtro.

```http
GET /api/v1/cajas/pagares?operator=op_42&cut_on=2026-11-13&client_name=Jos%C3%A9%20P%C3%A9rez&page=1
```

**Préstamos responde `200`** con la lista y el indicador de paginación. Una búsqueda sin coincidencias devuelve `data: []`.

```json
{
  "currency": "MXN",
  "cut_on": "2026-11-13",
  "data": [
    {
      "loan_id": "loan_810",
      "folio": "ORV-810",
      "client": "Cliente de ejemplo",
      "model": "Versa",
      "day": 15,
      "installment_id": "ins_324",
      "number": 4,
      "status": "pending",
      "due_on": "2026-11-15",
      "remaining_cents": 1200000,
      "principal_cents": 1000000,
      "interest_cents": 200000,
      "optional_collection_fee_cents": 0,
      "allowed_payment_types": ["ordinary"],
      "version": "p7"
    }
  ],
  "has_more": false
}
```

## 2. Validar el corte antes de confirmar

**Cajas envía** los conceptos de la versión que Herbe revisa. Préstamos usa estas reglas tanto en el catálogo como al validar y aplicar.

| Tipo | Regla sobre la fecha efectiva del corte | Regla sobre importe y destino |
| --- | --- | --- |
| `ordinary` | Letra del mismo mes del corte o de un mes anterior, aunque el día de vencimiento todavía no llegue. | Importe contractual positivo, parcial o completo, hasta el saldo pendiente de una sola letra. |
| `capital` | Letra elegida con vencimiento en un mes posterior. | Exige todo el capital pendiente. La letra queda adelantada y con saldo contractual cero. El interés que deja de exigirse se informa aparte y no se registra como efectivo, pago de interés ni retorno. |
| `capital_interest` | Letra elegida con vencimiento en un mes posterior. | Exige el saldo contractual completo, incluido el capital y el interés. |
| `general_capital` | Solo letras pendientes de meses posteriores al corte y del mismo crédito. Es una restricción deliberada de la v1 de Cajas respecto del adelanto general de Préstamos. | El concepto identifica un grupo ordenado desde la última letra pendiente hacia atrás, sin saltos. Cada elemento incluye su ID, versión y capital pendiente completo. Un cobro pendiente de confirmar o un bloqueo que afecte el grupo obliga a revisarlo. El total contractual debe coincidir con la suma del grupo. Cada letra satisfecha queda adelantada y con saldo cero. |

El uso inicial cubre créditos regulares y redondeados con capital e interés. Una cuota de cobranza opcional configurada por separado se informa en `optional_collection_fee_cents` y por sí sola no cambia la elegibilidad ni el saldo contractual. Cuando se incluye la cuota en el cobro, el pago completo corresponde a Préstamos. También quedan fuera moratorios, IVA, cuotas contractuales de administración, créditos de solo intereses, **Pagado sin efectos** y liquidación total anticipada. Pagar por completo una letra individual sí está permitido. Catálogo, validación y aplicación comparten las reglas de elegibilidad y canal. Una modalidad excluida devuelve `422 PAYMENT_NOT_SUPPORTED`; un cobro con la cuota incluida devuelve `422 CAPTURE_CHANNEL_MISMATCH` al validar o el mismo código en el resultado `requires_review` si se detecta al aplicar. Un pago previo por confirmar puede impedir la aplicación y se informa como `PAYMENT_PENDING_CONFIRMATION`.

`contract_amount_cents` es lo aplicado al contrato. `reported_amount_cents` es el importe bruto reportado antes de compensar y puede diferir del efectivo que recibe Adriana. `extras` desglosa conceptos cobrados fuera del contrato y debe llegar vacío desde Cajas. Con la misma letra de $4,900 de capital e interés y $300 de cuota opcional, el catálogo devuelve `remaining_cents: 490000`, `optional_collection_fee_cents: 30000` y `ordinary` para el mes del corte. Este es el concepto elegible.

```json
{
  "item_id": "item_fee_01",
  "installment_id": "ins_325",
  "installment_version": "p2",
  "payment_type": "ordinary",
  "contract_amount_cents": 490000,
  "reported_amount_cents": 490000,
  "extras": []
}
```

Si se pretende cobrar $5,200, se captura completo en Préstamos como $4,900 contractuales y $300 de cuota. Si Cajas intenta enviarlo con `reported_amount_cents: 520000` y `extras: [{"type": "collection_fee", "amount_cents": 30000}]`, la validación devuelve `422`.

```json
{
  "error": {
    "code": "CAPTURE_CHANNEL_MISMATCH",
    "message": "El cobro incluye cuota de cobranza y corresponde a Préstamos.",
    "details": [{"item_id": "item_fee_01", "installment_id": "ins_325", "channel": "prestamos"}]
  }
}
```

Si un pago de esa letra ya se capturó por Préstamos, su estado y versión actualizados prevalecen al validar cualquier intento posterior en Cajas.

Ejemplo independiente de concepto agrupado para un corte del 13 de noviembre. Las dos letras son futuras, contiguas y corresponden al final del calendario vigente del mismo crédito.

```json
{
  "item_id": "item_gc_01",
  "loan_id": "loan_813",
  "payment_type": "general_capital",
  "contract_amount_cents": 300000,
  "reported_amount_cents": 300000,
  "extras": [],
  "installments": [
    {"installment_id": "ins_388", "installment_version": "p3", "principal_cents": 150000},
    {"installment_id": "ins_387", "installment_version": "p2", "principal_cents": 150000}
  ]
}
```

Para los otros tipos se envían `installment_id` e `installment_version` directamente en el concepto. En la validación y el resultado de aplicación se devuelve un registro por letra en `allocations` con su ID, capital aplicado, interés aplicado, interés que deja de exigirse y saldo resultante. El reparto de un pago parcial lo calcula Préstamos. `interest_waived_cents` es cero cuando se cobra el interés aplicable según las reglas del pago.

```http
POST /api/v1/cajas/cortes/validaciones
Content-Type: application/json
```

```json
{
  "cut_id": "cut_1042",
  "version": 3,
  "operator": "op_42",
  "cut_on": "2026-11-13",
  "currency": "MXN",
  "items": [
    {
      "item_id": "item_01",
      "installment_id": "ins_324",
      "installment_version": "p7",
      "payment_type": "ordinary",
      "contract_amount_cents": 1200000,
      "reported_amount_cents": 1200000,
      "extras": []
    }
  ]
}
```

**Préstamos responde `200`** con el desglose previsto. Esta consulta solo calcula y verifica. El envío autorizado vuelve a validar bajo bloqueo de los datos afectados.

```json
{
  "cut_id": "cut_1042",
  "version": 3,
  "currency": "MXN",
  "status": "valid",
  "items": [
    {
      "item_id": "item_01",
      "contract_amount_cents": 1200000,
      "reported_amount_cents": 1200000,
      "extras": [],
      "allocations": [
        {
          "installment_id": "ins_324",
          "principal_cents": 1000000,
          "interest_cents": 200000,
          "interest_waived_cents": 0,
          "remaining_after_cents": 0
        }
      ]
    }
  ]
}
```

### Si cambió una letra

**Préstamos responde `409`** si la validación encuentra una versión distinta. Si la letra fue sustituida, el código es `INSTALLMENT_REPLACED` y el detalle incluye `replacement_installment_ids` autorizados. Cajas marca el corte **Requiere revisión**, muestra la diferencia, vuelve a consultar el pagaré y permite corregir el corte. Si ya existía un recibo, conserva el efectivo real registrado y vuelve a conciliar cualquier diferencia. Herbe revisa y confirma la nueva versión con los datos actualizados.

```json
{
  "error": {
    "code": "INSTALLMENT_CHANGED",
    "message": "Cambió el saldo de una letra del corte.",
    "cut_id": "cut_1042",
    "version": 3,
    "details": [
      {
        "item_id": "item_01",
        "installment_id": "ins_324",
        "sent_version": "p7",
        "current_version": "p8",
        "current_remaining_cents": 1100000
      }
    ]
  }
}
```

## 3. Enviar el corte autorizado

**Cajas envía** la versión completa autorizada. Guarda en una transacción propia la aprobación, el cuerpo y el mensaje pendiente de envío. El botón **Confirmar corte** dispara este envío. La clave `cut:{cut_id}:v{version}` identifica esa ejecución.

El ejemplo concilia $12,000 de cobros con $3,000 recibidos y $9,000 compensados para un crédito nuevo. Cajas verifica los recibos, compensaciones y egresos contra sus asientos efectivos antes de autorizar. Préstamos verifica las referencias, su unicidad, el vínculo con el corte o crédito, y recalcula los totales del detalle autorizado.

| Total | Se obtiene de |
| --- | --- |
| `reported_cents` | Suma de `items[].reported_amount_cents`. |
| `received_cents` | Suma de `receipts[].amount_cents`, saldo efectivo de cada recibo después de ajustes asentados. |
| `offset_cents` | Suma de `offsets[].amount_cents`, importes efectivos compensados para los créditos identificados. |
| `paid_cents` | Suma de `payouts[].amount_cents` menos `payout_returns[].amount_cents`. Es el efectivo neto entregado al operador para cubrir la diferencia. |

Debe cumplirse `reportado − recibido − compensado + pagado = pendiente` y `remaining_cents = 0`. Si el cuerpo recibido no cuadra con su propio detalle, Préstamos responde `422 CUT_TOTALS_MISMATCH` antes de aceptarlo. Si la revalidación de una ejecución aceptada detecta una discrepancia de conciliación, el resultado del corte es `requires_review`. Un desembolso directo registrado en `disbursements` sirve como referencia de fondeo. Su importe queda fuera de compensaciones y egresos del corte. Para un crédito sin dinero ejecutado se envía `funding: {"type": "unfunded"}` y el alta sigue pendiente del fondeo.

Cada egreso en `payouts` incluye `payout_id`, `cash_account_id`, `amount_cents` y `paid_on`. Cada registro en `disbursements` incluye `disbursement_id`, `pending_credit_id`, `cash_account_id`, `amount_cents` y `disbursed_on`. En ese caso, `funding.type` es `disbursement` y `funding.reference_id` apunta al desembolso.

Si una ejecución queda en revisión y el egreso al operador supera la diferencia recalculada, Cajas conserva el egreso original y bloquea la nueva autorización hasta comprobar la devolución física del excedente. Una persona habilitada confirma la entrada independiente y su evidencia. Cajas emite una nueva versión conciliada con una nueva autorización de Herbe. `payout_returns` es una lista opcional del mismo cuerpo firmado. Si se omite, equivale a una lista vacía. Cada entrada contiene `payout_return_id`, `payout_id`, `cash_account_id` de la caja receptora, `amount_cents` positivo, `received_on`, `confirmed_by`, `confirmed_at` en UTC y `evidence_ref`. La devolución referencia un egreso del mismo corte, no puede ser anterior a él y la suma de sus devoluciones no puede superar el original. Cajas coteja actor, evidencia y asiento físico. Préstamos verifica el cuerpo firmado, los vínculos, fechas e identidades únicas. El mismo corte puede conservar referencias sin cambiar sus datos en una nueva versión si la anterior no se aplicó.

Una versión posterior conserva las referencias de egresos y devoluciones ya aceptadas para ese corte. Cambiarlas, omitirlas o reutilizarlas en otro corte devuelve `409 PAYOUT_REFERENCE_CONFLICT`. Una devolución sin egreso correspondiente, con fecha anterior o cuyo acumulado supera el original devuelve `422 PAYOUT_RETURN_INVALID`.

### Cuenta del operador

El signo positivo aumenta lo que el operador debe entregar y el negativo lo reduce. Préstamos registra los siguientes efectos en la cuenta del operador, vinculados al ID del corte y su versión. Cada efecto tiene una referencia única al hecho de origen. Si un efecto ya existe por el mismo hecho, se vincula el asiento existente y se valida que importe y signo coincidan. Los reintentos y la formalización del crédito no repiten el efecto.

| Hecho | Signo y referencia en Préstamos |
| --- | --- |
| Cobro del corte | Positivo por el importe reportado, con el ID del concepto y el ID del pago aplicado. Si el motor ya lo registró, se usa ese asiento. |
| Recibo efectivo | Negativo por el importe recibido, con el ID del recibo en Cajas. |
| Compensación ejecutada | Negativo por el importe compensado, con el ID de la compensación y del crédito pendiente. |
| Egreso para cubrir el corte | Positivo por el importe pagado al operador, con el ID del egreso en Cajas. Contrarresta la parte de la compensación que cubrió ese egreso. |
| Devolución física de egreso del corte | Negativo por el importe devuelto, con el ID de la devolución y su referencia al payout original. Préstamos conserva ambos hechos con referencias únicas y registra su efecto neto al aplicar la versión conciliada. `payout_effect_cents` expresa ese neto. |
| Desembolso directo | Referencia de fondeo por ID del desembolso, fuera del saldo del corte. Si Cajas identifica al operador como receptor y el importe queda bajo su responsabilidad, Préstamos registra un cargo positivo separado por el importe entregado. Si Cajas lo entrega directamente al cliente, solo guarda la referencia de fondeo. |

En el ejemplo, Préstamos registra **+$12,000** de cobros, **−$3,000** del recibo y **−$9,000** de compensación. El corte aporta **$0 de diferencia** a la cuenta del operador. Cajas conserva **+$3,000** de ingreso real. El corte informa su diferencia propia y no sustituye saldos previos del operador. Un traspaso posterior entre cajas tampoco vuelve a afectar esa cuenta.

```http
POST /api/v1/cajas/cortes
Content-Type: application/json
Idempotency-Key: cut:cut_1042:v3
Approval-Proof: <prueba firmada por Cajas>
```

```json
{
  "cut_id": "cut_1042",
  "version": 3,
  "operator": "op_42",
  "cut_on": "2026-11-13",
  "currency": "MXN",
  "prepared_by": "usr_adriana",
  "approval": {
    "id": "approval_981",
    "approved_by": "usr_herbe",
    "approved_at": "2026-11-14T00:40:00Z"
  },
  "items": [
    {
      "item_id": "item_01",
      "installment_id": "ins_324",
      "installment_version": "p7",
      "payment_type": "ordinary",
      "contract_amount_cents": 1200000,
      "reported_amount_cents": 1200000,
      "extras": []
    }
  ],
  "totals": {
    "reported_cents": 1200000,
    "received_cents": 300000,
    "offset_cents": 900000,
    "paid_cents": 0,
    "remaining_cents": 0
  },
  "receipts": [
    {
      "receipt_id": "receipt_73",
      "cash_account_id": "cash_adriana",
      "amount_cents": 300000,
      "received_on": "2026-11-13"
    }
  ],
  "offsets": [
    {
      "offset_id": "offset_22",
      "pending_credit_id": "pending_224",
      "amount_cents": 900000,
      "executed_on": "2026-11-13",
      "confirmation_id": "confirm_22",
      "confirmed_by": "usr_adriana",
      "confirmed_at": "2026-11-14T00:30:00Z",
      "evidence_ref": "record_offset_22"
    }
  ],
  "payouts": [],
  "disbursements": [],
  "pending_credits": [
    {
      "pending_credit_id": "pending_224",
      "client_id": "client_502",
      "terms": {
        "principal_cents": 900000,
        "monthly_rate_percent": "5.0000",
        "term_months": 6,
        "start_on": "2026-11-13",
        "first_payment_on": "2026-12-15",
        "payment_day": 15,
        "calculation_method": "regular",
        "interest_calculation_method": "fixed_principal",
        "administration_fee_cents": 0,
        "vat_enabled": false,
        "rounding_option": "hundreds",
        "rounding_adjustment": "first"
      },
      "proposed_schedule": [
        {"number": 1, "due_on": "2026-12-15", "principal_cents": 175000, "interest_cents": 45000, "administration_fee_cents": 0, "vat_cents": 0, "amount_cents": 220000},
        {"number": 2, "due_on": "2027-01-15", "principal_cents": 145000, "interest_cents": 45000, "administration_fee_cents": 0, "vat_cents": 0, "amount_cents": 190000},
        {"number": 3, "due_on": "2027-02-15", "principal_cents": 145000, "interest_cents": 45000, "administration_fee_cents": 0, "vat_cents": 0, "amount_cents": 190000},
        {"number": 4, "due_on": "2027-03-15", "principal_cents": 145000, "interest_cents": 45000, "administration_fee_cents": 0, "vat_cents": 0, "amount_cents": 190000},
        {"number": 5, "due_on": "2027-04-15", "principal_cents": 145000, "interest_cents": 45000, "administration_fee_cents": 0, "vat_cents": 0, "amount_cents": 190000},
        {"number": 6, "due_on": "2027-05-15", "principal_cents": 145000, "interest_cents": 45000, "administration_fee_cents": 0, "vat_cents": 0, "amount_cents": 190000}
      ],
      "funding": {"type": "offset", "reference_id": "offset_22"}
    }
  ]
}
```

Cada crédito hereda el operador del corte. Para un cliente nuevo, `client` sustituye a `client_id` y contiene `name` y `phone`. `monthly_rate_percent` es una cadena decimal en porcentaje con hasta cuatro decimales. `"5.0000"` significa **5 % mensual**. El motor de Préstamos comprueba el calendario contractual antes de formalizar.

**Préstamos responde `202`** después de verificar la autorización, guardar el corte y registrar su ejecución pendiente. Préstamos revalida bajo bloqueo y aplica automáticamente. Dentro de una misma transacción guarda todos los pagos y retornos, los efectos en la cuenta del operador, las propuestas con su estado y el resultado aplicado. Si en la aplicación falla un cobro, la conciliación o la validación de una referencia de fondeo, revierte todos esos efectos y deja el corte completo en `requires_review`, con `payments: []` y `pending_credits: []`. El recibo real y los asientos efectivos permanecen en Cajas. Un corte conciliado con solo créditos puede terminar `applied` con `payments: []`.

Durante la aplicación y antes de confirmar la transacción, Préstamos recalcula el calendario de cada propuesta con datos válidos. Si solo difiere el calendario, aplica los cobros válidos, conserva la compensación registrada y deja el corte en `applied`. Solo el crédito afectado queda en `requires_review` con `SCHEDULE_MISMATCH`. Su alta queda bloqueada hasta que Herbe confirme las condiciones corregidas y Préstamos revalide el calendario. Un fallo temporal del motor reintenta la ejecución y conserva la atomicidad.

```http
HTTP/1.1 202 Accepted
Location: /api/v1/cajas/cortes/cut_1042/versiones/3
```

```json
{
  "cut_id": "cut_1042",
  "version": 3,
  "status": "received"
}
```

Un POST idéntico devuelve el mismo `202` y `Location` de la ejecución registrada. El recurso indicado devuelve su resultado actual. La vigencia de la prueba y los permisos del revisor se comprueban en la primera aceptación. Una versión nueva puede enviarse cuando la anterior quedó en revisión. Préstamos serializa la aceptación de versiones por ID público del corte. Una versión distinta mientras existe una aplicación en curso devuelve `409 CUT_IN_PROGRESS`. Si el corte ya se aplicó, una versión distinta devuelve `409 CUT_ALREADY_APPLIED` y el cambio se tramita por corrección. Una versión anterior que nunca se aceptó devuelve `409 CUT_VERSION_STALE` si ya hay una versión superior registrada.

### Verificar el permiso de Herbe en Cajas

Préstamos consulta con su credencial de servicio. Cajas coteja la autorización registrada con el corte, la versión, el operador, el revisor y la operación solicitada. Evalúa el permiso vigente y responde `200` con `allowed: true` o `allowed: false`. Si el permiso fue revocado, responde `allowed: false` y `reason: "PERMISSION_REVOKED"`. Préstamos solo acepta por primera vez con una respuesta afirmativa.

```http
POST /api/v1/cajas/autorizaciones/approval_981/verificar
Content-Type: application/json
```

```json
{
  "operation": "apply_cut",
  "cut_id": "cut_1042",
  "version": 3,
  "operator": "op_42",
  "reviewer_id": "usr_herbe"
}
```

```json
{"allowed": true}
```

### Canal de captura durante la transición

Antes de habilitar un operador y una fecha efectiva, ambos sistemas comparten la asignación de canal y concilian los pagos nativos en curso. Para los operadores y fechas habilitados, Cajas captura los conceptos que cubre la v1 y la captura nativa de Préstamos queda bloqueada para esos conceptos. Préstamos conserva la captura de fechas y operadores aún fuera del flujo, y de las modalidades o conceptos excluidos. Si un cobro incluye un componente excluido, el cobro completo se tramita en Préstamos. Cajas consulta la cartera actualizada antes de volver a preparar ese corte.

Las reglas de canal se verifican en los backends, incluida la revalidación bajo bloqueo. Si ya existe un cobro pendiente por el canal nativo, devuelve `PAYMENT_PENDING_CONFIRMATION` y el corte requiere revisión. Si se intenta enviar desde el canal equivocado, devuelve `CAPTURE_CHANNEL_MISMATCH`. Un cambio de asignación exige identificar y conciliar primero los cobros en tránsito. Para los cobros que siguen en Préstamos, cualquier efectivo bajo custodia se registra en Cajas con el ID y folio de ese pago como referencia, fuera del envío de cobros del corte.

## 4. Consultar el resultado de aplicación

**Cajas envía** el ID público y la versión en la ruta. **Préstamos responde `200`**. Cajas conserva separadas la aprobación local y la respuesta de Préstamos.

| Estado | Qué confirma |
| --- | --- |
| Autorizado en Cajas | Herbe aprobó la versión y Cajas guardó el envío pendiente. |
| `received` | Préstamos guardó la versión para procesarla. |
| `processing` | Préstamos está aplicando la versión. |
| `applied` | Préstamos confirmó la transacción completa y devuelve sus folios. |
| `requires_review` | Existe un conflicto de negocio. Préstamos no aplicó ningún concepto de esa versión. |

Cajas muestra **Pendiente de aplicar** cuando ya tiene autorización y el resultado es desconocido, recibido o en aplicación.

La consulta conserva el resultado original de la aplicación. Las correcciones posteriores devuelven sus propios folios. Si falla temporalmente el proceso de aplicación en Préstamos, este recupera la ejecución pendiente con la misma identidad. `requires_review` indica una diferencia de negocio que requiere intervención.

```http
GET /api/v1/cajas/cortes/cut_1042/versiones/3
```

```json
{
  "cut_id": "cut_1042",
  "version": 3,
  "status": "applied",
  "currency": "MXN",
  "applied_at": "2026-11-14T00:40:03Z",
  "payments": [
    {
      "item_id": "item_01",
      "payment_id": "pay_3785",
      "payment_folio": "PAG-3785",
      "contract_amount_cents": 1200000,
      "reported_amount_cents": 1200000,
      "extras": [],
      "allocations": [
        {
          "installment_id": "ins_324",
          "principal_cents": 1000000,
          "interest_cents": 200000,
          "interest_waived_cents": 0,
          "remaining_after_cents": 0
        }
      ]
    }
  ],
  "operator_account": {
    "reported_cents": 1200000,
    "received_effect_cents": -300000,
    "offset_effect_cents": -900000,
    "payout_effect_cents": 0,
    "difference_cents": 0
  },
  "pending_credits": [{"pending_credit_id": "pending_224", "status": "pending"}]
}
```

Si un calendario queda en revisión, el corte conserva `status: "applied"` y la entrada de ese crédito en `pending_credits` cambia como en este ejemplo alternativo.

```json
{
  "pending_credit_id": "pending_224",
  "status": "requires_review",
  "observations": [
    {
      "code": "SCHEDULE_MISMATCH",
      "message": "El vencimiento de la primera letra difiere.",
      "details": [{"number": 1, "field": "due_on", "proposed": "2026-12-14", "calculated": "2026-12-15"}]
    }
  ]
}
```

Si la letra cambia **después de la validación y antes de aplicar**, la consulta devuelve el resultado persistido de esa ejecución. Es `200` porque la consulta se completó correctamente.

```json
{
  "cut_id": "cut_1042",
  "version": 3,
  "status": "requires_review",
  "currency": "MXN",
  "payments": [],
  "pending_credits": [],
  "error": {
    "code": "INSTALLMENT_CHANGED",
    "message": "Cambió el saldo de una letra antes de aplicar.",
    "details": [
      {
        "item_id": "item_01",
        "installment_id": "ins_324",
        "sent_version": "p7",
        "current_version": "p8",
        "current_remaining_cents": 1100000
      }
    ]
  }
}
```

### Si falla el envío o se pierde el acuse

1. Si el POST expira, se corta la conexión o responde `429` o `5xx`, Cajas conserva la aprobación. Un `503 APPROVAL_CHECK_UNAVAILABLE` indica que Préstamos todavía no aceptó el corte y el envío queda pendiente de reintento. Para fallos de comunicación sin ese diagnóstico, Cajas muestra **Pendiente de aplicar** hasta recuperar el estado.
2. Cajas consulta `GET /api/v1/cajas/cortes/cut_1042/versiones/3`. Si devuelve `applied`, guarda los IDs, folios y saldos. Si devuelve `received` o `processing`, sigue consultando. Si devuelve `requires_review`, muestra el motivo y abre el flujo de revisión.
3. Si devuelve `404`, Cajas reenvía el **mismo contenido** y `Idempotency-Key: cut:cut_1042:v3`. Si entretanto Préstamos ya había aceptado el corte, devuelve el acuse registrado aunque la prueba haya vencido o Herbe ya no tenga el permiso de revisión. Si todavía es una primera aceptación, verifica la prueba y consulta el permiso vigente en Cajas. Si la prueba vence antes de aceptar, Cajas puede renovarla sobre el mismo cuerpo únicamente si la aprobación sigue vigente y el revisor conserva permiso. Si la consulta tampoco responde, vuelve a intentar con espera creciente y respeta `Retry-After` cuando venga en la respuesta.

Ejemplo de `503` del envío inicial cuando Préstamos no puede consultar a Cajas.

```json
{
  "error": {
    "code": "APPROVAL_CHECK_UNAVAILABLE",
    "message": "Cajas no respondió a la consulta del permiso vigente."
  }
}
```

Ejemplo de `404` para un folio y una versión que Préstamos todavía no tiene registrados.

```json
{
  "error": {
    "code": "CUT_VERSION_NOT_FOUND",
    "message": "La versión indicada todavía no está registrada."
  }
}
```

Si la comunicación sigue caída, el corte queda pendiente y se puede reintentar con la misma autorización. La consulta y el reenvío jamás generan otro recibo ni otra autorización.

## 5. Consultar la resolución de un crédito nuevo

**Cajas envía** el identificador que mandó dentro del corte. Préstamos registra la propuesta pendiente al aplicar el corte autorizado. Cajas confirma la ejecución del dinero y Herbe autoriza por separado el alta desde el formulario precargado en Préstamos. Son tres actos con referencias y fechas propias.

Antes del alta, Adriana u otra persona de Cajas autorizada para la operación y esa caja puede registrar una compensación ejecutada o confirmar una entrega real desde caja con saldo suficiente. Cajas conserva el actor, la fecha, el importe y la evidencia. Una compensación o desembolso cubre el capital completo y se vincula a un único crédito formalizado. Si un pago de diferencia del corte respalda una compensación, conserva su asiento en `payouts` y su referencia al corte. El préstamo se vincula a la compensación y no vuelve a registrar esa salida como desembolso directo. Formalizar conserva el movimiento original y su reflejo financiero previo sin volver a asentar una salida ni un desembolso en la cuenta del operador.

Si Herbe cambia condiciones económicas o de calendario después de ejecutar el dinero, el crédito queda `requires_review`. Préstamos devuelve las observaciones y Cajas concilia el movimiento original. Herbe puede formalizar cuando las nuevas condiciones estén confirmadas, la conciliación tenga evidencia y el importe ejecutado siga cubriendo íntegramente el capital. Si rechaza las condiciones y se requiere devolver dinero, revertir una compensación o cubrir una diferencia, Cajas resuelve el ajuste mediante un movimiento autorizado y vinculado. Se conserva el historial de lo entregado. El alta sigue bloqueada mientras exista una diferencia sin resolver.

### Modalidades y comparación del calendario

El alta v1 admite la modalidad `regular`, mensual, con interés sobre capital fijo, `rounding_option` en `tens` o `hundreds` y el ajuste en la primera letra. Para este alcance, IVA y cuota contractual de administración son cero. La modalidad `rounded` del préstamo es distinta de redondear las letras de un crédito `regular`. La v1 permite cobrar créditos `rounded` elegibles, pero su alta queda fuera de este contrato junto con el alta de créditos de solo intereses.

Para altas se admiten los plazos habilitados por el catálogo de Préstamos. Los valores iniciales de referencia son 6, 12, 18, 24, 30, 36, 40 y 48 meses. El ejemplo usa 6 meses y presupone que el plazo está habilitado. Préstamos verifica el plazo al recibir la propuesta y de nuevo al formalizar.

Al aplicar el corte y de nuevo antes de crear el crédito vigente, el motor compara el número de letras y, por número, fecha de vencimiento, capital, interés, cuota contractual de administración, IVA y total. Compara importes en centavos exactos, el capital total y el total del calendario. Ante una diferencia devuelve `SCHEDULE_MISMATCH` con la letra y los campos que cambiaron. Se conserva el calendario propuesto. Para liberar el alta, Herbe confirma las condiciones corregidas y Préstamos verifica que el calendario corresponda a esas condiciones.

```http
GET /api/v1/cajas/creditos-pendientes/pending_224
```

**Préstamos responde `200`** con `pending`, `requires_review` o `active`, y el estado de fondeo. En revisión incluye `observations` con código y mensaje. El folio, ID y calendario definitivo se incluyen cuando está vigente.

```json
{
  "pending_credit_id": "pending_224",
  "cut_id": "cut_1042",
  "cut_version": 3,
  "status": "active",
  "loan_id": "loan_921",
  "loan_folio": "ORV-921",
  "currency": "MXN",
  "funding": {"type": "offset", "status": "confirmed", "version": 1, "reference_id": "offset_22"},
  "schedule": [
    {"number": 1, "installment_id": "ins_402", "due_on": "2026-12-15", "principal_cents": 175000, "interest_cents": 45000, "administration_fee_cents": 0, "vat_cents": 0, "amount_cents": 220000},
    {"number": 2, "installment_id": "ins_403", "due_on": "2027-01-15", "principal_cents": 145000, "interest_cents": 45000, "administration_fee_cents": 0, "vat_cents": 0, "amount_cents": 190000},
    {"number": 3, "installment_id": "ins_404", "due_on": "2027-02-15", "principal_cents": 145000, "interest_cents": 45000, "administration_fee_cents": 0, "vat_cents": 0, "amount_cents": 190000},
    {"number": 4, "installment_id": "ins_405", "due_on": "2027-03-15", "principal_cents": 145000, "interest_cents": 45000, "administration_fee_cents": 0, "vat_cents": 0, "amount_cents": 190000},
    {"number": 5, "installment_id": "ins_406", "due_on": "2027-04-15", "principal_cents": 145000, "interest_cents": 45000, "administration_fee_cents": 0, "vat_cents": 0, "amount_cents": 190000},
    {"number": 6, "installment_id": "ins_407", "due_on": "2027-05-15", "principal_cents": 145000, "interest_cents": 45000, "administration_fee_cents": 0, "vat_cents": 0, "amount_cents": 190000}
  ]
}
```

## 6. Comunicar el fondeo de un crédito pendiente

Un crédito `unfunded` se puede completar después mediante un **desembolso directo confirmado en Cajas**. La compensación desde un corte posterior queda fuera de la v1. Herbe puede pasarlo a `active` después de que Préstamos confirme el fondeo y valide las condiciones y el calendario.

En la primera aceptación de un evento de fondeo, Préstamos verifica `Funding-Proof`, la confirmación firmada de Cajas que vincula el entorno, crédito pendiente, operador, evento, versión anterior, acción y resumen SHA-256 del cuerpo. La evidencia incluye `confirmation_id`, `confirmed_by`, `confirmed_at` y `evidence_ref`. Cajas acredita en esa prueba que verificó el permiso del actor para la acción y la caja al confirmar. Préstamos verifica la firma, vigencia, identificación del actor y alcance del servicio. Verifica también importe, receptor, fecha, referencia del asiento y unicidad del fondeo. La evidencia detallada queda en Cajas y su referencia se conserva para auditoría. `confirmed_by` por sí solo identifica al actor. Una prueba inválida devuelve `403 FUNDING_PROOF_INVALID`. Para un fondeo incluido en el corte original, la prueba del corte autenticada cubre las mismas evidencias incluidas en su cuerpo y el permiso del actor acreditado por Cajas.

Cada registro inicial de compensación o desembolso enviado dentro del corte incluye `confirmation_id`, `confirmed_by`, `confirmed_at` y `evidence_ref`. Para desembolso directo incluye también `recipient`, con `type` igual a `client` u `operator` e `id` del receptor. El fondeo referenciado en el corte empieza en versión 1 y `confirmed`. Un crédito `unfunded` empieza en versión 0 y `unfunded`.

Ejemplo con otro crédito. La clave es `funding:{event_id}`. Préstamos conserva y devuelve el resultado del evento en un reenvío idéntico. Cada evento nuevo exige que `expected_funding_version` coincida con la versión registrada y aumenta la versión en uno. Una versión distinta, una referencia usada en otro crédito o un cambio de fondeo después del alta devuelve `409 FUNDING_CONFLICT`.

```http
POST /api/v1/cajas/creditos-pendientes/pending_225/fondeo
Content-Type: application/json
Idempotency-Key: funding:fnd_evt_52
Funding-Proof: <confirmación firmada por Cajas>
```

```json
{
  "operator": "op_42",
  "currency": "MXN",
  "event_id": "fnd_evt_52",
  "action": "confirm",
  "expected_funding_version": 0,
  "disbursement_id": "disb_52",
  "cash_account_id": "cash_herbe",
  "recipient": {"type": "client", "id": "client_503"},
  "amount_cents": 750000,
  "disbursed_on": "2026-11-14",
  "confirmation_id": "confirm_52",
  "evidence_ref": "record_disb_52",
  "confirmed_by": "usr_herbe",
  "confirmed_at": "2026-11-14T16:35:00Z"
}
```

Préstamos devuelve `200` tras vincular el fondeo. El alta todavía requiere la autorización de Herbe.

```json
{
  "pending_credit_id": "pending_225",
  "event_id": "fnd_evt_52",
  "status": "pending",
  "currency": "MXN",
  "funding": {"type": "disbursement", "status": "confirmed", "version": 1, "reference_id": "disb_52", "amount_cents": 750000}
}
```

### Cambiar o cancelar un fondeo confirmado

Cajas envía por la misma ruta un evento firmado con `action: "hold"`, `reference_id` del fondeo vigente y `reason`, además de los campos comunes de identificación, versión y confirmación del evento. Préstamos serializa este evento con el alta y verifica la versión de fondeo vigente al formalizar. Al aceptar el bloqueo, devuelve `200` con el ID del evento, la nueva versión de fondeo en `under_reconciliation` y el crédito en `requires_review`. Cajas espera ese acuse antes de ejecutar una cancelación o cambio planificados. Si el alta ya ocurrió, Préstamos devuelve `409 FUNDING_CONFLICT` y el caso pasa a la revisión del crédito vigente.

Cuando el dinero ya está conciliado, Cajas envía un evento firmado `cancel` o `replace`, con la versión del bloqueo, referencia original, motivo y `reconciliation_id` del ajuste efectivo en Cajas. Préstamos verifica que el ajuste corresponde a la referencia bloqueada y conserva ambos registros. `cancel` deja el fondeo `unfunded` y el alta bloqueada. `replace` incluye los mismos campos del desembolso de `confirm` para el nuevo movimiento, que debe cubrir todo el capital. El alta se habilita para revisión de Herbe cuando el nuevo fondeo está confirmado y el calendario es válido. Préstamos refleja el ajuste en la cuenta del operador solo si cambia un efecto previo, mediante una entrada vinculada a `reconciliation_id`. Una reversa completa usa el mismo importe con el signo opuesto al asiento original. El saldo histórico del corte conserva su diferencia al momento de aplicarse. Si existe un ajuste físico imprevisto, Cajas conserva su evidencia, comunica el bloqueo de inmediato y concilia según el estado del crédito.

Ejemplo de cancelación después de recibir acuse del bloqueo de la versión 1. El bloqueo dejó la versión 2.

```json
{
  "operator": "op_42",
  "currency": "MXN",
  "event_id": "fnd_evt_54",
  "action": "cancel",
  "expected_funding_version": 2,
  "reference_id": "disb_52",
  "reason": "Se recibió la devolución completa del desembolso.",
  "reconciliation_id": "cash_adj_52",
  "confirmation_id": "confirm_54",
  "confirmed_by": "usr_herbe",
  "confirmed_at": "2026-11-14T17:10:00Z",
  "evidence_ref": "record_cash_adj_52"
}
```

```json
{
  "pending_credit_id": "pending_225",
  "event_id": "fnd_evt_54",
  "status": "requires_review",
  "currency": "MXN",
  "funding": {"type": "unfunded", "status": "unfunded", "version": 3},
  "observations": [{"code": "FUNDING_CANCELLED", "message": "El alta requiere un nuevo desembolso confirmado."}]
}
```

## 7. Consultar capital disponible para prestar

**Cajas envía** la moneda y el alcance `portfolio`. **Préstamos responde `200`** con la suma del capital disponible de los inversionistas activos habilitados para fondear la cartera dentro del alcance de la credencial. Se excluyen inversionistas inactivos o suspendidos, capital ya asignado y retornos que aún no se han reinvertido. Una reinversión se refleja en el agregado cuando Préstamos la registra. `as_of` indica el instante del saldo leído. Esta consulta es informativa y no reserva capital.

```http
GET /api/v1/cajas/capital-disponible?scope=portfolio&currency=MXN
```

```json
{
  "scope": "portfolio",
  "currency": "MXN",
  "available_principal_cents": 25000000,
  "as_of": "2026-11-14T00:45:00Z"
}
```

## 8. Consultar saldo de Caja Herbe

**Préstamos envía** el ID público de la caja y la moneda. **Cajas responde `200`**. Ambas pantallas leen de Cajas el efectivo bajo custodia. El capital disponible de Préstamos puede tener un importe distinto. `as_of` indica el instante del saldo leído. La consulta no reserva efectivo.

Un traspaso entregado reduce el disponible del origen y cuenta en tránsito en ambas cajas hasta su aceptación. Al aceptarse, sale de tránsito y aumenta el disponible del destino. Un traspaso en disputa sigue incluido en tránsito, con su importe identificado también en `*_disputed_cents`. Este desglose es un subconjunto de tránsito. La disputa conserva la salida de origen y no aumenta el disponible de destino. Un traspaso preparado o cancelado antes de entregar queda fuera de los saldos y del tránsito.

```http
GET /api/v1/cajas/cuentas/cash_herbe/saldo?currency=MXN
```

```json
{
  "cash_account_id": "cash_herbe",
  "currency": "MXN",
  "available_cents": 8200000,
  "incoming_in_transit_cents": 300000,
  "outgoing_in_transit_cents": 0,
  "incoming_disputed_cents": 0,
  "outgoing_disputed_cents": 0,
  "ledger_position": 7,
  "as_of": "2026-11-14T00:45:00Z"
}
```

### Conciliación entre Cajas y Préstamos

El arqueo interno compara el efectivo contado contra el saldo registrado de Cajas. La conciliación API compara el saldo registrado del alcance común contra el esperado de Préstamos. El esperado parte de una apertura verificada y registrada en ambos proyectos y se calcula con entradas y salidas confirmadas del libro propio de Préstamos. El saldo de Cajas y las observaciones de sus movimientos se usan para cotejo. Préstamos identifica el alcance por cuenta y no usa el capital global disponible ni datos individuales de inversionistas en esta respuesta.

Las dos lecturas usan el mismo ID público de cuenta, `currency=MXN`, `scope=prestamos_custody`, `close_id`, `opening_close_id` y `cutoff_at` UTC. `ledger_position` identifica la última posición confirmada de cada libro y puede diferir entre sistemas. Una partida retrofechada asentada después del cierre pertenece al siguiente. El cierre puede estar completo aunque falte una referencia de un lado. Si la cobertura u operación del cierre sigue pendiente, no se calcula una diferencia concluyente.

En el piloto, `prestamos_custody` cubre la apertura arqueada y reconocida, recibos de cobranza y desembolsos con referencia asociada a Caja Herbe. Los traspasos y movimientos ajenos a ese alcance se presentan por separado. Los ajustes o devoluciones sin atribución verificable dejan la comparación pendiente. Recepciones en tránsito, compensaciones puras y correcciones financieras sin movimiento físico se excluyen. El cobro directo entra al alcance cuando su entrega física se confirma para esa caja conforme a la sección 10. Una devolución física reconocida conforme a esa misma sección resta en la cuenta original y conserva el recibo y la corrección financiera. La salida de un traspaso ya entregado se refleja en el disponible físico. El tránsito no se suma como una segunda partida. Los $400.00 de 6.1 conservan su conciliación separada en la caja original.

El alcance incluye los `payouts` y sus `payout_returns` según la caja de custodia de cada hecho. Cajas identifica la salida física con `payout_id` y signo negativo, y la entrada con `payout_return_id` y signo positivo. Una devolución recibida en Caja Adriana permanece atribuida a esa caja hasta que Cajas confirme su recepción en Herbe y ambas partes reconozcan el vínculo conforme a la sección 10. En un cierre posterior al reconocimiento, Cajas clasifica los dos asientos existentes dentro del alcance con el folio del traspaso, signo negativo en Adriana y signo positivo en Herbe. Préstamos deriva su esperado desde su registro de atribución. Los traspasos ordinarios siguen mostrándose por separado.

Préstamos reconoce cada partida de custodia una vez por su propio folio, a partir de las referencias firmadas de la versión aplicada. Verifica caja, fecha efectiva e importe para calcular el esperado desde su libro. El efecto neto en la cuenta del operador sigue siendo el de la sección 3. Un hecho pendiente de confirmación deja la cobertura del cierre pendiente. Las partidas registradas después del límite contable pertenecen al cierre siguiente y los cierres ya guardados conservan su resultado.

```http
GET /api/v1/cajas/cuentas/{cash_account_id}/saldo?currency=MXN&scope=prestamos_custody&close_id={close_id}
GET /api/v1/cajas/cuentas/{cash_account_id}/movimientos?currency=MXN&scope=prestamos_custody&close_id={close_id}
```

Con cierre, Cajas devuelve su posición al cierre, el saldo total `available_cents`, `opening_cents`, `in_scope_cents`, `outside_scope_cents`, `coverage_status`, `pending_reasons` y los importes en tránsito. El endpoint de movimientos devuelve además `movements`, con una fila por asiento posterior a la apertura hasta la posición confirmada. Cada fila incluye `reference`, `movement_id`, `amount_cents` con signo, `kind`, `operated_on`, `ledger_position` e `in_scope`. Un `in_scope: null` señala falta de atribución. Sin `scope` ni `close_id`, saldo y movimientos devuelven el estado actual de la cuenta, con el desglose de tránsito. Ambas lecturas requieren una credencial de servicio habilitada para la caja y son de solo lectura.

```http
GET /api/v1/cajas/conciliaciones/{close_id}/esperado-financiero?cash_account_id={cash_account_id}&currency=MXN&scope=prestamos_custody
```

Préstamos responde `200` con los campos comunes y `opening_cents`, `confirmed_inflows_cents`, `confirmed_outflows_cents` (magnitudes positivas o cero), `expected_custody_cents`, `coverage_status: complete|pending`, `pending_reasons` y sus propios `movements: [{reference, amount_cents}]` con signo y sin incluir la apertura. Puede devolver `cajas_only_movements: [{reference, amount_cents, status}]` con `status: unmatched|pending` para observaciones exclusivas de Cajas, sin incorporarlas a las partidas propias. El esperado es `null` cuando la cobertura está pendiente. Cajas muestra **Pendiente de conciliación** si el esperado falta o los cierres son incompatibles. Si los cortes son comparables, muestra `in_scope_cents − expected_custody_cents` y los folios correspondientes. Consultar, comparar y repetir la lectura conserva los saldos y asientos.

| Caso de aceptación | Resultado esperado |
| --- | --- |
| Apertura reconocida de $7,500.00 en ambos libros. Cajas registra y cuenta $9,500.00. Falta en Préstamos un recibo de $2,000.00 al cierre confirmado. | Arqueo $0.00. Cajas $9,500.00 frente al esperado de Préstamos de $7,500.00. Diferencia +$2,000.00 y folio exclusivo de Cajas. |
| Mismo alcance con respaldo incompleto en Préstamos. | `200 pending`, esperado y diferencia por determinar. Las relecturas conservan los movimientos. |
| Caja Herbe abre con $10,000.00, entrega $760.00 y recibe la devolución comprobada de $260.00. El corte aplicado conserva ambos folios. | Arqueo y diferencia entre libros de $0.00. Saldo físico y esperado de $9,500.00. Los libros de custodia muestran −$760.00 y +$260.00 por folios distintos; la cuenta del operador conserva el efecto neto de $500.00. |
| Caja Herbe abre con $10,000.00, entrega $760.00 y Caja Adriana recibe la devolución comprobada de $260.00. Después Adriana traspasa esos $260.00 a Herbe. | Antes de recibir el traspaso, saldo físico y esperado de Herbe de $9,240.00. Tras confirmar la recepción, si falta el vínculo, el saldo físico es $9,500.00 y el esperado sigue en $9,240.00. La atribución se señala pendiente en el detalle del traspaso. Tras reconocer el vínculo, un nuevo cierre de Herbe muestra $9,500.00 físicos y esperados, y $0.00 de diferencia. Los cierres previos y el folio original de devolución se conservan. |

## 9. Corregir un pago aplicado

**Cajas envía** la corrección autorizada con el ID y folio del movimiento activo, versión del corte, monto anterior, monto correcto y motivo. La v1 admite **una sola reducción por pago ordinario original aplicado a una sola letra**. El nuevo importe debe ser positivo y menor que el original. Los aumentos, las cancelaciones completas y las correcciones del reemplazo quedan fuera de este flujo.

```http
POST /api/v1/cajas/cortes/cut_1042/correcciones
Content-Type: application/json
Idempotency-Key: correction:corr_18
Approval-Proof: <prueba firmada por Cajas>
```

```json
{
  "correction_id": "corr_18",
  "cut_version": 3,
  "item_id": "item_01",
  "operator": "op_42",
  "payment_id": "pay_3785",
  "payment_folio": "PAG-3785",
  "currency": "MXN",
  "before_cents": 1200000,
  "after_cents": 1100000,
  "reason": "El comprobante ampara $11,000 para este pagaré.",
  "prepared_by": "usr_adriana",
  "approval": {
    "id": "approval_995",
    "approved_by": "usr_herbe",
    "approved_at": "2026-11-14T01:10:00Z"
  }
}
```

Cajas verifica el estado del corte antes de autorizar la corrección y bloquea un cierre incompatible mientras el resultado esté pendiente. Préstamos verifica bajo bloqueo que el movimiento identificado sigue activo, pertenece al concepto y a la versión del corte, y coincide con `before_cents`. Un movimiento distinto o un importe anterior desactualizado devuelve `409 PAYMENT_CHANGED`. La corrección se bloquea si pagos posteriores tienen efectos incompatibles, si existe una liquidación que impide revertir, si un cierre impide ajustar o si los retornos del pago ya se usaron, retiraron o reinvirtieron. Si Préstamos detecta el bloqueo, devuelve `409 CORRECTION_BLOCKED` con la causa y conserva los saldos que ya existían.

Préstamos identifica los retornos por pago, inversionista y componente. Antes de revertir, verifica bajo bloqueo si algún importe de los retornos de ese pago está vinculado a un uso, retiro o reinversión. La verificación y los movimientos de consumo comparten el mismo control de concurrencia. Un retorno de otro cobro puede aumentar el saldo agregado del inversionista y aun así el retorno original seguir consumido. Si falta trazabilidad para comprobar la disponibilidad de los retornos originales, devuelve `409 CORRECTION_BLOCKED` con `RETURN_PROVENANCE_UNVERIFIABLE`.

**Préstamos responde `200`** después de guardar juntos la reversa, el reemplazo y el resultado. El reemplazo conserva la fecha efectiva original. Cajas guarda ambos IDs y folios. Si se pierde la respuesta, Cajas reenvía la misma clave y contenido para recuperar el resultado, aunque el pago original ya figure revertido. Si cambia el contenido con esa clave, devuelve `409 IDEMPOTENCY_KEY_REUSED`.

```json
{
  "correction_id": "corr_18",
  "status": "applied",
  "currency": "MXN",
  "reversed_payment_id": "pay_3785",
  "reversed_payment_folio": "PAG-3785",
  "replacement_payment_id": "pay_3791",
  "replacement_payment_folio": "PAG-3791",
  "contract_amount_cents": 1100000,
  "installment_remaining_cents": 100000
}
```

Ejemplo alternativo de `409` si los retornos del pago ya se utilizaron.

```json
{
  "error": {
    "code": "CORRECTION_BLOCKED",
    "message": "Los retornos del pago ya se utilizaron.",
    "details": [
      {"payment_id": "pay_3785", "reason": "RETURNS_ALREADY_USED"}
    ]
  }
}
```

Cajas conserva la corrección como **Pendiente de conciliación** mientras desconoce el resultado financiero, y como **Bloqueada** si Préstamos devuelve un impedimento. Una reducción de $12,000 a $11,000 solo cambia la aplicación contractual. Si realmente hubo devolución o un error en el recibo, Cajas autoriza y asienta el ajuste correspondiente con su evidencia y lo vincula a la corrección. Si ya ocurrió un movimiento físico, queda asentado y pendiente de conciliar con el resultado financiero. Un rechazo de Préstamos no deshace ese hecho.

## 10. Confirmar custodia física

El GET consulta exclusivamente pagos nativos en efectivo ya aplicados. Identifica `payment_id`, `payment_folio`, `operator`, `currency`, `amount_cents`, `paid_on` y `status: applied`, con acceso vigente del servicio al operador. La consulta es de solo lectura.

```http
GET /api/v1/cajas/pagos-directos/{payment_id}?currency=MXN
POST /api/v1/cajas/confirmaciones-custodia
Idempotency-Key: custody:{cash_movement_id}
Custody-Proof: <prueba firmada por Cajas>
```

Cajas confirma únicamente efectivo físicamente recibido o entregado y guarda el asiento y su evidencia antes del envío. Vincula el ingreso `direct_payment_receipt` al `payment_id` y `payment_folio` ya existentes, fuera del corte. Vincula el egreso `correction_refund` al `correction_id` aplicado y al `receipt_id` original. En el piloto la devolución exige que la rebaja y el recibo sean identificables sin ambigüedad. El recibo original, corte, reversa y sustituto se conservan. Una devolución física acreditada genera un movimiento separado.

```json
{
  "kind": "direct_payment_receipt",
  "cash_movement_id": "movement_64",
  "cash_account_id": "cash_herbe",
  "currency": "MXN",
  "amount_cents": 500000,
  "effective_on": "2026-11-14",
  "confirmed_at": "2026-11-14T23:20:00Z",
  "confirmed_by": "usr_herbe",
  "evidence_ref": "REC-64",
  "payment_id": "pay_64",
  "payment_folio": "MOV-261114-0001"
}
```

En una devolución, `kind` es `correction_refund`, `amount_cents` expresa la magnitud positiva y `correction_id` y `receipt_id` sustituyen los campos de pago directo. Préstamos verifica cuenta, referencia, importe y `Custody-Proof` en la primera aceptación. La prueba Ed25519 vincula emisor `cajas`, destinatario `prestamos`, entorno `pilot` o `production`, operación `confirm_custody`, IDs de movimiento y caja, actor `confirmed_by`, vigencia y SHA-256 de los bytes exactos. El reconocimiento auxiliar suma el ingreso o resta la devolución con referencia `cash_movement_id`. No crea pagos, asignaciones, retornos ni asientos físicos.

Préstamos responde `200` con `cash_movement_id`, `cash_account_id`, `currency`, `kind`, `status: recognized` y `amount_cents` como magnitud. La identidad de la entrega y la referencia de origen son únicas. Un POST idéntico recupera la respuesta registrada con acceso vigente. Otro contenido para la misma clave se rechaza. Una falta temporal de acuse deja la confirmación pendiente en Cajas y el reintento conserva el movimiento original. Un estado pendiente de entrega por sí solo no suma al saldo esperado.

Para trasladar la atribución de una devolución íntegra de payout desde Adriana a la caja que entregó el payout, Cajas usa el mismo POST con `kind: payout_return_transfer`. `cash_movement_id` es el folio del traspaso físico existente, `source_cash_account_id` identifica la caja que recibió la devolución, `cash_account_id` la caja que confirmó la recepción del traspaso y `payout_return_id` la devolución firmada del corte aplicado. `amount_cents` es el importe íntegro positivo. `effective_on`, `confirmed_at` y `confirmed_by` corresponden a la recepción original. `evidence_ref` identifica el comprobante del vínculo. El cuerpo firmado conserva ambos folios y ambas cajas.

```json
{
  "kind": "payout_return_transfer",
  "cash_movement_id": "transfer_260",
  "source_cash_account_id": "cash_adriana",
  "cash_account_id": "cash_herbe",
  "payout_return_id": "payout_return_260",
  "currency": "MXN",
  "amount_cents": 26000,
  "effective_on": "2026-11-15",
  "confirmed_at": "2026-11-15T18:11:06Z",
  "confirmed_by": "usr_herbe",
  "evidence_ref": "COMPROBANTE-260"
}
```

Cajas comprueba la devolución, el comprobante y los dos asientos existentes. Si falta el vínculo o la recepción confirmada, mantiene la atribución previa y deja indicada la causa pendiente. Préstamos verifica la devolución reconocida, las dos cajas, el importe y la cronología. Registra de forma atómica una partida negativa en el origen y una positiva en el destino con el folio del traspaso. Un reenvío o envío simultáneo conserva el mismo acuse y el mismo par de partidas. Cada traspaso y devolución se puede vincular una sola vez. La operación conserva el efecto neto del payout en la cuenta del operador. El vínculo agregado después de un cierre se incorpora al siguiente cierre posterior a su reconocimiento, con los cierres ya guardados intactos.

Una reversión física posterior de un traspaso atribuido deja pendiente el cotejo de custodia en el cierre de esa reversión. Los sistemas conservan el registro histórico de atribución.

| Caso de aceptación | Resultado esperado |
| --- | --- |
| Herbe cobra $5,000.00 en Préstamos, el pago queda aplicado y confirma la recepción física en Herbe. | Un ingreso de $5,000.00 vinculado a ese pago. Se conserva un pago financiero. Dos reenvíos recuperan los mismos datos de confirmación. |
| Recibo original de $4,000.00 y pago sustituto de $3,600.00 tras una corrección aplicada. Una persona autorizada comprueba una devolución física de $400.00. | Un egreso independiente de $400.00. Se conservan recibo, folios y resultado financiero. No queda excedente por aclarar de ese recibo. |

## Respuestas de error comunes

Los errores usan `error.code` para decisiones de software, `error.message` para explicar el problema y `error.details` cuando hay campos o conceptos específicos.

| HTTP | Cuándo ocurre | Qué hace quien llama |
| --- | --- | --- |
| `401` | Credencial ausente o inválida. | Recupera la configuración o credencial del servicio. |
| `403` | Servicio, operador, caja o revisor fuera de alcance. | Escala la asignación de permisos. |
| `404` | Recurso o versión desconocidos dentro del alcance autorizado. | En recuperación de un envío, reenvía el mismo corte y clave. Para otras consultas, verifica el identificador. |
| `409` | Letra modificada, estado incompatible o clave reutilizada con otros datos. | Actúa según el código. Ante un corte en curso consulta su ejecución y ante un conflicto de datos abre revisión. |
| `422` | Cuerpo, filtro o conciliación inválidos. | Corrige los datos. Un corte cuyo contenido cambia requiere nueva versión y autorización. |
| `503 APPROVAL_CHECK_UNAVAILABLE` | Cajas no responde al verificar el permiso para la primera aceptación. | Conserva la autorización y reintenta el mismo cuerpo y clave. |
| `503 INTEGRATION_PAUSED` | Préstamos tiene pausada la primera aceptación de operaciones en producción. | Conserva el envío y consulta si existe un resultado. Reintenta con la misma clave al reanudar. |
| `429`, `5xx` | Límite temporal o fallo del proveedor. | Recupera el estado y reintenta la misma operación según corresponda. |

## Casos de aceptación

| Caso | Prueba | Resultado esperado |
| --- | --- | --- |
| Búsqueda por nombre completo o parcial. | Consultar por `José Pérez`, `jose perez` y un fragmento contiguo del nombre, además de combinar nombre con folio, modelo, día e ID de letra. | Coinciden únicamente letras del cliente dentro del operador y fecha autorizados que cumplen todos los filtros y las reglas vigentes. Mayúsculas y acentos no distinguen resultados; el ID exacto no omite el filtro de nombre. |
| Símbolos, vacío y validación del nombre. | Buscar fragmentos que incluyen `%`, `_` o `=`; omitir el nombre, enviarlo vacío o con espacios exteriores; enviar un arreglo o más de 150 caracteres. | Los símbolos son literales. Ausente, nulo o vacío equivale al catálogo anterior. Los tipos inválidos o el exceso de longitud devuelven `422`; no modifican ningún registro. |
| Búsqueda de cliente antes de paginar. | Colocar coincidencias más allá de la primera página del catálogo sin filtro, tener 51 coincidencias para el nombre y un homónimo de otro operador. Buscar y navegar. | La primera búsqueda filtra toda la cartera autorizada y retorna las primeras 50 coincidencias, con `has_more: true`; la página siguiente conserva el filtro y devuelve la restante. El homónimo ajeno no aparece. Cambiar la búsqueda o limpiar filtros vuelve a la página 1. Las huellas de pagos, retornos, saldos, recibos y movimientos permanecen iguales. |
| Cuota opcional en la misma letra. | En escenarios independientes, consultar `ins_325` con $4,900 de capital e interés y $300 de cuota configurada. Validar y ejecutar primero el escenario de $4,900 y en el otro intentar $5,200 con cuota. | El catálogo permite $4,900 por Cajas, la validación lo acepta y la aplicación registra $4,900. El cobro de $5,200 se tramita completo en Préstamos. Un envío por Cajas con cuota devuelve `CAPTURE_CHANNEL_MISMATCH` y queda sin aplicación. |
| Se pierde el acuse del corte autorizado. | Herbe confirma una vez. Préstamos acepta el corte y se descarta la respuesta. Vence la prueba y se retira a Herbe el permiso de revisión. Cajas conserva acceso al recurso y reenvía cuerpo y clave idénticos. | Préstamos devuelve el mismo `202` y `Location`. La consulta recupera el resultado con los mismos IDs y folios. Hay un solo pago por concepto, un solo efecto de retornos y una sola propuesta por crédito. Cajas conserva un único recibo. |
| El primer envío tiene una prueba vencida o el permiso fue revocado. | Enviar una versión que Préstamos aún desconoce con una prueba vencida. En otra prueba, enviar una prueba vigente y hacer que Cajas responda `allowed: false` y `reason: "PERMISSION_REVOKED"`. | `403 APPROVAL_INVALID` en ambos casos. Préstamos no acepta ni aplica esa ejecución. |
| Cajas está inaccesible en el primer envío. | Enviar un corte con prueba vigente y hacer que Cajas no responda a la consulta del permiso. Restablecer la consulta y reenviar el mismo cuerpo y clave con permiso vigente. | El primer intento devuelve `503 APPROVAL_CHECK_UNAVAILABLE`. La consulta por versión devuelve `404` mientras no se haya aceptado el corte. El recibo permanece en Cajas. Al restablecerse la consulta, Préstamos devuelve `202` y aplica el corte una sola vez. |
| Cambia una letra después de la revisión. | Con un corte de al menos dos conceptos y el recibo ya asentado, se cambia la versión de una letra después de la validación y antes de aplicar. | `requires_review` con la letra afectada. Ningún concepto se aplica. Tampoco se guardan retornos ni propuestas de esa versión. El recibo y el saldo efectivo de Cajas permanecen disponibles para conciliar y preparar la nueva versión. |
| Se formaliza un crédito compensado. | Aplicar el corte de ejemplo. Herbe autoriza por separado el crédito de $9,000 en Préstamos y se repite el alta. | La cuenta del operador refleja +$12,000 − $3,000 − $9,000 y queda conciliada con $0 de diferencia. Cajas conserva $3,000 de ingreso real. Hay un solo crédito vigente, un único vínculo a `offset_22` y ningún desembolso adicional. |
| Se devuelve el excedente de un egreso del corte. | Un corte con $2,240 de cobros y $3,000 compensados registra un egreso de $760. La primera versión queda en revisión porque cambió la letra. El cobro confirmado de la revisión es $2,500 y Herbe comprueba una devolución real de $260. Se reenvía dos veces la nueva versión autorizada. | Antes de la devolución, el corte sigue en revisión. Cajas conserva el egreso de $760 y una entrada independiente de $260. Préstamos aplica $2,500 − $3,000 + $760 − $260 = $0. El efecto neto del payout es $500 y los reenvíos conservan los movimientos originales. |
| El calendario de un crédito difiere. | Enviar un corte conciliado con cobros y fondeos válidos y dos propuestas. Alterar el primer vencimiento de una sin cambiar sus condiciones. Consultar el corte e intentar formalizar ambos créditos. | El corte queda `applied` con los folios de pago. La compensación se conserva. Solo la propuesta con `SCHEDULE_MISMATCH` queda en `requires_review` y con el alta bloqueada. La otra queda disponible para autorización. Herbe confirma las condiciones corregidas del crédito afectado y Préstamos verifica el calendario antes de formalizarlo. |
| Falla la conciliación o una referencia de fondeo. | Hacer que la revalidación de un corte aceptado detecte una diferencia en la conciliación o una referencia de fondeo inválida. Verificar cada fallo por separado con cobros que serían válidos. | El corte completo queda en `requires_review` y no se aplican cobros, retornos ni propuestas de esa versión. El recibo real permanece en Cajas. |
| Un fondeo llega con actor pero sin evidencia verificable. | Enviar por primera vez el evento de desembolso con `confirmed_by` y una prueba ausente o cuya firma no cubre la confirmación del movimiento. | `403 FUNDING_PROOF_INVALID`. El crédito conserva el fondeo anterior y sigue bloqueado para el alta si estaba `unfunded`. |
| Se cancela un fondeo antes del alta. | Bloquear un desembolso confirmado, intentar formalizar mientras se concilia y enviar después la cancelación con la referencia del ajuste efectivo. | El intento de alta queda bloqueado. Préstamos registra el ajuste y conserva la referencia original. El pendiente queda `unfunded` y requiere otro desembolso confirmado. |
| Un retorno posterior repone el saldo agregado. | Reinvertir los retornos de `pay_3785`. Registrar retornos de otro cobro hasta que el saldo agregado vuelva a cubrir lo que se intentaría revertir. Pedir la reducción del pago original de $12,000 a $11,000. | `409 CORRECTION_BLOCKED` con `RETURNS_ALREADY_USED`. `pay_3785` sigue activo. El intento deja los saldos intactos y no genera reversa ni reemplazo. |
