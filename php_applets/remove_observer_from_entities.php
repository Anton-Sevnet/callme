<?php
/**
 * Удаление наблюдателя из CRM сущностей и восстановление параметра "Доступен для всех".
 *
 * Принимает JSON payload в формате:
 * {
 *   "entities": [
 *     {
 *       "ENTITY_TYPE_ID": 1,
 *       "ENTITY_ID": 123,
 *       "USER_ID": 42,
 *       "RESTORE_OPENED": true  // true если нужно восстановить OPENED = 'N'
 *     }
 *   ]
 * }
 *
 * Для каждой сущности:
 * 1. Удаляет USER_ID из массива OBSERVER_IDS
 * 2. Если RESTORE_OPENED = true, устанавливает OPENED = 'N'
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
 * Проверяет, является ли тип сущности динамическим смарт-процессом
 */
function isDynamicType(int $entityTypeId): bool
{
    return \CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId);
}

/**
 * Получает класс для работы с CRM сущностью по типу (только для стандартных типов)
 */
function getEntityClass(int $entityTypeId): ?string
{
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
            return null;
    }
}

/**
 * Удаляет наблюдателя из динамической CRM сущности через новый API
 */
function removeObserverFromDynamicEntity(int $entityTypeId, int $entityId, int $userId, bool $restoreOpened): array
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

        // Получаем текущих наблюдателей
        $currentObservers = ObserverManager::getEntityObserverIDs($entityTypeId, $entityId);
        if (!is_array($currentObservers)) {
            $currentObservers = array();
        }

        // Удаляем пользователя из наблюдателей только если USER_ID > 0
        $wasInObservers = false;
        if ($userId > 0) {
            $wasInObservers = in_array($userId, $currentObservers);
            if ($wasInObservers) {
                $newObservers = array_values(array_diff($currentObservers, array($userId)));
                // Устанавливаем новых наблюдателей
                if ($factory->isObserversEnabled()) {
                    $item->setObservers($newObservers);
                }
            }
        }
        
        // Восстанавливаем OPENED = 'N' если нужно
        if ($restoreOpened) {
            $item->setOpened(false);
        }

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
            return array(
                'success' => true,
                'entity_type_id' => $entityTypeId,
                'entity_id' => $entityId,
                'was_removed' => $wasInObservers,
                'opened_restored' => $restoreOpened
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
 * Удаляет наблюдателя из CRM сущности
 */
function removeObserverFromEntity(int $entityTypeId, int $entityId, int $userId, bool $restoreOpened): array
{
    // Для динамических типов используем новый API
    if (isDynamicType($entityTypeId)) {
        return removeObserverFromDynamicEntity($entityTypeId, $entityId, $userId, $restoreOpened);
    }

    // Для стандартных типов используем старый API
    $entityClass = getEntityClass($entityTypeId);
    if ($entityClass === null) {
        return array(
            'success' => false,
            'error' => "Неподдерживаемый тип сущности: {$entityTypeId}"
        );
    }

    // Создаем экземпляр сущности без проверки прав
    $entity = new $entityClass(false);

    // Получаем текущих наблюдателей
    $currentObservers = ObserverManager::getEntityObserverIDs($entityTypeId, $entityId);
    if (!is_array($currentObservers)) {
        $currentObservers = array();
    }

    // Удаляем пользователя из наблюдателей только если USER_ID > 0
    $wasInObservers = false;
    $fields = array();
    
    if ($userId > 0) {
        $wasInObservers = in_array($userId, $currentObservers);
        if ($wasInObservers) {
            $newObservers = array_values(array_diff($currentObservers, array($userId)));
            $fields['OBSERVER_IDS'] = $newObservers;
        }
    }
    
    // Восстанавливаем OPENED = 'N' если нужно
    if ($restoreOpened) {
        $fields['OPENED'] = 'N';
    }
    
    // Если нет полей для обновления, выходим
    if (empty($fields)) {
        return array(
            'success' => true,
            'entity_type_id' => $entityTypeId,
            'entity_id' => $entityId,
            'was_removed' => false,
            'opened_restored' => false,
            'skipped' => true,
            'reason' => 'No changes needed'
        );
    }

    // Обновляем сущность
    $result = $entity->Update($entityId, $fields, true, true, array('DISABLE_USER_FIELD_CHECK' => true));

    if ($result) {
        return array(
            'success' => true,
            'entity_type_id' => $entityTypeId,
            'entity_id' => $entityId,
            'was_removed' => $wasInObservers,
            'opened_restored' => $restoreOpened
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

    if (!isset($payload['entities']) || !is_array($payload['entities'])) {
        return array(
            'success' => false,
            'error' => 'Некорректный формат данных. Ожидается: {"entities": [...]}'
        );
    }

    $entities = $payload['entities'];
    if (empty($entities)) {
        return array(
            'success' => false,
            'error' => 'Массив entities пуст'
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
        $userId = isset($entity['USER_ID']) ? (int)$entity['USER_ID'] : 0;
        $restoreOpened = isset($entity['RESTORE_OPENED']) ? (bool)$entity['RESTORE_OPENED'] : false;

        if ($entityTypeId <= 0 || $entityId <= 0) {
            $errorCount++;
            $results[] = array(
                'success' => false,
                'error' => 'ENTITY_TYPE_ID и ENTITY_ID должны быть положительными числами',
                'entity' => $entity
            );
            continue;
        }
        
        // USER_ID может быть 0, если нужно только восстановить OPENED без удаления наблюдателя
        if ($userId < 0) {
            $errorCount++;
            $results[] = array(
                'success' => false,
                'error' => 'USER_ID не может быть отрицательным',
                'entity' => $entity
            );
            continue;
        }

        $result = removeObserverFromEntity($entityTypeId, $entityId, $userId, $restoreOpened);
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

