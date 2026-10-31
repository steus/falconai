<?php

declare(strict_types=1);

namespace App;

use PDOException;
use RuntimeException;
use Throwable;

final class Http
{
    public static function databasePath(): string
    {
        $override = getenv('TIMETRACKER_DB');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return dirname(__DIR__) . '/data/timetracker.sqlite';
    }

    public static function tracker(): TimeTracker
    {
        return TimeTracker::connect(self::databasePath());
    }

    public static function dispatch(callable $handler): void
    {
        try {
            $handler();
        } catch (\InvalidArgumentException $e) {
            self::json(['error' => Translator::line($e->getMessage())], 422);
        } catch (ConflictException $e) {
            self::json(['error' => Translator::line($e->getMessage())], 409);
        } catch (PDOException $e) {
            self::json(['error' => Translator::line('database_error')], 500);
        } catch (RuntimeException $e) {
            self::json(['error' => Translator::line($e->getMessage())], 500);
        } catch (Throwable $e) {
            self::json(['error' => Translator::line('internal_error')], 500);
        }
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::json(['error' => Translator::line('expected_json')], 400);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
