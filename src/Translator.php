<?php

declare(strict_types=1);

namespace App;

final class Translator
{
    private const LINES = [
        'ru' => [
            'pdo_sqlite_required' => 'Нужно расширение PHP pdo_sqlite. Установите пакет php-sqlite3.',
            'data_directory' => 'Не удалось создать каталог для базы: ',
            'employee_name_required' => 'Укажите имя сотрудника',
            'employee_name_too_long' => 'Имя не длиннее 80 символов',
            'task_required' => 'Опишите задачу',
            'task_too_long' => 'Описание не длиннее 300 символов',
            'timer_already_running' => 'Таймер этого сотрудника уже запущен. Сначала остановите его.',
            'timer_not_running' => 'У сотрудника нет запущенного таймера',
            'stop_before_start' => 'Время остановки раньше времени старта',
            'period_reversed' => 'Дата «по» раньше даты «с»',
            'employee_not_found' => 'Сотрудник не найден',
            'entry_not_found' => 'Запись не найдена',
            'invalid_date' => 'Некорректная дата: ',
            'method_not_allowed' => 'Метод не поддерживается',
            'employee_not_selected' => 'Не выбран сотрудник',
            'unknown_action' => 'Неизвестное действие',
            'dates_required' => 'Укажите даты начала и конца периода',
            'database_error' => 'Ошибка базы данных',
            'internal_error' => 'Внутренняя ошибка',
            'expected_json' => 'Ожидался JSON-объект',
        ],
        'en' => [
            'pdo_sqlite_required' => 'The PHP pdo_sqlite extension is required. Install the php-sqlite3 package.',
            'data_directory' => 'Could not create the database directory: ',
            'employee_name_required' => 'Enter the employee name',
            'employee_name_too_long' => 'The name must be 80 characters or fewer',
            'task_required' => 'Describe the task',
            'task_too_long' => 'The description must be 300 characters or fewer',
            'timer_already_running' => 'This employee already has a running timer. Stop it first.',
            'timer_not_running' => 'This employee has no running timer',
            'stop_before_start' => 'The stop time is earlier than the start time',
            'period_reversed' => 'The end date is earlier than the start date',
            'employee_not_found' => 'Employee not found',
            'entry_not_found' => 'Entry not found',
            'invalid_date' => 'Invalid date: ',
            'method_not_allowed' => 'Method not allowed',
            'employee_not_selected' => 'No employee selected',
            'unknown_action' => 'Unknown action',
            'dates_required' => 'Choose the start and end dates',
            'database_error' => 'Database error',
            'internal_error' => 'Internal error',
            'expected_json' => 'A JSON object was expected',
        ],
    ];

    public static function locale(): string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'ru';
        $first = strtolower(trim(explode(',', $header)[0]));

        return str_starts_with($first, 'en') ? 'en' : 'ru';
    }

    public static function line(string $key): string
    {
        $name = $key;
        $value = null;
        $split = strpos($key, ':');
        if ($split !== false) {
            $name = substr($key, 0, $split);
            $value = substr($key, $split + 1);
        }

        $template = self::LINES[self::locale()][$name] ?? self::LINES['ru'][$name] ?? null;
        if ($template === null) {
            return $key;
        }

        return $value === null ? $template : $template . $value;
    }
}
