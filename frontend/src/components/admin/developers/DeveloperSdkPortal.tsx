"use client";

import { useEffect, useState } from "react";
import { AdminPage } from "@/components/admin/AdminPage";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { LoadingState } from "@/components/ui/LoadingState";
import { getApiErrorMessage } from "@/services/api-client";
import {
  developersService,
  type DiagnosticsMetadata,
  type SdkMetadata,
} from "@/services/developers.service";

const CODE_EXAMPLES: Record<
  "laravel" | "node" | "django",
  { resolver: string; setup: string }
> = {
  laravel: {
    resolver: `// app/Resolvers/HostUserResolver.php
namespace App\\Resolvers;

use Doxa\\OAuthClient\\Contracts\\AccountResolverInterface;
use Doxa\\OAuthClient\\Identity\\DoxaIdentity;
use App\\Models\\User;

class HostUserResolver implements AccountResolverInterface
{
    public function resolve(DoxaIdentity $identity): User
    {
        // 1. Look up existing local user by immutable subject ID
        $user = User::where('doxa_sub', $identity->subject)->first();

        if (! $user) {
            // 2. Safe host provisioning policy (assign local role & company)
            $user = User::create([
                'doxa_sub' => $identity->subject,
                'email' => $identity->email,
                'name' => $identity->name,
                'role' => 'employee', // Local host authorization!
            ]);
        }

        return $user;
    }
}`,
    setup: `// config/doxa-oauth.php (or register in AppServiceProvider)
use App\\Resolvers\\HostUserResolver;

return [
    'account_resolver' => HostUserResolver::class,
    'routes' => [
        'login' => '/auth/doxa',
        'callback' => '/auth/doxa/callback',
    ],
];`,
  },
  node: {
    resolver: `// src/resolvers/user.resolver.ts
import { AccountResolver, DoxaIdentity } from '@doxa/auth-node';
import { prisma } from '../db';

export class HostUserResolver implements AccountResolver {
  async resolve(identity: DoxaIdentity) {
    // 1. Look up existing local host account by immutable subject
    let user = await prisma.user.findUnique({
      where: { doxaSub: identity.subject }
    });

    if (!user) {
      // 2. Provision host user with local roles
      user = await prisma.user.create({
        data: {
          doxaSub: identity.subject,
          email: identity.email,
          name: identity.name,
          role: 'STAFF', // Local application authorization
        }
      });
    }

    return user;
  }
}`,
    setup: `// src/server.ts
import express from 'express';
import session from 'express-session';
import { DoxaClient, createExpressAuth } from '@doxa/auth-node';
import { HostUserResolver } from './resolvers/user.resolver';

const app = express();
app.use(session({ secret: process.env.SESSION_SECRET!, resave: false, saveUninitialized: false }));

const doxa = new DoxaClient({
  issuer: process.env.DOXA_ISSUER!,
  clientId: process.env.DOXA_CLIENT_ID!,
  redirectUri: process.env.DOXA_REDIRECT_URI!,
});

const auth = createExpressAuth(doxa);

// Drop-in Express routes
app.get('/auth/doxa', auth.login());
app.get('/auth/doxa/callback', auth.callback(new HostUserResolver(), (user, req, res) => {
  req.session.userId = user.id;
  res.redirect('/dashboard');
}));`,
  },
  django: {
    resolver: `# myapp/resolvers.py
from django.contrib.auth import get_user_model
from doxa_django.resolvers import AccountResolver
from doxa_django.identity import DoxaIdentity

User = get_user_model()

class HostUserResolver(AccountResolver):
    def resolve(self, identity: DoxaIdentity):
        # 1. Match local Django user by immutable subject identifier
        user, created = User.objects.get_or_create(
            username=f"doxa_{identity.subject}",
            defaults={
                'email': identity.email,
                'first_name': identity.name.split(' ')[0] if identity.name else '',
                'last_name': ' '.join(identity.name.split(' ')[1:]) if identity.name else '',
            }
        )
        return user`,
    setup: `# settings.py
INSTALLED_APPS = [
    ...,
    'doxa_django',
]

AUTHENTICATION_BACKENDS = [
    'doxa_django.backend.DoxaAuthBackend',
    'django.contrib.auth.backends.ModelBackend',
]

DOXA_AUTH = {
    'ISSUER': os.getenv('DOXA_ISSUER'),
    'CLIENT_ID': os.getenv('DOXA_CLIENT_ID'),
    'CLIENT_SECRET': os.getenv('DOXA_CLIENT_SECRET'),
    'REDIRECT_URI': os.getenv('DOXA_REDIRECT_URI'),
    'ACCOUNT_RESOLVER': 'myapp.resolvers.HostUserResolver',
}

# urls.py
urlpatterns = [
    ...,
    path('auth/doxa/', include('doxa_django.urls', namespace='doxa')),
]`,
  },
};

export function DeveloperSdkPortal() {
  const [sdks, setSdks] = useState<SdkMetadata[]>([]);
  const [diagnostics, setDiagnostics] = useState<DiagnosticsMetadata | null>(null);
  const [activeTab, setActiveTab] = useState<"laravel" | "node" | "django">("laravel");
  const [selectedAppId, setSelectedAppId] = useState<string>("");
  const [selectedClientId, setSelectedClientId] = useState<string>("");
  const [selectedRedirectUri, setSelectedRedirectUri] = useState<string>("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  useEffect(() => {
    Promise.all([
      developersService.getSdks(),
      developersService.getDiagnostics().catch(() => null),
    ])
      .then(([sdkList, diag]) => {
        setSdks(sdkList);
        setDiagnostics(diag);
        if (diag && diag.applications.length > 0) {
          // Prioritize an application that already has OAuth clients registered (e.g. HRM)
          const defaultApp =
            diag.applications.find((a) => a.clients.length > 0) ??
            diag.applications[0];
          setSelectedAppId(defaultApp.id);
          if (defaultApp.clients.length > 0) {
            const firstClient = defaultApp.clients[0];
            setSelectedClientId(firstClient.client_id);
            if (firstClient.redirect_uris.length > 0) {
              setSelectedRedirectUri(firstClient.redirect_uris[0]);
            }
          }
        }
      })
      .catch((err) => setError(getApiErrorMessage(err)))
      .finally(() => setLoading(false));
  }, []);

  function handleAppChange(appId: string) {
    setSelectedAppId(appId);
    const app = diagnostics?.applications.find((a) => a.id === appId);
    if (app && app.clients.length > 0) {
      setSelectedClientId(app.clients[0].client_id);
      setSelectedRedirectUri(app.clients[0].redirect_uris[0] ?? "");
    } else {
      setSelectedClientId("");
      setSelectedRedirectUri("");
    }
  }

  function handleClientChange(clientId: string) {
    setSelectedClientId(clientId);
    const app = diagnostics?.applications.find((a) => a.id === selectedAppId);
    const client = app?.clients.find((c) => c.client_id === clientId);
    if (client && client.redirect_uris.length > 0) {
      setSelectedRedirectUri(client.redirect_uris[0]);
    }
  }

  function copyToClipboard(text: string, key: string) {
    navigator.clipboard.writeText(text);
    setCopiedKey(key);
    setTimeout(() => setCopiedKey(null), 2000);
  }

  const activeSdk = sdks.find((s) => s.framework === activeTab);
  const activeCode = CODE_EXAMPLES[activeTab];

  const currentApp = diagnostics?.applications.find((a) => a.id === selectedAppId);
  const currentClients = currentApp?.clients ?? [];
  const currentClient = currentClients.find((c) => c.client_id === selectedClientId);
  const appWithClients = diagnostics?.applications.find((a) => a.clients.length > 0);

  // Derive customized .env content based on selected app/client
  const issuerUrl = diagnostics?.issuer ?? "https://localhost:8443";
  const clientId = selectedClientId || "your-client-id-uuid";
  const redirectUri = selectedRedirectUri || "https://your-app.test/auth/doxa/callback";

  const envContent = `# Central IAM OIDC Configuration (${activeTab.toUpperCase()})
DOXA_ISSUER=${issuerUrl}
DOXA_CLIENT_ID=${clientId}
DOXA_CLIENT_SECRET=your-client-secret
DOXA_REDIRECT_URI=${redirectUri}
DOXA_SCOPES="openid profile email"`;

  if (loading) {
    return (
      <AdminPage title="Developer SDK Portal">
        <LoadingState label="Loading SDK catalogs and identity metadata…" />
      </AdminPage>
    );
  }

  return (
    <AdminPage
      title="Developer SDK Portal"
      description="Download and integrate official client SDKs for Central IAM OpenID Connect / OAuth 2.0 authentication."
    >
      {error && (
        <div className="mb-6">
          <Alert>{error}</Alert>
        </div>
      )}

      {/* Top Banner & Health Status */}
      <div className="mb-8 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <div className="flex items-center gap-3">
              <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 border border-emerald-200">
                <span className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
                Universal SDK Contract {diagnostics?.contract_version ?? "v1.3"}
              </span>
              <span className="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700 border border-blue-200">
                S256 PKCE Mandatory
              </span>
              <span className="inline-flex items-center rounded-full bg-purple-50 px-3 py-1 text-xs font-medium text-purple-700 border border-purple-200">
                Fail-Closed App Access
              </span>
            </div>
            <h2 className="mt-3 text-lg font-semibold text-slate-900">
              Identity & Access Boundary Architecture
            </h2>
            <p className="mt-1 text-sm text-slate-600 max-w-2xl">
              Central IAM authenticates user identity and verifies authoritative application access permissions via{" "}
              <code className="rounded bg-slate-100 px-1 py-0.5 text-xs font-mono text-slate-800">
                GET /oauth/application-access
              </code>
              . Downstream client applications own 100% of local authorization (roles, permissions, sessions) via the{" "}
              <code className="rounded bg-slate-100 px-1 py-0.5 text-xs font-mono text-slate-800">
                AccountResolver
              </code>{" "}
              contract.
            </p>
          </div>
          {activeSdk && (
            <div className="flex items-center gap-3">
              <a
                href={developersService.getDownloadUrl(activeTab)}
                download
                className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-colors focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                Download {activeSdk.name} (.zip)
              </a>
            </div>
          )}
        </div>
      </div>

      {/* Framework Navigation Tabs */}
      <div className="mb-6 flex border-b border-slate-200">
        {[
          { id: "laravel", label: "Laravel (PHP)", badge: "PHP 8.2+" },
          { id: "node", label: "Node.js (TypeScript)", badge: "Node 18+" },
          { id: "django", label: "Django (Python)", badge: "Python 3.9+" },
        ].map((tab) => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id as "laravel" | "node" | "django")}
            className={`flex items-center gap-2 border-b-2 px-6 py-3.5 text-sm font-medium transition-colors ${
              activeTab === tab.id
                ? "border-indigo-600 text-indigo-600 bg-indigo-50/50"
                : "border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900"
            }`}
          >
            <span>{tab.label}</span>
            <span
              className={`rounded px-1.5 py-0.5 text-xs ${
                activeTab === tab.id ? "bg-indigo-100 text-indigo-700" : "bg-slate-100 text-slate-500"
              }`}
            >
              {tab.badge}
            </span>
          </button>
        ))}
      </div>

      {activeSdk && (
        <div className="space-y-8">
          {/* Package Overview Card */}
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div className="lg:col-span-2 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
              <div className="flex items-start justify-between">
                <div>
                  <h3 className="text-xl font-bold text-slate-900">{activeSdk.name}</h3>
                  <p className="mt-1 text-sm text-slate-600">{activeSdk.description}</p>
                </div>
                <span className="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-mono font-medium text-slate-700">
                  v{activeSdk.version}
                </span>
              </div>

              {/* Install Command */}
              <div className="mt-6">
                <label className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                  Installation
                </label>
                <div className="mt-2 flex items-center justify-between rounded-lg bg-slate-900 p-3.5 font-mono text-sm text-slate-100 shadow-inner">
                  <code>{activeSdk.install_command}</code>
                  <button
                    onClick={() => copyToClipboard(activeSdk.install_command, "install")}
                    className="ml-3 rounded bg-slate-800 px-2.5 py-1 text-xs text-slate-300 hover:bg-slate-700 transition"
                  >
                    {copiedKey === "install" ? "Copied!" : "Copy"}
                  </button>
                </div>
              </div>

              {/* Feature Checklist */}
              <div className="mt-6">
                <label className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                  Contract Compliance Features
                </label>
                <ul className="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm text-slate-700">
                  {activeSdk.features.map((feat, idx) => (
                    <li key={idx} className="flex items-start gap-2">
                      <svg className="h-5 w-5 text-emerald-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                      </svg>
                      <span>{feat}</span>
                    </li>
                  ))}
                </ul>
              </div>
            </div>

            {/* Quick Specs / Distribution Card */}
            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm flex flex-col justify-between">
              <div>
                <h4 className="text-sm font-semibold uppercase tracking-wider text-slate-500">
                  Package Specifications
                </h4>
                <dl className="mt-4 space-y-3 text-sm">
                  <div className="flex justify-between border-b border-slate-100 pb-2">
                    <dt className="text-slate-500">Runtime</dt>
                    <dd className="font-medium text-slate-900">{activeSdk.runtime}</dd>
                  </div>
                  <div className="flex justify-between border-b border-slate-100 pb-2">
                    <dt className="text-slate-500">Package</dt>
                    <dd className="font-mono text-xs font-semibold text-indigo-600">{activeSdk.package_name}</dd>
                  </div>
                  <div className="flex justify-between border-b border-slate-100 pb-2">
                    <dt className="text-slate-500">Compatibility</dt>
                    <dd className="font-medium text-slate-900">{activeSdk.supported_versions}</dd>
                  </div>
                  <div className="flex justify-between border-b border-slate-100 pb-2">
                    <dt className="text-slate-500">Crypto Alg</dt>
                    <dd className="font-medium text-slate-900">RS256 + S256 PKCE</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-slate-500">Contract</dt>
                    <dd className="font-medium text-emerald-600">{activeSdk.contract_version} (Universal)</dd>
                  </div>
                </dl>
              </div>

              <div className="mt-6 pt-4 border-t border-slate-100">
                <a
                  href={developersService.getDownloadUrl(activeTab)}
                  download
                  className="w-full text-center block rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 transition"
                >
                  Download Source (.zip)
                </a>
              </div>
            </div>
          </div>

          {/* Interactive .env Generator Card */}
          <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-4">
              <div>
                <h3 className="text-base font-semibold text-slate-900">
                  Interactive Environment Generator (.env)
                </h3>
                <p className="text-sm text-slate-500">
                  Select a registered Central IAM application to generate tailored credentials.
                </p>
              </div>
              <button
                onClick={() => copyToClipboard(envContent, "env")}
                className="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-200 transition"
              >
                {copiedKey === "env" ? "Copied .env!" : "Copy .env snippet"}
              </button>
            </div>

            <div className="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1">
                  Target Application
                </label>
                <select
                  value={selectedAppId}
                  onChange={(e) => handleAppChange(e.target.value)}
                  className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none"
                >
                  {diagnostics?.applications.map((app) => (
                    <option key={app.id} value={app.id}>
                      {app.name} ({app.slug}) — {app.clients.length} {app.clients.length === 1 ? "OAuth client" : "OAuth clients"}
                    </option>
                  ))}
                </select>
                <p className="mt-1 text-[11px] text-slate-500">
                  Select which Central IAM registered app to connect.
                </p>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1">
                  OAuth Client
                </label>
                {currentClients.length > 0 ? (
                  <>
                    <select
                      value={selectedClientId}
                      onChange={(e) => handleClientChange(e.target.value)}
                      className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none"
                    >
                      {currentClients.map((c) => (
                        <option key={c.client_id} value={c.client_id}>
                          {c.name} ({c.client_id.slice(0, 8)}...)
                        </option>
                      ))}
                    </select>
                    <p className="mt-1 text-[11px] text-slate-500">
                      Client UUID for the Authorization Code flow.
                    </p>
                  </>
                ) : (
                  <div>
                    <select
                      disabled
                      className="w-full rounded-md border border-slate-200 bg-slate-100 px-3 py-2 text-sm text-slate-400 cursor-not-allowed"
                    >
                      <option>No OAuth clients registered</option>
                    </select>
                    <div className="mt-1.5 flex flex-wrap items-center justify-between gap-1 rounded bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800 border border-amber-200">
                      <span>No OAuth credentials yet for {currentApp?.name ?? "this app"}.</span>
                      <a
                        href={`/applications/${selectedAppId}`}
                        target="_blank"
                        rel="noreferrer"
                        className="font-semibold text-amber-900 underline hover:text-amber-950 inline-flex items-center gap-0.5 ml-auto shrink-0"
                      >
                        + Create Client ↗
                      </a>
                    </div>
                  </div>
                )}
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1">
                  Redirect URI
                </label>
                {currentClient && currentClient.redirect_uris.length > 1 ? (
                  <>
                    <select
                      value={selectedRedirectUri}
                      onChange={(e) => setSelectedRedirectUri(e.target.value)}
                      className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none"
                    >
                      {currentClient.redirect_uris.map((uri) => (
                        <option key={uri} value={uri}>
                          {uri}
                        </option>
                      ))}
                    </select>
                    <p className="mt-1 text-[11px] text-slate-500">
                      Registered redirect callback endpoint.
                    </p>
                  </>
                ) : (
                  <>
                    <input
                      type="text"
                      value={selectedRedirectUri}
                      onChange={(e) => setSelectedRedirectUri(e.target.value)}
                      placeholder="https://yourapp.test/auth/doxa/callback"
                      className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none"
                    />
                    <p className="mt-1 text-[11px] text-slate-500">
                      Must match one of the client's registered redirect URIs.
                    </p>
                  </>
                )}
              </div>
            </div>

            {currentClients.length === 0 && appWithClients && (
              <div className="mt-3 flex items-center gap-2 rounded-lg bg-indigo-50/60 p-2.5 text-xs text-indigo-900 border border-indigo-100">
                <span className="font-semibold">💡 Tip:</span>
                <span>Want to see a working example?</span>
                <button
                  type="button"
                  onClick={() => handleAppChange(appWithClients.id)}
                  className="font-semibold text-indigo-700 underline hover:text-indigo-800"
                >
                  Switch to {appWithClients.name} ({appWithClients.clients.length} active client credentials)
                </button>
              </div>
            )}

            <pre className="mt-4 overflow-x-auto rounded-lg bg-slate-950 p-4 font-mono text-xs text-emerald-400">
              {envContent}
            </pre>
          </div>

          {/* Code Architecture & Setup */}
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {/* AccountResolver Code */}
            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
              <div className="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                <div>
                  <h4 className="text-sm font-semibold text-slate-900">
                    1. AccountResolver Implementation
                  </h4>
                  <p className="text-xs text-slate-500">
                    Host application controls local users & roles
                  </p>
                </div>
                <button
                  onClick={() => copyToClipboard(activeCode.resolver, "resolver")}
                  className="text-xs font-medium text-indigo-600 hover:text-indigo-500"
                >
                  {copiedKey === "resolver" ? "Copied!" : "Copy"}
                </button>
              </div>
              <pre className="overflow-x-auto rounded-lg bg-slate-900 p-4 font-mono text-xs text-slate-100 leading-relaxed max-h-96">
                {activeCode.resolver}
              </pre>
            </div>

            {/* Route Setup Code */}
            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
              <div className="flex items-center justify-between border-b border-slate-100 pb-3 mb-3">
                <div>
                  <h4 className="text-sm font-semibold text-slate-900">
                    2. Integration & Route Setup
                  </h4>
                  <p className="text-xs text-slate-500">
                    Drop-in routes and middleware registration
                  </p>
                </div>
                <button
                  onClick={() => copyToClipboard(activeCode.setup, "setup")}
                  className="text-xs font-medium text-indigo-600 hover:text-indigo-500"
                >
                  {copiedKey === "setup" ? "Copied!" : "Copy"}
                </button>
              </div>
              <pre className="overflow-x-auto rounded-lg bg-slate-900 p-4 font-mono text-xs text-slate-100 leading-relaxed max-h-96">
                {activeCode.setup}
              </pre>
            </div>
          </div>

          {/* OIDC Endpoints Reference Card */}
          {diagnostics && (
            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
              <h3 className="text-base font-semibold text-slate-900 mb-2">
                Central IAM OIDC Endpoints & Live Diagnostics
              </h3>
              <p className="text-sm text-slate-500 mb-4">
                These endpoints are automatically discovered by the SDKs using OIDC Discovery.
              </p>
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm text-slate-700">
                  <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                      <th className="px-4 py-2.5">Endpoint</th>
                      <th className="px-4 py-2.5">URL</th>
                      <th className="px-4 py-2.5 text-right">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 font-mono text-xs">
                    {Object.entries(diagnostics.endpoints).map(([name, url]) => (
                      <tr key={name} className="hover:bg-slate-50/50">
                        <td className="px-4 py-2.5 font-sans font-medium text-slate-900 capitalize">
                          {name.replace("_", " ")}
                        </td>
                        <td className="px-4 py-2.5 text-slate-600">{url}</td>
                        <td className="px-4 py-2.5 text-right">
                          <button
                            onClick={() => copyToClipboard(url, name)}
                            className="rounded bg-slate-100 px-2 py-1 text-xs text-slate-700 hover:bg-slate-200 transition"
                          >
                            {copiedKey === name ? "Copied!" : "Copy"}
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      )}
    </AdminPage>
  );
}
