import { Suspense } from "react";
import { ApplicationAccessManager } from "@/components/application-access/ApplicationAccessManager";
import { LoadingState } from "@/components/ui/LoadingState";
export default function ApplicationAccessPage() { return <Suspense fallback={<LoadingState />}><ApplicationAccessManager /></Suspense>; }
