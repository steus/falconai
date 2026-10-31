<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Clock;
use App\ConflictException;
use App\TimeTracker;

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Нужно расширение pdo_sqlite.\n");
    fwrite(STDERR, "Ubuntu/Debian: sudo apt install php-sqlite3\n");
    exit(1);
}

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "ok   {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL {$name}\n     {$e->getMessage()}\n";
    }
}

function assertSame(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        $want = json_encode($expected, JSON_UNESCAPED_UNICODE);
        $got = json_encode($actual, JSON_UNESCAPED_UNICODE);
        throw new RuntimeException($what . ': ожидалось ' . $want . ', получено ' . $got);
    }
}

function assertTrue(bool $value, string $what): void
{
    if (!$value) {
        throw new RuntimeException($what);
    }
}

function assertThrows(string $class, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new RuntimeException('Ожидалось ' . $class . ', получено ' . $e::class . ': ' . $e->getMessage());
    }
    throw new RuntimeException('Ожидалось исключение ' . $class);
}

final class FrozenClock implements Clock
{
    public function __construct(private int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function set(int $now): void
    {
        $this->now = $now;
    }
}

function kyiv(string $local): int
{
    return (new DateTimeImmutable($local, new DateTimeZone(TimeTracker::TIMEZONE)))->getTimestamp();
}

/**
 * @return array{0: TimeTracker, 1: string}
 */
function openTracker(FrozenClock $clock): array
{
    $dir = sys_get_temp_dir() . '/tabel-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать временный каталог');
    }

    $path = $dir . '/timetracker.sqlite';

    return [TimeTracker::connect($path, $clock), $path];
}

test('добавление сотрудника убирает лишние пробелы', function () {
    $clock = new FrozenClock(kyiv('2026-10-31 10:00:00'));
    [$app] = openTracker($clock);
    $employee = $app->addEmployee("  Марина   Лебедева\n");
    assertSame('Марина Лебедева', $employee['name'], 'имя');
    assertTrue(str_starts_with($employee['created_at'], '2026-10-31T10:00:00'), $employee['created_at']);
    assertSame(1, count($app->listEmployees()), 'список');
});

test('пустое имя отклоняется', function () {
    [$app] = openTracker(new FrozenClock(kyiv('2026-10-31 10:00:00')));
    assertThrows(InvalidArgumentException::class, fn () => $app->addEmployee('   '));
});

test('повторный запуск при открытом таймере отклоняется', function () {
    $clock = new FrozenClock(kyiv('2026-10-31 10:00:00'));
    [$app] = openTracker($clock);
    $id = $app->addEmployee('Марина Лебедева')['id'];
    $app->startTimer($id, 'Вёрстка отчёта');
    assertThrows(ConflictException::class, fn () => $app->startTimer($id, 'Другая задача'));
    assertSame(1, count($app->activeTimers()), 'число таймеров');
});

test('повторный запуск после остановки создаёт новую запись', function () {
    $clock = new FrozenClock(kyiv('2026-10-31 10:00:00'));
    [$app] = openTracker($clock);
    $id = $app->addEmployee('Игорь Самойлов')['id'];
    $first = $app->startTimer($id, 'Сборка стенда');
    $clock->set(kyiv('2026-10-31 10:25:00'));
    $stopped = $app->stopTimer($id);
    assertSame(false, $stopped['running'], 'после стопа');
    assertSame(25 * 60, $stopped['duration_seconds'], 'длительность');
    $clock->set(kyiv('2026-10-31 10:30:00'));
    $second = $app->startTimer($id, 'Правки стенда');
    assertTrue($second['id'] !== $first['id'], 'новый id');
    assertSame(true, $second['running'], 'снова идёт');
    $clock->set(kyiv('2026-10-31 10:45:00'));
    $app->stopTimer($id);
    $report = $app->report('2026-10-31', '2026-10-31');
    assertSame(2, $report['totals'][0]['entry_count'], 'две записи');
    assertSame(40 * 60, $report['totals'][0]['total_seconds'], 'сумма');
});

test('два сотрудника могут вести таймер одновременно', function () {
    $clock = new FrozenClock(kyiv('2026-10-31 11:00:00'));
    [$app] = openTracker($clock);
    $marina = $app->addEmployee('Марина Лебедева')['id'];
    $igor = $app->addEmployee('Игорь Самойлов')['id'];
    $app->startTimer($marina, 'Макет');
    $app->startTimer($igor, 'Тексты');
    assertSame(2, count($app->activeTimers()), 'два таймера');
});

test('остановка без таймера отклоняется', function () {
    [$app] = openTracker(new FrozenClock(kyiv('2026-10-31 11:00:00')));
    $id = $app->addEmployee('Олена Грищук')['id'];
    assertThrows(ConflictException::class, fn () => $app->stopTimer($id));
});

test('пустой отчёт без сотрудников', function () {
    [$app] = openTracker(new FrozenClock(kyiv('2026-10-31 11:00:00')));
    $report = $app->report('2026-10-01', '2026-10-31');
    assertSame([], $report['entries'], 'записи');
    assertSame([], $report['totals'], 'итоги');
});

test('пустой отчёт за период без записей оставляет нули', function () {
    [$app] = openTracker(new FrozenClock(kyiv('2026-10-31 11:00:00')));
    $app->addEmployee('Марина Лебедева');
    $app->addEmployee('Игорь Самойлов');
    $report = $app->report('2026-01-01', '2026-01-31');
    assertSame([], $report['entries'], 'записи');
    assertSame(2, count($report['totals']), 'оба сотрудника');
    foreach ($report['totals'] as $row) {
        assertSame(0, $row['total_seconds'], $row['employee_name']);
        assertSame(0, $row['entry_count'], $row['employee_name']);
    }
});

test('запись в 00:10 попадает в тот же день', function () {
    $clock = new FrozenClock(kyiv('2026-10-31 00:10:00'));
    [$app] = openTracker($clock);
    $id = $app->addEmployee('Марина Лебедева')['id'];
    $app->startTimer($id, 'Ночная правка');
    $clock->set(kyiv('2026-10-31 00:40:00'));
    $app->stopTimer($id);
    $same = $app->report('2026-10-31', '2026-10-31');
    assertSame(30 * 60, $same['totals'][0]['total_seconds'], 'тот же день');
    assertTrue(str_starts_with($same['entries'][0]['started_at'], '2026-10-31T00:10:00'), 'начало');
    $previous = $app->report('2026-10-30', '2026-10-30');
    assertSame([], $previous['entries'], 'предыдущий день');
    assertSame(0, $previous['totals'][0]['total_seconds'], 'ноль');
});

test('запись через полночь делится между днями', function () {
    $clock = new FrozenClock(kyiv('2026-10-31 23:00:00'));
    [$app] = openTracker($clock);
    $id = $app->addEmployee('Марина Лебедева')['id'];
    $app->startTimer($id, 'Дежурство');
    $clock->set(kyiv('2026-11-01 01:00:00'));
    $app->stopTimer($id);
    $first = $app->report('2026-10-31', '2026-10-31');
    $second = $app->report('2026-11-01', '2026-11-01');
    $both = $app->report('2026-10-31', '2026-11-01');
    assertSame(3600, $first['totals'][0]['total_seconds'], '31 октября');
    assertSame(3600, $second['totals'][0]['total_seconds'], '1 ноября');
    assertSame(7200, $both['totals'][0]['total_seconds'], 'оба дня');
    assertSame(1, count($both['entries']), 'одна запись');
});

test('открытый таймер в отчёте не выходит за границы периода', function () {
    $clock = new FrozenClock(kyiv('2026-10-30 22:00:00'));
    [$app] = openTracker($clock);
    $id = $app->addEmployee('Олена Грищук')['id'];
    $app->startTimer($id, 'Выкладка');
    $clock->set(kyiv('2026-10-31 02:00:00'));
    $yesterday = $app->report('2026-10-30', '2026-10-30');
    $today = $app->report('2026-10-31', '2026-10-31');
    assertSame(7200, $yesterday['totals'][0]['total_seconds'], 'вчера до полуночи');
    assertSame(true, $yesterday['entries'][0]['running'], 'ещё идёт');
    assertSame(7200, $today['totals'][0]['total_seconds'], 'сегодня с полуночи');
});

test('остановка ровно в полночь не попадает в следующий день', function () {
    $start = kyiv('2026-03-29 00:00:00');
    $end = kyiv('2026-03-30 00:00:00');
    $clock = new FrozenClock($start);
    [$app] = openTracker($clock);
    $id = $app->addEmployee('Игорь Самойлов')['id'];
    $app->startTimer($id, 'Сутки');
    $clock->set($end);
    $app->stopTimer($id);
    $day = $app->report('2026-03-29', '2026-03-29');
    $next = $app->report('2026-03-30', '2026-03-30');
    assertSame($end - $start, $day['totals'][0]['total_seconds'], 'фактические секунды суток');
    assertSame([], $next['entries'], 'следующий день пуст');
    assertSame(0, $next['totals'][0]['total_seconds'], 'ноль');
});

test('будущий период не получает время открытого таймера', function () {
    $clock = new FrozenClock(kyiv('2026-10-31 15:00:00'));
    [$app] = openTracker($clock);
    $id = $app->addEmployee('Марина Лебедева')['id'];
    $app->startTimer($id, 'Сейчас');
    $report = $app->report('2026-11-02', '2026-11-02');
    assertSame([], $report['entries'], 'записи');
    assertSame(0, $report['totals'][0]['total_seconds'], 'ноль');
});

test('запись нулевой длины внутри дня попадает в отчёт', function () {
    $clock = new FrozenClock(kyiv('2026-10-31 15:00:00'));
    [$app] = openTracker($clock);
    $id = $app->addEmployee('Олена Грищук')['id'];
    $app->startTimer($id, 'Мгновение');
    $app->stopTimer($id);
    $report = $app->report('2026-10-31', '2026-10-31');
    assertSame(1, count($report['entries']), 'запись есть');
    assertSame(0, $report['totals'][0]['total_seconds'], 'ноль секунд');
});

test('данные остаются после нового подключения к базе', function () {
    $clock = new FrozenClock(kyiv('2026-10-31 12:00:00'));
    [$app, $path] = openTracker($clock);
    $id = $app->addEmployee('Игорь Самойлов')['id'];
    $app->startTimer($id, 'Сборка стенда');
    $clock->set(kyiv('2026-10-31 12:00:15'));
    $again = TimeTracker::connect($path, $clock);
    $timers = $again->activeTimers();
    assertSame(1, count($again->listEmployees()), 'сотрудник');
    assertSame('Игорь Самойлов', $again->listEmployees()[0]['name'], 'имя');
    assertSame(1, count($timers), 'таймер');
    assertSame('Сборка стенда', $timers[0]['description'], 'задача');
    assertSame(15, $timers[0]['duration_seconds'], 'прошло 15 секунд');
});

test('31 февраля и перевёрнутый период отклоняются', function () {
    [$app] = openTracker(new FrozenClock(kyiv('2026-10-31 12:00:00')));
    assertThrows(InvalidArgumentException::class, fn () => $app->report('2026-02-31', '2026-02-31'));
    assertThrows(InvalidArgumentException::class, fn () => $app->report('2026-10-31', '2026-10-01'));
});

test('пустая задача и неизвестный сотрудник отклоняются', function () {
    [$app] = openTracker(new FrozenClock(kyiv('2026-10-31 12:00:00')));
    $id = $app->addEmployee('Марина Лебедева')['id'];
    assertThrows(InvalidArgumentException::class, fn () => $app->startTimer($id, '  '));
    assertThrows(InvalidArgumentException::class, fn () => $app->startTimer(99, 'Задача'));
});

test('в базе не может быть двух открытых таймеров одного сотрудника', function () {
    $clock = new FrozenClock(kyiv('2026-10-31 12:00:00'));
    [$app, $path] = openTracker($clock);
    $id = $app->addEmployee('Марина Лебедева')['id'];
    $app->startTimer($id, 'Первая');
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $failed = false;
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO time_entries (employee_id, description, started_at, stopped_at)
             VALUES (:employee_id, :description, :started_at, NULL)'
        );
        $stmt->execute([
            'employee_id' => $id,
            'description' => 'Вторая',
            'started_at' => $clock->now(),
        ]);
    } catch (PDOException $e) {
        $failed = str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
    assertTrue($failed, 'уникальный индекс не сработал');
});

test('HTTP: пустой отчёт, повторный запуск и сохранение после перезапуска', function () {
    $dir = sys_get_temp_dir() . '/tabel-http-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать временный каталог');
    }
    $db = $dir . '/app.sqlite';
    $log = $dir . '/server.log';
    $port = freePort();
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['TIMETRACKER_DB'] = $db;
    $proc = null;
    try {
        $proc = startServer($port, $log, $env);
        $base = 'http://127.0.0.1:' . $port;

        [$status, $page] = httpRequest('GET', $base . '/');
        assertSame(200, $status, 'главная');
        assertTrue(is_string($page) && str_contains($page, 'Табель'), 'разметка');

        [$status, $css] = httpRequest('GET', $base . '/css/app.css');
        assertSame(200, $status, 'css');
        assertTrue(is_string($css) && str_contains($css, '.clock'), 'стили');

        [$status, $script] = httpRequest('GET', $base . '/js/app.js');
        assertSame(200, $status, 'js');
        assertTrue(is_string($script) && str_contains($script, '/api/timer.php'), 'скрипт');

        [$status, $empty] = httpRequest('GET', $base . '/api/report.php?from=1999-01-01&to=1999-01-31');
        assertSame(200, $status, 'пустой отчёт');
        assertSame([], $empty['entries'], 'нет записей');
        assertSame([], $empty['totals'], 'нет итогов');

        [$status, $created] = httpRequest('POST', $base . '/api/employees.php', ['name' => 'Олена Грищук']);
        assertSame(201, $status, 'создание');
        $id = $created['employee']['id'];

        [$status, $stillEmpty] = httpRequest('GET', $base . '/api/report.php?from=1999-01-01&to=1999-01-31');
        assertSame(200, $status, 'пустой период');
        assertSame([], $stillEmpty['entries'], 'записей нет');
        assertSame(0, $stillEmpty['totals'][0]['total_seconds'], 'ноль');

        [$status, $started] = httpRequest('POST', $base . '/api/timer.php', [
            'action' => 'start',
            'employee_id' => $id,
            'description' => 'Проверка API',
        ]);
        assertSame(201, $status, 'старт');
        $firstId = $started['entry']['id'];

        [$status, $again] = httpRequest('POST', $base . '/api/timer.php', [
            'action' => 'start',
            'employee_id' => $id,
            'description' => 'Ещё раз',
        ]);
        assertSame(409, $status, 'повторный запуск');
        assertTrue(isset($again['error']) && str_contains($again['error'], 'уже запущен'), 'текст ошибки');

        [$status] = httpRequest('POST', $base . '/api/timer.php', [
            'action' => 'start',
            'employee_id' => $id,
            'description' => '   ',
        ]);
        assertSame(422, $status, 'пустая задача');

        [$status] = httpRequest('POST', $base . '/api/timer.php', [
            'action' => 'stop',
            'employee_id' => $id,
        ]);
        assertSame(200, $status, 'стоп');

        [$status, $restarted] = httpRequest('POST', $base . '/api/timer.php', [
            'action' => 'start',
            'employee_id' => $id,
            'description' => 'После остановки',
        ]);
        assertSame(201, $status, 'повторный старт');
        assertTrue($restarted['entry']['id'] !== $firstId, 'другая запись');

        stopServer($proc);
        $proc = startServer($port, $log, $env);

        [$status, $employees] = httpRequest('GET', $base . '/api/employees.php');
        assertSame(200, $status, 'после перезапуска');
        assertSame('Олена Грищук', $employees['employees'][0]['name'], 'имя сохранилось');
        [$status, $timers] = httpRequest('GET', $base . '/api/timer.php');
        assertSame(200, $status, 'таймеры');
        assertSame(1, count($timers['timers']), 'открытый таймер жив');
        assertSame('После остановки', $timers['timers'][0]['description'], 'задача');
    } finally {
        if (is_resource($proc)) {
            stopServer($proc);
        }
    }
});

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Не удалось выбрать порт: ' . $error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr((string) strrchr((string) $address, ':'), 1);
    if ($port <= 0) {
        throw new RuntimeException('Пустой порт');
    }

    return $port;
}

function phpInvocation(array $args): array
{
    $command = [PHP_BINARY];
    if (is_readable('/proc/self/cmdline')) {
        $chunks = explode("\0", (string) file_get_contents('/proc/self/cmdline'));
        for ($i = 1, $count = count($chunks); $i < $count; $i++) {
            if ($chunks[$i] === '-d' && isset($chunks[$i + 1]) && $chunks[$i + 1] !== '') {
                $command[] = '-d';
                $command[] = $chunks[$i + 1];
                $i++;
            }
        }
    }

    return array_merge($command, $args);
}

/**
 * @param array<string, string> $env
 */
function startServer(int $port, string $log, array $env)
{
    $command = phpInvocation([
        '-S',
        '127.0.0.1:' . $port,
        '-t',
        dirname(__DIR__) . '/public',
    ]);
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ], $pipes, dirname(__DIR__), $env);
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    if (!is_resource($process)) {
        throw new RuntimeException('Не удалось запустить встроенный сервер');
    }

    $deadline = microtime(true) + 4;
    while (microtime(true) < $deadline) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            return $process;
        }
        usleep(50000);
    }

    stopServer($process);
    $output = is_file($log) ? (string) file_get_contents($log) : '';
    throw new RuntimeException('Сервер не поднялся. ' . $output);
}

function stopServer($process): void
{
    if (!is_resource($process)) {
        return;
    }
    proc_terminate($process);
    $deadline = microtime(true) + 2;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(50000);
    }
    proc_close($process);
}

/**
 * @param array<string, mixed>|null $payload
 * @return array{0: int, 1: mixed}
 */
function httpRequest(string $method, string $url, ?array $payload = null): array
{
    $header = "Accept: application/json\r\n";
    $options = [
        'method' => $method,
        'ignore_errors' => true,
        'timeout' => 3,
    ];
    if ($payload !== null) {
        $header .= "Content-Type: application/json\r\n";
        $options['content'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    $options['header'] = $header;
    $body = file_get_contents($url, false, stream_context_create(['http' => $options]));
    if ($body === false) {
        throw new RuntimeException('Нет ответа: ' . $url);
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
            $status = (int) $match[1];
        }
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [$status, $body];
    }

    return [$status, $decoded];
}

echo "\n{$passed} прошло, {$failed} не прошло\n";
exit($failed > 0 ? 1 : 0);
