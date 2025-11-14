# Роллбэк изменений hangup/state-hook

Эта памятка позволяет быстро вернуть прежнее поведение, если адаптация под Asterisk 1.8 окажется нежелательной.

## 1. Диалплан FreePBX
1. Выполните `git checkout -- contrib/extensions_override_freepbx.callme.conf`.
2. Скопируйте файл на АТС (если развёртывание делается вручную) и перезагрузите диалплан:
   ```
   asterisk -rx "dialplan reload"
   ```

## 2. PHP-часть CallMe
1. Откатите правки:
   ```
   git checkout -- CallMeIn.php classes/HelperFuncs.php
   ```
2. Перезапустите воркер:
   ```
   supervisorctl restart callme
   ```

## 3. Полный откат коммита
Если изменения попали в общий репозиторий:
```
git revert <hash_коммита>
git push
```

После роллбэка вернутся исходные обработчики `CHANNEL(hangup_handler_push)` и старая система логирования.

