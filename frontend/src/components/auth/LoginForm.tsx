"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { useForm } from "react-hook-form";

import { FormField } from "@/components/forms/FormField";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { useAuth } from "@/hooks/useAuth";
import { loginSchema, type LoginFormValues } from "@/schemas/auth";
import { getApiErrorMessage } from "@/services/api-client";
import { authService } from "@/services/auth.service";
import { useRememberedAccounts } from "@/hooks/useRememberedAccounts";
import { rememberAccount, removeRememberedAccount } from "@/services/remembered-accounts";

export function LoginForm() {
  const { user, isLoading, login, error: sessionError } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();
  const [requestError, setRequestError] = useState<string | null>(null);
  const [choosingOther, setChoosingOther] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [rememberOnBrowser, setRememberOnBrowser] = useState(true);
  const [choiceContext, setChoiceContext] = useState<{token: string; application: string} | null>(null);
  const [choiceFailed, setChoiceFailed] = useState<string | null>(null);
  const [isContinuing, setIsContinuing] = useState(false);
  const [storageNotice, setStorageNotice] = useState<string | null>(null);
  const remembered = useRememberedAccounts();
  const choiceToken = searchParams.get("account_choice");
  useEffect(() => {
    if (!choiceToken) return;
    let current = true;
    if (!/^[A-Za-z0-9]{64}$/.test(choiceToken)) {
      queueMicrotask(() => { if (current) setChoiceFailed(choiceToken); });
    } else {
      authService.accountChoice(choiceToken).then(context => { if (current) { setChoiceContext({...context, token: choiceToken}); setChoiceFailed(null); } })
        .catch(() => { if (current) setChoiceFailed(choiceToken); });
    }
    return () => { current = false; };
  }, [choiceToken]);
  const errorSummary = useRef<HTMLDivElement>(null);
  useEffect(() => { if (requestError) errorSummary.current?.focus(); }, [requestError]);
  const {
    formState: { errors, isSubmitting },
    handleSubmit,
    register,
    reset,
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    shouldFocusError: false,
    defaultValues: { email: "", password: "", remember: false },
  });

  async function continueToDestination(email?: string): Promise<void> {
      if (choiceToken && choiceContext?.token === choiceToken) {
        const target = await authService.selectAccount(choiceToken, email ?? user?.email ?? "");
        // Only the server's session-bound choice can return an OIDC destination.
        window.location.assign(target);
      } else
      if (searchParams.get("oauth_continue") === "1") {
        const apiBase = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
        // OAuth continuation intentionally leaves the Next.js origin for the IAM backend.
        // eslint-disable-next-line @next/next/no-location-assign-relative-destination
        window.location.assign(`${apiBase}/oauth/continue`);
      } else {
        router.replace("/dashboard");
        router.refresh();
      }
  }

  async function onSubmit(values: LoginFormValues): Promise<void> {
    setRequestError(null);
    try {
      const authenticated = await login(values);
      const toRemember = (authenticated && typeof authenticated.email === "string" && authenticated.email)
        ? authenticated
        : { email: values.email, name: values.email.split("@")[0] };
      if (rememberOnBrowser) {
        if (!rememberAccount(toRemember)) setStorageNotice("This browser could not save the account label.");
      }
      await continueToDestination(authenticated?.email ?? values.email);
    } catch (error) {
      setRequestError(getApiErrorMessage(error));
    }
  }

  async function chooseCurrent(): Promise<void> {
    setRequestError(null);
    setIsContinuing(true);
    try { await continueToDestination(user?.email); } catch (error) { setRequestError(getApiErrorMessage(error)); }
    finally { setIsContinuing(false); }
  }

  function chooseHint(email: string): void {
    if (user?.status === "active" && user.email.toLowerCase() === email.toLowerCase()) { void chooseCurrent(); return; }
    reset({email, password: "", remember: true});
    setChoosingOther(true);
    setRememberOnBrowser(true);
    setRequestError(null);
  }

  async function cancelChoice(): Promise<void> {
    if (!choiceToken) return;
    setIsContinuing(true);
    try {
      const target = await authService.cancelAccountChoice(choiceToken);
      window.location.assign(target);
    } catch (error) { setRequestError(getApiErrorMessage(error)); }
    finally { setIsContinuing(false); }
  }

  if (choiceToken && choiceFailed === choiceToken) return <Alert>This account-selection request expired or your session changed. Return to your application and start a new sign-in. No access was approved.</Alert>;
  if (isLoading || (choiceToken && choiceContext?.token !== choiceToken)) return <p role="status" className="py-6 text-sm text-slate-600">Checking your session…</p>;

  if ((user?.status === "active" || remembered.length > 0) && !choosingOther) {
    return <div className="space-y-4">
      <h2 className="text-lg font-semibold text-slate-900">Choose an account</h2>
      <p className="text-sm text-slate-600">{choiceContext ? `Continue to ${choiceContext.application}.` : "Choose a Doxa account on this browser."} Saved accounts are hints; you may need to sign in.</p>
      {sessionError ? <Alert>{sessionError}</Alert> : null}
      {requestError ? <Alert>{requestError}</Alert> : null}
      {user?.status === "active" ? <><button type="button" disabled={isContinuing} onClick={() => void chooseCurrent()} className="flex min-h-20 w-full items-center gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-left transition hover:border-teal-700 hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-700 disabled:opacity-60">
        <span aria-hidden="true" className="grid size-11 shrink-0 place-items-center rounded-full bg-teal-100 text-lg font-semibold text-teal-900">{user.name.slice(0, 1)}</span>
        <span className="min-w-0"><span className="block font-semibold text-slate-900">{user.name}</span><span className="block break-all text-sm text-slate-600">{user.email}</span></span>
        <span aria-hidden="true" className="ml-auto text-slate-500">→</span>
      </button><p className="text-xs text-teal-800">Signed in</p>
      {remembered.some(account => account.email === user.email.toLowerCase()) ? <button type="button" className="min-h-10 text-sm underline" onClick={() => { if (!removeRememberedAccount(user.email)) setStorageNotice("This browser could not update saved accounts."); }}>Remove saved label for {user.email}</button> : <button type="button" className="min-h-10 text-sm underline" onClick={() => { if (!rememberAccount(user)) setStorageNotice("This browser could not save the account label."); }}>Remember this account on this browser</button>}</> : null}
      {remembered.filter(account => account.email !== user?.email.toLowerCase()).map(account => <div key={account.email} className="rounded-xl border border-slate-200 p-3">
        <button type="button" disabled={isContinuing} className="min-h-14 w-full rounded-lg text-left focus-visible:outline-2 focus-visible:outline-teal-700" onClick={() => chooseHint(account.email)}><span className="block font-semibold text-slate-900">{account.name}</span><span className="block break-all text-sm text-slate-600">{account.email}</span><span className="block text-xs text-slate-500">Sign-in required</span></button>
        <button type="button" className="min-h-10 text-xs text-slate-600 underline" aria-label={`Remove remembered account ${account.email}`} onClick={() => { if (!removeRememberedAccount(account.email)) setStorageNotice("This browser could not update saved accounts."); }}>Remove from this browser</button>
      </div>)}
      <Button type="button" disabled={isContinuing} variant="secondary" className="min-h-12 w-full rounded-xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-700" onClick={() => {reset({email: "", password: "", remember: false}); setChoosingOther(true); setRememberOnBrowser(false);}}>Use another account</Button>
      <p className="text-xs leading-5 text-slate-500">Saved labels stay on this browser for up to 30 days. Removing one does not sign out an account.</p>
      {storageNotice ? <p role="status" className="text-sm text-slate-600">{storageNotice}</p> : null}
      {choiceToken ? <Button type="button" variant="secondary" disabled={isContinuing} className="w-full" onClick={() => void cancelChoice()}>Cancel account selection</Button> : null}
    </div>;
  }

  return (
    <form className="space-y-5" aria-label="Sign in" onSubmit={handleSubmit(onSubmit, () => setRequestError("Check the highlighted fields and try again."))} noValidate aria-busy={isSubmitting}>
      {choiceContext ? <p className="text-sm text-slate-600">Sign in to continue to {choiceContext.application}.</p> : null}
      {choosingOther ? <div className="rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-600"><h2 className="font-semibold text-slate-900">Sign in to another account</h2>{user ? "Your current account stays signed in until you sign in successfully." : "Enter this account’s password to continue."}</div> : null}
      {requestError || sessionError ? <div ref={errorSummary} tabIndex={-1} className="rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-red-700"><Alert>{requestError ?? sessionError}</Alert></div> : null}
      <FormField
        label="Email"
        type="email"
        autoComplete="email"
        required
        error={errors.email?.message}
        {...register("email")}
      />
      <div className="relative"><FormField
        label="Password"
        type={showPassword ? "text" : "password"}
        autoComplete="current-password"
        required
        error={errors.password?.message}
        {...register("password")}
      />
      <button type="button" aria-pressed={showPassword} onClick={() => setShowPassword(!showPassword)} className="mt-1 min-h-10 rounded px-1 text-sm font-medium text-slate-600 underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-teal-700">{showPassword ? "Hide password" : "Show password"}</button></div>
      <label className="flex items-center gap-2 text-sm text-slate-600">
        <input
          type="checkbox"
          className="size-4 rounded border-slate-300"
          {...register("remember")}
        />
        Keep me signed in
      </label>
      <label className="flex items-start gap-2 text-sm text-slate-600"><input type="checkbox" className="mt-1 size-4" checked={rememberOnBrowser} onChange={event => setRememberOnBrowser(event.target.checked)} /><span>Remember this account on this browser <span className="block text-xs">Saves your name and email for 30 days. No password is saved. Avoid this on shared devices.</span></span></label>
      <Button type="submit" className="min-h-12 w-full rounded-xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-700" isLoading={isSubmitting}>
        Sign in
      </Button>
      {choosingOther && (user || remembered.length > 0) ? <Button type="button" variant="secondary" className="w-full" disabled={isSubmitting} onClick={() => { reset(); setChoosingOther(false); setRequestError(null); }}>Back to account selection</Button> : null}
      {storageNotice ? <p role="status">{storageNotice}</p> : null}
      {choiceToken ? <Button type="button" variant="secondary" disabled={isSubmitting || isContinuing} className="w-full" onClick={() => void cancelChoice()}>Cancel account selection</Button> : null}
    </form>
  );
}
