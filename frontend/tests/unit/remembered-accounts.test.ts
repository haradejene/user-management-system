import {afterEach, beforeEach, expect, it, vi} from "vitest";
import {readRememberedAccounts, rememberAccount, rememberedAccountsSnapshot, removeRememberedAccount} from "@/services/remembered-accounts";

beforeEach(() => {localStorage.clear(); vi.useFakeTimers(); vi.setSystemTime(new Date("2026-10-21T12:00:00Z"));});
afterEach(() => {vi.useRealTimers(); vi.restoreAllMocks(); localStorage.clear();});

it("stores only labels and expiry, even when given an authenticated object containing extra fields", () => {
  const identity = {email: "ADA@example.test", name: "Ada Example", password: "not-a-real-password", access_token: "not-a-real-token", id: "not-a-session"};
  expect(rememberAccount(identity)).toBe(true);
  const stored = JSON.parse(rememberedAccountsSnapshot());
  expect(Object.keys(stored[0]).sort()).toEqual(["email", "expiresAt", "name"]);
  expect(stored[0].email).toBe("ada@example.test");
  expect(rememberedAccountsSnapshot()).not.toContain("not-a-real");
});

it("bounds the roster and deduplicates accounts", () => {
  for (let index = 0; index < 8; index++) rememberAccount({email:`user${index}@example.test`, name:`User ${index}`});
  rememberAccount({email:"USER7@example.test",name:"Updated label"});
  const saved = readRememberedAccounts(rememberedAccountsSnapshot());
  expect(saved).toHaveLength(5);
  expect(saved[0].name).toBe("Updated label");
  expect(saved.filter(item=>item.email==="user7@example.test")).toHaveLength(1);
});

it("expires hints after 30 days and allows removal without any authentication action", () => {
  rememberAccount({email:"one@example.test",name:"One"});
  rememberAccount({email:"two@example.test",name:"Two"});
  expect(removeRememberedAccount("one@example.test")).toBe(true);
  expect(readRememberedAccounts(rememberedAccountsSnapshot()).map(item=>item.email)).toEqual(["two@example.test"]);
  vi.advanceTimersByTime(31*24*60*60*1000);
  expect(readRememberedAccounts(rememberedAccountsSnapshot())).toEqual([]);
});

it.each(["not JSON", "{}", "null", JSON.stringify([{email:[],name:"X",expiresAt:Date.now()+1000}]), "x".repeat(10001)])("ignores malformed storage safely", raw => {
  expect(readRememberedAccounts(raw)).toEqual([]);
});

it("drops unknown fields from tampered browser hints", () => {
  const hints = readRememberedAccounts(JSON.stringify([{email:"hint@example.test",name:"Unverified label",expiresAt:Date.now()+1000,authenticated:true,refresh_token:"fake"}]));
  expect(Object.keys(hints[0]).sort()).toEqual(["email","expiresAt","name"]);
});

it("works without storage permission and rejects invalid labels", () => {
  expect(rememberAccount({email:"invalid",name:"Label"})).toBe(false);
  vi.spyOn(Storage.prototype,"getItem").mockImplementation(()=>{throw new Error("Blocked");});
  vi.spyOn(Storage.prototype,"setItem").mockImplementation(()=>{throw new Error("Blocked");});
  expect(rememberedAccountsSnapshot()).toBe("");
  expect(rememberAccount({email:"valid@example.test",name:"Label"})).toBe(false);
});
