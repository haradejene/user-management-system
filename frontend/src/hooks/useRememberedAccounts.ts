"use client";

import { useMemo, useSyncExternalStore } from "react";
import { readRememberedAccounts, rememberedAccountsSnapshot, subscribeRememberedAccounts } from "@/services/remembered-accounts";

export function useRememberedAccounts() {
  const raw = useSyncExternalStore(subscribeRememberedAccounts, rememberedAccountsSnapshot, () => "");
  return useMemo(() => readRememberedAccounts(raw), [raw]);
}
