# Механизм показа карточек звонка в Bitrix24

## Быстрая схема

- **Хранилище состояния.** Синглтон `Globals` ведёт активные звонки:
  - соответствия `uniqueid ↔ linkedid` и `linkedid → CALL_ID`;
  - текущее состояние внутренних (`ringingIntNums`, `callShownCards`, `ringOrder`);
  - связи `intNum → CALL_ID` (`callIdByInt`), историю трансферов (`transferHistory`).
- **Нормализация и CRM.** На `Newchannel` номер нормализуется и ищется в Bitrix24. CallerID в АТС обновляется именем из CRM.
- **Регистрация сразу.** Звонок регистрируется в Bitrix24 немедленно на ответственного пользователя; если у него нет внутреннего, используется `fallback_responsible_user_id`.
- **Сигнализация из Asterisk.** В `macro-dial-one` перед и после `Dial()` выставляется `CALLME_CARD_STATE=SHOW:<int>` / `HIDE:<int>`. Эти VarSet-события ловит `CallMeIn.php` и решает, кому показать или скрыть карточку.

## Регистрация и получение CALL_ID

1. В `NewchannelEvent` выбирается внутренний номер (ответственный или fallback).
2. `HelperFuncs::runInputCall` вызывает REST `telephony.externalcall.register`.
3. Полученный `CALL_ID` сохраняется в `callIdByLinkedid`, `calls`, `callIdByInt`.
4. В `ringingIntNums` создаётся заготовка для отслеживания RING состояния.

## Показ карточек

- `CALLME_CARD_STATE=SHOW:<int>` инициирует `callme_show_card_for_int`. Карточка показывается через `telephony.externalcall.show`, а внутренние структуры помечаются как `shown`.
- `CALLME_CARD_STATE=HIDE:<int>` приводит к вызову `hideInputCall`, очистке статуса, удалению внутреннего номера из `ringingIntNums` и `callShownCards`.
- Дополнительные bulk-показы (`showInputCallForUsers`) больше не требуются — все сигналы приходят из Asterisk.

## Реакция на события AMI

### DialBegin

- Фиксируется RING-состояние и пользователь (для логов и fallback).
- Карточка не показывается автоматически — отображение идёт только по `CALLME_CARD_STATE`.

### DialEnd (ANSWER/BUSY/CANCEL)

- Статус сохраняется в `Dispositions`, массивы `ringingIntNums` корректируются, чтобы последующие события VarSet не дублировались.
- При ответе карточки у остальных скрываются через VarSet (`HIDE`).

### Bridge/BRIDGEPEER (трансферы)

- `BridgeEvent` и `VarSet(BRIDGEPEER)` отслеживают перевод звонка.
- Карточка «переезжает» за каналом: старому оператору шлётся `hideInputCall`, новому — `showInputCall`, история сохраняется в `transferHistory`.

### Hangup

- `telephony.externalcall.finish` отправляется с длительностью и статусом.
- Карточки скрываются (на случай, если последний VarSet не пришёл вовремя).
- Чистятся `callShownCards`, `ringingIntNums`, `callIdByInt`, `uniqueidToLinkedid`, `transferHistory`.

## Особые случаи

- **Очереди / ring-group.** Каждый `Dial()` на абонента порождает пару VarSet (SHOW/HIDE). Карточка всплывает ровно на время RING и скрывается по `HIDE`.
- **Нет ответственного.** Регистрация идёт на fallback или ответственного; карточки управляются по факту звонка.
- **user_show_cards.** Если список задан, карточка поднимется только для разрешённых внутренних — остальные VarSet игнорируются в макросе.
- **Исходящие (Originate).** Для исходящих используется отдельная логика, но команды show/hide те же (`telephony.externalcall.show/hide`).

## Ключевые REST-вызовы Bitrix24

| Метод REST                         | Где используется                           | Назначение                                         |
|-----------------------------------|--------------------------------------------|----------------------------------------------------|
| `telephony.externalcall.register` | `HelperFuncs::runInputCall`                | Регистрация входящего звонка, получение `CALL_ID`. |
| `telephony.externalcall.show`     | `HelperFuncs::showInputCall`               | Показ карточки конкретному сотруднику.             |
| `telephony.externalcall.hide`     | `HelperFuncs::hideInputCall`               | Скрытие карточки у сотрудника.                     |
| `telephony.externalcall.finish`   | `HelperFuncs::finishCall`                  | Завершение звонка, статусы и длительность.         |

## Проверка конфигурации

- Убедитесь, что в `config.php` заполнены `bitrixApiUrl`, `extentions`, `user_show_cards`, `fallback_responsible_user_id`.
- На АТС должны быть применены `extensions_custom.callme.conf` и `extensions_override_freepbx.callme.conf`, чтобы VarSet `CALLME_CARD_STATE` действительно отправлялся.
- Для отладки включите `CallMeDEBUG`, VarSet события видно в `logs/CallMe.log`.

## Что тестировать

1. Входящий на прямой внутренний: карточка появляется при RING, скрывается после ответа/сброса.
2. Очередь/ring-group: SHOW/HIDE приходят на каждого агента; при ответе карточка остаётся у отвечающего.
3. Трансфер между сотрудниками: карточка переходит по `BRIDGEPEER`.
4. Сценарий без ответственного: регистрация на fallback и корректные VarSet.

