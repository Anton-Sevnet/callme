<?php
/**
 * Добавление наблюдателя к CRM сущностям и установка параметра "Доступен для всех".
 *
 * Принимает JSON payload в формате:
 * {
 *   "entities": [
 *     {"ENTITY_TYPE_ID": 1, "ENTITY_ID": 123},
 *     {"ENTITY_TYPE_ID": 2, "ENTITY_ID": 456}
 *   ],
 *   "USER_ID": 42
 * }
 *
 * Или альтернативный формат (массив):
 * [
 *   [{"ENTITY_TYPE_ID": 1, "ENTITY_ID": 123}, {"ENTITY_TYPE_ID": 2, "ENTITY_ID": 456}],
 *   42
 * ]
 *
 * Для каждой сущности:
 * 1. Добавляет USER_ID в массив OBSERVER_IDS (merge с существующими)
 * 2. Устанавливает OPENED = 'Y' (Доступен для всех)
 *
 * Скрипт обходит контроль прав доступа.
 */

define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/../../..');
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;
use Bitrix\Crm\Observer\ObserverManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Item;

// Подключаем необходимые модули
if (!Loader::includeModule('crm')) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error' => 'Модуль CRM не доступен'
    ));
    exit(1);
}

/**
 * Получает JSON payload из запроса
 */
function getJsonPayload(): ?array
{
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        // Пробуем получить из POST
        if (isset($_POST['payload'])) {
            $rawInput = $_POST['payload'];
        } elseif (isset($_REQUEST['payload'])) {
            $rawInput = $_REQUEST['payload'];
        }
    }

    if (empty($rawInput)) {
        return null;
    }

    $data = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $data;
}

/**
 * Нормализует входные данные в единый формат
 */
function normalizeInput(array $data): ?array
{
    // Формат 1: {"entities": [...], "USER_ID": 42}
    if (isset($data['entities']) && isset($data['USER_ID'])) {
        return array(
            'entities' => $data['entities'],
            'user_id' => (int)$data['USER_ID']
        );
    }

    // Формат 2: [entities_array, USER_ID]
    if (is_array($data) && count($data) === 2) {
        $entities = $data[0];
        $userId = $data[1];

        if (is_array($entities) && is_numeric($userId)) {
            return array(
                'entities' => $entities,
                'user_id' => (int)$userId
            );
        }
    }

    // Формат 3: альтернативные ключи
    if (isset($data['entities']) && isset($data['user_id'])) {
        return array(
            'entities' => $data['entities'],
            'user_id' => (int)$data['user_id']
        );
    }

    return null;
}

/**
 * Проверяет, является ли тип сущности динамическим смарт-процессом
 * Использует метод Битрикс24 для проверки всех диапазонов:
 * - Старый диапазон: 128-192
 * - Новый неограниченный диапазон: >= 1030 (четные ID)
 */
function isDynamicType(int $entityTypeId): bool
{
    return \CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId);
}

/**
 * Получает класс для работы с CRM сущностью по типу (только для стандартных типов)
 * Для динамических типов возвращает null (используется новый API)
 */
function getEntityClass(int $entityTypeId): ?string
{
    // Используем числовые значения вместо констант
    switch ($entityTypeId) {
        case 1: // Lead
            return 'CCrmLead';
        case 2: // Deal
            return 'CCrmDeal';
        case 3: // Contact
            return 'CCrmContact';
        case 4: // Company
            return 'CCrmCompany';
        case 7: // Quote
            return 'CCrmQuote';
        case 5: // Invoice
            return 'CCrmInvoice';
        default:
            // Для динамических типов возвращаем null
            // Они обрабатываются через новый API (Factory/Item)
            return null;
    }
}

/**
 * Добавляет запись в историю CRM сущности
 * 
 * @param int $entityTypeId Тип сущности (CCrmOwnerType)
 * @param int $entityId ID сущности
 * @param string $fieldName Имя поля
 * @param string $eventText Текст события
 * @param int|null $systemUserId ID пользователя для системной записи (по умолчанию 1)
 */
function addHistoryRecord(int $entityTypeId, int $entityId, string $fieldName, string $eventText, ?int $systemUserId = 1): void
{
    try {
        $entityTypeName = \CCrmOwnerType::ResolveName($entityTypeId);
        if (empty($entityTypeName)) {
            return; // Не удалось определить тип сущности
        }

        // Используем системного пользователя (ID 1) или переданный
        $historyUserId = $systemUserId > 0 ? $systemUserId : 1;

        $event = new \CCrmEvent();
        $event->Add(array(
            'ENTITY_TYPE' => $entityTypeName,
            'ENTITY_ID' => $entityId,
            'ENTITY_FIELD' => $fieldName,
            'EVENT_TYPE' => \CCrmEvent::TYPE_CHANGE,
            'USER_ID' => $historyUserId,
            'EVENT_NAME' => 'Изменение',
            'EVENT_TEXT_1' => $eventText,
            'EVENT_TEXT_2' => ''
        ), false); // false = без проверки прав доступа
    } catch (\Throwable $e) {
        // Игнорируем ошибки записи истории, чтобы не прервать основной процесс
        // Можно залогировать если нужно
    }
}

/**
 * Обновляет динамическую CRM сущность через новый API (Factory/Item)
 */
function updateDynamicEntity(int $entityTypeId, int $entityId, int $userId): array
{
    try {
        $container = Container::getInstance();
        $factory = $container->getFactory($entityTypeId);
        
        if ($factory === null) {
            return array(
                'success' => false,
                'error' => "Не удалось получить фабрику для типа сущности: {$entityTypeId}"
            );
        }

        // Получаем элемент
        $item = $factory->getItem($entityId);
        if ($item === null) {
            return array(
                'success' => false,
                'error' => "Сущность с ID {$entityId} не найдена"
            );
        }

        // Проверяем текущее значение OPENED из БД (через remindActual для получения реального значения)
        // Если уже 'Y' или true, выходим без изменений
        $currentOpened = null;
        
        // Пробуем разные способы получить значение OPENED
        if ($item->hasField('OPENED')) {
            $currentOpened = $item->remindActual('OPENED');
            if ($currentOpened === null) {
                $currentOpened = $item->get('OPENED');
            }
            if ($currentOpened === null) {
                $currentOpened = $item->getOpened();
            }
        }
        
        // Проверяем различные форматы: true, 'Y', 1, '1'
        // Используем строгое сравнение для булевых и нестрогое для строк
        $isOpened = false;
        if ($currentOpened === true || $currentOpened === 'Y' || $currentOpened === 1 || $currentOpened === '1') {
            $isOpened = true;
        } elseif ($currentOpened !== null && $currentOpened !== false && $currentOpened !== 'N' && $currentOpened !== 0 && $currentOpened !== '0') {
            // Дополнительная проверка: если значение не пустое и не 'N', считаем что открыто
            $isOpened = true;
        }
        
        if ($isOpened) {
            // Даже если OPENED уже 'Y', все равно добавляем наблюдателя если нужно
            $currentObservers = ObserverManager::getEntityObserverIDs($entityTypeId, $entityId);
            if (!is_array($currentObservers)) {
                $currentObservers = array();
            }
            
            // Проверяем, нужно ли добавить наблюдателя
            if (!in_array($userId, $currentObservers)) {
                $newObservers = array_unique(
                    array_merge($currentObservers, array($userId)),
                    SORT_NUMERIC
                );
                
                if ($factory->isObserversEnabled()) {
                    $item->setObservers($newObservers);
                }
                
                $operation = $factory->getUpdateOperation($item);
                if ($operation !== null) {
                    $result = $operation->launch();
                    if ($result->isSuccess()) {
                        // Записываем в историю: добавлен наблюдатель
                        $userName = '';
                        try {
                            $user = \CUser::GetByID($userId)->Fetch();
                            if ($user) {
                                $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
                                if (empty($userName)) {
                                    $userName = $user['LOGIN'] ?? "ID: {$userId}";
                                }
                            }
                        } catch (\Throwable $e) {
                            $userName = "ID: {$userId}";
                        }
                        
                        addHistoryRecord(
                            $entityTypeId,
                            $entityId,
                            'OBSERVER_IDS',
                            "Добавлен наблюдатель: {$userName}",
                            1
                        );
                    }
                }
            }
            
            return array(
                'success' => true,
                'entity_type_id' => $entityTypeId,
                'entity_id' => $entityId,
                'skipped' => true,
                'reason' => 'OPENED уже установлен в Y',
                'opened_changed' => false,
                'opened_was_n' => false,
                'was_added' => !in_array($userId, $currentObservers ?? array())
            );
        }

        // Получаем текущих наблюдателей
        $currentObservers = ObserverManager::getEntityObserverIDs($entityTypeId, $entityId);
        if (!is_array($currentObservers)) {
            $currentObservers = array();
        }

        // Сохраняем исходное значение OPENED (было ли оно 'N' или false)
        $wasOpened = $isOpened;
        $openedChanged = !$wasOpened; // Изменилось только если было 'N' и стало 'Y'

        // Объединяем с новым наблюдателем (merge)
        $newObservers = array_unique(
            array_merge($currentObservers, array($userId)),
            SORT_NUMERIC
        );

        // Устанавливаем наблюдателей и OPENED
        if ($factory->isObserversEnabled()) {
            $item->setObservers($newObservers);
        }
        $item->setOpened(true);

        // Получаем операцию обновления
        $operation = $factory->getUpdateOperation($item);
        if ($operation === null) {
            return array(
                'success' => false,
                'error' => "Не удалось создать операцию обновления"
            );
        }

        // Выполняем обновление
        $result = $operation->launch();
        
        if ($result->isSuccess()) {
            // Получаем информацию о пользователе для истории
            $userName = '';
            try {
                $user = \CUser::GetByID($userId)->Fetch();
                if ($user) {
                    $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
                    if (empty($userName)) {
                        $userName = $user['LOGIN'] ?? "ID: {$userId}";
                    }
                }
            } catch (\Throwable $e) {
                $userName = "ID: {$userId}";
            }

            // Записываем в историю: добавлен наблюдатель
            if (!in_array($userId, $currentObservers)) {
                addHistoryRecord(
                    $entityTypeId,
                    $entityId,
                    'OBSERVER_IDS',
                    "Добавлен наблюдатель: {$userName}",
                    1
                );
            }

            // Записываем в историю: установлено "Доступен для всех" (если изменилось)
            if (!$wasOpened) {
                addHistoryRecord(
                    $entityTypeId,
                    $entityId,
                    'OPENED',
                    'Доступен для всех: Да',
                    1
                );
            }

            return array(
                'success' => true,
                'entity_type_id' => $entityTypeId,
                'entity_id' => $entityId,
                'observers_count' => count($newObservers),
                'was_added' => !in_array($userId, $currentObservers),
                'opened_changed' => $openedChanged,
                'opened_was_n' => !$wasOpened
            );
        } else {
            $errors = $result->getErrorMessages();
            return array(
                'success' => false,
                'entity_type_id' => $entityTypeId,
                'entity_id' => $entityId,
                'error' => implode(', ', $errors)
            );
        }
    } catch (\Throwable $e) {
        return array(
            'success' => false,
            'entity_type_id' => $entityTypeId,
            'entity_id' => $entityId,
            'error' => 'Ошибка при обновлении динамической сущности: ' . $e->getMessage()
        );
    }
}

/**
 * Обновляет CRM сущность: добавляет наблюдателя и устанавливает OPENED
 */
function updateEntity(int $entityTypeId, int $entityId, int $userId): array
{
    // Для динамических типов используем новый API
    if (isDynamicType($entityTypeId)) {
        return updateDynamicEntity($entityTypeId, $entityId, $userId);
    }

    // Для стандартных типов используем старый API
    $entityClass = getEntityClass($entityTypeId);
    if ($entityClass === null) {
        return array(
            'success' => false,
            'error' => "Неподдерживаемый тип сущности: {$entityTypeId}"
        );
    }

    // Создаем экземпляр сущности без проверки прав для получения текущих данных
    $entity = new $entityClass(false);

    // Получаем текущее значение OPENED для проверки (в самом начале!)
    // Важно: получаем данные ДО любых изменений
    $currentOpened = 'N';
    try {
        $arEntity = $entity->GetByID($entityId, false); // false = без проверки прав
        if ($arEntity && isset($arEntity['OPENED'])) {
            $currentOpened = $arEntity['OPENED'];
        }
    } catch (\Throwable $e) {
        // Игнорируем ошибку получения данных
    }

    // Проверяем: если OPENED уже 'Y' или true, выходим без изменений
    // Проверяем различные форматы: 'Y', true, 1, '1'
    $isOpened = false;
    if ($currentOpened === 'Y' || $currentOpened === true || $currentOpened === 1 || $currentOpened === '1') {
        $isOpened = true;
    } elseif ($currentOpened !== null && $currentOpened !== false && $currentOpened !== 'N' && $currentOpened !== 0 && $currentOpened !== '0') {
        // Дополнительная проверка: если значение не пустое и не 'N', считаем что открыто
        $isOpened = true;
    }
    
    // Сохраняем исходное значение OPENED
    $wasOpened = $isOpened;
    $openedChanged = !$wasOpened; // Изменилось только если было 'N' и стало 'Y'
    
    if ($isOpened) {
        // Даже если OPENED уже 'Y', все равно добавляем наблюдателя если нужно
        $currentObservers = ObserverManager::getEntityObserverIDs($entityTypeId, $entityId);
        if (!is_array($currentObservers)) {
            $currentObservers = array();
        }
        
        // Проверяем, нужно ли добавить наблюдателя
        if (!in_array($userId, $currentObservers)) {
            $newObservers = array_unique(
                array_merge($currentObservers, array($userId)),
                SORT_NUMERIC
            );
            
            $fields = array(
                'OBSERVER_IDS' => $newObservers
            );
            
            $result = $entity->Update($entityId, $fields, true, true, array('DISABLE_USER_FIELD_CHECK' => true));
            
            if ($result) {
                // Записываем в историю: добавлен наблюдатель
                $userName = '';
                try {
                    $user = \CUser::GetByID($userId)->Fetch();
                    if ($user) {
                        $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
                        if (empty($userName)) {
                            $userName = $user['LOGIN'] ?? "ID: {$userId}";
                        }
                    }
                } catch (\Throwable $e) {
                    $userName = "ID: {$userId}";
                }
                
                addHistoryRecord(
                    $entityTypeId,
                    $entityId,
                    'OBSERVER_IDS',
                    "Добавлен наблюдатель: {$userName}",
                    1
                );
            }
        }
        
        return array(
            'success' => true,
            'entity_type_id' => $entityTypeId,
            'entity_id' => $entityId,
            'skipped' => true,
            'reason' => 'OPENED уже установлен в Y',
            'opened_changed' => false,
            'opened_was_n' => false,
            'was_added' => !in_array($userId, $currentObservers ?? array())
        );
    }

    // Получаем текущих наблюдателей
    $currentObservers = ObserverManager::getEntityObserverIDs($entityTypeId, $entityId);
    if (!is_array($currentObservers)) {
        $currentObservers = array();
    }

    // Объединяем с новым наблюдателем (merge)
    $newObservers = array_unique(
        array_merge($currentObservers, array($userId)),
        SORT_NUMERIC
    );

    // Подготавливаем поля для обновления
    $fields = array(
        'OBSERVER_IDS' => $newObservers,
        'OPENED' => 'Y'
    );

    // Обновляем сущность
    // Параметры: ID, fields, bCompare, bUpdateSearch, options
    $result = $entity->Update($entityId, $fields, true, true, array('DISABLE_USER_FIELD_CHECK' => true));

    if ($result) {
        // Получаем информацию о пользователе для истории
        $userName = '';
        try {
            $user = \CUser::GetByID($userId)->Fetch();
            if ($user) {
                $userName = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
                if (empty($userName)) {
                    $userName = $user['LOGIN'] ?? "ID: {$userId}";
                }
            }
        } catch (\Throwable $e) {
            $userName = "ID: {$userId}";
        }

        // Записываем в историю: добавлен наблюдатель
        if (!in_array($userId, $currentObservers)) {
            addHistoryRecord(
                $entityTypeId,
                $entityId,
                'OBSERVER_IDS',
                "Добавлен наблюдатель: {$userName}",
                1
            );
        }

        // Записываем в историю: установлено "Доступен для всех" (если изменилось)
        if ($currentOpened !== 'Y') {
            addHistoryRecord(
                $entityTypeId,
                $entityId,
                'OPENED',
                'Доступен для всех: Да',
                1
            );
        }

        return array(
            'success' => true,
            'entity_type_id' => $entityTypeId,
            'entity_id' => $entityId,
            'observers_count' => count($newObservers),
            'was_added' => !in_array($userId, $currentObservers),
            'opened_changed' => $openedChanged,
            'opened_was_n' => !$wasOpened
        );
    } else {
        $error = $entity->LAST_ERROR ?: 'Неизвестная ошибка обновления';
        return array(
            'success' => false,
            'entity_type_id' => $entityTypeId,
            'entity_id' => $entityId,
            'error' => $error
        );
    }
}

/**
 * Основная функция обработки
 */
function processRequest(): array
{
    $payload = getJsonPayload();
    if ($payload === null) {
        return array(
            'success' => false,
            'error' => 'Не удалось получить или распарсить JSON payload'
        );
    }

    $normalized = normalizeInput($payload);
    if ($normalized === null) {
        return array(
            'success' => false,
            'error' => 'Некорректный формат данных. Ожидается: {"entities": [...], "USER_ID": 42} или [[entities], USER_ID]'
        );
    }

    $entities = $normalized['entities'];
    $userId = $normalized['user_id'];

    if (!is_array($entities) || empty($entities)) {
        return array(
            'success' => false,
            'error' => 'Массив entities пуст или не является массивом'
        );
    }

    if ($userId <= 0) {
        return array(
            'success' => false,
            'error' => 'USER_ID должен быть положительным числом'
        );
    }

    $results = array();
    $successCount = 0;
    $errorCount = 0;

    foreach ($entities as $entity) {
        if (!is_array($entity)) {
            $errorCount++;
            $results[] = array(
                'success' => false,
                'error' => 'Элемент entities должен быть массивом'
            );
            continue;
        }

        $entityTypeId = isset($entity['ENTITY_TYPE_ID']) ? (int)$entity['ENTITY_TYPE_ID'] : 0;
        $entityId = isset($entity['ENTITY_ID']) ? (int)$entity['ENTITY_ID'] : 0;

        if ($entityTypeId <= 0 || $entityId <= 0) {
            $errorCount++;
            $results[] = array(
                'success' => false,
                'error' => 'ENTITY_TYPE_ID и ENTITY_ID должны быть положительными числами',
                'entity' => $entity
            );
            continue;
        }

        $result = updateEntity($entityTypeId, $entityId, $userId);
        $results[] = $result;

        if ($result['success']) {
            $successCount++;
        } else {
            $errorCount++;
        }
    }

    return array(
        'success' => $errorCount === 0,
        'total' => count($entities),
        'success_count' => $successCount,
        'error_count' => $errorCount,
        'results' => $results
    );
}

// Обработка запроса
try {
    $response = processRequest();
    http_response_code($response['success'] ? 200 : 400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'success' => false,
        'error' => 'Внутренняя ошибка сервера',
        'message' => $e->getMessage()
    ), JSON_UNESCAPED_UNICODE);
}

