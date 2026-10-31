#!/usr/bin/env node
/**
 * Установка закреплённого по версии криптодвижка Matrix для браузера Notes.
 * Запускать при подготовке поставки; на HTTP-запросах npm не вызывается.
 * Не загружать SDK из внешнего CDN в браузер пользователя.
 */
import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const destination = join(root, 'modules/messenger/crypto/sdk');
const packageName = '@matrix-org/matrix-sdk-crypto-wasm';
const pinnedVersion = '18.9.0';
const files = [
    ['index.mjs', 'index.mjs'],
    ['pkg/matrix_sdk_crypto_wasm_bg.js', 'pkg/matrix_sdk_crypto_wasm_bg.js'],
    ['pkg/matrix_sdk_crypto_wasm_bg.wasm', 'pkg/matrix_sdk_crypto_wasm_bg.wasm'],
];

const temporary = mkdtempSync(join(tmpdir(), 'notes-e2ee-sdk-'));
try {
    execFileSync('npm', [
        'install', '--prefix', temporary,
        '--no-save', '--ignore-scripts', '--no-package-lock',
        '--no-audit', '--no-fund',
        packageName + '@' + pinnedVersion,
    ], { stdio: 'inherit', timeout: 120000 });

    const source = join(temporary, 'node_modules', '@matrix-org', 'matrix-sdk-crypto-wasm');
    const metadata = JSON.parse(readFileSync(join(source, 'package.json'), 'utf8'));
    if (metadata.name !== packageName || metadata.version !== pinnedVersion) {
        throw new Error('Установленный криптодвижок не соответствует закреплённой версии');
    }

    // Сначала проверяем полный комплект, затем заменяем установленную копию.
    const checksums = {};
    for (const [from, to] of files) {
        const path = join(source, from);
        if (!existsSync(path)) {
            throw new Error('Отсутствует обязательный файл криптодвижка: ' + from);
        }
        checksums[to] = createHash('sha256').update(readFileSync(path)).digest('hex');
    }

    const staged = join(temporary, 'ready');
    mkdirSync(join(staged, 'pkg'), { recursive: true });
    for (const [from, to] of files) {
        cpSync(join(source, from), join(staged, to));
    }
    writeFileSync(join(staged, 'MANIFEST.json'), JSON.stringify({
        package: packageName, version: pinnedVersion, sha256: checksums,
    }, null, 2) + '\n');

    // Копия нужна для отсутствия внешних запросов на клиенте.
    mkdirSync(join(destination, 'pkg'), { recursive: true });
    for (const [, to] of files) {
        cpSync(join(staged, to), join(destination, to));
    }
    cpSync(join(staged, 'MANIFEST.json'), join(destination, 'MANIFEST.json'));
    process.stdout.write('Криптодвижок Matrix ' + pinnedVersion + ' установлен локально.\n');
} catch (error) {
    process.stderr.write('Не удалось установить криптодвижок: ' + error.message + '\n');
    process.exitCode = 1;
} finally {
    rmSync(temporary, { recursive: true, force: true });
}
