"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

export default function Home() {
  const router = useRouter();

  useEffect(() => {
    const userJson = localStorage.getItem("erp_user");
    if (!userJson) {
      router.push("/login");
    } else {
      router.push("/dashboard");
    }
  }, [router]);

  return (
    <div className="flex h-screen w-screen items-center justify-center bg-slate-50 dark:bg-slate-950">
      <div className="flex flex-col items-center gap-3">
        <div className="h-10 w-10 animate-spin rounded-full border-4 border-indigo-500 border-t-transparent"></div>
        <span className="text-sm font-semibold text-slate-500 dark:text-slate-400">
          Redirecting...
        </span>
      </div>
    </div>
  );
}
