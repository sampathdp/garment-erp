// Development launcher: starts the Laravel API (artisan serve) and the Next.js dev server together.
// `npm run dev` uses this; `npm run dev:frontend` starts Next.js alone.
//
// PHP is found in this order:
//   1. PHP_BIN environment variable (path to php / php.exe)
//   2. ../.tools/php.sh (local Windows wrapper, run through bash)
//   3. `php` on the PATH
// If something already listens on the API port, the backend is not started again.

import { spawn } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import net from "node:net";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const backendDir = path.resolve(root, "..", "backend");
const wrapper = path.resolve(root, "..", ".tools", "php.sh");
const apiPort = Number(process.env.API_PORT ?? 8000);
const isWindows = process.platform === "win32";

const children = [];

function prefixed(name, stream, out) {
  let buffer = "";
  stream.on("data", (chunk) => {
    buffer += chunk.toString();
    const lines = buffer.split(/\r?\n/);
    buffer = lines.pop() ?? "";
    for (const line of lines) out.write(`[${name}] ${line}\n`);
  });
}

function start(name, command, args, cwd) {
  const child = spawn(command, args, { cwd, env: process.env });
  prefixed(name, child.stdout, process.stdout);
  prefixed(name, child.stderr, process.stderr);
  child.on("exit", (code) => {
    process.stdout.write(`[${name}] exited with code ${code}\n`);
    shutdown(code ?? 0);
  });
  child.on("error", (error) => {
    process.stderr.write(`[${name}] failed to start: ${error.message}\n`);
    if (name === "api") process.stderr.write("[api] Set PHP_BIN to your php executable, or start the API yourself.\n");
  });
  children.push(child);
  return child;
}

let stopping = false;
function shutdown(code) {
  if (stopping) return;
  stopping = true;
  for (const child of children) {
    if (child.exitCode !== null || !child.pid) continue;
    if (isWindows) spawn("taskkill", ["/pid", String(child.pid), "/T", "/F"], { stdio: "ignore" });
    else child.kill("SIGTERM");
  }
  setTimeout(() => process.exit(code), 500);
}

process.on("SIGINT", () => shutdown(0));
process.on("SIGTERM", () => shutdown(0));

function portInUse(port) {
  return new Promise((resolve) => {
    const socket = net.connect({ port, host: "127.0.0.1" });
    socket.once("connect", () => (socket.destroy(), resolve(true)));
    socket.once("error", () => resolve(false));
  });
}

/**
 * On Windows `bash` is often WSL, which cannot run the Git-Bash wrapper. Read the wrapper's
 * `exec <php> <flags> "$@"` line instead and run that PHP directly with the same flags.
 */
function fromWrapper() {
  const line = readFileSync(wrapper, "utf8").split(/\r?\n/).find((l) => l.trim().startsWith("exec "));
  if (!line) return null;
  const tokens = (line.trim().slice(5).match(/"[^"]*"|\S+/g) ?? []).map((t) => t.replace(/"/g, "")).filter((t) => t !== "$@");
  const toWindows = (p) => p.replace(/^\/([a-zA-Z])\//, (_, d) => `${d.toUpperCase()}:/`);
  const [php, ...flags] = tokens.map(toWindows);
  return php ? [php, flags] : null;
}

function phpCommand() {
  if (process.env.PHP_BIN) return [process.env.PHP_BIN, []];
  if (existsSync(wrapper)) {
    if (!isWindows) return ["bash", [wrapper]];
    const parsed = fromWrapper();
    if (parsed) return parsed;
  }
  return ["php", []];
}

if (!existsSync(path.join(backendDir, "artisan"))) {
  process.stdout.write("[api] backend/ not found next to frontend/ — starting the frontend only.\n");
} else if (await portInUse(apiPort)) {
  process.stdout.write(`[api] port ${apiPort} already in use — using the running API.\n`);
} else {
  const [php, prefix] = phpCommand();
  start("api", php, [...prefix, "artisan", "serve", "--host=127.0.0.1", `--port=${apiPort}`], backendDir);
}

const nextBin = path.join(root, "node_modules", "next", "dist", "bin", "next");
start("web", process.execPath, [nextBin, "dev", ...process.argv.slice(2)], root);
