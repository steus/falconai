# Tabel

A small web application for tracking employees' working time. You can add an employee, start and stop a timer with a task description, and see each person's entries and total for a chosen period. Data is stored in a SQLite file and survives a restart. There is no login. Names in the interface and in the checks are fictional.

The screen is in Russian and English. A language switch in the header stores the choice in the browser. PHP, SQLite, and plain JavaScript were enough, so Drupal was not used.

## How to run

Docker is the way to run it. The image already contains PHP 8.3, `pdo_sqlite`, and `mbstring`.

On each machine, build the image once. It contains PHP and its extensions. The project directory is mounted into the container, so later edits and `git pull` are picked up on the next request. The same `docker-compose.yml` works on every machine: the mount path is relative to the project.

```bash
docker compose up --build
```

Open http://localhost:8080

After the image exists, start it without a rebuild:

```bash
docker compose up
```

Rebuild only when `Dockerfile` or `docker/entrypoint.sh` changes. Stop it with `Ctrl+C`, then `docker compose down`.

The database file is created on the host at `data/timetracker.sqlite`. Records remain after the container restarts. The `data` directory is not served by the site.

Run the checks inside the image:

```bash
docker compose run --rm app php tests/run.php
```

If the machine already has PHP 8.3 with `pdo_sqlite` and `mbstring` (`php8.3-sqlite3` and `php8.3-mbstring`):

```bash
php -S localhost:8080 -t public
php tests/run.php
```

## AI tools and models

The code was written in Cursor with the Grok 4.7 model. The result was then run through the test suite, `php -l`, and a browser scenario, and the mistakes found there were fixed in this repository.

### Example prompts

Build the assignment and choose the stack:

```text
Test assignment: work time tracking.

Create a small web application from scratch for tracking employees' working time.
It must support:
- adding employees;
- starting and stopping a timer with a task description;
- viewing entries and each employee's total time for a selected period;
- keeping the data after the application restarts.

The interface can be simple. No login or roles. Fictional data only.
In the README, describe how to run it, which AI models were used, example prompts,
and which bugs were found and fixed. Add checks for the main scenarios,
including starting the timer again and an empty report.

Stack: PHP and vanilla JS. Drupal is fine if plain PHP is too awkward.
```

Store the data without MySQL:

```text
You can use a lightweight SQL engine so MySQL is not required.
```

Run it without installing PHP extensions on the host:

```text
It does not start directly. Let's put it in a Docker container.
```

## Bugs found and fixed

### A day boundary shifted to the current time of day

`DateTimeImmutable::createFromFormat('Y-m-d', ...)` leaves the fields that are not in the format as they are. In the evening, parsing `2026-10-31` produced the current time instead of midnight:

```text
plain: 2026-10-31T20:12:16+02:00
!Y-m-d: 2026-10-31T00:00:00+02:00
```

An entry from 00:10 to 00:40 would then fall outside that day's report. The parser now uses `!Y-m-d`. It also rejects PHP's warning for impossible dates: `2026-02-31` would otherwise become 3 March. The checks "an entry at 00:10 belongs to the same day" and "31 February and a reversed period are rejected" cover this.

### A useless `use` printed a warning in front of JSON

`public/api/timer.php` and `public/api/report.php` have no namespace, but they still contained `use InvalidArgumentException`. `php -l` reported:

```text
PHP Warning: The use statement with non-compound name 'InvalidArgumentException' has no effect
```

With `display_errors` on, that text would be sent before the JSON and the page could not parse the response. The import was removed. In a file without a namespace the class is already global.

### The HTTP check died on the first refused connection

The error handler in `tests/run.php` turned every warning into an exception, including warnings suppressed with `@`. While the built-in server was starting, the first `fsockopen` got `Connection refused` and the wait loop stopped:

```text
FAIL HTTP: empty report, starting the timer again, and data after restart
     fsockopen(): Unable to connect to 127.0.0.1:35707 (Connection refused)
```

Suppressed errors are now ignored (`error_reporting()` is zero inside the handler). The scenario then passes: an empty report, a second start while a timer is open returns 409, stop, start again, and the same data after the server process restarts.

## Checks

`php tests/run.php` uses a temporary database and, for the last scenario, the built-in server. The suite has 19 checks and finishes with `19 прошло, 0 не прошло`.

The assignment's two required cases are covered directly:

- Starting the timer again while it is already running is rejected, and only one entry stays open.
- Starting the timer again after a stop creates a second entry, and the report total is the sum of both.
- An empty report with no employees returns empty lists instead of an error.
- An empty report for a period with no entries still lists every employee with zero time.

The same cases are repeated over HTTP, including a 409 on a repeated start and the open timer still being there after the server process is restarted. Other checks cover an entry that crosses midnight, an open timer that must not run past the end of the period, and reading the same SQLite file from a new connection.

## How it works

- `public/index.html`, `public/css/app.css`, and `public/js/app.js` are the page. Employee names are inserted with `textContent`.
- `public/api/*.php` serves JSON for employees, the timer, and the report.
- `src/TimeTracker.php` holds the tracking rules.
- Instants are stored as Unix time and shown in `Europe/Kyiv`.
- One employee can have only one open timer. The code checks this, and a partial unique index in SQLite enforces it. After a stop, the timer can be started again as a new entry. Two different employees can run at the same time.
- A report counts only the part of an entry that falls inside the selected dates. The period is half-open: from 00:00 on the first day until 00:00 on the day after the end date. An open timer counts up to the current moment, and not past the end of the period. An entry from 23:00 to 01:00 is split across the two days. Stopping exactly at midnight does not add time to the next day.
