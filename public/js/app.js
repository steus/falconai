"use strict";

const alertBox = document.querySelector("#alert");
const employeeForm = document.querySelector("#employee-form");
const nameInput = document.querySelector("#employee-name");
const peopleTitle = document.querySelector("#people-title");
const peopleList = document.querySelector("#people");
const timerForm = document.querySelector("#timer-form");
const employeeSelect = document.querySelector("#employee-select");
const taskInput = document.querySelector("#task");
const timerButton = document.querySelector("#timer-button");
const timerClock = document.querySelector("#timer-clock");
const timerStatus = document.querySelector("#timer-status");
const activeList = document.querySelector("#active-list");
const reportForm = document.querySelector("#report-form");
const fromInput = document.querySelector("#from");
const toInput = document.querySelector("#to");
const totalsRoot = document.querySelector("#totals");
const entriesRoot = document.querySelector("#entries");

const state = {
  employees: [],
  active: [],
  report: null,
};

let busy = false;
let shownRunningId = null;

document.addEventListener("DOMContentLoaded", () => {
  setDefaultPeriod();
  employeeForm.addEventListener("submit", onAddEmployee);
  timerForm.addEventListener("submit", onTimer);
  reportForm.addEventListener("submit", onReport);
  employeeSelect.addEventListener("change", () => {
    sessionStorage.setItem("employeeId", employeeSelect.value);
    renderTimer();
    renderPeople();
  });
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible" && !busy) {
      refreshAll();
    }
  });
  refreshAll();
  setInterval(tick, 1000);
});

function setDefaultPeriod() {
  const today = kyivDate();
  fromInput.value = sessionStorage.getItem("from") || `${today.slice(0, 8)}01`;
  toInput.value = sessionStorage.getItem("to") || today;
}

function kyivDate(date = new Date()) {
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Europe/Kyiv",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(date);
  const pick = (type) => parts.find((part) => part.type === type).value;
  return `${pick("year")}-${pick("month")}-${pick("day")}`;
}

async function onAddEmployee(event) {
  event.preventDefault();
  const name = nameInput.value;
  await guard(async () => {
    const result = await api("/api/employees.php", {
      method: "POST",
      body: JSON.stringify({ name }),
    });
    sessionStorage.setItem("employeeId", String(result.employee.id));
    nameInput.value = "";
    await refreshAll();
  });
}

async function onTimer(event) {
  event.preventDefault();
  const employeeId = Number(employeeSelect.value);
  if (!employeeId) {
    showError("Сначала добавьте сотрудника");
    return;
  }
  const active = activeFor(employeeId);
  await guard(async () => {
    if (active) {
      await api("/api/timer.php", {
        method: "POST",
        body: JSON.stringify({ action: "stop", employee_id: employeeId }),
      });
    } else {
      const description = taskInput.value.trim();
      if (!description) {
        showError("Опишите задачу");
        return;
      }
      await api("/api/timer.php", {
        method: "POST",
        body: JSON.stringify({
          action: "start",
          employee_id: employeeId,
          description,
        }),
      });
      taskInput.value = "";
    }
    await refreshAll();
  });
}

async function onReport(event) {
  event.preventDefault();
  await guard(() => refreshAll());
}

async function guard(action) {
  if (busy) {
    return;
  }
  busy = true;
  try {
    await action();
  } catch (error) {
    showError(error.message || "Не удалось выполнить запрос");
  } finally {
    busy = false;
  }
}

async function refreshAll() {
  const params = new URLSearchParams({
    from: fromInput.value,
    to: toInput.value,
  });
  const [employees, timers, report] = await Promise.all([
    api("/api/employees.php"),
    api("/api/timer.php"),
    api(`/api/report.php?${params.toString()}`),
  ]);
  state.employees = employees.employees;
  state.active = timers.timers;
  state.report = report;
  sessionStorage.setItem("from", fromInput.value);
  sessionStorage.setItem("to", toInput.value);
  clearError();
  render();
}

async function api(url, options = {}) {
  const response = await fetch(url, {
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    ...options,
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(data.error || "Запрос не выполнен");
  }
  return data;
}

function render() {
  renderSelect();
  renderPeople();
  renderTimer();
  renderActive();
  renderReport();
}

function renderPeople() {
  peopleTitle.textContent = `Сотрудники (${state.employees.length})`;
  peopleList.replaceChildren();
  if (state.employees.length === 0) {
    peopleList.append(note("Пока никого нет", "li"));
    return;
  }
  const selected = selectedId();
  state.employees.forEach((employee) => {
    const button = document.createElement("button");
    button.type = "button";
    button.setAttribute("aria-pressed", String(employee.id === selected));
    const name = document.createElement("span");
    name.textContent = employeeLabel(employee);
    button.append(name);
    if (activeFor(employee.id)) {
      const pill = document.createElement("span");
      pill.className = "pill";
      pill.textContent = "идёт";
      button.append(pill);
    }
    button.addEventListener("click", () => selectEmployee(employee.id));
    const item = document.createElement("li");
    item.append(button);
    peopleList.append(item);
  });
}

function renderSelect() {
  const preferred = sessionStorage.getItem("employeeId") || employeeSelect.value;
  employeeSelect.replaceChildren();
  if (state.employees.length === 0) {
    const option = document.createElement("option");
    option.value = "";
    option.textContent = "Нет сотрудников";
    employeeSelect.append(option);
    employeeSelect.disabled = true;
    return;
  }
  employeeSelect.disabled = false;
  state.employees.forEach((employee) => {
    const option = document.createElement("option");
    option.value = String(employee.id);
    option.textContent = employeeLabel(employee);
    employeeSelect.append(option);
  });
  if ([...employeeSelect.options].some((option) => option.value === preferred)) {
    employeeSelect.value = preferred;
  }
}

function renderTimer() {
  const employeeId = selectedId();
  const active = employeeId ? activeFor(employeeId) : null;
  timerButton.disabled = !employeeId;
  timerButton.textContent = active ? "Стоп" : "Старт";
  timerButton.className = active ? "danger" : "primary";
  if (active) {
    taskInput.disabled = true;
    taskInput.value = active.description;
    shownRunningId = active.id;
    timerStatus.textContent = `Идёт: ${active.description}`;
    showClock(active.started_at);
    return;
  }
  taskInput.disabled = false;
  if (shownRunningId !== null) {
    taskInput.value = "";
    shownRunningId = null;
  }
  timerStatus.textContent = employeeId ? "Таймер не запущен" : "Сначала добавьте сотрудника";
  showClock(null);
}

function renderActive() {
  activeList.replaceChildren();
  if (state.active.length === 0) {
    activeList.append(note("Сейчас никто не работает", "li"));
    return;
  }
  state.active.forEach((entry) => {
    const button = document.createElement("button");
    button.type = "button";
    const who = document.createElement("span");
    who.className = "who";
    const name = document.createElement("span");
    name.textContent = entry.employee_name;
    const task = document.createElement("span");
    task.className = "task";
    task.textContent = entry.description;
    who.append(name, task);
    const clock = document.createElement("span");
    clock.className = "mini-clock";
    clock.dataset.started = entry.started_at;
    clock.textContent = formatClock(elapsedSeconds(entry.started_at));
    button.append(who, clock);
    button.addEventListener("click", () => selectEmployee(entry.employee_id));
    const item = document.createElement("li");
    item.append(button);
    activeList.append(item);
  });
}

function renderReport() {
  totalsRoot.replaceChildren();
  entriesRoot.replaceChildren();
  if (!state.report) {
    entriesRoot.append(note("Загрузка…"));
    return;
  }
  if (state.report.totals.length === 0) {
    totalsRoot.append(note("Сотрудников пока нет — отчёт пуст."));
    return;
  }

  totalsRoot.append(table(
    ["Сотрудник", "Записей", "Всего"],
    state.report.totals.map((row) => [
      row.employee_name,
      String(row.entry_count),
      formatClock(row.total_seconds),
    ]),
  ));

  if (state.report.entries.length === 0) {
    entriesRoot.append(note("Записей за этот период нет."));
    return;
  }

  entriesRoot.append(table(
    ["Сотрудник", "Задача", "Начало", "Конец", "В периоде"],
    state.report.entries.map((entry) => [
      entry.employee_name,
      entry.description,
      formatWhen(entry.started_at),
      entry.stopped_at ? formatWhen(entry.stopped_at) : "идёт",
      formatClock(entry.duration_seconds),
    ]),
  ));
}

function table(headers, rows) {
  const element = document.createElement("table");
  const head = document.createElement("tr");
  headers.forEach((header) => {
    const cell = document.createElement("th");
    cell.scope = "col";
    cell.textContent = header;
    head.append(cell);
  });
  const thead = document.createElement("thead");
  thead.append(head);
  const body = document.createElement("tbody");
  rows.forEach((row) => {
    const line = document.createElement("tr");
    row.forEach((value) => {
      const cell = document.createElement("td");
      cell.textContent = value;
      line.append(cell);
    });
    body.append(line);
  });
  element.append(thead, body);
  return element;
}

function note(text, tag = "p") {
  const item = document.createElement(tag);
  item.className = "muted";
  item.textContent = text;
  return item;
}

function selectEmployee(id) {
  sessionStorage.setItem("employeeId", String(id));
  employeeSelect.value = String(id);
  renderPeople();
  renderTimer();
}

function selectedId() {
  const value = Number(employeeSelect.value);
  return Number.isInteger(value) && value > 0 ? value : null;
}

function activeFor(employeeId) {
  return state.active.find((entry) => entry.employee_id === employeeId) || null;
}

function employeeLabel(employee) {
  const sameName = state.employees.filter((item) => item.name === employee.name).length;
  return sameName > 1 ? `${employee.name} (№${employee.id})` : employee.name;
}

function showClock(iso) {
  if (!iso) {
    delete timerClock.dataset.started;
    timerClock.textContent = "00:00:00";
    return;
  }
  timerClock.dataset.started = iso;
  timerClock.textContent = formatClock(elapsedSeconds(iso));
}

function tick() {
  document.querySelectorAll("[data-started]").forEach((node) => {
    node.textContent = formatClock(elapsedSeconds(node.dataset.started));
  });
}

function elapsedSeconds(iso) {
  const started = Date.parse(iso);
  if (Number.isNaN(started)) {
    return 0;
  }
  return Math.max(0, Math.floor((Date.now() - started) / 1000));
}

function formatClock(totalSeconds) {
  const seconds = Math.max(0, Math.floor(Number(totalSeconds) || 0));
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const rest = seconds % 60;
  return [hours, minutes, rest].map((part) => String(part).padStart(2, "0")).join(":");
}

function formatWhen(iso) {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(iso || "");
  if (!match) {
    return iso || "";
  }
  return `${match[3]}.${match[2]}.${match[1]} ${match[4]}:${match[5]}`;
}

function showError(message) {
  alertBox.hidden = false;
  alertBox.textContent = message;
}

function clearError() {
  alertBox.hidden = true;
  alertBox.textContent = "";
}
