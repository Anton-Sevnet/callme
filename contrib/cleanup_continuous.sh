#!/bin/bash
# Очистка каталога непрерывных записей Asterisk
# Удаляет файлы старше RETENTION_DAYS и чистит пустые вложенные директории.

set -euo pipefail

TARGET_DIR="/var/spool/asterisk/continuous"
LOGFILE="/var/log/cleanup_continuous.log"
RETENTION_DAYS=90

timestamp() {
    date +"%Y-%m-%d %H:%M:%S"
}

{
    echo "$(timestamp) --- старт очистки каталога ${TARGET_DIR}"

    if [ ! -d "${TARGET_DIR}" ]; then
        echo "$(timestamp) ошибка: каталог ${TARGET_DIR} не найден"
        exit 1
    fi

    # Удаление файлов старше заданного срока
    find "${TARGET_DIR}" -type f -mtime +"${RETENTION_DAYS}" -print -delete

    # Очистка пустых директорий (кроме корневой)
    find "${TARGET_DIR}" -type d -mindepth 1 -empty -print -delete

    echo "$(timestamp) --- очистка завершена"
} >> "${LOGFILE}" 2>&1

