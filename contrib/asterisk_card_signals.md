# Общая информация
- Назначение: управление всплытием карточек звонка в Bitrix24 через переменную `CALLME_CARD_STATE`.
- Область применения: все входящие и исходящие вызовы, проходящие через `macro-dial-one` (очереди, группы, IVR, правила времени, прямой набор).

# Состав пакета
- `extensions_custom.callme.conf`
  - Копия пользовательского диалплана FreePBX.
  - Включает новые контексты `macro-dial-one-custom` и `callme-card-state-hide`, которые добавляют VarSet и hangup-handler.

# Порядок применения на АТС
1. **Подготовка файлов**
   - Скопируйте файл из `contrib/` на целевой сервер (например, SCP).
   - Проверьте права доступа (`chmod 640`, владелец `asterisk:asterisk`).
2. **Размещение**
   - Сделайте резервную копию `/etc/asterisk/extensions_custom.conf`.
   - Объедините текущий файл с содержимым `extensions_custom.callme.conf` (или замените, если он используется только под CallMe).
   - Убедитесь, что в файл попали секции `macro-dial-one-custom` и `callme-card-state-hide`.
3. **Применение**
   - Выполните `fwconsole reload` (или `amportal reload` для старых дистрибутивов).
   - Проверьте, что FreePBX не перезаписывает `extensions_custom.conf` после перезагрузки.
4. **Валидация**
   - Командой `asterisk -rx "dialplan show macro-dial-one"` убедитесь, что в начале макроса исполняется наш custom-блок (`CALLME_CARD_STATE=SHOW`) и что в `h`-ветке зарегистрирован hangup-handler (`CALLME_CARD_STATE=HIDE`).
   - Совершите тестовый звонок: в `CallMe.log` должны появиться `VarSet` события с `CALLME_CARD_STATE=SHOW/HIDE` и корректным внутренним номером; запись разговора тоже должна идти.

# Откат
- Верните резервную копию `/etc/asterisk/extensions_custom.conf` и выполните `fwconsole reload`.

# Примечания
- Все изменения держатся в `contrib/` и не затрагивают боевые конфиги до ручного деплоя.
- При обновлении FreePBX сохраняйте блок `macro-dial-one-custom`, иначе VarSet события перестанут приходить.
