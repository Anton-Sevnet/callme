# Общая информация
- Назначение: управление всплытием карточек звонка в Bitrix24 через переменную `CALLME_CARD_STATE`.
- Область применения: все входящие и исходящие вызовы, проходящие через `macro-dial-one` (очереди, группы, IVR, правила времени, прямой набор).

# Состав пакета
- `extensions_custom.callme.conf`
  - Копия кастомного диалплана FreePBX с локальными доработками.
  - Содержит только пользовательские правила клиента.
- `extensions_override_freepbx.callme.conf`
  - Содержит **только** дополнительный блок `macro-dial-one`, который ставит/сбрасывает `CALLME_CARD_STATE`.
  - Файл добавляется к существующему `/etc/asterisk/extensions_override_freepbx.conf`, а не заменяет его.

# Порядок применения на АТС
1. **Подготовка файлов**
   - Скопируйте файлы из `contrib/` на целевой сервер (например, SCP).
   - Проверьте права доступа (`chmod 640`, владелец `asterisk:asterisk`).
2. **Размещение**
   - Сделайте резервные копии `/etc/asterisk/extensions_custom.conf` и `/etc/asterisk/extensions_override_freepbx.conf`.
   - `extensions_custom.callme.conf` можно целиком заменить (если это ваш единственный кастомный файл) либо вручную слить изменения.
   - Содержимое `extensions_override_freepbx.callme.conf` **добавьте** в конец `/etc/asterisk/extensions_override_freepbx.conf` (или объедините с существующим блоком), не удаляя имеющиеся секции типа `[sub-record-check]`.
3. **Применение**
   - Выполните `fwconsole reload` (или `amportal reload` для старых дистрибутивов).
   - Убедитесь, что FreePBX не перезаписывает файлы после reload (при необходимости запретите автогенерацию или используйте `*_custom`).
4. **Валидация**
   - Командой `asterisk -rx "dialplan show macro-dial-one"` убедитесь, что в макросе присутствуют строки `Set(__CALLME_CARD_STATE=SHOW:...)` и `Set(__CALLME_CARD_STATE=HIDE:...)`; при этом остальные инструкции (запись, проверка CF и т.д.) должны остаться на месте.
   - Совершите тестовый звонок: в `CallMe.log` должны появиться `VarSet` события с `CALLME_CARD_STATE=SHOW/HIDE` и корректным внутренним номером.

# Откат
- Верните резервные файлы и повторите `fwconsole reload`.

# Примечания
- Все изменения держатся в `contrib/` и не затрагивают боевые конфиги до ручного деплоя.
- При обновлении FreePBX проверяйте, не перезаписал ли генератор `extensions_override_freepbx.conf` — блок `macro-dial-one` с `CALLME_CARD_STATE` должен оставаться в файле.
