# FBO: грузоместа, документы, изменения и приёмка

Что можно делать с FBO-заявкой после создания ([FBO: создание поставки](fbo.md)): распределить товар по коробкам
и паллетам, собрать транспортные паллеты, напечатать этикетки, изменить состав или слот, передать данные машины,
отменить заявку, пройти приёмку и получить акты.

Все запросы — `POST` с `Client-Id` и `Api-Key`. ID в примерах берите из своих ответов. Примеры используют первые
два товара базового набора: `FBO-TEST-A` (SKU 910001) и `FBO-TEST-B` (SKU 910002, штрихкод `2000000000022`).

## Полный цикл

1. Получите `order_id`, `supply_id`, `bundle_id` и состав через `/v3/supply-order/get` и `/v1/supply-order/bundle`.
2. Прочитайте правила `/v1/cargoes/rules/get`. Сначала грузомест нет, товар не распределён.
3. Если поставка идёт на транспортных паллетах (ТГМ), включите режим `/v1/cargoes/transport/activate`
   с `is_transport: true` и дождитесь статуса. Режим меняется, только пока нет ни грузомест, ни ТГМ.
4. Создайте грузоместа `/v1/cargoes/create`. У каждой коробки или паллеты — свой `key`, тип `BOX` или `PALLET`
   и товары:

   ```json
   {
     "supply_id": 100005,
     "cargoes": [
       {"key": "box-1", "value": {"type": "BOX", "items": [
         {"offer_id": "FBO-TEST-A", "quantity": 10, "quant": 1},
         {"barcode": "2000000000022", "quantity": 6, "quant": 1}
       ]}}
     ]
   }
   ```

   `quantity` — единицы товара, `quant` — размер кванта; количество должно делиться на квант. Для товара со сроком
   годности (`expirationRequired`) передайте будущий `expires_at`. Один SKU можно разложить по нескольким коробкам,
   но всего не больше, чем в поставке. Поставка готова, только когда распределён весь товар.
   Коробка с разными товарами получает `content_type: MIX`. Если у товаров разные зоны размещения
   (`placementZone`), правило `placement_zones_rule` будет нарушено.
5. Опрашивайте `/v2/cargoes/create/info` по `operation_id`: в результате ваш `key` связан с `cargo_id`.
   Проверить итог можно через `/v1/cargoes/get` или `/v2/cargoes/get`
   (в v2 запрос имеет вид `{"supplies": [{"supply_id": 100005, "cargo_ids": []}]}`).
6. Большой набор отправляйте частями: до 30 коробок или 40 паллет за запрос, общие лимиты — в `cargoLimits`.
   Каждый запрос добавляет грузоместа к уже созданным. Флаг `delete_current_version: true` заменяет все товарные
   грузоместа новым набором; если замена отклонена, остаётся прежний набор. Не ставьте этот флаг в каждой части.
7. Для ТГМ создайте паллеты `/v1/cargoes/transport/create` и привяжите к ним коробки:

   ```json
   {"supply_id": 100005, "transport_cargo_bind": [{"transport_cargo_id": 100020, "cargo_ids": [100007, 100009]}]}
   ```

   Отвязать — `cargoes_unbind_transport_cargoes: [100020]`; привязывать и отвязывать одним запросом нельзя.
   ТГМ не хранит свой товар: его состав — сумма привязанных коробок. Связи и штрихкоды грузомест —
   в `/v1/cargoes/supplies/get`.
8. Закажите этикетки (см. ниже).
9. Если профиль требует машину, передайте её данные через `/v1/supply-order/pass/create`. Загрузку УПД, ЭТТН или
   ЭВСД эмулятор не делает — факт загрузки задаётся событием `requirements`.
10. Когда выполнены правила всех поставок и есть нужные документы и машина, заявка переходит в `READY_TO_SUPPLY`.
    Пока товар распределён не полностью, коробки не привязаны к ТГМ или истёк срок годности, остаётся
    `DATA_FILLING`.
11. Передачу на склад и приёмку задаёт оператор эмулятора событиями `state` и `acceptance` (см. ниже).
    Клиент видит результат обычными методами чтения и актов.

## Асинхронные операции

Операции завершаются через `operationDelaySeconds` (в базовом наборе 2 секунды). Операции одной заявки
выполняются по очереди: пока идёт одна, вторая получает отказ и ничего не перезаписывает. При завершении
состояние, версия, количество и слот проверяются ещё раз.

| Запуск | Статус | Поле статуса и значения |
|---|---|---|
| `/v1/cargoes/create` | `/v2/cargoes/create/info` | `status`: IN_PROGRESS / SUCCESS / FAILED |
| `/v1/cargoes/delete` | `/v1/cargoes/delete/status` | `status`: IN_PROGRESS / SUCCESS / ERROR |
| `/v2/cargoes/delete` | `/v2/cargoes/delete/status` | `status`: IN_PROGRESS / SUCCESS / FAILED |
| `/v1/cargoes/transport/activate`, `create`, `bind` | соответствующий `/status` | `status`: IN_PROGRESS / SUCCESS / FAILED |
| `/v1/cargoes-label/create` | `/v1/cargoes-label/get` | `status`: IN_PROGRESS / SUCCESS / FAILED |
| `/v1/cargoes/label/transport/create` | `/v1/cargoes/label/transport/status` | `status`: IN_PROGRESS / SUCCESS / FAILED |
| `/v1/cargoes/label/transport-by-order/create` | `/v1/cargoes/label/transport-by-order/status` | `status`: IN_PROGRESS / SUCCESS / FAILED |
| `/v1/supply-order/content/update` | `/v1/supply-order/content/update/status` | `status`: IN_PROGRESS / SUCCESS / ERROR |
| `/v1/supply-order/timeslot/update` | `/v1/supply-order/timeslot/status` | `status`: STATUS_IN_PROGRESS / STATUS_SUCCESS / STATUS_ERROR |
| `/v1/supply-order/pass/create` | `/v1/supply-order/pass/status` | **`result`**: InProgress / Success / Failed |
| `/v1/supply-order/cancel` | `/v1/supply-order/cancel/status` | `status`: IN_PROGRESS / SUCCESS / ERROR |
| `/v1/supply-order/act/accept` | `/v1/supply-order/act/accept/status` | `status`: IN_PROGRESS / SUCCESS / FAILED |

Как и в Ozon, у методов разный формат ошибок: объект `errors`, список `errors`, `error_reasons`. Не пытайтесь
разбирать их одним общим кодом. Статус создания самой заявки запрашивается по `draft_id`, а не по `operation_id`.

## Этикетки

Успешный статус этикеток возвращает `result.file_url`. Файл скачивается обычным GET без заголовков Seller API:
в ссылке есть секретный токен документа. Ссылка действует сутки по часам кабинета; повторное скачивание отдаёт
тот же файл. Чужой кабинет или неверный токен — 404; истёкшая ссылка, отменённая заявка или изменившиеся
грузоместа — 410. Не пишите такие ссылки в общедоступные логи.

PDF синтетический: страница 100 × 150 мм на каждое грузоместо или ТГМ, штрихкод Code 128 `OZTEST<id>`
и пометка `OZON LOCAL TEST`. Это не копия настоящей этикетки Ozon.

Печать не создаёт грузомест. Любое изменение грузомест, связей ТГМ или состава поставки создаёт новую версию,
а старый файл перестаёт скачиваться — нужны новые этикетки.

## Изменения, удаление и отмена

- **Состав** — `/v1/supply-order/content/update` с `order_id`, `supply_id` и `items: [{sku, quantity, quant}]`.
  После успеха читайте `new_bundle_id`. При ошибке `SUPPLY_CONTENT_NOT_VALID` подробности отдаёт
  `/v1/supply-order/content/update/validation`, а прежний состав сохраняется. Уменьшить количество ниже
  уже разложенного по грузоместам нельзя.
- **Слот** — список `/v2/supply-order/timeslot/list` по `order_id`, перенос `/v1/supply-order/timeslot/update` по
  **`supply_order_id`** с `timeslot: {from, to}`. Занятый слот или превышение `timeslotChangesLimit` — отказ.
  Перенос и отмена освобождают прежний слот.
- **Машина** — `/v1/supply-order/pass/create` по **`supply_order_id`** с
  `vehicle: {driver_name, driver_phone, vehicle_model, vehicle_number}`; данные видны в `details.vehicle.value`.
- **Удаление грузомест** — v1 и v2, асинхронно. Последнее товарное грузоместо удалить нельзя — для полной замены
  используйте создание с `delete_current_version`. В v2 `transport_cargo_deletion_type` задаёт, что делать с
  коробками ТГМ: `UNBIND_CONTAINED_CARGOES` оставляет их, `DELETE_CONTAINED_CARGOES` удаляет вместе с паллетой.
- **Отмена** — `/v1/supply-order/cancel` по `order_id`; результат подтверждает отмену заявки и поставок.
  После передачи на склад отмена и изменения запрещены. Признак `can_set` в `details` учитывает состояние,
  дедлайн и загруженный УПД.

Изменения разрешены до часа до начала слота. Это правило эмулятора, у реальных маршрутов Ozon дедлайн может
быть другим.

## Приёмка и акты

После передачи на склад (событие `state`) оператор задаёт результат приёмки событием `acceptance`. Появляются акты:
`/v1/supply-order/act/summary/get` по заявке и `/v1/supply-order/act/product/get` по поставке — основной
`ACCEPTANCE` и отдельные `SHORTCOMING`, `SURPLUS`, `DEFECT`. Акты расхождений не прибавляются к основному как
отдельные поступления. Денежные суммы эмулятор не считает. Акт принимается только явным вызовом
`/v1/supply-order/act/accept`.

## Управляющие события

Как запускать события и общие типы — в [Тестовый кабинет](cabinet.md#управляющие-события). Для FBO есть ещё:

| `type` | Поля | Что делает |
|---|---|---|
| `scenario` | `path`, `remaining` (по умолчанию 1), `delaySeconds`, `hold`, `fail`, `partialCargoCount` | Меняет поведение следующих асинхронных операций метода: задержка, удержание до `release`, ошибка; `partialCargoCount` — только для создания грузомест, создаст лишь часть |
| `release` | `operationId` | Снимает удержание, операция завершится при следующем опросе |
| `state` | `orderId`, `state` | Внешний статус заявки. Вернуть статус назад после передачи на склад нельзя |
| `acceptance` | `supplyId`, `items: [{sku, factQuantity, defectQuantity}]` | Результат приёмки поставки; брака не больше принятого |
| `requirements` | `supplyId`, `utdUploaded`, `ettnUploaded`, `evsdUploaded` | Загружены ли УПД, ЭТТН, ЭВСД вне Seller API |

Передача на склад:

```json
{"eventId": "handover-1", "type": "state", "orderId": 100004, "state": "ACCEPTED_AT_SUPPLY_WAREHOUSE"}
```

Приёмка с недостачей, излишком и браком:

```json
{"eventId": "receipt-1", "type": "acceptance", "supplyId": 100005, "items": [
  {"sku": 910001, "factQuantity": 8, "defectQuantity": 1},
  {"sku": 910002, "factQuantity": 7, "defectQuantity": 0}
]}
```

SKU, которого нет в `items`, считается принятым полностью; `items: []` — полная приёмка. Одна поставка
принимается один раз.

Чтобы проверить, как клиент переживает новый статус Ozon, задайте несуществующий, например
`"state": "FUTURE_OZON_STATE"`. Чтение вернёт его как есть, изменения будут заблокированы. Вернуть заявку из
такого или завершённого статуса в рабочий нельзя — создайте новую заявку или очистите кабинет событием `reset`.

Готовые события: [частичное создание грузомест](../fixtures/events/partial-cargo.json),
[удержанные этикетки](../fixtures/events/held-labels.json),
[недоступные бета-методы](../fixtures/events/beta-unavailable.json).

## Расширения для тестов

Два query-параметра упрощают повторение сценариев при разработке. В настоящем Ozon их нет, и в
Seller JSON они ничего не меняют:

- `POST /v1/cargoes/create?wms_cargo_scenario=success|error` — `error` сразу отвечает 400 без создания операции,
  `success` выполняет обычное создание со всеми проверками. На других методах параметр запрещён.
- `POST /v1/cargoes/delete?wms_cargo_scenario=reset` разрешает удалить последнее грузоместо поставки, чтобы
  начать регистрацию заново. На `/v2/cargoes/delete` он же разрешает удалить все ТГМ поставки. Без параметра
  действуют обычные запреты `CANT_DELETE_ALL_CARGOES` и `CANT_DELETE_ALL_TRANSPORT_CARGOES`.
