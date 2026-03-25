<?php
// ---------- Пути и конфигурация ----------
$root = dirname(__DIR__);
$db = include($root . '/config/db.php');
$db_pass = $db['pass'];

$serverini = parse_ini_file($root . "/server.ini", true);
$host = $serverini['SERVERINF']['SERVER'];
$dbname = $serverini['SERVERINF']['DATABASE'];

// ---------- Обработка дат ----------
$defaultFrom = date('Y-m-d', strtotime('-30 days'));
$defaultTo   = date('Y-m-d');

$dateFrom = $_GET['date_from'] ?? $defaultFrom;
$dateTo   = $_GET['date_to']   ?? $defaultTo;

$errors = [];

$fromObj = DateTime::createFromFormat('Y-m-d', $dateFrom);
$toObj   = DateTime::createFromFormat('Y-m-d', $dateTo);

if (!$fromObj || $fromObj->format('Y-m-d') !== $dateFrom) {
    $errors[] = 'Некорректная дата "С". Ожидается формат ГГГГ-ММ-ДД.';
}
if (!$toObj || $toObj->format('Y-m-d') !== $dateTo) {
    $errors[] = 'Некорректная дата "По". Ожидается формат ГГГГ-ММ-ДД.';
}
if (!$errors && $dateFrom > $dateTo) {
    $errors[] = 'Дата "С" не может быть больше даты "По".';
}

// Основные переменные для статистики
$chatsCount = null;             // Количество чатов в MAX
$chatsTotal = null;             // Всего чатов МАХ (без учета периода)
$chatsCreatedInPeriod = null;   // Чаты созданные за период
$chatsActiveInPeriod = null;    // Активные чаты за период
$statusesCount = null;          // Статусы рассмотрения
$readyResultsCount = null;      // Уведомления о готовности результатов
$preliminaryRecords = null;     // Предварительная запись

if (!$errors) {
    // ---------- Подключение к PostgreSQL ----------
    $link = pg_connect("host=$host dbname=$dbname user=postgres password=$db_pass")
        or die("DB connect error");

    // ---------- SQL #1: Всего чатов МАХ (без учета периода) ----------
    $sql_total_chats = "
        SELECT COUNT(*) AS total_count
        FROM telegram.chats 
        WHERE service_kind = 5;
    ";

    $result_total = pg_query($link, $sql_total_chats);
    if ($result_total) {
        $row = pg_fetch_assoc($result_total);
        $chatsTotal = (int)$row['total_count'];
    }

    // ---------- SQL #2: Чаты созданные за период ----------
    $sql_created_chats = "
        SELECT COUNT(*) AS total_count
        FROM telegram.chats 
        WHERE service_kind = 5
          AND DATE(created_at) BETWEEN $1 AND $2;
    ";

    $result_created = pg_query_params($link, $sql_created_chats, [$dateFrom, $dateTo]);
    if ($result_created) {
        $row = pg_fetch_assoc($result_created);
        $chatsCreatedInPeriod = (int)$row['total_count'];
    }

    // ---------- SQL #3: Активные чаты за период (которые писали) ----------
    $sql_active_chats = "
        SELECT COUNT(DISTINCT chats.chat_id) AS total_count
        FROM telegram.chat_history
        JOIN telegram.chats ON chats.chat_id = chat_history.chat_id
        WHERE DATE(chat_history.created_at) BETWEEN $1 AND $2
          AND chats.service_kind = 5;
    ";

    $result_active = pg_query_params($link, $sql_active_chats, [$dateFrom, $dateTo]);
    if ($result_active) {
        $row = pg_fetch_assoc($result_active);
        $chatsActiveInPeriod = (int)$row['total_count'];
    }

    // ---------- SQL #4: Статусы о ходе рассмотрения ----------
    $sql_statuses = "
        SELECT COUNT(*) AS count
        FROM telegram.chat_history
        JOIN telegram.chats ON chats.chat_id = chat_history.chat_id
        WHERE DATE(chat_history.created_at) BETWEEN $1 AND $2
          AND chat_history.algorithm = 'case-status'
          AND chat_history.message ILIKE 'Номер дела:%'
          AND chats.service_kind = 5;
    ";

    $result_statuses = pg_query_params($link, $sql_statuses, [$dateFrom, $dateTo]);

    if ($result_statuses) {
        $row = pg_fetch_assoc($result_statuses);
        $statusesCount = (int)$row['count'];
    }

    // ---------- SQL #5: Уведомления о готовности результатов ----------
    $sql_notify = "
        SELECT COUNT(*) AS total_count
        FROM clients.notify_queue_jobs
        WHERE DATE(created_at) BETWEEN $1 AND $2
          AND template_name IN (
            'max_notify_close',                -- Закрытие
            'max_notify_odnomoment',           -- В один момент
            'max_notify_novidacha',            -- Невидача
            'max_notify_odnomoment_vashkontrol', -- В один момент (ваш контроль)
            'max_notify_close_vashkontrol',    -- Закрытие (ваш контроль)
            'max_notify_novidacha_vashkontrol'  -- Невидача (ваш контроль)
          );
    ";

    $result_notify = pg_query_params($link, $sql_notify, [$dateFrom, $dateTo]);

    if ($result_notify) {
        $row = pg_fetch_assoc($result_notify);
        $readyResultsCount = (int)$row['total_count'];
    }

    // ---------- SQL #6: Предварительная запись ----------
    $sql_prelim = "
        SELECT COUNT(*) AS count
        FROM telegram.chat_history
        JOIN telegram.chats ON chats.chat_id = chat_history.chat_id
        WHERE DATE(chat_history.created_at) BETWEEN $1 AND $2
          AND chat_history.algorithm = 'queue-record'
          AND chat_history.message ILIKE '%Запись подтверждена.%'
          AND chats.service_kind = 5;
    ";

    $result_prelim = pg_query_params($link, $sql_prelim, [$dateFrom, $dateTo]);

    if ($result_prelim) {
        $row = pg_fetch_assoc($result_prelim);
        $preliminaryRecords = (int)$row['count'];
    }

    pg_close($link);
}

// Определяем, что показывать как "Количество чатов в MAX"
// По умолчанию показываем активные чаты за период
$chatsCount = $chatsActiveInPeriod;

// Функция для форматирования даты в русский формат
function formatDateRu($date) {
    $months = [
        '01' => 'января', '02' => 'февраля', '03' => 'марта', '04' => 'апреля',
        '05' => 'мая', '06' => 'июня', '07' => 'июля', '08' => 'августа',
        '09' => 'сентября', '10' => 'октября', '11' => 'ноября', '12' => 'декабря'
    ];
    
    $dateObj = new DateTime($date);
    $day = $dateObj->format('d');
    $month = $months[$dateObj->format('m')];
    $year = $dateObj->format('y'); // последние две цифры года
    
    // Убираем ведущий ноль у дня
    $day = ltrim($day, '0');
    
    return "$day.$month.$year";
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Статистика МАХ</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            margin: 0;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        .header h1 {
            margin: 0;
            color: #2c3e50;
            font-size: 32px;
            font-weight: 700;
        }
        .header .subtitle {
            color: #7f8c8d;
            font-size: 16px;
            margin-top: 10px;
        }
        .date-form {
            background: #f8f9fa;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .date-input-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .date-input-group label {
            font-weight: 600;
            color: #495057;
            min-width: 40px;
        }
        input[type="date"] {
            padding: 12px 16px;
            font-size: 16px;
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            width: 200px;
            transition: all 0.3s;
        }
        input[type="date"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        button {
            padding: 14px 32px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        button:active {
            transform: translateY(0);
        }
        .error {
            background: #ffe5e5;
            color: #c0392b;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 5px solid #c0392b;
        }
        .period-info {
            text-align: center;
            margin-bottom: 40px;
            padding: 25px;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border-radius: 15px;
            font-size: 20px;
            font-weight: 600;
        }
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(600px, 1fr));
            gap: 25px;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border: 1px solid #eef1f5;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.12);
        }
        .stat-label {
            font-size: 18px;
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 15px;
            line-height: 1.4;
        }
        .stat-value {
            font-size: 48px;
            font-weight: 800;
            color: #2c3e50;
            margin: 15px 0;
            padding: 15px 20px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 2px solid #e1e8ed;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Courier New', monospace;
            text-align: center;
            user-select: all;
        }
        .stat-value:hover {
            background: #e9ecef;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .stat-value:active {
            background: #dee2e6;
        }
        .stat-value.copied {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        .stat-description {
            color: #7f8c8d;
            font-size: 14px;
            margin-top: 10px;
            line-height: 1.5;
        }
        .copy-hint {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #6c757d;
            font-size: 12px;
            margin-top: 10px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .stat-card:hover .copy-hint {
            opacity: 1;
        }
        .no-data {
            text-align: center;
            padding: 20px;
            color: #7f8c8d;
            font-style: italic;
            font-size: 16px;
            background: #f8f9fa;
            border-radius: 8px;
            margin: 10px 0;
        }
        .stat-icon {
            display: inline-block;
            width: 50px;
            height: 50px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .icon-chat { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; }
        .icon-status { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white; }
        .icon-notify { background: linear-gradient(135deg, #30cfd0 0%, #330867 100%); color: white; }
        .icon-record { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #2c3e50; }
        
        .footer {
            text-align: center;
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .chat-details {
            margin-top: 20px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            font-size: 14px;
        }
        .chat-details h4 {
            margin: 0 0 10px 0;
            color: #495057;
        }
        .chat-details ul {
            margin: 0;
            padding-left: 20px;
            color: #6c757d;
        }
        .chat-details li {
            margin-bottom: 5px;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            .stats-container {
                grid-template-columns: 1fr;
            }
            .stat-card {
                padding: 20px;
            }
            .date-form {
                flex-direction: column;
                align-items: stretch;
            }
            .date-input-group {
                width: 100%;
            }
            input[type="date"] {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>📊 Статистика МАХ</h1>
        <div class="subtitle">Отчет по активности в системе МАХ за выбранный период</div>
    </div>
    
    <form method="get" class="date-form">
        <div class="date-input-group">
            <label for="date_from">С:</label>
            <input type="date" id="date_from" name="date_from"
                   value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        
        <div class="date-input-group">
            <label for="date_to">По:</label>
            <input type="date" id="date_to" name="date_to"
                   value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        
        <button type="submit">
            <span>📈</span> Сформировать отчет
        </button>
    </form>

    <?php if ($errors): ?>
        <div class="error">
            <?php foreach ($errors as $err): ?>
                <div>❌ <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <?php if ($chatsTotal !== null || $statusesCount !== null || $readyResultsCount !== null || $preliminaryRecords !== null): ?>
            
            <div class="period-info">
                Сформировано с <?= formatDateRu($dateFrom) ?> по <?= formatDateRu($dateTo) ?>
            </div>
            
            <div class="stats-container">
                <!-- Статистика 1: Количество чатов в MAX -->
                <div class="stat-card">
                    <div class="stat-icon icon-chat">💬</div>
                    <div class="stat-label">
                        Количество чатов в MAX с <?= formatDateRu($dateFrom) ?> по <?= formatDateRu($dateTo) ?>:
                    </div>
                    <?php if ($chatsCount !== null): ?>
                        <div class="stat-value" onclick="copyToClipboard(this, <?= $chatsCount ?>)">
                            <?= number_format($chatsCount, 0, ',', ' ') ?>
                        </div>
                        <div class="copy-hint">
                            <span>📋</span> Нажмите на цифру, чтобы скопировать
                        </div>
                        <div class="stat-description">
                            Активные чаты, которые отправляли сообщения за выбранный период
                        </div>
                        
                        <div class="chat-details">
                            <h4>📊 Детализация по чатам:</h4>
                            <ul>
                                <li>Всего чатов МАХ в системе: <strong><?= number_format($chatsTotal, 0, ',', ' ') ?></strong></li>
                                <li>Создано за период: <strong><?= number_format($chatsCreatedInPeriod, 0, ',', ' ') ?></strong></li>
                                <li>Активных за период (показано выше): <strong><?= number_format($chatsActiveInPeriod, 0, ',', ' ') ?></strong></li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            Данные недоступны
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Статистика 2: Статусы рассмотрения -->
                <div class="stat-card">
                    <div class="stat-icon icon-status">📋</div>
                    <div class="stat-label">
                        Количество предоставленных чат-ботом статусов о ходе рассмотрения запросов с <?= formatDateRu($dateFrom) ?> по <?= formatDateRu($dateTo) ?>:
                    </div>
                    <?php if ($statusesCount !== null): ?>
                        <div class="stat-value" onclick="copyToClipboard(this, <?= $statusesCount ?>)">
                            <?= number_format($statusesCount, 0, ',', ' ') ?>
                        </div>
                        <div class="copy-hint">
                            <span>📋</span> Нажмите на цифру, чтобы скопировать
                        </div>
                        <div class="stat-description">
                            Статусы о ходе рассмотрения дел, предоставленные чат-ботом
                        </div>
                    <?php else: ?>
                        <div class="no-data">Данные недоступны</div>
                    <?php endif; ?>
                </div>
                
                <!-- Статистика 3: Уведомления о готовности -->
                <div class="stat-card">
                    <div class="stat-icon icon-notify">🔔</div>
                    <div class="stat-label">
                        Количество отправленных уведомлений только о готовности результатов дел, в МАХ с <?= formatDateRu($dateFrom) ?> по <?= formatDateRu($dateTo) ?>:
                    </div>
                    <?php if ($readyResultsCount !== null): ?>
                        <div class="stat-value" onclick="copyToClipboard(this, <?= $readyResultsCount ?>)">
                            <?= number_format($readyResultsCount, 0, ',', ' ') ?>
                        </div>
                        <div class="copy-hint">
                            <span>📋</span> Нажмите на цифру, чтобы скопировать
                        </div>
                        <div class="stat-description">
                            Уведомления о готовности результатов дел (6 шаблонов):
                            <div style="margin-top: 10px; font-size: 12px; color: #6c757d;">
                                • max_notify_close<br>
                                • max_notify_odnomoment<br>
                                • max_notify_novidacha<br>
                                • max_notify_close_vashkontrol<br>
                                • max_notify_odnomoment_vashkontrol<br>
                                • max_notify_novidacha_vashkontrol
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-data">Данные недоступны</div>
                    <?php endif; ?>
                </div>
                
                <!-- Статистика 4: Предварительная запись -->
                <div class="stat-card">
                    <div class="stat-icon icon-record">📅</div>
                    <div class="stat-label">
                        Количество предварительной записи в MAX с <?= formatDateRu($dateFrom) ?> по <?= formatDateRu($dateTo) ?>:
                    </div>
                    <?php if ($preliminaryRecords !== null): ?>
                        <div class="stat-value" onclick="copyToClipboard(this, <?= $preliminaryRecords ?>)">
                            <?= number_format($preliminaryRecords, 0, ',', ' ') ?>
                        </div>
                        <div class="copy-hint">
                            <span>📋</span> Нажмите на цифру, чтобы скопировать
                        </div>
                        <div class="stat-description">
                            Подтвержденные предварительные записи через систему МАХ
                        </div>
                    <?php else: ?>
                        <div class="no-data">Данные недоступны</div>
                    <?php endif; ?>
                </div>
            </div>
            
        <?php else: ?>
            <div class="no-data">
                📭 Нет данных за выбранный период<br>
                Попробуйте выбрать другой период времени
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <div class="footer">
        Система статистики МАХ • <?= date('d.m.Y H:i') ?> • 
        <a href="otchet_max.php" style="color: #667eea; text-decoration: none;">← Вернуться к старому отчету</a>
    </div>
</div>

<script>
// Функция для копирования в буфер обмена
function copyToClipboard(element, value) {
    // Создаем временный элемент для копирования
    const tempInput = document.createElement('input');
    tempInput.value = value.toString().replace(/\s/g, '');
    document.body.appendChild(tempInput);
    tempInput.select();
    tempInput.setSelectionRange(0, 99999);
    
    try {
        const successful = document.execCommand('copy');
        if (successful) {
            // Визуальная обратная связь
            element.classList.add('copied');
            element.innerHTML = 'Скопировано! ✓';
            
            setTimeout(() => {
                element.classList.remove('copied');
                element.innerHTML = formatNumber(value);
            }, 1500);
            
            // Уведомление
            showNotification('Число скопировано в буфер обмена');
        }
    } catch (err) {
        console.error('Ошибка при копировании:', err);
        showNotification('Ошибка при копировании', 'error');
    }
    
    document.body.removeChild(tempInput);
}

// Функция для форматирования чисел с пробелами
function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

// Функция показа уведомлений
function showNotification(message, type = 'success') {
    // Создаем элемент уведомления
    const notification = document.createElement('div');
    notification.textContent = message;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 25px;
        background: ${type === 'error' ? '#f8d7da' : '#d4edda'};
        color: ${type === 'error' ? '#721c24' : '#155724'};
        border: 1px solid ${type === 'error' ? '#f5c6cb' : '#c3e6cb'};
        border-radius: 10px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        z-index: 1000;
        font-weight: 500;
        animation: slideIn 0.3s ease;
    `;
    
    document.body.appendChild(notification);
    
    // Удаляем уведомление через 3 секунды
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => {
            document.body.removeChild(notification);
        }, 300);
    }, 3000);
}

// Анимации для уведомлений
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Автоматический фокус на первом поле даты
document.getElementById('date_from')?.focus();
</script>
</body>
</html>