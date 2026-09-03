import path from "node:path";

import { defineConfig, devices } from "@playwright/test";

const projectDirectory = process.cwd();

export default defineConfig({
  testDir: "./tests/e2e",
  timeout: 60_000,
  workers: 1,
  use: {
    baseURL: "http://localhost:3000",
    trace: "retain-on-failure",
  },
  projects: [
    {
      name: "edge",
      use: { ...devices["Desktop Edge"], channel: "msedge" },
    },
  ],
  webServer: [
    {
      command: "php artisan serve --host=127.0.0.1 --port=8000",
      cwd: path.resolve(projectDirectory, "../backend"),
      url: "http://localhost:8000/up",
      reuseExistingServer: true,
      timeout: 60_000,
    },
    {
      command: "npm run dev -- --hostname 127.0.0.1 --port 3000",
      cwd: projectDirectory,
      url: "http://localhost:3000/login",
      reuseExistingServer: true,
      timeout: 120_000,
    },
  ],
});
