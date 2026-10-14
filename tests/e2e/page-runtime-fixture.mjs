import { readFile } from 'node:fs/promises';

export async function addWorkspaceScript(page, options) {
    if (!await page.evaluate(() => Boolean(window.wspace?.page))) {
        await page.addScriptTag({ content: await readFile('assets/js/page-runtime.js', 'utf8') });
    }
    if (!await page.evaluate(() => Boolean(window.wspace?.modalMotion))) {
        await page.addScriptTag({ content: await readFile('assets/js/modal-motion.js', 'utf8') });
    }
    return page.addScriptTag(options);
}
