#!/usr/bin/env node
/**
 * Call-events + Call Monitoring WebSocket bridge.
 * - Dialer clients: ?uuid=...
 * - Monitoring wallboard: ?channel=monitoring&workspace_id=...
 * Laravel POSTs /push (call state) and /push-monitoring (board refresh).
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
/** @type {Map<string, object>} */
const lastMonitoring = new Map();

function roomKey(uuid, destination = '') {
  const id = String(uuid || '').trim().toLowerCase();
  const dest = String(destination || '').replace(/\D/g, '').slice(-10);
  return dest ? `${id}:${dest}` : id;
}

function monitoringKey(workspaceId = '') {
  const id = String(workspaceId || '').trim();
  return id ? `monitoring:${id}` : 'monitoring:all';
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

  // Any live call change should wake Call Monitoring boards immediately.
  const workspaceId = String(state.workspace_id || state.workspaceId || '').trim();
  broadcastMonitoring({
    type: 'monitoring_refresh',
    reason: 'call_event',
    uuid,
    workspace_id: workspaceId || null,
    version: state.monitoring_version ?? null,
    live: Boolean(state.live),
    outcome: state.outcome || state.status || null,
    at: new Date().toISOString(),
  }, workspaceId);

  return { ok: true, sent, uuid };
}

function broadcastMonitoring(payload, workspaceId = '') {
  const body = {
    ok: true,
    type: 'monitoring_refresh',
    ...payload,
  };
  const specific = monitoringKey(workspaceId);
  const all = monitoringKey('');
  lastMonitoring.set(specific, body);
  lastMonitoring.set(all, body);
  const sent = pushToRoom(specific, body) + (specific !== all ? pushToRoom(all, body) : 0);
  return { ok: true, sent, workspace_id: workspaceId || null };
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

function authorizePush(req, res) {
  if (!PUSH_SECRET) {
    return true;
  }
  const header = String(req.headers['x-call-events-secret'] || '');
  if (header === PUSH_SECRET) {
    return true;
  }
  res.writeHead(401, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ ok: false, error: 'unauthorized' }));
  return false;
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url || '/', `http://${HOST}:${PORT}`);

  if (req.method === 'GET' && url.pathname === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      ok: true,
      rooms: rooms.size,
      clients: [...rooms.values()].reduce((n, set) => n + set.size, 0),
      monitoring_rooms: [...rooms.keys()].filter((k) => k.startsWith('monitoring:')).length,
    }));
    return;
  }

  if (req.method === 'POST' && url.pathname === '/push') {
    if (!authorizePush(req, res)) {
      return;
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

  if (req.method === 'POST' && url.pathname === '/push-monitoring') {
    if (!authorizePush(req, res)) {
      return;
    }
    try {
      const body = await readJson(req);
      const workspaceId = String(body.workspace_id || body.workspaceId || '').trim();
      const result = broadcastMonitoring({
        type: 'monitoring_refresh',
        reason: body.reason || 'bump',
        workspace_id: workspaceId || null,
        version: body.version ?? null,
        presence_version: body.presence_version ?? null,
        at: new Date().toISOString(),
      }, workspaceId);
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
  /** @type {string[]} */
  const joined = [];
  try {
    const url = new URL(req.url || '/ws', `http://${HOST}:${PORT}`);
    const channel = String(url.searchParams.get('channel') || '').trim().toLowerCase();

    if (channel === 'monitoring') {
      const workspaceId = String(url.searchParams.get('workspace_id') || url.searchParams.get('workspace') || '').trim();
      const specific = monitoringKey(workspaceId);
      const all = monitoringKey('');
      addClient(specific, ws);
      joined.push(specific);
      if (specific !== all) {
        addClient(all, ws);
        joined.push(all);
      }

      const hello = lastMonitoring.get(specific) || lastMonitoring.get(all) || {
        ok: true,
        type: 'monitoring_hello',
        workspace_id: workspaceId || null,
        pending: true,
        at: new Date().toISOString(),
      };
      try {
        ws.send(JSON.stringify(hello));
      } catch {
        // ignore
      }

      ws.on('close', () => {
        for (const key of joined) {
          removeClient(key, ws);
        }
      });
      return;
    }

    const uuid = String(url.searchParams.get('uuid') || '').trim();
    const destination = String(url.searchParams.get('destination') || '').trim();
    if (!uuid) {
      ws.close(1008, 'uuid or channel=monitoring required');
      return;
    }
    const key = roomKey(uuid, destination);
    const loose = roomKey(uuid, '');
    addClient(key, ws);
    addClient(loose, ws);
    joined.push(key, loose);

    const snapshot = lastState.get(key) || lastState.get(loose);
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
    for (const key of joined) {
      removeClient(key, ws);
    }
  });
});

server.listen(PORT, HOST, () => {
  console.log(`[call-events-ws] listening on ${HOST}:${PORT} (dialer + monitoring)`);
});
