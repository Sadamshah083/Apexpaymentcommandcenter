#!/usr/bin/env node
/**
 * Runs Prettier in check mode and writes a human-readable report under reports/.
 */
import { execSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const reportsDir = join(root, 'reports');
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const reportPath = join(reportsDir, `prettier-${stamp}.md`);
const latestPath = join(reportsDir, 'prettier-latest.md');

mkdirSync(reportsDir, { recursive: true });

let exitCode = 0;
let stdout = '';
let stderr = '';

try {
    stdout = execSync('npx prettier --list-different .', {
        cwd: root,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });
} catch (error) {
    exitCode = error.status ?? 1;
    stdout = error.stdout?.toString() ?? '';
    stderr = error.stderr?.toString() ?? '';
}

const files = stdout
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);

const byExt = files.reduce((acc, file) => {
    const ext = file.includes('.') ? file.slice(file.lastIndexOf('.')) : '(none)';
    acc[ext] = (acc[ext] ?? 0) + 1;
    return acc;
}, /** @type {Record<string, number>} */ ({}));

const body = [
    '# Prettier format report',
    '',
    `Generated: ${new Date().toISOString()}`,
    '',
    '## Summary',
    '',
    `| Metric | Value |`,
    `| --- | --- |`,
    `| Status | ${files.length === 0 ? 'PASS — all checked files formatted' : 'FAIL — formatting drift detected'} |`,
    `| Files needing format | ${files.length} |`,
    '',
];

if (files.length > 0) {
    body.push('## By file type', '', '| Extension | Count |', '| --- | --- |');
    for (const [ext, count] of Object.entries(byExt).sort((a, b) => b[1] - a[1])) {
        body.push(`| \`${ext}\` | ${count} |`);
    }
    body.push('');
}

if (files.length > 0) {
    body.push('## Files to format', '', '```', ...files, '```', '');
    body.push('## Fix', '', '```bash', 'npm run format', '```', '');
} else {
    body.push('No formatting changes required.', '');
}

if (stderr.trim()) {
    body.push('## Warnings / stderr', '', '```', stderr.trim(), '```', '');
}

const content = body.join('\n');
writeFileSync(reportPath, content, 'utf8');
writeFileSync(latestPath, content, 'utf8');

console.log(content);
console.log(`\nReport saved: ${reportPath}`);
console.log(`Latest copy:  ${latestPath}`);

process.exit(exitCode);
