#!/usr/bin/env node
/**
 * Lightweight call-events WebSocket bridge.
 * Laravel webhooks / destination-connected POST here → push to dialer browsers.
 * No Morpheus polling — CRM stays responsive.
 */
import http from 'node:http';
import { WebSocketServer } from 'ws';

const PORT = Number(process.env.CALL_EVENTS_WS_PORT || 8787);
const HOST = process.env.CALL_EVENTS_WS_HOST || '127.0.0.1';
const PUSH_SECRET = String(process.env.CALL_EVENTS_WS_SECRET || '').trim();

/** @type {Map<string, Set<import('ws').WebSocket>>} */
const rooms = new Map();
/** @type {Map<string, object>} */
const lastState = new Map();

function roomKey(uuid, destination = '') {
  const id = String(uuid || '').trim().toLowerCase();
  const dest = String(destination || '').replace(/\D/g, '').slice(-10);
  return dest ? `${id}:${dest}` : id;
}

function addClient(key, ws) {
  if (!rooms.has(key)) {
    rooms.set(key, new Set());
  }
  rooms.get(key).add(ws);
}

function removeClient(key, ws) {
  const set = rooms.get(key);
  if (!set) {
    return;
  }
  set.delete(ws);
  if (set.size === 0) {
    rooms.delete(key);
  }
}

function pushToRoom(key, payload) {
  const set = rooms.get(key);
  if (!set || set.size === 0) {
    return 0;
  }
  const msg = JSON.stringify(payload);
  let sent = 0;
  for (const ws of set) {
    if (ws.readyState === 1) {
      try {
        ws.send(msg);
        sent += 1;
      } catch {
        // ignore closed sockets
      }
    }
  }
  return sent;
}

function broadcastState(state) {
  const uuid = String(state?.uuid || '').trim();
  if (!uuid) {
    return { ok: false, sent: 0 };
  }
  const destination = String(state.destination || state.to || state.phone_number || '');
  const exact = roomKey(uuid, destination);
  const loose = roomKey(uuid, '');
  lastState.set(exact, state);
  lastState.set(loose, state);
  const sent = pushToRoom(exact, state) + (exact !== loose ? pushToRoom(loose, state) : 0);
  return { ok: true, sent, uuid };
}

function readJson(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      try {
        const raw = Buffer.concat(chunks).toString('utf8') || '{}';
        resolve(JSON.parse(raw));
      } catch (error) {
        reject(error);
      }
    });
    req.on('error', reject);
  });
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url || '/', `http://${HOST}:${PORT}`);

  if (req.method === 'GET' && url.pathname === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      ok: true,
      rooms: rooms.size,
      clients: [...rooms.values()].reduce((n, set) => n + set.size, 0),
    }));
    return;
  }

  if (req.method === 'POST' && url.pathname === '/push') {
    if (PUSH_SECRET) {
      const header = String(req.headers['x-call-events-secret'] || '');
      if (header !== PUSH_SECRET) {
        res.writeHead(401, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, error: 'unauthorized' }));
        return;
      }
    }

    try {
      const state = await readJson(req);
      const result = broadcastState(state);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(result));
    } catch (error) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: false, error: String(error?.message || error) }));
    }
    return;
  }

  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ ok: false, error: 'not_found' }));
});

const wss = new WebSocketServer({ server, path: '/ws' });

wss.on('connection', (ws, req) => {
  let key = '';
  try {
    const url = new URL(req.url || '/ws', `http://${HOST}:${PORT}`);
    const uuid = String(url.searchParams.get('uuid') || '').trim();
    const destination = String(url.searchParams.get('destination') || '').trim();
    if (!uuid) {
      ws.close(1008, 'uuid required');
      return;
    }
    key = roomKey(uuid, destination);
    addClient(key, ws);
    addClient(roomKey(uuid, ''), ws);

    // Immediate hello so the dialer treats the socket as ready (no wait for first event).
    const snapshot = lastState.get(key) || lastState.get(roomKey(uuid, ''));
    const hello = snapshot || {
      ok: true,
      uuid,
      pending: true,
      live: true,
      outcome: 'listening',
      source: 'websocket-hello',
    };
    try {
      ws.send(JSON.stringify(hello));
    } catch {
      // ignore
    }
  } catch {
    ws.close(1011, 'bad request');
    return;
  }

  ws.on('close', () => {
    if (key) {
      removeClient(key, ws);
      const uuid = key.split(':')[0];
      removeClient(roomKey(uuid, ''), ws);
    }
  });
});

server.listen(PORT, HOST, () => {
  console.log(`[call-events-ws] listening on ${HOST}:${PORT}`);
});
