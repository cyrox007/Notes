import fs from 'node:fs';
import vm from 'node:vm';

const root = new URL('../../', import.meta.url);
const sourcePath = new URL('app/views/messager_page/protocol-origin.js', root);
let source = fs.readFileSync(sourcePath, 'utf8');
source = source.replace(/^\{literal\}\s*/, '').replace(/\s*\{\/literal\}\s*$/, '');

function loadResolver(location) {
  const listeners = new Map();
  const window = {
    location,
    wspace: { socketConfig: { url: '' } },
  };
  const document = {
    addEventListener(name, callback) {
      listeners.set(name, callback);
    },
  };
  const context = vm.createContext({ window, document, console });
  vm.runInContext(source, context, { filename: 'protocol-origin.js' });
  if (typeof window.wspaceResolveSocketUrl !== 'function') {
    throw new Error('protocol resolver was not exported');
  }
  return { window, fire: (name) => listeners.get(name)?.() };
}

function assertEqual(actual, expected, message) {
  if (actual !== expected) {
    throw new Error(`${message}: expected ${expected}, got ${actual}`);
  }
}

{
  const { window } = loadResolver({ protocol: 'http:', host: 'workspace-organizer.local' });
  assertEqual(
    window.wspaceResolveSocketUrl('/ws'),
    'ws://workspace-organizer.local/ws',
    'HTTP page must resolve same-origin Messenger endpoint to ws://'
  );
}

{
  const { window } = loadResolver({ protocol: 'https:', host: 'workspace-organizer.local' });
  assertEqual(
    window.wspaceResolveSocketUrl('/workspace/ws'),
    'wss://workspace-organizer.local/workspace/ws',
    'HTTPS page must resolve same-origin Messenger endpoint to wss://'
  );
}

{
  const { window } = loadResolver({ protocol: 'http:', host: '127.0.0.1:8080' });
  assertEqual(
    window.wspaceResolveSocketUrl('/workspace/ws?transport=native'),
    'ws://127.0.0.1:8080/workspace/ws?transport=native',
    'same-origin resolver must preserve current authority and query'
  );
}

{
  const { window } = loadResolver({ protocol: 'http:', host: 'workspace-organizer.local' });
  const external = 'wss://socket.example.test/ws';
  assertEqual(
    window.wspaceResolveSocketUrl(external),
    external,
    'external WebSocket endpoint must not be rewritten'
  );
}

{
  const { window, fire } = loadResolver({ protocol: 'http:', host: 'workspace-organizer.local' });
  window.wspace.socketConfig.url = '/ws';
  fire('DOMContentLoaded');
  assertEqual(
    window.wspace.socketConfig.url,
    'ws://workspace-organizer.local/ws',
    'DOMContentLoaded wiring did not normalize Messenger socket config'
  );
}

console.log('[OK] Messenger protocol/origin resolver contract');
