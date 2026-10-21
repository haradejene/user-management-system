export interface RememberedAccount {
  email: string;
  name: string;
  expiresAt: number;
}

const KEY = "doxa.remembered-accounts.v1";
const CHANGE = "doxa-remembered-accounts-changed";
const MAX_AGE = 30 * 24 * 60 * 60 * 1000;

export function readRememberedAccounts(raw: string): RememberedAccount[] {
  try {
    if (raw.length > 10_000) return [];
    const data: unknown = JSON.parse(raw);
    if (!Array.isArray(data)) return [];
    const seen = new Set<string>();
    return data.flatMap((value: unknown) => {
      if (!value || typeof value !== "object") return [];
      const item = value as Record<string, unknown>;
      if (typeof item.email !== "string" || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(item.email) || item.email.length > 254 ||
          typeof item.name !== "string" || !item.name.trim() || item.name.length > 100 ||
          typeof item.expiresAt !== "number" || !Number.isFinite(item.expiresAt) || item.expiresAt <= Date.now() || item.expiresAt > Date.now() + MAX_AGE) return [];
      const email = item.email.toLowerCase();
      if (seen.has(email)) return [];
      seen.add(email);
      // Explicitly select labels only. Unknown fields (including credentials)
      // are never returned, displayed, or written back.
      return [{email, name: item.name, expiresAt: item.expiresAt}];
    }).slice(0, 5);
  } catch { return []; }
}

export function rememberedAccountsSnapshot(): string {
  try { return window.localStorage.getItem(KEY) ?? ""; } catch { return ""; }
}

export function subscribeRememberedAccounts(callback: () => void): () => void {
  window.addEventListener("storage", callback);
  window.addEventListener(CHANGE, callback);
  return () => { window.removeEventListener("storage", callback); window.removeEventListener(CHANGE, callback); };
}

function write(accounts: RememberedAccount[]): boolean {
  try {
    window.localStorage.setItem(KEY, JSON.stringify(accounts));
    window.dispatchEvent(new Event(CHANGE));
    return true;
  } catch { return false; }
}

export function rememberAccount(account: {email: string; name: string}): boolean {
  const email = account.email.toLowerCase();
  const hint = {email, name: account.name, expiresAt: Date.now() + MAX_AGE};
  const accounts = readRememberedAccounts(rememberedAccountsSnapshot()).filter(item => item.email !== email);
  // Round-trip through the same whitelist before storing anything.
  const validated = readRememberedAccounts(JSON.stringify([hint]));
  return validated.length === 1 && write([...validated, ...accounts].slice(0, 5));
}

export function removeRememberedAccount(email: string): boolean {
  return write(readRememberedAccounts(rememberedAccountsSnapshot()).filter(item => item.email !== email.toLowerCase()));
}
