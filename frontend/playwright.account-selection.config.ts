import {defineConfig} from "@playwright/test";

// UI boundary tests use mocked identity endpoints and the existing IAM frontend.
// They neither start HRM nor write to the live IAM database.
export default defineConfig({
  testDir: "./tests/e2e",
  testMatch: "account-selection.spec.ts",
  workers: 1,
  timeout: 30_000,
  use: {baseURL: "http://localhost:3001", channel: "chrome", headless: true},
});
