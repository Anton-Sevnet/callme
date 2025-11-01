# CallMe v2 - Интеграция Asterisk и Битрикс24

Расширенная версия проекта [callme](https://github.com/Demosthen42/callme) с полной поддержкой PHP 8.2+, исходящих звонков, отслеживания transfer'ов и работы на стороне сервера Битрикс24.

**Оригинальный проект:** https://habrahabr.ru/post/349316/

---

## 📋 Содержание

1. [Основные отличия от оригинала](#основные-отличия-от-оригинала)
2. [Новые возможности](#новые-возможности)
3. [Технические изменения](#технические-изменения)
4. [Архитектура](#архитектура)
5. [Установка](#установка)
6. [Конфигурация](#конфигурация)
7. [Использование](#использование)

---

## 🎯 Основные отличия от оригинала

### 1. **PHP 8.2+ совместимость**
- ✅ Полная адаптация под PHP 8.2.29
- ✅ Исправлены все deprecated функции PHP 5.3
- ✅ Адаптирована библиотека PAMI для работы с PHP 8.2
- ✅ Проверки типов и совместимости на всех уровнях

### 2. **Работа на стороне Битрикс24**
- ✅ Приложение устанавливается на сервере Битрикс24 (CentOS 9), а не на АТС
- ✅ Подключение к АТС через AMI (Asterisk Manager Interface)
- ✅ **Минимальные изменения в диалплане:** требуется только подключение модуля `b24_continuous_parallel.conf`
- ✅ Модуль не изменяет логику телефонии, только добавляет параллельную запись и передачу Linkedid
- ✅ Работает с FreePBX без модификации ядра (только добавление include в extensions_custom.conf)

### 3. **Поддержка исходящих звонков**
- ✅ Полная реализация исходящих звонков через CallMeOut.php
- ✅ Интеграция с вебхуками Битрикс24 (`ONEXTERNALCALLSTART`)
- ✅ Отслеживание всех этапов Originate-вызова
- ✅ Автоматическое открытие карточки звонка при ответе

### 4. **Отслеживание Transfer звонков**
- ✅ Автоматическое определение переключений между абонентами
- ✅ Использование Linkedid для группировки каналов
- ✅ Отслеживание через BridgeEvent и BRIDGEPEER
- ✅ Правильное скрытие/показ карточек при transfer

### 5. **Улучшенная работа с CRM**
- ✅ Определение ответственного за звонок из CRM (ASSIGNED_BY_ID)
- ✅ Правильная последовательность: сначала ответственный, потом регистрация
- ✅ Поддержка Contact/Company/Lead с приоритетами
- ✅ Автоматическое создание лидов для новых клиентов

---

## ✨ Новые возможности

### 1. **Исходящие звонки через Originate**

**Как работает:**
1. Пользователь кликает на номер в Битрикс24
2. Битрикс24 отправляет вебхук `ONEXTERNALCALLSTART` на `CallMeOut.php`
3. CallMeOut.php инициирует вызов через AMI:
   - Сначала звонок на внутренний номер сотрудника (например, SIP/219)
   - После ответа сотрудника - исходящий звонок на номер клиента
4. CallMeIn.php отслеживает все события и открывает карточку звонка

**Особенности:**
- Отслеживание всех каналов через Linkedid
- Правильная обработка успешных/неуспешных вызовов
- Автоматическое определение статуса звонка (200/304/486/603)
- Поддержка записи разговоров

### 2. **Отслеживание Transfer звонков**

**Два механизма отслеживания:**

**A) Через BridgeEvent (основной):**
- Отслеживает момент подключения внешнего канала к новому внутреннему
- Автоматически скрывает карточку у старого абонента
- Показывает карточку новому абоненту
- Сохраняет историю transfer'ов

**B) Через BRIDGEPEER (дополнительный):**
- Отслеживает изменения переменной BRIDGEPEER в каналах
- Игнорирует bridge между внутренними каналами (ложные срабатывания)
- Дополнительная проверка реального transfer внешнего звонка

**Правило "Кто последний тот и папа":**
- При завершении звонка используется последний абонент из transfer
- Все записи в CDR привязываются к последнему ответственному

### 3. **Непрерывная запись звонков**

**⚠️ ВАЖНО:** Модуль `b24_continuous_parallel.conf` **ТРЕБУЕТСЯ** устанавливать на сервере АТС (Asterisk). Это единственное изменение в диалплане, необходимое для работы CallMe.

**Модуль:** `contrib/b24_continuous_parallel.conf`

**Технология записи:**

Запись звонков реализована через **MixMonitor** - встроенную функцию Asterisk, использующую **Audio Hook API** для "подслушивания" аудиопотоков каналов в реальном времени.

**Принцип работы:**

1. **Audio Hook API (подслушивание каналов):**
   - Asterisk использует механизм **Audio Hooks** для доступа к аудиопотокам канала
   - MixMonitor "подключается" к каналу через Audio Hook и получает копию всех аудиоданных
   - Данные записываются в файл **независимо** от основного разговора (параллельная запись)
   - Это позволяет записывать звонки без влияния на качество связи

2. **AUDIOHOOK_INHERIT - наследование записи:**
   - При установке `AUDIOHOOK_INHERIT(MixMonitor)=yes` запись **автоматически наследуется** при Transfer
   - Когда звонок переключается на нового абонента, MixMonitor продолжает работать
   - Новый канал получает тот же Audio Hook, запись идет в **тот же файл**
   - Результат: **один непрерывный файл** вместо нескольких разорванных частей

3. **Linkedid как ключ группировки:**
   - Имя файла формируется на основе `linkedid` канала
   - Все каналы одного звонка имеют одинаковый `linkedid`
   - При Transfer новый канал получает тот же `linkedid` → запись продолжается в тот же файл

**Жизненный цикл записи:**

```
1. Входящий звонок → канал SIP/trunk-000026d8 (linkedid=ABC)
   └─> MixMonitor запускается → call-ABC.wav
   
2. Transfer на сотрудника 219 → канал SIP/219-000026d9 (linkedid=ABC)
   └─> MixMonitor НЕ запускается заново (linkedid уже есть)
   └─> AUDIOHOOK_INHERIT=yes → запись наследуется → call-ABC.wav (продолжается!)
   
3. Transfer на сотрудника 220 → канал SIP/220-000026da (linkedid=ABC)
   └─> MixMonitor НЕ запускается заново
   └─> AUDIOHOOK_INHERIT=yes → запись наследуется → call-ABC.wav (продолжается!)
   
4. Звонок завершается
   └─> MixMonitor останавливается
   └─> Автоконвертация: lame → call-ABC.mp3
   └─> WAV файл удаляется
   
Результат: call-ABC.mp3 содержит ВСЮ беседу от начала до конца!
```

**Преимущества технологии:**

- ✅ **Непрерывность:** один файл на весь звонок, даже при множественных transfer
- ✅ **Параллельность:** запись не влияет на основной поток разговора
- ✅ **Эффективность:** автоматическое наследование без дублирования файлов
- ✅ **Автоматизация:** автоконвертация WAV → MP3 после завершения
- ✅ **Интеграция:** передача Linkedid в CallMe через AMI события

**Возможности:**
- Параллельная запись в отдельную папку `/var/spool/asterisk/continuous/`
- Один файл на весь звонок (даже при Transfer)
- Автоматическая конвертация WAV → MP3 через lame (bitrate 64 kbps)
- Передача Linkedid в CallMe через переменную `CallMeLINKEDID` (AMI VarSetEvent)
- Интеграция с существующими записями (не перезаписывает текущие)

**Результат:**
```
/var/spool/asterisk/continuous/2025/10/26/call-1761855304.108488.mp3
```
Один файл содержит весь разговор, включая все transfer между абонентами.

**Установка на АТС:**

Модуль **ОБЯЗАТЕЛЬНО** должен быть установлен на сервере Asterisk:

```bash
# 1. Скопировать файл на АТС
sudo cp b24_continuous_parallel.conf /etc/asterisk/

# 2. Добавить в extensions_custom.conf
echo "#include b24_continuous_parallel.conf" | sudo tee -a /etc/asterisk/extensions_custom.conf

# 3. Перезагрузить диалплан
sudo asterisk -rx "dialplan reload"

# 4. Создать папку для записей
sudo mkdir -p /var/spool/asterisk/continuous
sudo chown -R asterisk:asterisk /var/spool/asterisk/continuous

# 5. Установить lame (для конвертации MP3)
sudo yum install lame  # CentOS/RHEL
# или
sudo apt-get install lame  # Debian/Ubuntu
```

**Сравнение с оригинальными записями:**

```
Оригинальные записи (FreePBX):
/var/spool/asterisk/monitor/2025/10/26/
├── in-79991234567-201-20251026-153045-1730036445.123.wav  ← часть 1 (сотр. 201)
└── in-79991234567-202-20251026-153145-1730036445.456.wav  ← часть 2 (сотр. 202)
❌ Два отдельных файла, разорванный разговор

Новые непрерывные записи (CallMe):
/var/spool/asterisk/continuous/2025/10/26/
└── call-1730036445.123456.mp3  ← ПОЛНАЯ запись (201+202 в одном файле!)
✅ Один файл, непрерывный разговор
```

### 4. **Улучшенное логирование**

**Режим DEBUG (`CallMeDEBUG = true`):**
- Логирование всех запросов к API Битрикс24 (URL + параметры JSON)
- Логирование ответов от API
- Детальное логирование всех событий AMI
- Полный лог событий в `full.log` (опционально)

**Пример лога:**
```
Bitrix24 API Request
[URL] => https://example.com/rest/<user_id>/xxx/telephony.externalcall.register.json
[METHOD] => telephony.externalcall.register
[PARAMS] => Array(...)
[PARAMS_JSON] => {
    "USER_ID": <user_id>,
    "PHONE_NUMBER": "74951234567",
    ...
}
```

### 5. **Правильное определение ответственного**

**Старая логика (оригинал):**
1. Регистрация звонка с дефолтным номером (100)
2. Попытка определить ответственного (уже поздно!)

**Новая логика:**
1. Поиск CRM-сущности по номеру телефона
2. Извлечение ответственного (ASSIGNED_BY_ID)
3. Получение внутреннего номера ответственного
4. Регистрация звонка с правильным номером

**Методы:**
- `getCrmEntityDataByPhone()` - возвращает имя + ID ответственного
- `getResponsibleIntNum()` - получает внутренний номер ответственного
- Поддержка режима `responsible_mode` (crm_responsible / static_mapping)

### 6. **Фильтрация событий DialBegin/DialEnd**

**Проблема оригинала:**
- Обработчики DialBegin/DialEnd пытались обработать Originate-вызовы
- Это приводило к ошибочным запросам в Б24 (поиск сотрудника по DialString)

**Решение:**
- Исключение Originate-вызовов из обработки DialBegin/DialEnd
- Originate-вызовы обрабатываются только специализированными обработчиками
- Правильная фильтрация по `$globalsObj->originateCalls`

---

## 🔧 Технические изменения

### 1. **Адаптация PAMI для PHP 8.2**

**Проблемы оригинала:**
- Библиотека `marcelog/pami` разработана для PHP 5.3
- Использование deprecated функций
- Отсутствие типизации

**Исправления:**
- Обновлена библиотека до версии 2.* (частично совместимой с PHP 8.2)
- Добавлены проверки типов на всех уровнях
- Исправлены вызовы `array_key_exists()` с проверкой типа массива
- Добавлены проверки существования ключей перед доступом

### 2. **Новая структура данных для Originate**

```php
$globalsObj->originateCalls[$linkedid] = [
    'channels' => [],           // Все каналы вызова
    'call_id' => null,          // CALL_ID от Битрикс24
    'intNum' => null,           // Внутренний номер
    'is_originate' => true,     // Маркер Originate
    'answered' => false,        // Флаг ответа
    'answer_time' => null,      // Время ответа
    'last_dialstatus' => null,  // Последний DialStatus
    'last_hangup_cause' => null,// Последний HangupCause
    'record_url' => null,       // URL записи
    'created_at' => time(),     // Время создания
    'last_activity' => time()   // Время последней активности
];
```

### 3. **История Transfer'ов**

```php
$globalsObj->transferHistory[$externalUniqueid] = [
    'externalChannel' => 'SIP/trunk-000026d8',
    'externalUniqueid' => '1761855304.108488',
    'call_id' => 'externalCall.xxx',
    'currentIntNum' => 220,  // Текущий абонент
    'history' => [
        ['from' => 219, 'to' => 220, 'timestamp' => 1730036445]
    ]
];
```

### 4. **Маппинг Uniqueid → Linkedid**

```php
$globalsObj->uniqueidToLinkedid[$uniqueid] = $linkedid;
```

Используется для связи каналов Originate с основным Linkedid.

---

## 🏗️ Архитектура

### Компоненты системы:

```
┌────────────────────────────────────────────────────────┐
│                    Битрикс24 Сервер                    │
│  ┌─────────────────────────────────────────────────┐   │
│  │         CallMe Application                      │   │
│  │                                                 │   │
│  │  ┌──────────────┐      ┌──────────────┐         │   │
│  │  │ CallMeIn.php │      │ CallMeOut.php│         │   │
│  │  │ (AMI Listener)│     │ (Webhook)    │         │   │
│  │  └──────┬───────┘      └──────┬───────┘         │   │
│  │         │                      │                │   │
│  │         │ AMI Events           │ Webhook        │   │
│  └─────────┼──────────────────────┼────────────────┘   │
│            │                      │                    │
└────────────┼──────────────────────┼────────────────────┘
             │                      │
             │ TCP 5038             │ HTTP/HTTPS
             │                      │
┌────────────┴──────────────────────┴────────────────────┐
│              Asterisk PBX                              │
│  ┌──────────────────────────────────────────────────┐  │
│  │  AMI (Asterisk Manager Interface)                │  │
│  │  - NewchannelEvent                               │  │
│  │  - DialBegin/DialEnd                             │  │
│  │  - BridgeEvent                                   │  │
│  │  - VarSetEvent (CallMeLINKEDID, CallMeCALL_ID)   │  │
│  │  - HangupEvent                                   │  │
│  └──────────────────────────────────────────────────┘  │
│                                                        │
│  ┌──────────────────────────────────────────────────┐  │
│  │  Dialplan Hook                                   │  │
│  │  b24_continuous_parallel.conf                    │  │
│  │  - Устанавливает CallMeLINKEDID                  │  │
│  │  - Запускает параллельную запись                 │  │
│  └──────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────┘
```

### Потоки данных:

**Входящий звонок:**
```
1. Внешний клиент → Asterisk (SIP)
2. Asterisk → AMI Event (NewchannelEvent)
3. CallMeIn.php → API Б24 (crm.duplicate.findbycomm)
4. API Б24 → CallMeIn.php (ответственный)
5. CallMeIn.php → API Б24 (telephony.externalcall.register)
6. API Б24 → CallMeIn.php (call_id)
7. CallMeIn.php → API Б24 (telephony.externalcall.showCard)
8. Звонок завершается → CallMeIn.php → API Б24 (telephony.externalcall.finish)
```

**Исходящий звонок:**
```
1. Пользователь Б24 → Клик на номер
2. Б24 → CallMeOut.php (вебхук ONEXTERNALCALLSTART)
3. CallMeOut.php → AMI (Originate: внутренний → внешний)
4. Asterisk → AMI Events (VarSet: IS_CALLME_ORIGINATE, CallMeCALL_ID)
5. CallMeIn.php → Отслеживание всех событий
6. CallMeIn.php → API Б24 (telephony.externalcall.showCard)
7. Звонок завершается → CallMeIn.php → API Б24 (telephony.externalcall.finish)
```

---

## 📦 Установка

### Требования:
- PHP 8.2.29 или выше
- CentOS 9 (или совместимый Linux)
- Asterisk 1.8.13+ с AMI
- Доступ к API Битрикс24 (REST API)
- Composer

### Быстрая установка:

```bash
# 1. Клонирование репозитория
cd /var/www/
git clone https://github.com/your-repo/callme.git callme
cd callme

# 2. Установка зависимостей
composer install --no-dev

# 3. Копирование конфигурации
cp config.example.php config.php
nano config.php  # Настройте параметры

# 4. Настройка прав
chown -R www-data:www-data /var/www/callme
chmod +x CallMeIn.php CallMeOut.php

# 5. Установка скриптов деплоя (опционально)
cd deploy/
sudo ./install.sh
```

### Установка модуля записи на Asterisk:

**⚠️ ВАЖНО:** Этот модуль **ОБЯЗАТЕЛЬНО** должен быть установлен на сервере АТС (Asterisk). Это единственное изменение в диалплане Asterisk, необходимое для работы CallMe.

```bash
# 1. Скопировать файл на АТС
sudo cp contrib/b24_continuous_parallel.conf /etc/asterisk/

# 2. Добавить в extensions_custom.conf (НЕ удалять!)
echo "#include b24_continuous_parallel.conf" | sudo tee -a /etc/asterisk/extensions_custom.conf

# 3. Создать папку для записей
sudo mkdir -p /var/spool/asterisk/continuous
sudo chown -R asterisk:asterisk /var/spool/asterisk/continuous

# 4. Установить lame (для автоконвертации WAV → MP3)
sudo yum install lame  # CentOS/RHEL
# или
sudo apt-get install lame  # Debian/Ubuntu

# 5. Перезагрузить диалплан
sudo asterisk -rx "dialplan reload"

# 6. Проверка
sudo asterisk -rx "dialplan show sub-record-check-custom"
```

**Что делает модуль:**

1. **Устанавливает переменную `CallMeLINKEDID`** для каждого канала → CallMe получает через AMI VarSetEvent
2. **Запускает параллельный MixMonitor** для непрерывной записи
3. **Наследует запись при Transfer** через `AUDIOHOOK_INHERIT=yes`
4. **Автоматически конвертирует** WAV → MP3 после завершения звонка
5. **Устанавливает переменную `CallMeFULLFNAME`** с путем к записи → CallMe получает URL для загрузки в Битрикс24

**Почему это необходимо:**

- Без этого модуля CallMe не сможет получить `Linkedid` каналов
- Без `Linkedid` невозможно отслеживать transfer'ы и правильно завершать звонки
- Без модуля не будет непрерывных записей (только разорванные части при transfer)

### Настройка supervisord:

```bash
# Скопируйте конфигурацию
sudo cp deploy/supervisord.conf /etc/supervisord.d/callme.ini

# Редактируйте пути
sudo nano /etc/supervisord.d/callme.ini

# Перезапустите supervisord
sudo systemctl restart supervisord
sudo supervisorctl reread
sudo supervisorctl add callme
sudo supervisorctl start callme
```

---

## ⚙️ Конфигурация

### Основные параметры `config.php`:

```php
return array(
    // Asterisk AMI
    'asterisk' => array(
        'host' => '192.168.1.100',  // IP АТС
        'port' => 5038,
        'username' => 'b24',
        'secret' => 'your_secret',
    ),
    
    // Bitrix24 API
    'bitrixApiUrl' => 'https://your-domain.bitrix24.ru/rest/1/webhook/',
    'authToken' => 'your-token',
    
    // Технология и контекст
    'tech' => 'SIP',
    'context' => 'from-internal',
    
    // Внешние номера для мониторинга
    'extentions' => array('8001231231', '8002345678'),
    
    // Режим определения ответственного
    'responsible_mode' => 'crm_responsible',  // или 'static_mapping'
    
    // Статический маппинг (fallback)
    'bx24' => array(
        '8001' => '101',
        'default_user_number' => '100',
    ),
    
    // Список пользователей для показа карточек
    'user_show_cards' => array('101', '102', '103'),
    
    // Debug режим
    'CallMeDEBUG' => true,
    'enable_full_log' => false,
);
```

### Настройка вебхука в Битрикс24:

1. Настройки → Телефония → Вебхуки
2. Добавьте вебхук для исходящих звонков:
   - URL: `http://your-server/callme/CallMeOut.php`
   - События: `ONEXTERNALCALLSTART`
   - Авторизация: токен из `config.php`

---

## 🚀 Использование

### Запуск CallMeIn.php:

```bash
# Через supervisord (рекомендуется)
sudo supervisorctl start callme

# Или вручную
php /var/www/callme/CallMeIn.php &

# Проверка логов
tail -f /var/www/callme/logs/CallMe.log
```

### Проверка работы:

```bash
# 1. Проверка подключения к AMI
sudo asterisk -rx "manager show connected"

# 2. Проверка событий в логах
tail -f /var/www/callme/logs/CallMe.log | grep "ORIGINATE\|TRANSFER"

# 3. Тестовый входящий звонок
# Позвоните на один из номеров из 'extentions'

# 4. Тестовый исходящий звонок
# Кликните на номер в Битрикс24
```

---

## 📝 Известные отличия от оригинала

| Функция | Оригинал | CallMe v2 |
|---------|----------|-----------|
| **PHP версия** | 5.3 | 8.2+ |
| **Место установки** | На АТС | На сервере Б24 |
| **Изменения диалплана** | Требуются | Не требуются |
| **Исходящие звонки** | ❌ Нет | ✅ Полная поддержка |
| **Transfer отслеживание** | ❌ Нет | ✅ Через Linkedid |
| **Определение ответственного** | Дефолтный | Из CRM |
| **Непрерывная запись** | ❌ Нет | ✅ Модуль записи |
| **Логирование** | Базовое | Расширенное (DEBUG) |

---

## 🐛 Исправленные баги

1. ✅ Множественные переключения карточек при transfer (игнорирование bridge между внутренними)
2. ✅ Потеря CALL_ID при Originate-вызовах (обработка CallMeCALL_ID до CallMeLINKEDID)
3. ✅ Ошибочные запросы user.get с DialString (исключение Originate из DialBegin/DialEnd)
4. ✅ Регистрация звонка на дефолтный номер (определение ответственного до регистрации)
5. ✅ Неправильный статус завершения Originate-вызовов (определение через DialStatus/HangupCause)

---

## 📚 Дополнительные материалы

- [Руководство по развертыванию](deploy/README.md)
- [Конфигурация записи звонков](contrib/b24_continuous_parallel.conf)
- [Примеры конфигураций](contrib/)

---

## 👥 Авторы

- **Оригинальный проект:** [ViStep.RU](https://github.com/ViStepRU/callme)
- **Форк без диалплана:** [Demosthen42/callme](https://github.com/Demosthen42/callme)
- **Адаптация для PHP 8.2:** Anton-Sevnet

---

## 📄 Лицензия

См. лицензию оригинального проекта.

---

## 🔗 Ссылки

- Оригинальная статья: https://habrahabr.ru/post/349316/
- Библиотека PAMI: https://github.com/marcelog/PAMI
- Asterisk AMI: https://wiki.asterisk.org/wiki/display/AST/The+Asterisk+Manager+Interface

---

**Версия:** 2.0  
**Последнее обновление:** 2025-11-01
