const {defineConfig} = require('@playwright/test');
module.exports = defineConfig({
  testDir: '../tests/e2e', testMatch: '*.spec.cjs', workers: 1, fullyParallel: false,
  timeout: 45000, expect: {timeout: 10000},
  outputDir: '../.workbench/playwright-results',
  reporter: [['list'], ['json', {outputFile: '../.workbench/playwright-results.json'}]],
  use: {baseURL: process.env.ANONYMOUS_E2E_URL || 'http://127.0.0.1:8088',
    channel: process.env.PLAYWRIGHT_CHANNEL || 'chromium', headless: true,
    viewport: {width: 1280, height: 900}, trace: 'retain-on-failure', screenshot: 'only-on-failure'},
});
