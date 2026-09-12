// 管理员认证：PBKDF2 密码哈希 + HMAC 会话 cookie（Workers 无 bcrypt）
import { setSetting, getSetting } from './db';

const enc = (s: string) => new TextEncoder().encode(s);
const hex = (buf: ArrayBuffer | Uint8Array) =>
  [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');

// saltHex:hashHex 格式，PBKDF2-SHA256, 100000 次, 32 字节
export async function hashPassword(pw: string): Promise<string> {
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const km = await crypto.subtle.importKey('raw', enc(pw), 'PBKDF2', false, ['deriveBits']);
  const bits = await crypto.subtle.deriveBits(
    { name: 'PBKDF2', salt, iterations: 100000, hash: 'SHA-256' },
    km,
    256
  );
  return hex(salt) + ':' + hex(bits);
}

export async function verifyPassword(pw: string, stored: string): Promise<boolean> {
  const [saltHex, hashHex] = stored.split(':');
  if (!saltHex || !hashHex) return false;
  try {
    const salt = new Uint8Array(saltHex.match(/.{2}/g)!.map((b) => parseInt(b, 16)));
    const km = await crypto.subtle.importKey('raw', enc(pw), 'PBKDF2', false, ['deriveBits']);
    const bits = await crypto.subtle.deriveBits(
      { name: 'PBKDF2', salt, iterations: 100000, hash: 'SHA-256' },
      km,
      256
    );
    return hex(bits) === hashHex;
  } catch {
    return false;
  }
}

// ── 会话：HMAC-SHA256 签名 cookie ──
// 密钥存 settings.session_secret（首次生成）；改密码时轮换，旧 cookie 全部失效
const SESSION_TTL = 30 * 86400; // 30 天

async function getSessionSecret(db: D1Database): Promise<string> {
  let secret = await getSetting(db, 'session_secret');
  if (!secret) {
    secret = hex(crypto.getRandomValues(new Uint8Array(32)));
    await setSetting(db, 'session_secret', secret);
  }
  return secret;
}

export async function signSession(db: D1Database): Promise<string> {
  const secret = await getSessionSecret(db);
  const payload = { exp: Math.floor(Date.now() / 1000) + SESSION_TTL };
  const body = btoa(JSON.stringify(payload));
  const sig = hex(await hmac(secret, body));
  return `${body}.${sig}`;
}

export async function verifySession(db: D1Database, token: string | null): Promise<boolean> {
  if (!token) return false;
  const [body, sig] = token.split('.');
  if (!body || !sig) return false;
  const secret = await getSessionSecret(db);
  const expect = hex(await hmac(secret, body));
  if (expect !== sig) return false;
  try {
    const payload = JSON.parse(atob(body));
    return typeof payload.exp === 'number' && payload.exp > Math.floor(Date.now() / 1000);
  } catch {
    return false;
  }
}

// 改密码成功后轮换 session_secret
export async function rotateSessionSecret(db: D1Database): Promise<void> {
  await setSetting(db, 'session_secret', hex(crypto.getRandomValues(new Uint8Array(32))));
}

async function hmac(secret: string, data: string): Promise<ArrayBuffer> {
  const key = await crypto.subtle.importKey(
    'raw',
    enc(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  return crypto.subtle.sign('HMAC', key, enc(data));
}

export const SESSION_COOKIE = 'lc_admin_session';
export const sessionCookie = (token: string): string =>
  `${SESSION_COOKIE}=${token}; Path=/; HttpOnly; SameSite=Lax; Max-Age=${SESSION_TTL}`;
export const clearSessionCookie = (): string =>
  `${SESSION_COOKIE}=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0`;

// 从请求 Cookie 头解析
export function readCookie(request: Request, name: string): string | null {
  const header = request.headers.get('cookie') || '';
  for (const part of header.split(';')) {
    const idx = part.indexOf('=');
    if (idx > 0 && part.slice(0, idx).trim() === name) return part.slice(idx + 1).trim();
  }
  return null;
}
