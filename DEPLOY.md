CallMe v2 — деплой и AMI keepalive

1) Обновление/установка
- Рекомендуемый способ запуска — supervisor (`callme`), чтобы процесс перезапускался при падении.

2) AMI Healthcheck в приложении
- В `config.php` доступны параметры (с дефолтами, если не заданы):
  - `ami_ping_interval` — период AMI Ping, сек (по умолчанию 5)
  - `ami_ping_missed_max` — сколько подряд пропусков считать отказом (по умолчанию 3)
  - `idle_watchdog_timeout` — максимальный простой по событиям, сек (по умолчанию 5)
- При превышении порогов выполняется авто‑реконнект.

2.1) Логирование AMI Healthcheck
- Параметр `ami_healthcheck_log` управляет логированием работы системы мониторинга AMI
- Логи пишутся в отдельный файл `logs/ami_healthcheck.log` (независимо от `CallMeDEBUG`)
- Структура параметра:
  ```php
  'ami_healthcheck_log' => array(
      'ping' => array(
          'NOTICE' => true,   // Важные события: пропуски, ошибки, сброс счётчика
          'DEBUG' => false    // Все пинги, включая успешные
      ),
      'watchdog' => array(
          'NOTICE' => true,   // Срабатывания watchdog таймаута
          'DEBUG' => false    // Все проверки watchdog с временем последнего события
      ),
      'reconnect' => array(
          'NOTICE' => true,   // Факты реконнектов с причиной
          'DEBUG' => false    // Детальное логирование: backoff, попытки, результаты
      )
  )
  ```
- Уровни логирования:
  - **NOTICE** — только важные события (ошибки, таймауты, реконнекты)
  - **DEBUG** — все события, включая успешные операции и периодические проверки
- Периодическая статистика (раз в минуту) записывается на уровне DEBUG и включает:
  - Время работы соединения
  - Количество реконнектов
  - Количество успешных пингов
  - Текущие параметры healthcheck

3) Включение TCP keepalive на CentOS 9 (системно)
PAMI не включает keepalive на сокете программно, используйте системные параметры ядра.

Временно (до перезагрузки):
```bash
sudo sysctl -w net.ipv4.tcp_keepalive_time=60
sudo sysctl -w net.ipv4.tcp_keepalive_intvl=10
sudo sysctl -w net.ipv4.tcp_keepalive_probes=5
```

Постоянно:
```bash
sudo tee /etc/sysctl.d/99-ami-keepalive.conf >/dev/null <<'EOF'
net.ipv4.tcp_keepalive_time=60
net.ipv4.tcp_keepalive_intvl=10
net.ipv4.tcp_keepalive_probes=5
EOF
sudo sysctl --system
```

Проверка активного keepalive на AMI (порт 5038):
```bash
ss -tin dst :5038
# В колонке timer ожидается keepalive
```

4) Проверка авто‑переподключения
- Перезапустите Asterisk: `asterisk -rx "core restart now"`.
- В логах `logs/` появится `AMI reconnected ...`.
- `asterisk*CLI> manager show connected` должен показывать `b24` без `supervisorctl restart`.


