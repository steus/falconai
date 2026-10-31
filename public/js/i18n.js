"use strict";

const STRINGS = {
  ru: {
    title: "Табель — учёт рабочего времени",
    logo: "Тб",
    eyebrow: "Учёт рабочего времени",
    app_name: "Табель",
    lede: "Вымышленные сотрудники, живой таймер и итог за период. База SQLite сохраняется после перезапуска.",
    language: "Язык",
    employees_count: "Сотрудники ({count})",
    name_label: "Имя",
    name_placeholder: "Например, Марина Лебедева",
    add: "Добавить",
    nobody_yet: "Пока никого нет",
    running_short: "идёт",
    timer: "Таймер",
    employee: "Сотрудник",
    task: "Задача",
    task_placeholder: "Например, сверка отчёта за октябрь",
    start: "Старт",
    stop: "Стоп",
    running_with: "Идёт: {task}",
    timer_idle: "Таймер не запущен",
    add_employee_first: "Сначала добавьте сотрудника",
    describe_task: "Опишите задачу",
    hint_timer: "Пока таймер идёт, второй запуск для того же сотрудника не принимается. После остановки задачу можно начать снова.",
    active_heading: "Сейчас работают",
    nobody_working: "Сейчас никто не работает",
    no_employees_option: "Нет сотрудников",
    report: "Отчёт",
    from: "С",
    to: "По",
    show: "Показать",
    hint_report: "Длительность — только часть записи внутри выбранных дат. Открытый таймер считается до текущего момента и не выходит за конец периода. Время — Киев.",
    loading: "Загрузка…",
    report_empty_people: "Сотрудников пока нет — отчёт пуст.",
    no_entries: "Записей за этот период нет.",
    col_employee: "Сотрудник",
    col_entries: "Записей",
    col_total: "Всего",
    col_task: "Задача",
    col_started: "Начало",
    col_stopped: "Конец",
    col_in_period: "В периоде",
    running: "идёт",
    request_failed: "Запрос не выполнен",
  },
  en: {
    title: "Timesheet — work time tracking",
    logo: "Ts",
    eyebrow: "Work time tracking",
    app_name: "Timesheet",
    lede: "Fictional employees, a live timer, and a total for the period. The SQLite database is kept after a restart.",
    language: "Language",
    employees_count: "Employees ({count})",
    name_label: "Name",
    name_placeholder: "For example, Marina Lebedeva",
    add: "Add",
    nobody_yet: "No one yet",
    running_short: "running",
    timer: "Timer",
    employee: "Employee",
    task: "Task",
    task_placeholder: "For example, checking the October report",
    start: "Start",
    stop: "Stop",
    running_with: "Running: {task}",
    timer_idle: "Timer is stopped",
    add_employee_first: "Add an employee first",
    describe_task: "Describe the task",
    hint_timer: "A second start is not accepted while this employee's timer is running. After a stop, the task can be started again.",
    active_heading: "Working now",
    nobody_working: "Nobody is working right now",
    no_employees_option: "No employees",
    report: "Report",
    from: "From",
    to: "To",
    show: "Show",
    hint_report: "Duration is only the part of an entry inside the selected dates. An open timer counts up to the current moment and does not go past the end of the period. Times are Europe/Kyiv.",
    loading: "Loading…",
    report_empty_people: "No employees yet — the report is empty.",
    no_entries: "No entries in this period.",
    col_employee: "Employee",
    col_entries: "Entries",
    col_total: "Total",
    col_task: "Task",
    col_started: "Started",
    col_stopped: "Stopped",
    col_in_period: "In period",
    running: "running",
    request_failed: "The request failed",
  },
};

let lang = "ru";

function currentLang() {
  return lang;
}

function t(key, vars = {}) {
  const table = STRINGS[lang] || STRINGS.ru;
  let text = table[key] || STRINGS.ru[key] || key;
  Object.entries(vars).forEach(([name, value]) => {
    text = text.replaceAll(`{${name}}`, String(value));
  });
  return text;
}

function applyLanguage() {
  const saved = localStorage.getItem("lang");
  lang = saved === "en" || saved === "ru" ? saved : "ru";
  document.documentElement.lang = lang;
  document.title = t("title");
  document.querySelectorAll("[data-i18n]").forEach((node) => {
    node.textContent = t(node.dataset.i18n);
  });
  document.querySelectorAll("[data-i18n-placeholder]").forEach((node) => {
    node.placeholder = t(node.dataset.i18nPlaceholder);
  });
  document.querySelectorAll("[data-lang]").forEach((button) => {
    button.setAttribute("aria-pressed", String(button.dataset.lang === lang));
  });
  const group = document.querySelector(".lang");
  if (group) {
    group.setAttribute("aria-label", t("language"));
  }
  if (typeof render === "function") {
    render();
  }
}
