<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final class TimeTracker
{
    public const TIMEZONE = 'Europe/Kyiv';

    private DateTimeZone $timezone;

    public function __construct(private readonly PDO $pdo, private readonly Clock $clock)
    {
        $this->timezone = new DateTimeZone(self::TIMEZONE);
    }

    public static function connect(string $path, ?Clock $clock = null): self
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException(
                'Нужно расширение PHP pdo_sqlite. Установите пакет php-sqlite3.'
            );
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог для базы: ' . $directory);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        self::migrate($pdo);

        return new self($pdo, $clock ?? new SystemClock());
    }

    /**
     * @return array{id: int, name: string, created_at: string}
     */
    public function addEmployee(string $name): array
    {
        $name = $this->cleanText($name);
        if ($name === '') {
            throw new InvalidArgumentException('Укажите имя сотрудника');
        }
        if ($this->length($name) > 80) {
            throw new InvalidArgumentException('Имя не длиннее 80 символов');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO employees (name, created_at) VALUES (:name, :created_at)'
        );
        $stmt->execute([
            'name' => $name,
            'created_at' => $this->clock->now(),
        ]);

        return $this->employeeById((int) $this->pdo->lastInsertId());
    }

    /**
     * @return list<array{id: int, name: string, created_at: string}>
     */
    public function listEmployees(): array
    {
        $employees = [];
        foreach ($this->employeeRows() as $row) {
            $employees[] = $this->presentEmployee($row);
        }

        return $employees;
    }

    /**
     * @return array{
     *     id: int,
     *     employee_id: int,
     *     employee_name: string,
     *     description: string,
     *     started_at: string,
     *     stopped_at: ?string,
     *     duration_seconds: int,
     *     running: bool
     * }
     */
    public function startTimer(int $employeeId, string $description): array
    {
        $this->requireEmployee($employeeId);
        $description = $this->cleanText($description);
        if ($description === '') {
            throw new InvalidArgumentException('Опишите задачу');
        }
        if ($this->length($description) > 300) {
            throw new InvalidArgumentException('Описание не длиннее 300 символов');
        }
        if ($this->activeRow($employeeId) !== null) {
            throw new ConflictException('Таймер этого сотрудника уже запущен. Сначала остановите его.');
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO time_entries (employee_id, description, started_at, stopped_at)
                 VALUES (:employee_id, :description, :started_at, NULL)'
            );
            $stmt->execute([
                'employee_id' => $employeeId,
                'description' => $description,
                'started_at' => $this->clock->now(),
            ]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw new ConflictException(
                    'Таймер этого сотрудника уже запущен. Сначала остановите его.',
                    0,
                    $e
                );
            }
            throw $e;
        }

        return $this->entryById((int) $this->pdo->lastInsertId());
    }

    /**
     * @return array{
     *     id: int,
     *     employee_id: int,
     *     employee_name: string,
     *     description: string,
     *     started_at: string,
     *     stopped_at: ?string,
     *     duration_seconds: int,
     *     running: bool
     * }
     */
    public function stopTimer(int $employeeId): array
    {
        $this->requireEmployee($employeeId);
        $active = $this->activeRow($employeeId);
        if ($active === null) {
            throw new ConflictException('У сотрудника нет запущенного таймера');
        }

        $now = $this->clock->now();
        if ($now < (int) $active['started_at']) {
            throw new ConflictException('Время остановки раньше времени старта');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE time_entries
             SET stopped_at = :stopped_at
             WHERE id = :id AND stopped_at IS NULL'
        );
        $stmt->execute([
            'stopped_at' => $now,
            'id' => $active['id'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new ConflictException('У сотрудника нет запущенного таймера');
        }

        return $this->entryById((int) $active['id']);
    }

    /**
     * @return list<array{
     *     id: int,
     *     employee_id: int,
     *     employee_name: string,
     *     description: string,
     *     started_at: string,
     *     stopped_at: ?string,
     *     duration_seconds: int,
     *     running: bool
     * }>
     */
    public function activeTimers(): array
    {
        $stmt = $this->pdo->query(
            'SELECT te.id, te.employee_id, te.description, te.started_at, te.stopped_at,
                    emp.name AS employee_name
             FROM time_entries te
             JOIN employees emp ON emp.id = te.employee_id
             WHERE te.stopped_at IS NULL
             ORDER BY te.started_at, te.id'
        );
        if ($stmt === false) {
            return [];
        }

        $timers = [];
        foreach ($stmt->fetchAll() as $row) {
            $timers[] = $this->presentEntry($row);
        }

        return $timers;
    }

    /**
     * Report duration is the overlap of an entry with the half-open interval
     * [start of the first selected day, start of the day after the end date).
     *
     * @return array{
     *     from: string,
     *     to: string,
     *     entries: list<array<string, mixed>>,
     *     totals: list<array{employee_id: int, employee_name: string, total_seconds: int, entry_count: int}>
     * }
     */
    public function report(string $from, string $to): array
    {
        $periodStart = $this->parseDate($from);
        $periodTo = $this->parseDate($to);
        if ($periodTo < $periodStart) {
            throw new InvalidArgumentException('Дата «по» раньше даты «с»');
        }

        $startTs = $periodStart->getTimestamp();
        $endTs = $periodTo->modify('+1 day')->getTimestamp();
        $now = $this->clock->now();

        $totals = [];
        foreach ($this->employeeRows() as $employee) {
            $totals[(int) $employee['id']] = [
                'employee_id' => (int) $employee['id'],
                'employee_name' => (string) $employee['name'],
                'total_seconds' => 0,
                'entry_count' => 0,
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT te.id, te.employee_id, te.description, te.started_at, te.stopped_at,
                    emp.name AS employee_name
             FROM time_entries te
             JOIN employees emp ON emp.id = te.employee_id
             WHERE te.started_at < :period_end
               AND (te.stopped_at IS NULL OR te.stopped_at >= :period_start)
             ORDER BY te.started_at DESC, te.id DESC'
        );
        $stmt->execute([
            'period_end' => $endTs,
            'period_start' => $startTs,
        ]);

        $entries = [];
        foreach ($stmt->fetchAll() as $row) {
            $started = (int) $row['started_at'];
            $ended = $row['stopped_at'] === null ? $now : (int) $row['stopped_at'];
            $seconds = $this->overlapSeconds($started, $ended, $startTs, $endTs);
            if ($seconds === null) {
                continue;
            }

            $entries[] = $this->presentEntry($row, $seconds);
            $employeeId = (int) $row['employee_id'];
            if (!isset($totals[$employeeId])) {
                continue;
            }
            $totals[$employeeId]['total_seconds'] += $seconds;
            $totals[$employeeId]['entry_count'] += 1;
        }

        return [
            'from' => $periodStart->format('Y-m-d'),
            'to' => $periodTo->format('Y-m-d'),
            'entries' => $entries,
            'totals' => array_values($totals),
        ];
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS employees (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS time_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL REFERENCES employees(id),
                description TEXT NOT NULL,
                started_at INTEGER NOT NULL,
                stopped_at INTEGER
            )'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_entries_started ON time_entries (started_at)'
        );
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_one_active_timer
             ON time_entries (employee_id)
             WHERE stopped_at IS NULL'
        );
    }

    private function requireEmployee(int $employeeId): void
    {
        if ($employeeId <= 0 || $this->findEmployee($employeeId) === null) {
            throw new InvalidArgumentException('Сотрудник не найден');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEmployee(int $employeeId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, created_at FROM employees WHERE id = :id'
        );
        $stmt->execute(['id' => $employeeId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array{id: int, name: string, created_at: string}
     */
    private function employeeById(int $id): array
    {
        $row = $this->findEmployee($id);
        if ($row === null) {
            throw new RuntimeException('Сотрудник не найден');
        }

        return $this->presentEmployee($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, created_at: string}
     */
    private function presentEmployee(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'created_at' => $this->iso((int) $row['created_at']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employeeRows(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, created_at FROM employees ORDER BY name, id'
        );
        if ($stmt === false) {
            return [];
        }

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeRow(int $employeeId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, employee_id, description, started_at, stopped_at
             FROM time_entries
             WHERE employee_id = :employee_id AND stopped_at IS NULL'
        );
        $stmt->execute(['employee_id' => $employeeId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array{
     *     id: int,
     *     employee_id: int,
     *     employee_name: string,
     *     description: string,
     *     started_at: string,
     *     stopped_at: ?string,
     *     duration_seconds: int,
     *     running: bool
     * }
     */
    private function entryById(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT te.id, te.employee_id, te.description, te.started_at, te.stopped_at,
                    emp.name AS employee_name
             FROM time_entries te
             JOIN employees emp ON emp.id = te.employee_id
             WHERE te.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Запись не найдена');
        }

        return $this->presentEntry($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     id: int,
     *     employee_id: int,
     *     employee_name: string,
     *     description: string,
     *     started_at: string,
     *     stopped_at: ?string,
     *     duration_seconds: int,
     *     running: bool
     * }
     */
    private function presentEntry(array $row, ?int $duration = null): array
    {
        $started = (int) $row['started_at'];
        $stopped = $row['stopped_at'] === null ? null : (int) $row['stopped_at'];
        if ($duration === null) {
            $duration = max(0, ($stopped ?? $this->clock->now()) - $started);
        }

        return [
            'id' => (int) $row['id'],
            'employee_id' => (int) $row['employee_id'],
            'employee_name' => (string) $row['employee_name'],
            'description' => (string) $row['description'],
            'started_at' => $this->iso($started),
            'stopped_at' => $stopped === null ? null : $this->iso($stopped),
            'duration_seconds' => $duration,
            'running' => $stopped === null,
        ];
    }

    private function overlapSeconds(int $started, int $ended, int $periodStart, int $periodEnd): ?int
    {
        if ($started === $ended && $started >= $periodStart && $started < $periodEnd) {
            return 0;
        }

        $from = max($started, $periodStart);
        $to = min($ended, $periodEnd);
        if ($to <= $from) {
            return null;
        }

        return $to - $from;
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        $hasErrors = $errors !== false
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

        if (!$date || $hasErrors || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Некорректная дата: ' . $value);
        }

        return $date;
    }

    private function iso(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone($this->timezone)
            ->format('c');
    }

    private function cleanText(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        return $collapsed ?? '';
    }

    private function length(string $value): int
    {
        return mb_strlen($value);
    }
}
